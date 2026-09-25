<?php

namespace Drupal\ppuc_games\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\default_content_deploy\ExporterInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Form\GameImportForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * ConfigDownloadController.
 */
class GamesController extends ControllerBase {

  /**
   * What the game folder is called inside the archive.
   *
   * Not the game's name: the runtime looks for a folder called 'ppuc' on the
   * USB stick, so the thing the user copies has to be called that already.
   */
  protected const GAME_FOLDER_NAME = 'ppuc';

  /**
   * What the game folder download is called.
   *
   * The site hands out two archives per game and they are not interchangeable:
   * this one is a runnable game folder for a machine, the other is the game's
   * content for another config-tool. Both were <Game>_<uuid>.tar.gz, which is
   * one wrong download away from an operator wondering why a stick full of
   * JSON does not boot.
   */
  protected const GAME_FOLDER_ARCHIVE_PREFIX = 'PPUC_Game_Folder_';

  /**
   * What the runtime opens, and therefore what the downloads are called.
   *
   * ppuc reads these two by name from the game folder -- see ppuc.cpp, which
   * loads `gameFolder / "io-boards.yaml"`. The names are not a label for the
   * download, they are the contract with the machine, so the single-file
   * downloads and the game folder have to agree on them. They did not: the
   * folder wrote io-boards.yaml while the standalone download produced
   * <Game>_<uuid>.yml, which has to be renamed by hand before the runtime will
   * look at it. Both now come from here.
   */
  protected const CONFIG_FILENAME = 'io-boards.yaml';
  protected const PPUC_INI_FILENAME = 'ppuc.ini';

  public function __construct(protected FileSystemInterface $fileSystem, protected ExporterInterface $exporter) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): GamesController {
    return new static(
      $container->get('file_system'),
      $container->get('default_content_deploy.exporter')
    );
  }

  public function sortEntitiesByNumberField($a, $b): int {
    if ($a->field_number->value == $b->field_number->value) {
      return 0;
    }
    return ($a->field_number->value > $b->field_number->value) ? 1 : -1;
  }

  public function sortEntitiesById($a, $b): int {
    if ($a->id() == $b->id()) {
      return 0;
    }
    return ($a->id() > $b->id()) ? 1 : -1;
  }

  public function sortArrayByNumberValues($a, $b): int {
    if ($a['number'] == $b['number']) {
      return 0;
    }
    return ($a['number'] > $b['number']) ? 1 : -1;
  }

  protected function getSwitchDebounceMode(NodeInterface $switch): string {
    if (!$switch->hasField('field_debounce_mode') || $switch->get('field_debounce_mode')->isEmpty()) {
      return 'standard';
    }

    return match ($switch->get('field_debounce_mode')->entity?->uuid()) {
      'a95ab8d7-fd1d-4bd1-94df-d00eee01ec62' => 'fastFlip',
      default => 'standard',
    };
  }

  protected function getBooleanFieldValue(NodeInterface $node, string $field_name): bool {
    return $node->hasField($field_name) && !$node->get($field_name)->isEmpty() && (bool) $node->get($field_name)->value;
  }

  /**
   * The number of the switch an entity reference field points at.
   *
   * NULL when the field is absent, empty, or references a node that has been
   * deleted - a dangling reference must not export as switch 0, which is a
   * real switch number.
   */
  protected function getSwitchNumberFieldValue(NodeInterface $node, string $field_name): ?int {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $switch = $node->get($field_name)->entity;
    if (!$switch instanceof NodeInterface || !$switch->hasField('field_number')) {
      return NULL;
    }

    $number = $switch->get('field_number')->value;
    return $number === NULL ? NULL : (int) $number;
  }

  /**
   * The numbers of every switch a multi-value reference field points at.
   *
   * Skips anything dangling for the same reason getSwitchNumberFieldValue()
   * returns NULL for one: exporting 0 would name switch 0, which a game can
   * really have.
   *
   * @return int[]
   */
  protected function getSwitchNumbersFieldValue(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [];
    }

    $numbers = [];
    foreach ($node->get($field_name)->referencedEntities() as $switch) {
      if (!$switch instanceof NodeInterface || !$switch->hasField('field_number')) {
        continue;
      }
      $number = $switch->get('field_number')->value;
      if ($number !== NULL) {
        $numbers[] = (int) $number;
      }
    }

    return $numbers;
  }

  protected function getColorFieldValue(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $color = $node->get($field_name)->color;
    return $color !== '' ? $color : NULL;
  }

  protected function getWhiteFieldValue(NodeInterface $node, string $field_name): int {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return 0;
    }

    return max(0, min(255, (int) $node->get($field_name)->value));
  }

  /**
   * An integer field's value, or NULL when the field is absent or empty.
   *
   * Distinguishes "not set" from "set to zero", so a caller can fall back to a
   * sensible default without a deliberate 0 being overwritten by it.
   */
  protected function getIntFieldValue(NodeInterface $node, string $field_name): ?int {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    return (int) $node->get($field_name)->value;
  }

  protected function getStringFieldValue(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $value = trim((string) $node->get($field_name)->value);
    return $value !== '' ? $value : NULL;
  }

  protected function getIniSettingsNode(NodeInterface $game): ?NodeInterface {
    return \Drupal::service('ppuc_games.game_settings')->find($game);
  }

  protected function getIniFieldValue(NodeInterface $settings, string $field_name): string {
    if (!$settings->hasField($field_name) || $settings->get($field_name)->isEmpty()) {
      return '';
    }

    $field = $settings->get($field_name);
    $definition = $field->getFieldDefinition();
    if ($definition->getType() === 'boolean') {
      return (bool) $field->value ? 'true' : 'false';
    }

    return trim((string) $field->value);
  }

  protected function getIniSettingValue(NodeInterface $game, string $field_name, string $default = ''): string {
    $settings = $this->getIniSettingsNode($game);
    if (!$settings instanceof NodeInterface) {
      return $default;
    }

    $value = $this->getIniFieldValue($settings, $field_name);
    return $value !== '' ? $value : $default;
  }

  protected function getIniBool01Value(NodeInterface $settings, string $field_name): string {
    if (!$settings->hasField($field_name) || $settings->get($field_name)->isEmpty()) {
      return '0';
    }

    return (bool) $settings->get($field_name)->value ? '1' : '0';
  }

  protected function buildPpucIni(NodeInterface $game): string {
    $settings = $this->getIniSettingsNode($game);

    $value = function (string $field_name, string $default = '') use ($settings): string {
      if (!$settings instanceof NodeInterface) {
        return $default;
      }
      $field_value = $this->getIniFieldValue($settings, $field_name);
      return $field_value !== '' ? $field_value : $default;
    };
    $bool01 = function (string $field_name, string $default = '0') use ($settings): string {
      return $settings instanceof NodeInterface ? $this->getIniBool01Value($settings, $field_name) : $default;
    };

    // The one name in the game folder that has to agree with itself. PinMAME
    // looks up the ROM zip by it, and the folder skeleton names altcolor/,
    // pupvideos/ and altsound/ subdirectories after it. This used to resolve
    // separately here -- ini setting, machine name, node title -- while the
    // skeleton went through getGameRomName(), which also reads the filename of
    // the uploaded ROM media. A game with a ROM attached but no machine name
    // therefore got directories called flash_l1 and a ppuc.ini saying
    // Rom=Flash, and PinMAME found no ROM at all. One resolver, one answer.
    $sections = [
      'Game' => [
        'Rom' => $this->getGameRomName($game),
        // io-boards.yaml carries `engine: gamecore`, but nothing reads it --
        // ppuc selects its engine from [Game] Engine alone, and a folder that
        // never wrote the key always started in PinMAME mode, whatever the game
        // was configured as. It also spells the ROM-less engine "script" and
        // refuses to start on any other value, so `gamecore` cannot be passed
        // through as-is.
        'Engine' => $this->getEngine($game) === 'gamecore' ? 'script' : 'pinmame',
      ],
      'Paths' => [
        'ConfigFile' => $value('field_ini_config_file'),
        'Rom' => $value('field_ini_paths_rom'),
        'Serial' => $value('field_ini_serial'),
        'PinmamePath' => $value('field_ini_pinmame_path'),
        'Rules' => $value('field_ini_rules_path'),
        'MusicFiles' => $value('field_ini_music_files'),
        'MusicGapMs' => $value('field_ini_music_gap_ms', '2000'),
        'Translite' => $value('field_ini_translite'),
        'TransliteAttract' => $value('field_ini_translite_attract'),
      ],
      // Levels, in percent, 100 being unchanged. MusicFiles and MusicGapMs stay
      // under Paths where ppuc has always read them; only the levels are new,
      // and a volume is not a path.
      'Audio' => [
        'Volume' => $value('field_ini_volume', '100'),
        'RomVolume' => $value('field_ini_rom_volume', '100'),
        'SpeechVolume' => $value('field_ini_speech_volume', '100'),
        'MusicVolume' => $value('field_ini_music_volume', '100'),
      ],
      // The two buttons that page through the how-to-play slides, normally the
      // flipper buttons. Only written when one of them is set: an [Attract]
      // section of nothing but zeroes would be a section saying "no buttons"
      // where saying nothing says the same thing, and every other key in this
      // section has a default in ppuc that a game folder should not have to
      // repeat.
      'Attract' => array_filter([
        'SlideNextSwitch' => $value('field_ini_slide_next_switch', '0'),
        'SlidePreviousSwitch' => $value('field_ini_slide_prev_switch', '0'),
      ], static fn($v) => $v !== '' && $v !== '0'),
      'Backbox' => [
        'Address' => $value('field_ini_backbox_address'),
        'Port' => $value('field_ini_backbox_port', '6789'),
      ],
      'Runtime' => [
        'NoSerial' => $value('field_ini_no_serial', 'false'),
        'NoDisplay' => $value('field_ini_no_display', 'false'),
        'NoSound' => $value('field_ini_no_sound', 'false'),
        'Debug' => $value('field_ini_debug', 'false'),
        'DebugErrors' => $value('field_ini_debug_errors', 'false'),
        'DebugSwitches' => $value('field_ini_debug_switches', 'false'),
        'DebugCoils' => $value('field_ini_debug_coils', 'false'),
        'DebugLamps' => $value('field_ini_debug_lamps', 'false'),
        'DebugEffects' => $value('field_ini_debug_effects', 'false'),
        'DebugSoundCommands' => $value('field_ini_debug_sound_commands', 'false'),
        'DebugAudio' => $value('field_ini_debug_audio', 'false'),
        'DebugSegments' => $value('field_ini_debug_segments', 'false'),
        'Rules' => $value('field_ini_runtime_rules', 'false'),
        // ppuc accepts Serum and AltColor as two spellings of one option, and
        // DumpDmdTxt likewise for DumpDisplay, so only one of each is written.
        'AltColor' => $value('field_ini_alt_color', 'false'),
        'SerumTimeout' => $value('field_ini_serum_timeout', '0'),
        'SerumResolution' => $value('field_ini_serum_resolution', '0'),
        'SerumSkipFrames' => $value('field_ini_serum_skip_frames', '0'),
        'PUP' => $value('field_ini_pup', 'false'),
        'AltSound' => $value('field_ini_alt_sound', 'false'),
        'AltSoundMode' => $value('field_ini_altsound_mode', '0'),
        'B2S' => $value('field_ini_b2s', 'false'),
        'B2SSegmentAngleDegrees' => $value('field_ini_b2s_angle', '18.0'),
        'B2SSegmentGlow' => $value('field_ini_b2s_glow', '80.0'),
        'B2SSegmentSmoothing' => $value('field_ini_b2s_smoothing', 'true'),
        'PluginDir' => $value('field_ini_plugin_dir'),
        'PUPFolder' => $value('field_ini_pup_folder'),
        'AltSoundFolder' => $value('field_ini_altsound_folder'),
        'ConsoleDisplay' => $value('field_ini_console_display', 'false'),
        'DumpDisplay' => $value('field_ini_dump_display', 'false'),
        'SkipBoards' => $value('field_ini_skip_boards'),
        // Firmware updates over the game bus. Every gate defaults to false, so
        // an exported folder never flashes a board unless it was asked to.
        'FirmwarePath' => $value('field_ini_firmware_path'),
        'AllowFirmwareUpdate' => $value('field_ini_allow_fw_update', 'false'),
        'AllowDevFirmwareUpdate' => $value('field_ini_allow_dev_fw_update', 'false'),
        'AllowFirmwareDowngrade' => $value('field_ini_allow_fw_downgrade', 'false'),
        'AllowUnvalidatedFirmwareUpdate' => $value('field_ini_allow_unvalidated_fw', 'false'),
        // 0 everywhere else -- firmware, libppuc and this binary. The 2 ms
        // this used to default to was compensating for the 32-byte UART receive
        // buffer on pre-0.3.0 boards, which drops switch replies the parser
        // never sees; firmware 0.3.0 sizes that FIFO to 512 bytes and the delay
        // stops doing that job. It must not be lowered on older firmware.
        // See ppuc/docs/V2_PROTOCOL.md, "The receive buffer is the one that bites".
        'SwitchReplyDelayUs' => $value('field_ini_switch_reply_us', '0'),
        'SwitchRefreshIdleMs' => $value('field_ini_switch_refresh_ms', '15000'),
        'OutputFrameIntervalMs' => $value('field_ini_output_frame_ms', '4'),
        'BallSearch' => $value('field_ini_ball_search', 'false'),
        'BallSearchDelayMs' => $value('field_ini_ball_search_delay', '15000'),
        'BallSearchRoundDelayMs' => $value('field_ini_ball_search_round', '5000'),
        'CoilHoldFrames' => $value('field_ini_coil_hold_frames', '3'),
        'CloseCoinDoor' => $value('field_ini_close_coin_door', 'false'),
        'HardReset' => $value('field_ini_hard_reset', 'false'),
      ],
      'OutputFilters' => [
        'RoundedCorners' => $value('field_ini_rounded_corners', '0'),
      ],
      'ZeDMD' => [
        'Enabled' => $bool01('field_ini_zedmd_enabled', '1'),
        'Device' => $value('field_ini_zedmd_device'),
        'Debug' => $bool01('field_ini_zedmd_debug', '0'),
        'Brightness' => $value('field_ini_zedmd_brightness', '-1'),
      ],
      'ZeDMD-WiFi' => [
        'Enabled' => $bool01('field_ini_zedmd_wifi_enabled', '0'),
        'WiFiAddr' => $value('field_ini_zedmd_wifi_addr'),
      ],
      'ZeDMD-SPI' => [
        'Enabled' => $bool01('field_ini_zedmd_spi_enabled', '0'),
        'Speed' => $value('field_ini_zedmd_spi_speed', '72000000'),
        'FramePause' => $value('field_ini_zedmd_spi_pause', '2'),
        'Width' => $value('field_ini_zedmd_spi_width', '128'),
        'Height' => $value('field_ini_zedmd_spi_height', '32'),
      ],
      'Pixelcade' => [
        'Enabled' => $bool01('field_ini_pixelcade_enabled', '0'),
        'Device' => $value('field_ini_pixelcade_device'),
      ],
      'PIN2DMD' => [
        'Enabled' => $bool01('field_ini_pin2dmd_enabled', '0'),
      ],
      'Speech' => [
        'Enabled' => $value('field_ini_speech_enabled', 'false'),
        'Greeting' => $value('field_ini_speech_greeting', 'false'),
        'Backend' => $value('field_ini_speech_backend', 'auto'),
        'Voice' => $value('field_ini_speech_voice'),
        'Rate' => $value('field_ini_speech_rate'),
        'Pitch' => $value('field_ini_speech_pitch'),
      ],
      'BenchTest' => [
        'SwitchTest' => $value('field_ini_switch_test', 'false'),
        'CoilTest' => $value('field_ini_coil_test', 'false'),
        'LampTest' => $value('field_ini_lamp_test', 'false'),
        'GITest' => $value('field_ini_gi_test', 'false'),
        'FlasherTest' => $value('field_ini_flasher_test', 'false'),
        'Interactive' => $value('field_ini_interactive_test', 'false'),
        'Number' => $value('field_ini_test_number', '0'),
      ],
      'Translite' => [
        'Window' => $value('field_ini_translite_window', 'false'),
        'Width' => $value('field_ini_translite_width', '1920'),
        'Height' => $value('field_ini_translite_height', '1080'),
        'Screen' => $value('field_ini_translite_screen', '-1'),
      ],
      'VirtualDMD' => [
        'Enabled' => $value('field_ini_virtual_dmd', 'false'),
        'HD' => $value('field_ini_virtual_dmd_hd', 'false'),
        'Window' => $value('field_ini_virtual_dmd_window', 'false'),
        'Width' => $value('field_ini_virtual_dmd_width', '1280'),
        'Height' => $value('field_ini_virtual_dmd_height', '320'),
        'Screen' => $value('field_ini_virtual_dmd_screen', '-1'),
        'X' => $value('field_ini_virtual_dmd_x', '0'),
        'Y' => $value('field_ini_virtual_dmd_y', '0'),
        'Rotation' => $value('field_ini_virtual_dmd_rotation', '0'),
        'Renderer' => $value('field_ini_virtual_dmd_renderer', 'dots'),
      ],
    ];

    $lines = [];
    foreach ($sections as $section => $pairs) {
      // A section with nothing in it says nothing that leaving it out does not.
      if (!$pairs) {
        continue;
      }
      $lines[] = '[' . $section . ']';
      foreach ($pairs as $key => $field_value) {
        $lines[] = $key . '=' . $field_value;
      }
      $lines[] = '';
    }

    return implode("\n", $lines);
  }

  protected function getLedTypeName(NodeInterface $node): ?string {
    if (!$node->hasField('field_led_type') || $node->get('field_led_type')->isEmpty()) {
      return NULL;
    }

    return $node->get('field_led_type')->entity?->getName();
  }

  protected function parseIntegerList(string $value): array {
    $numbers = [];
    foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $part) {
      if ($part === '') {
        continue;
      }
      if (!preg_match('/^\d+$/', $part)) {
        continue;
      }
      $numbers[] = (int) $part;
    }
    return array_values(array_unique($numbers));
  }

  protected function getLineConfigField(NodeInterface $node, string $field_name): array {
    $value = $this->getStringFieldValue($node, $field_name);
    if ($value === NULL) {
      return [];
    }

    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
      $line = trim(preg_replace('/#.*/', '', $line) ?? '');
      if ($line !== '') {
        $lines[] = $line;
      }
    }
    return $lines;
  }

  protected function parseSwitchGroups(NodeInterface $node): array {
    $group_names = $this->parseSwitchGroupNames($node);
    if ($group_names === []) {
      return [];
    }

    $groups = [];
    $membership_lines = $this->getLineConfigField($node, 'field_switch_group_memberships');
    if ($membership_lines === []) {
      $membership_lines = $this->getLineConfigField($node, 'field_switch_groups');
    }
    foreach ($membership_lines as $line) {
      if (!preg_match('/^([A-Za-z][A-Za-z0-9_-]*)\s*[:=]\s*(.*)$/', $line, $matches)) {
        continue;
      }
      $name = $matches[1];
      if (!isset($group_names[$name])) {
        continue;
      }
      $switches = $this->parseIntegerList($matches[2]);
      if ($switches !== []) {
        $groups[$name] = ['switches' => $switches];
      }
    }
    return $groups;
  }

  protected function parseSwitchGroupNames(NodeInterface $node): array {
    $groups = [];
    foreach ($this->getLineConfigField($node, 'field_switch_groups') as $line) {
      if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*)/', $line, $matches) && $matches[1] !== 'buttons') {
        $groups[$matches[1]] = TRUE;
      }
    }
    return $groups;
  }

  protected function parseCoilGiMappings(NodeInterface $node): array {
    $mappings = [];
    foreach ($this->getLineConfigField($node, 'field_coil_gi_mappings') as $line) {
      if (!preg_match('/^(\d+)\s*:\s*([0-9,\s]+)\s*=\s*(\d+)(?:\s*\/\s*(\d+))?$/', $line, $matches)) {
        continue;
      }
      $coil = (int) $matches[1];
      $on_brightness = max(0, min(8, (int) $matches[3]));
      $off_brightness = isset($matches[4]) && $matches[4] !== '' ? max(0, min(8, (int) $matches[4])) : 0;
      foreach ($this->parseIntegerList($matches[2]) as $gi) {
        if ($gi < 1) {
          continue;
        }
        $mappings[] = [
          'coil' => $coil,
          'gi' => $gi,
          'onBrightness' => $on_brightness,
          'offBrightness' => $off_brightness,
        ];
      }
    }
    return $mappings;
  }

  protected function ledTypeSupportsWhite(?string $led_type): bool {
    return $led_type !== NULL && str_contains(strtoupper($led_type), 'W');
  }

  protected function formatLedColorForConfig(string $color, int $white, bool $supports_white): string {
    if (!preg_match('/^#?(?:([0-9a-f]{2}))?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $color, $matches)) {
      return $color;
    }

    $red = $matches[2];
    $green = $matches[3];
    $blue = $matches[4];
    if ($supports_white) {
      return strtoupper(sprintf('%02X%s%s%s', $white, $red, $green, $blue));
    }

    return strtoupper($red . $green . $blue);
  }

  public function accessAddSwitchMatrixSwitch(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'switch_matrix')
      ->andIf(AccessResult::allowedIfHasPermission($account, 'create switch_matrix_switch content'))
      ->addCacheableDependency($node);
  }

  public function addSwitchMatrixSwitch(NodeInterface $node): array {
    $switch = Node::create([
      'type' => 'switch_matrix_switch',
      'field_switch_matrix' => ['target_id' => $node->id()],
    ]);

    return $this->entityFormBuilder()->getForm($switch);
  }

  /**
   * @param \Drupal\node\NodeInterface $node
   * @param array $objects
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function buildYaml(NodeInterface $node, array &$objects): array {
    /** @var \Drupal\taxonomy\TermInterface $platform */
    $platform = $node->get('field_platform')->entity;
    $yaml = [
      'ppucVersion' => 1,
      'rom' => 'dummy',
      'serialPort' => $this->getIniSettingValue($node, 'field_ini_serial', 'dummy'),
      'platform' => $platform->getName(),
      'coinDoorClosedSwitch' => (int) ($node->field_coin_door_closed_switch->value ?? 0),
      'gameOnSolenoid' => (int) ($node->field_game_on_solenoid->value ?? 0),
      'debug' => FALSE,
      'boards' => [],
      'dipSwitches' => [],
      'switches' => [],
      'ledStripes' => [],
      'pwmOutput' => [],
      'mechs' => [],
    ];

    // Device roles are collected as the devices are walked, so a role can only
    // ever name a device that was actually exported. This is why roles live on
    // the device rather than as numbers typed on the game.
    $roles = [];

    $switch_groups = $this->parseSwitchGroups($node);
    if ($switch_groups !== []) {
      $yaml['switchGroups'] = $switch_groups;
    }

    $coil_gi_mappings = $this->parseCoilGiMappings($node);
    if ($coil_gi_mappings !== []) {
      $yaml['coilGiMappings'] = $coil_gi_mappings;
    }

    $storage = $this->entityTypeManager()->getStorage($node->getEntityTypeId());

    $dip_switches = $storage->loadByProperties([
      'field_game' => $node->id(),
      $node->getEntityType()->getKey('bundle') => 'dip_switch',
    ]);
    uasort($dip_switches, [$this, 'sortEntitiesByNumberField']);

    /** @var NodeInterface $dip_switch */
    foreach ($dip_switches as $dip_switch) {
      $objects[] = $dip_switch;
      if (!$dip_switch->isPublished()) {
        continue;
      }
      $yaml['dipSwitches'][] = [
        'description' => trim($dip_switch->label()),
        'number' => (int) ($dip_switch->get('field_number')->value),
        'on' => (bool) ($dip_switch->get('field_status')->value),
      ];
    }

    $i_o_boards = $storage->loadByProperties([
      'field_game' => $node->id(),
      $node->getEntityType()->getKey('bundle') => 'i_o_board',
    ]);
    uasort($i_o_boards, [$this, 'sortEntitiesByNumberField']);

    /** @var NodeInterface $i_o_board */
    foreach ($i_o_boards as $i_o_board) {
      $objects[] = $i_o_board;
      $i_o_board_number = (int) ($i_o_board->get('field_number')->value);
      $i_o_board_type = $i_o_board->get('field_io_board_type')->entity;
      $i_o_board_gpio_mapping = unserialize($i_o_board_type->field_gpio_mapping->value, ['allowed_classes' => FALSE]);
      $poll_events = FALSE;

      // Switches, PWM, LED strings.
      $devices = $storage->loadByProperties([
        'field_i_o_board' => $i_o_board->id(),
      ]);
      /** @var NodeInterface $device */
      foreach ($devices as $device) {
        $objects[] = $device;
        switch ($device->bundle()) {
          case 'switch':
            if ($i_o_board->isPublished() && $device->isPublished()) {
              $switch = [
                'description' => trim($device->label()),
                'number' => (int) ($device->get('field_number')->value),
                'board' => $i_o_board_number,
                'port' => $i_o_board_gpio_mapping[(int) ($device->get('field_pin')->value)],
                'debounce' => $device->hasField('field_debounce') ? ((int) $device->get('field_debounce')->value) : 10,
                'debounceMode' => $this->getSwitchDebounceMode($device),
              ];
              if ($this->getBooleanFieldValue($device, 'field_button')) {
                $switch['button'] = TRUE;
              }

              $yaml['switches'][] = $switch;
              if ($role = $this->gameRoleOf($device, (int) ($device->get('field_number')->value))) {
                $roles[$role][] = (int) ($device->get('field_number')->value);
              }

              $poll_events = TRUE;
            }
            break;

          case 'switch_matrix':
            $switch_matrix_switches = $storage->loadByProperties([
              'field_switch_matrix' => $device->id(),
              $node->getEntityType()
                ->getKey('bundle') => 'switch_matrix_switch',
            ]);
            uasort($switch_matrix_switches, static function (NodeInterface $a, NodeInterface $b): int {
              return ((int) $a->get('field_position')->value <=> (int) $b->get('field_position')->value)
                ?: ((int) $a->get('field_number')->value <=> (int) $b->get('field_number')->value);
            });

            $switches = [];
            /** @var NodeInterface $switch_matrix_switch */
            foreach ($switch_matrix_switches as $switch_matrix_switch) {
              $objects[] = $switch_matrix_switch;
              if ($switch_matrix_switch->isPublished()) {
                $switch = [
                  'description' => trim($switch_matrix_switch->label()),
                  'number' => (int) ($switch_matrix_switch->get('field_number')->value),
                  'board' => $i_o_board_number,
                  'port' => (int) ($switch_matrix_switch->get('field_position')->value),
                ];
                if ($this->getBooleanFieldValue($switch_matrix_switch, 'field_button')) {
                  $switch['button'] = TRUE;
                }

                $switches[] = $switch;
                if ($role = $this->gameRoleOf($switch_matrix_switch, (int) ($switch_matrix_switch->get('field_number')->value))) {
                $roles[$role][] = (int) ($switch_matrix_switch->get('field_number')->value);
              }
              }
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $yaml['switchMatrix'] = [
                'description' => trim($device->label()),
                'board' => $i_o_board_number,
                'activeLow' => (bool) ($device->get('field_active_low')->value),
                'rows' => (int) ($device->get('field_rows')->value),
                'switches' => $switches,
              ];
            }

            break;

          case 'pwm_device':
            $type = '';
            switch ($device->get('field_pwm_type')->entity->uuid()) {
              case '620014f7-3bb6-4413-8d22-284706357dbb':
                $type = 'flasher';

                break;

              case '08c2b5ce-2209-4e13-89be-e95f2d4acdb4':
                $type = 'lamp';

                break;

              case '66bf8987-9d98-46dd-a7b4-81fde06af734':
                $type = 'motor';

                break;

              case 'bc5322b5-df99-4637-b0f9-59a6be00db27':
                $type = 'shaker';

                break;

              case 'f72c503f-19af-488e-8eb1-64f234854ea7':
              default:
                $type = 'coil';

                break;
            }

            $pwm_effects = $storage->loadByProperties([
              'field_pwm_device' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'pwm_effect',
            ]);
            uasort($pwm_effects, [$this, 'sortEntitiesById']);

            $effects = [];
            /** @var NodeInterface $pwm_effect */
            foreach ($pwm_effects as $pwm_effect) {
              $objects[] = $pwm_effect;
              if (!$pwm_effect->isPublished()) {
                continue;
              }
              $effect = [
                'name' => trim((string) $pwm_effect->get('field_machine_name')->value),
                'description' => trim($pwm_effect->label()),
                'duration' => (int) ($pwm_effect->get('field_duration')->value),
                'effect' => (int) ($pwm_effect->get('field_pwm_effect')->entity->field_number->value ?? 0),
                'frequency' => (int) ($pwm_effect->get('field_frequency')->value),
                'maxIntensity' => (int) ($pwm_effect->get('field_max_intensity')->value),
                'minIntensity' => (int) ($pwm_effect->get('field_min_intensity')->value),
                'mode' => (int) ($pwm_effect->get('field_mode')->value),
                'priority' => (int) ($pwm_effect->get('field_priority')->value),
                'repeat' => (int) ($pwm_effect->get('field_repeat')->value),
              ];
              $trigger_source = $this->getStringFieldValue($pwm_effect, 'field_trigger_source');
              if ($trigger_source !== NULL && $pwm_effect->hasField('field_trigger_number') && !$pwm_effect->get('field_trigger_number')->isEmpty()) {
                $effect['simpleTrigger'] = [
                  'source' => $trigger_source,
                  'number' => (int) $pwm_effect->get('field_trigger_number')->value,
                  'value' => $this->getBooleanFieldValue($pwm_effect, 'field_trigger_value') ? 1 : 0,
                ];
              }
              $effects[] = $effect;
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $pwm_output = [
                'description' => trim($device->label()),
                'type' => $type,
                'number' => (int) ($device->get('field_number')->value),
                'board' => $i_o_board_number,
                'port' => $i_o_board_gpio_mapping[(int) ($device->get('field_pin')->value)],
                'power' => (int) ($device->get('field_power')->value),
                'holdPower' => (int) ($device->get('field_hold_power')->value),
                'holdPowerActivationTime' => (int) ($device->get('field_hold_power_activation_time')->value),
                'minPulseTime' => (int) ($device->get('field_min_pulse_time')->value),
                'maxPulseTime' => (int) ($device->get('field_max_pulse_time')->value),
                'fastFlipSwitch' => (int) ($device->get('field_fast_activation_switch')->entity->field_number->value ?? 0),
                'effects' => $effects,
              ];
              if ($this->getBooleanFieldValue($device, 'field_ball_search')) {
                $pwm_output['ballSearch'] = TRUE;
              }
              // Declares that the coil bounds itself: it has a hold winding
              // and an EOS contact that transfers to it. libppuc warns about a
              // coil with no maxPulseTime, no hold power and no this, because
              // nothing then stops the ROM leaving it energised.
              if ($this->getBooleanFieldValue($device, 'field_dual_winding')) {
                $pwm_output['dualWinding'] = TRUE;
              }
              // Only when the contact is wired back to an input. Referenced as
              // a switch node so it cannot name a switch that does not exist,
              // and exported as that switch's number, which is what the
              // firmware addresses.
              $eos_switch = $this->getSwitchNumberFieldValue($device, 'field_eos_switch');
              if ($eos_switch !== NULL) {
                $pwm_output['eosSwitch'] = $eos_switch;
              }
              // A separately driven hold winding, as on WPC Fliptronic. It is
              // wound to sit energised, so libppuc accepts it with no maximum
              // pulse time - which is the only correct setting for it, and
              // which would otherwise be reported as an unprotected coil.
              if ($this->getBooleanFieldValue($device, 'field_hold_winding')) {
                $pwm_output['holdWinding'] = TRUE;
              }
              // Switches that cut this output when they close - a flipper's
              // EOS, or the ends of a motor's travel. The board acts on them
              // itself, which is why they have to be on its own I/O board.
              $stop_switches = $this->getSwitchNumbersFieldValue($device, 'field_stop_switches');
              if ($stop_switches) {
                $pwm_output['stopSwitches'] = $stop_switches;
              }

              $yaml['pwmOutput'][] = $pwm_output;
              if ($role = $this->gameRoleOf($device, (int) ($device->get('field_number')->value))) {
                $roles[$role][] = (int) ($device->get('field_number')->value);
              }
            }
            break;

          case 'addressable_leds':
            $stripe_led_type = $this->getLedTypeName($device);
            $leds = [
              'lamps' => [],
              'flashers' => [],
              'gi' => [],
            ];

            $addressable_leds = $storage->loadByProperties([
              'field_string' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'addressable_led',
            ]);
            uasort($addressable_leds, [$this, 'sortEntitiesByNumberField']);
            /** @var NodeInterface $addressable_led */
            foreach ($addressable_leds as $addressable_led) {
              $objects[] = $addressable_led;
              if (!$addressable_led->isPublished()) {
                continue;
              }
              $role = '';
              switch ($addressable_led->get('field_role')->entity->uuid()) {
                case '380dd744-eef0-4bb8-9b62-d6d4ac2af2c6':
                  $role = 'lamps';

                  break;

                case '5545fe5f-e4a0-489d-b200-4416807f17c9':
                  $role = 'flashers';

                  break;

                case 'abf972eb-9d90-4c98-9d84-926854d07f73':
                  $role = 'gi';

                  break;
              }

              $leds[$role][] = [
                'description' => trim($addressable_led->label()),
                'number' => (int) ($addressable_led->get('field_number')->value),
                'ledNumber' => (int) ($addressable_led->get('field_string_position')->value),
                'color' => $this->formatLedColorForConfig(
                  $addressable_led->get('field_color')->color,
                  $this->getWhiteFieldValue($addressable_led, 'field_white'),
                  $this->ledTypeSupportsWhite($stripe_led_type)
                ),
              ];
            }

            $led_effects = $storage->loadByProperties([
              'field_string' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'led_effect',
            ]);
            uasort($led_effects, [$this, 'sortEntitiesById']);

            $effects = [];
            /** @var NodeInterface $led_effect */
            foreach ($led_effects as $led_effect) {
              $objects[] = $led_effect;
              if (!$led_effect->isPublished()) {
                continue;
              }

              $supports_white = $this->ledTypeSupportsWhite($stripe_led_type);
              $color_2 = $this->getColorFieldValue($led_effect, 'field_color_2');
              $white_2 = $this->getWhiteFieldValue($led_effect, 'field_white_2');
              $color_3 = $this->getColorFieldValue($led_effect, 'field_color_3');
              $white_3 = $this->getWhiteFieldValue($led_effect, 'field_white_3');
              $color_slots = [
                [
                  'color' => $this->getColorFieldValue($led_effect, 'field_color') ?? '#000000',
                  'white' => $this->getWhiteFieldValue($led_effect, 'field_white'),
                  'present' => TRUE,
                ],
                [
                  'color' => $color_2 ?? '#000000',
                  'white' => $white_2,
                  'present' => $color_2 !== NULL || $white_2 > 0,
                ],
                [
                  'color' => $color_3 ?? '#000000',
                  'white' => $white_3,
                  'present' => $color_3 !== NULL || $white_3 > 0,
                ],
              ];
              while (count($color_slots) > 1 && end($color_slots)['present'] === FALSE) {
                array_pop($color_slots);
              }
              $colors = array_map(
                fn (array $slot): string => $this->formatLedColorForConfig($slot['color'], $slot['white'], $supports_white),
                $color_slots
              );

              $effect = [
                'name' => trim((string) $led_effect->get('field_machine_name')->value),
                'description' => trim($led_effect->label()),
                'colors' => $colors,
                'duration' => (int) ($led_effect->get('field_duration')->value),
                'effect' => (int) ($led_effect->get('field_effect')->entity->field_number->value ?? 0),
                'reverse' => (int) ($led_effect->get('field_reverse')->value),
                'segment' => (int) ($led_effect->get('field_segment')->value),
                'speed' => (int) ($led_effect->get('field_speed')->value),
                'mode' => (int) ($led_effect->get('field_mode')->value),
                'priority' => (int) ($led_effect->get('field_priority')->value),
                'repeat' => (int) ($led_effect->get('field_repeat')->value),
              ];
              if ($led_effect->hasField('field_fade_rate') && !$led_effect->get('field_fade_rate')->isEmpty()) {
                $effect['fadeRate'] = (int) ($led_effect->get('field_fade_rate')->value);
              }
              if ($this->getBooleanFieldValue($led_effect, 'field_gamma')) {
                $effect['gamma'] = TRUE;
              }
              if ($led_effect->hasField('field_size') && !$led_effect->get('field_size')->isEmpty()) {
                $effect['size'] = (int) ($led_effect->get('field_size')->value);
              }
              $trigger_source = $this->getStringFieldValue($led_effect, 'field_trigger_source');
              if ($trigger_source !== NULL && $led_effect->hasField('field_trigger_number') && !$led_effect->get('field_trigger_number')->isEmpty()) {
                $effect['simpleTrigger'] = [
                  'source' => $trigger_source,
                  'number' => (int) $led_effect->get('field_trigger_number')->value,
                  'value' => $this->getBooleanFieldValue($led_effect, 'field_trigger_value') ? 1 : 0,
                ];
              }
              $effects[] = $effect;
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $segments = [];
              /** @var \Drupal\range\Plugin\Field\FieldType\RangeIntegerItem $segment */
              foreach ($device->get('field_segments') as $number => $segment) {
                $segments[] = [
                  'number' => ((int) $number) + 1,
                  'from' => (int) ($segment->get('from')->getValue()),
                  'to' => (int) ($segment->get('to')->getValue()),
                ];
              }

              $yaml['ledStripes'][] = [
                  'description' => trim($device->label()),
                  'board' => $i_o_board_number,
                  'port' => $i_o_board_gpio_mapping[(int) ($device->get('field_pin')->value)],
                  'ledType' => $device->get('field_led_type')->entity->getName(),
                  'brightness' => (int) ($device->get('field_brightness')->value),
                  'amount' => (int) ($device->get('field_amount_leds')->value),
                  'lightUp' => (int) ($device->get('field_light_up')->value),
                  'afterGlow' => (int) ($device->get('field_after_glow')->value),
                  'segments' => $segments,
                ] + $leds + ['effects' => $effects];
            }

            break;
        }
      }

      if ($i_o_board->isPublished()) {
        usort($yaml['switches'], [$this, 'sortArrayByNumberValues']);
        usort($yaml['pwmOutput'], [$this, 'sortArrayByNumberValues']);

        $board = [
          'description' => trim($i_o_board->label()),
          'number' => $i_o_board_number,
          'pollEvents' => $poll_events,
        ];
        // Only emitted when set. A board with no latency-critical switches is
        // polled every eighth cycle instead of every one, so the boards that
        // drive flipper coils get their replies back sooner. Pointless on a
        // board nothing polls, and writing it there would suggest otherwise.
        if ($poll_events && $this->getBooleanFieldValue($i_o_board, 'field_slow_switches')) {
          $board['slowSwitches'] = TRUE;
        }
        // Declared but not built: the host owns this board's switches and drives
        // them itself. This is how the tilt inhibit reaches the flipper outputs
        // without a new frame type.
        if ($this->getBooleanFieldValue($i_o_board, 'field_virtual')) {
          $board['virtual'] = TRUE;
        }
        $yaml['boards'][] = $board;
      }
    }

    // Tilt warnings and ball save work under BOTH engines, so they are emitted
    // for a PinMAME game too: giving an early-electronic ROM features it was
    // never written to have is much of why they exist. The emGame block is
    // GameCore-only.
    $tilt = $this->buildTiltYaml($node, $roles);
    if ($tilt !== []) {
      $yaml['tilt'] = $tilt;
    }

    $ball_save = $this->buildBallSaveYaml($node, $roles);
    if ($ball_save !== []) {
      $yaml['ballSave'] = $ball_save;
    }

    if ($this->getEngine($node) === 'gamecore') {
      $yaml['engine'] = 'gamecore';
      $yaml['emGame'] = $this->buildEmGameYaml($node, $roles);
    }

    return $yaml;
  }

  /**
   * The engine this game runs on: 'pinmame' (default) or 'gamecore'.
   */
  protected function getEngine(NodeInterface $node): string {
    $value = $this->getStringFieldValue($node, 'field_engine');
    return $value === 'gamecore' ? 'gamecore' : 'pinmame';
  }

  /**
   * The GameCore role a device was given, or NULL for an ordinary device.
   *
   * Device number 0 is refused: it is not a real switch or coil number, and a
   * role pointing at nothing is worse than no role at all.
   */
  protected function gameRoleOf(NodeInterface $device, int $number): ?string {
    if ($number <= 0 || !$device->hasField('field_game_role') || $device->get('field_game_role')->isEmpty()) {
      return NULL;
    }
    $role = trim((string) $device->get('field_game_role')->value);
    return $role !== '' ? $role : NULL;
  }

  /**
   * All device numbers carrying a role, in export order.
   */
  protected function roleNumbers(array $roles, string $role): array {
    return array_values(array_unique($roles[$role] ?? []));
  }

  /**
   * The single device carrying a role, or 0 when none does.
   *
   * A role that is meant to be unique but was given to several devices takes the
   * lowest number rather than an arbitrary one, so the export is deterministic.
   */
  protected function roleNumber(array $roles, string $role): int {
    $numbers = $this->roleNumbers($roles, $role);
    if ($numbers === []) {
      return 0;
    }
    sort($numbers);
    return (int) reset($numbers);
  }

  protected function buildTiltYaml(NodeInterface $node, array $roles): array {
    $switches = $this->roleNumbers($roles, 'tilt');
    $slam = $this->roleNumbers($roles, 'slamTilt');
    if ($switches === [] && $slam === []) {
      return [];
    }

    $tilt = [];
    if ($switches !== []) {
      $tilt['switches'] = $switches;
    }
    if ($slam !== []) {
      $tilt['slamSwitches'] = $slam;
    }
    $tilt['warnings'] = (int) ($this->getIntFieldValue($node, 'field_tilt_warnings') ?? 2);
    $tilt['debounceMs'] = (int) ($this->getIntFieldValue($node, 'field_tilt_debounce_ms') ?? 500);
    $tilt['warningBlankingMs'] = (int) ($this->getIntFieldValue($node, 'field_tilt_blanking_ms') ?? 2000);

    $warning_lamp = $this->roleNumber($roles, 'tiltWarning');
    if ($warning_lamp > 0) {
      $tilt['warningLamp'] = $warning_lamp;
    }
    return $tilt;
  }

  protected function buildBallSaveYaml(NodeInterface $node, array $roles): array {
    if (!$this->getBooleanFieldValue($node, 'field_ball_save_enabled')) {
      return [];
    }

    $drain = $this->roleNumbers($roles, 'trough');
    $kick = $this->roleNumber($roles, 'troughKick');
    // Without somewhere to see the drain and something to kick the ball back
    // with, the feature cannot work. Emitting it half-configured would fail at
    // startup instead, which is a worse place to find out.
    if ($drain === [] || $kick === 0) {
      return [];
    }

    $save = [
      'enabled' => TRUE,
      'seconds' => (int) ($this->getIntFieldValue($node, 'field_ball_save_seconds') ?? 8),
      'startOn' => $this->getStringFieldValue($node, 'field_ball_save_start_on') ?: 'shooterLane',
      'drainSwitches' => $drain,
      'kickCoil' => $kick,
      'maxSavesPerBall' => (int) ($this->getIntFieldValue($node, 'field_ball_save_max_ball') ?? 1),
    ];

    $lane = $this->roleNumber($roles, 'shooterLane');
    if ($lane > 0) {
      $save['shooterLaneSwitch'] = $lane;
    }
    $playfield = $this->roleNumbers($roles, 'playfield');
    if ($playfield !== []) {
      $save['playfieldSwitches'] = $playfield;
    }
    $lamp = $this->roleNumber($roles, 'ballSave');
    if ($lamp > 0) {
      $save['lamp'] = $lamp;
    }
    return $save;
  }

  protected function buildEmGameYaml(NodeInterface $node, array $roles): array {
    $em = [
      'enabled' => TRUE,
      'ballsPerGame' => (int) ($this->getIntFieldValue($node, 'field_em_balls_per_game') ?? 3),
      'ballCount' => (int) ($this->getIntFieldValue($node, 'field_em_ball_count') ?? 1),
      'maxPlayers' => (int) ($this->getIntFieldValue($node, 'field_em_max_players') ?? 4),
      'addPlayerThroughBall' => (int) ($this->getIntFieldValue($node, 'field_em_add_player_ball') ?? 1),
      'freePlay' => $this->getBooleanFieldValue($node, 'field_em_free_play'),
      'creditsPerCoin' => (int) ($this->getIntFieldValue($node, 'field_em_credits_per_coin') ?? 1),
      'scoreDigits' => (int) ($this->getIntFieldValue($node, 'field_em_score_digits') ?? 6),
      'bonusTimeoutMs' => (int) ($this->getIntFieldValue($node, 'field_em_bonus_timeout_ms') ?? 30000),
    ];

    foreach ([
      'startSwitch' => 'start',
      'serviceCreditSwitch' => 'serviceCredit',
      'gameOnCoil' => 'gameOn',
      'knockerCoil' => 'knocker',
      'gameOverLamp' => 'gameOver',
      'tiltLamp' => 'tilt',
      'ballInPlayLamp' => 'ballInPlay',
      'shootAgainLamp' => 'shootAgain',
      'matchLamp' => 'match',
    ] as $key => $role) {
      $number = $this->roleNumber($roles, $role);
      if ($number > 0) {
        $em[$key] = $number;
      }
    }

    $coins = $this->roleNumbers($roles, 'coin');
    if ($coins !== []) {
      $em['coinSwitches'] = $coins;
    }
    $player_up = $this->roleNumbers($roles, 'playerUp');
    if ($player_up !== []) {
      $em['playerUpLamps'] = $player_up;
    }

    $trough = $this->roleNumbers($roles, 'trough');
    if ($trough !== []) {
      $em['trough'] = [
        'switches' => $trough,
        'kickCoil' => $this->roleNumber($roles, 'troughKick'),
        'kickPulseMs' => (int) ($this->getIntFieldValue($node, 'field_em_kick_pulse_ms') ?? 80),
        'settleMs' => (int) ($this->getIntFieldValue($node, 'field_em_trough_settle_ms') ?? 400),
      ];
    }

    $lane = $this->roleNumber($roles, 'shooterLane');
    if ($lane > 0) {
      $em['shooterLane'] = ['switch' => $lane];
    }

    // Only what GameCore does once the machine has already tilted. The switches
    // and the warning count live in the shared tilt block, because they work
    // under both engines.
    $tilt = [
      'giOff' => $this->getBooleanFieldValue($node, 'field_tilt_gi_off'),
      'endsBallOnly' => $this->getBooleanFieldValue($node, 'field_tilt_ends_ball_only'),
      'skipBonus' => $this->getBooleanFieldValue($node, 'field_tilt_skip_bonus'),
    ];
    $inhibit = $this->roleNumber($roles, 'tiltInhibit');
    if ($inhibit > 0) {
      $tilt['inhibitSwitch'] = $inhibit;
    }
    $em['tilt'] = $tilt;

    $thresholds = [];
    foreach ($this->getLineConfigField($node, 'field_em_replay_scores') as $line) {
      foreach ($this->parseIntegerList($line) as $score) {
        if ($score > 0) {
          $thresholds[] = $score;
        }
      }
    }
    if ($thresholds !== []) {
      sort($thresholds);
      $em['replay'] = ['thresholds' => array_values(array_unique($thresholds)), 'awardCredit' => TRUE];
    }

    $em['match'] = ['enabled' => $this->getBooleanFieldValue($node, 'field_em_match_enabled')];

    return $em;
  }

  /**
   * @param \Drupal\node\NodeInterface $node
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response object.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function streamPinMameYaml(NodeInterface $node): Response {
    $objects = [];

    return new Response(
      Yaml::encode($this->buildYaml($node, $objects)),
      200,
      [
        'Content-Type' => 'application/yaml',
        'Content-Disposition' => $this->attachment(self::CONFIG_FILENAME),
      ]
    );
  }

  public function streamRulesLua(NodeInterface $node): Response {
    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '_rules.lua', 'lua');
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_filename = $event->getFilename();

    return new Response(
      $this->getRulesLua($node),
      200,
      [
        'Content-Type' => 'text/x-lua',
        'Content-Disposition' => 'attachment; filename=' . $sanitized_filename,
      ]
    );
  }

  public function streamPpucIni(NodeInterface $node): Response {
    if ($node->bundle() !== 'game') {
      throw $this->createNotFoundException();
    }

    return new Response(
      $this->buildPpucIni($node),
      200,
      [
        'Content-Type' => 'text/plain',
        'Content-Disposition' => $this->attachment(self::PPUC_INI_FILENAME),
      ]
    );
  }

  public function streamRuleLua(NodeInterface $node): Response {
    if ($node->bundle() !== 'rule') {
      throw $this->createNotFoundException();
    }

    return new Response(
      $this->getRulesLua($node),
      200,
      [
        'Content-Type' => 'text/x-lua',
        'Content-Disposition' => 'attachment; filename=' . $this->buildRuleFilename($node, 'lua', false),
      ]
    );
  }

  public function addRule(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'game') {
      throw $this->createNotFoundException();
    }

    return $this->redirect('node.add', ['node_type' => 'rule'], ['query' => ['game' => $node->id()]]);
  }

  public function addSlide(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'game') {
      throw $this->createNotFoundException();
    }

    return $this->redirect('node.add', ['node_type' => 'slide'], ['query' => ['game' => $node->id()]]);
  }

  /**
   * Opens the game's ppuc.ini settings for editing.
   *
   * Games get their settings record when they are created, so this normally
   * just redirects to it. It still creates one when there is none, because a
   * game imported from an archive written before that was true would otherwise
   * have a settings tab leading nowhere.
   */
  public function editPpucSettings(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'game') {
      throw $this->createNotFoundException();
    }

    $settings = \Drupal::service('ppuc_games.game_settings')->getOrCreate($node);
    if (!$settings instanceof NodeInterface) {
      throw $this->createNotFoundException();
    }

    return $this->redirect('entity.node.edit_form', ['node' => $settings->id()]);
  }

  /**
   * The settings tab belongs to games, and only to someone who may edit them.
   */
  public function accessGameSettings(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'game')
      ->andIf(AccessResult::allowedIfHasPermission($account, 'edit any ppuc_settings content'))
      ->addCacheableDependency($node);
  }

  protected function getRulesLua(NodeInterface $node): string {
    if (!$node->hasField('field_rules_lua') || $node->get('field_rules_lua')->isEmpty()) {
      return '';
    }

    return (string) $node->get('field_rules_lua')->value;
  }

  protected function getRuleNodes(NodeInterface $game, bool $enabled_only = false): array {
    if ($game->bundle() !== 'game') {
      return [];
    }

    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'rule')
      ->condition('field_game.target_id', $game->id())
      ->sort('field_weight.value', 'ASC')
      ->sort('title', 'ASC');

    if ($enabled_only) {
      $query->condition('field_enabled.value', 1);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    return Node::loadMultiple($ids);
  }

  protected function ruleIsEnabled(NodeInterface $rule): bool {
    return !$rule->hasField('field_enabled') || $rule->get('field_enabled')->isEmpty() || (bool) $rule->get('field_enabled')->value;
  }

  protected function getNodeWeight(NodeInterface $rule): int {
    return $rule->hasField('field_weight') && !$rule->get('field_weight')->isEmpty() ? (int) $rule->get('field_weight')->value : 0;
  }

  protected function getRuleEditorMode(NodeInterface $rule): string {
    return $rule->hasField('field_rules_editor_mode') && !$rule->get('field_rules_editor_mode')->isEmpty()
      ? (string) $rule->get('field_rules_editor_mode')->value
      : 'blockly';
  }

  /**
   * A Content-Disposition header for a filename the runtime dictates.
   *
   * Quoted, via Symfony, rather than concatenated: the header was built as
   * 'attachment; filename=' . $name, which a name containing a space or a
   * semicolon turns into a malformed header and a differently named download.
   *
   * These names are also not put through FileUploadSanitizeNameEvent. That
   * exists to make a name a user supplied safe to store, and it is free to
   * rewrite what it is given -- transliterating, or appending an extension for
   * sites that munge them. Applied to a fixed name the runtime requires, it
   * could only break it.
   */
  protected function attachment(string $filename): string {
    return HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
  }

  protected function buildRuleFilename(NodeInterface $rule, string $extension, bool $include_weight = true): string {
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($rule->getTitle()));
    $base = trim($base ?: 'rule', '-');
    $filename = ($include_weight ? sprintf('%04d-', $this->getNodeWeight($rule)) : '') . $base . '.' . $extension;
    $event = new FileUploadSanitizeNameEvent($filename, $extension);
    \Drupal::service('event_dispatcher')->dispatch($event);
    return $event->getFilename();
  }

  /**
   * The slides of a game, in the order they play.
   *
   * Published is the switch, as it is for switches: an unpublished slide is
   * not written to the folder at all, so a machine cannot show one somebody
   * was still writing.
   */
  protected function getSlideNodes(NodeInterface $game): array {
    return $this->querySlideNodes($game, TRUE);
  }

  /**
   * Every slide of a game, published or not.
   *
   * The game folder a machine runs gets published slides only: an unpublished
   * slide is one somebody is still writing, and it has no business appearing
   * on a machine in a bar. A game archive is the opposite case -- it is the
   * whole game moving to another instance, and leaving the drafts behind would
   * lose work that only exists here.
   */
  protected function getAllSlideNodes(NodeInterface $game): array {
    return $this->querySlideNodes($game, FALSE);
  }

  protected function querySlideNodes(NodeInterface $game, bool $published_only): array {
    if ($game->bundle() !== 'game') {
      return [];
    }

    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'slide')
      ->condition('field_game.target_id', $game->id())
      ->sort('field_weight.value', 'ASC')
      ->sort('title', 'ASC');
    if ($published_only) {
      $query->condition('status', 1);
    }
    $ids = $query->execute();

    return $ids ? Node::loadMultiple($ids) : [];
  }

  /**
   * What a slide photograph is called in the game folder.
   *
   * Named after the file rather than after a slide, because a picture several
   * slides share belongs to none of them: calling the playfield photograph
   * 0200-top-lanes.jpg and then pointing nine other slides at that name would
   * read as a mistake in the folder even though it is not.
   *
   * `$used` carries the names already taken, so two files that happen to be
   * called the same thing do not overwrite one another.
   */
  protected function slideImageFilename(FileInterface $file, array $used): string {
    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION) ?: 'png');
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($file->getFilename(), PATHINFO_FILENAME)));
    $base = trim($base ?: 'slide', '-');

    $name = $base . '.' . $extension;
    $suffix = 2;
    while (isset($used[$name])) {
      $name = $base . '-' . $suffix++ . '.' . $extension;
    }
    return $name;
  }

  protected function buildSlideFilename(NodeInterface $slide, string $extension): string {
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($slide->getTitle()));
    $base = trim($base ?: 'slide', '-');
    return sprintf('%04d-', $this->getNodeWeight($slide)) . $base . '.' . $extension;
  }

  /**
   * The text of a slide as the machine will draw it: plain, unwrapped.
   *
   * The field has a text format behind it, so it can hold markup that means
   * nothing to a renderer drawing a line of words over a photograph.
   */
  protected function slidePlainText(NodeInterface $slide, string $field): string {
    if (!$slide->hasField($field) || $slide->get($field)->isEmpty()) {
      return '';
    }
    $value = (string) $slide->get($field)->value;
    $value = str_replace(['<br />', '<br/>', '<br>', '</p>'], "\n", $value);
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
  }

  /**
   * The markers of a slide, validated.
   *
   * Coordinates run 0 to 1 across the picture rather than in pixels, so they
   * survive it being scaled to whatever screen the machine has. A marker
   * outside the picture, or without a position, is dropped rather than
   * exported: a machine drawing an arrow off the edge helps nobody, and the
   * export must not fail over a typo in a slide.
   */
  protected function parseSlideMarkers(NodeInterface $slide): array {
    $raw = $this->slidePlainText($slide, 'field_slide_markers');
    if ($raw === '') {
      return [];
    }

    try {
      $decoded = Yaml::decode($raw);
    }
    catch (\Throwable $e) {
      return [];
    }
    if (!is_array($decoded)) {
      return [];
    }

    $markers = [];
    foreach ($decoded as $entry) {
      if (!is_array($entry) || !isset($entry['x'], $entry['y'])) {
        continue;
      }
      $x = (float) $entry['x'];
      $y = (float) $entry['y'];
      if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
        continue;
      }
      $marker = ['x' => round($x, 4), 'y' => round($y, 4)];
      if (isset($entry['number'])) {
        $marker['number'] = (int) $entry['number'];
      }
      // The eight sides an arrow can come in from. The diagonals matter: an
      // arrow coming from the lower left is the line a ball takes off the left
      // flipper, and one coming straight down is a ball draining. A pointer
      // that is not on this list is dropped rather than exported, and the
      // machine falls back to an arrow from the left.
      $pointer = isset($entry['pointer']) ? (string) $entry['pointer'] : '';
      if (in_array($pointer, [
        'left', 'right', 'above', 'below',
        'above-left', 'above-right', 'below-left', 'below-right',
      ], TRUE)) {
        $marker['pointer'] = $pointer;
      }

      // Optionally where the arrow starts, which turns it from a mark beside
      // the target into the line the ball takes to reach it. Held to the same
      // 0..1 as the marker: a start point off the picture would draw an arrow
      // from nowhere.
      if (isset($entry['fromX'], $entry['fromY'])) {
        $fx = (float) $entry['fromX'];
        $fy = (float) $entry['fromY'];
        if ($fx >= 0 && $fx <= 1 && $fy >= 0 && $fy <= 1) {
          $marker['fromX'] = round($fx, 4);
          $marker['fromY'] = round($fy, 4);
        }
      }

      $markers[] = $marker;
    }

    return $markers;
  }

  /**
   * Writes the slides folder: the photographs, and slides.yaml beside them.
   */
  protected function writeSlideFiles(NodeInterface $game, string $slides_folder): void {
    $slides = $this->getSlideNodes($game);
    if (!$slides) {
      return;
    }

    $this->fileSystem->prepareDirectory($slides_folder,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // One copy per photograph, not one per slide.
    //
    // Ten slides point at the same playfield photograph, which is the whole
    // reason markers are coordinates: one picture, re-marked. Exporting it once
    // per slide would have thrown that away again -- ten copies of the same
    // 800KB in the game folder, in the archive, and over the wire to the
    // machine. Drupal already knows it is one file, so the export says so too.
    $written = [];
    $used = [];

    $entries = [];
    foreach ($slides as $slide) {
      $entry = ['title' => trim($slide->label())];

      if ($slide->hasField('field_image') && !$slide->get('field_image')->isEmpty()) {
        $file = $slide->get('field_image')->entity ?? NULL;
        if ($file instanceof FileInterface) {
          $uri = $file->getFileUri();
          if (!isset($written[$uri])) {
            $written[$uri] = $this->slideImageFilename($file, $used);
            $used[$written[$uri]] = TRUE;
            $this->fileSystem->copy($uri, $slides_folder . '/' . $written[$uri],
              FileSystemInterface::EXISTS_REPLACE);
          }
          $entry['image'] = $written[$uri];
        }
      }

      if ($text = $this->slidePlainText($slide, 'field_slide_text')) {
        $entry['text'] = $text;
      }
      if ($slide->hasField('field_duration') && !$slide->get('field_duration')->isEmpty()) {
        $entry['durationMs'] = (int) $slide->get('field_duration')->value;
      }
      if ($markers = $this->parseSlideMarkers($slide)) {
        $entry['markers'] = $markers;
      }

      // A slide with neither a picture nor words would be a blank screen.
      if (isset($entry['image']) || isset($entry['text'])) {
        $entries[] = $entry;
      }
    }

    if ($entries) {
      file_put_contents($slides_folder . '/slides.yaml', Yaml::encode(['slides' => $entries]));
    }
  }

  protected function writeRuleFiles(NodeInterface $game, string $rules_folder): void {
    $directory = $rules_folder;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    foreach ($this->getRuleNodes($game, TRUE) as $rule) {
      $rules_lua = $this->getRulesLua($rule);
      if ($rules_lua === '') {
        continue;
      }

      file_put_contents($rules_folder . '/' . $this->buildRuleFilename($rule, 'lua'), $rules_lua);
    }
  }

  public function streamRulesArchive(NodeInterface $node): Response {
    if ($node->bundle() !== 'game') {
      throw $this->createNotFoundException();
    }

    $tmp = $this->fileSystem->getTempDirectory() . '/ppuc-rules-' . $node->id();
    $this->fileSystem->deleteRecursive($tmp);
    $rules_folder = $tmp . '/rules';
    $this->fileSystem->prepareDirectory($rules_folder, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->writeRuleFiles($node, $rules_folder);

    $tar = $tmp . '/rules.tar';
    $gz = $tar . '.gz';
    if (file_exists($tar)) {
      unlink($tar);
    }
    if (file_exists($gz)) {
      unlink($gz);
    }

    $archive = new \PharData($tar);
    $archive->buildFromDirectory($rules_folder);
    $archive->compress(\Phar::GZ);

    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '_rules.tar.gz', 'tar.gz');
    \Drupal::service('event_dispatcher')->dispatch($event);

    return new Response(
      file_get_contents($gz),
      200,
      [
        'Content-Type' => 'application/gzip',
        'Content-Disposition' => 'attachment; filename=' . $event->getFilename(),
      ]
    );
  }

  protected function sanitizeGeneratedFilename(string $filename, string $extension): string {
    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $filename), $extension);
    \Drupal::service('event_dispatcher')->dispatch($event);
    return $event->getFilename();
  }

  protected function sanitizeGameFolderName(NodeInterface $game): string {
    $filename = $this->sanitizeGeneratedFilename($game->getTitle() . '.folder', 'folder');
    $folder = preg_replace('/\.folder$/', '', $filename);
    $folder = trim((string) $folder, " .\t\n\r\0\x0B/");
    return $folder !== '' ? $folder : 'game';
  }

  protected function getGameRomName(NodeInterface $game): string {
    $rom = $this->getIniSettingValue($game, 'field_ini_game_rom');
    if ($rom === '') {
      $rom = $this->getStringFieldValue($game, 'field_machine_name') ?? '';
    }
    if ($rom === '') {
      $rom = $this->getFirstReferencedMediaSourceFilename($game, 'field_rom');
    }
    if ($rom === '') {
      $rom = $game->getTitle();
    }

    $rom = preg_replace('/\.zip$/i', '', trim($rom));
    $filename = $this->sanitizeGeneratedFilename($rom . '.folder', 'folder');
    $rom = preg_replace('/\.folder$/', '', $filename);
    $rom = trim((string) $rom, " .\t\n\r\0\x0B/");
    return $rom !== '' ? $rom : 'rom';
  }

  protected function getGameFolderSkeletonFolders(?string $rom = NULL): array {
    $folders = [
      '',
      'music',
      'pinmame',
      'pinmame/altcolor',
      'pinmame/altsound',
      'pinmame/cfg',
      'pinmame/nvram',
      'pinmame/roms',
      'pinmame/snap',
      'pinmame/sta',
      'pup',
      'pup/pupvideos',
      'rules',
    ];

    if ($rom !== NULL && $rom !== '') {
      $folders[] = 'pinmame/altcolor/' . $rom;
      $folders[] = 'pinmame/altsound/' . $rom;
    }

    return $folders;
  }

  protected function prepareGameFolderSkeleton(string $game_folder, ?string $rom = NULL): void {
    foreach ($this->getGameFolderSkeletonFolders($rom) as $folder) {
      $directory = $game_folder . ($folder !== '' ? '/' . $folder : '');
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
  }

  protected function writeGameFolderReadme(string $folder, string $rom): void {
    $readme = <<<TXT
This is the generated PPUC configuration for this game, in the folder named 'ppuc'
beside this file.

To run the game on a PPUC machine:

1. Copy the whole 'ppuc' folder to the top level of a USB stick.
2. Plug the stick into the machine and switch it on.

The folder has to keep its name and has to sit at the top level of the stick, which
is where the runtime looks for it. Put only one game on a stick.

To run it from a computer instead, point ppuc-pinmame at the folder:

    ppuc-pinmame --game /path/to/ppuc

Everything attached to the game in the config-tool is already in the folder: the
ROM, the colorization, background music, the AltSound package and the PUP pack,
each unpacked into the place the runtime looks for it. Attach them on the game
and they come with every export, and travel with the game when it is handed to
someone else.

Anything not held there goes into the 'ppuc' folder by hand:
- directb2s backglass files into the top level of the folder
- existing PinMAME nvram, cfg, snapshots or state files into the matching
  pinmame/ subfolders
- any further colorization or audio into pinmame/altcolor/{$rom}/,
  pinmame/altsound/{$rom}/, music/ or pup/pupvideos/

Having the files is only half of it: Serum/AltColor, PUP and AltSound each have a
switch in the game's PPUC Settings, and the files do nothing until it is on.

TXT;
    file_put_contents($folder . '/README.txt', $readme);
  }

  protected function getFirstReferencedMediaSourceFilename(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }

    foreach ($node->get($field_name)->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }

      $media_type = \Drupal::entityTypeManager()
        ->getStorage('media_type')
        ->load($media->bundle());
      $source_field = $media_type?->getSource()
        ->getConfiguration()['source_field'] ?? NULL;
      if ($source_field === NULL || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
        continue;
      }

      $file = $media->get($source_field)->entity ?? NULL;
      if ($file instanceof FileInterface) {
        return pathinfo($file->getFilename(), PATHINFO_FILENAME);
      }
    }

    return '';
  }

  protected function copyReferencedMediaFiles(NodeInterface $node, string $field_name, string $target_folder, ?string $target_basename = NULL): void {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return;
    }

    $this->fileSystem->prepareDirectory($target_folder, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    foreach ($node->get($field_name)->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }

      $media_type = \Drupal::entityTypeManager()
        ->getStorage('media_type')
        ->load($media->bundle());
      $source_field = $media_type?->getSource()
        ->getConfiguration()['source_field'] ?? NULL;
      $fields = $source_field !== NULL && $media->hasField($source_field)
        ? [$media->get($source_field)]
        : array_filter($media->getFields(), static function ($field): bool {
          $type = $field->getFieldDefinition()->getType();
          return in_array($type, ['file', 'image'], TRUE) && $field->getName() !== 'thumbnail';
        });

      foreach ($fields as $field) {
        if ($field->isEmpty()) {
          continue;
        }

        foreach ($field as $item) {
          $file = $item->entity ?? NULL;
          if (!$file instanceof FileInterface) {
            continue;
          }

          $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
          $filename = $target_basename !== NULL && $extension !== ''
            ? $target_basename . '.' . $extension
            : $file->getFilename();
          $extension = $extension !== '' ? $extension : pathinfo($filename, PATHINFO_EXTENSION);
          $filename = $this->sanitizeGeneratedFilename($filename, $extension);
          $this->fileSystem->copy($file->getFileUri(), $target_folder . '/' . $filename, FileSystemInterface::EXISTS_REPLACE);
        }
      }
    }
  }

  protected function copyGameFolderAssets(NodeInterface $game, string $game_folder, string $rom): void {
    $this->copyReferencedMediaFiles($game, 'field_rom', $game_folder . '/pinmame/roms');
    $this->copyReferencedMediaFiles($game, 'field_translite_in_game', $game_folder, 'translite-on');
    $this->copyReferencedMediaFiles($game, 'field_translite', $game_folder, 'translite-off');

    // Both of these are looked up by the ROM name, which is why the folders
    // they go into are named after it.
    $this->copyReferencedMediaFiles($game, 'field_colorization', $game_folder . '/pinmame/altcolor/' . $rom);
    $this->copyReferencedMediaFiles($game, 'field_music', $game_folder . '/music');

    // AltSound is read from the rom's own folder, so the package's contents go
    // straight into it. A PUP pack instead sits as a folder among others inside
    // pupvideos/, and PUP finds it by that folder's name.
    $this->extractReferencedArchives($game, 'field_altsound', $game_folder . '/pinmame/altsound/' . $rom, NULL);
    $this->extractReferencedArchives($game, 'field_pup_pack', $game_folder . '/pup/pupvideos', $rom);
  }

  /**
   * Unpacks zip archives referenced by a field into the game folder.
   *
   * Packs are published in two shapes: some wrap everything in one directory,
   * some hold the files at the top level. Both are accepted, so that whichever
   * a pack author chose the result is the layout the runtime expects.
   *
   * @param \Drupal\node\NodeInterface $game
   *   The game node.
   * @param string $field_name
   *   The field referencing the archive media.
   * @param string $target_folder
   *   Where the contents end up.
   * @param string|null $wrap_into
   *   NULL to put the contents directly in the target. A name to put them in a
   *   directory of that name, unless the archive already wraps them in one, in
   *   which case the archive's own directory name is kept.
   */
  protected function extractReferencedArchives(NodeInterface $game, string $field_name, string $target_folder, ?string $wrap_into): void {
    if (!$game->hasField($field_name) || $game->get($field_name)->isEmpty()) {
      return;
    }

    foreach ($game->get($field_name)->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }

      foreach ($this->mediaSourceFiles($media) as $file) {
        $path = $this->fileSystem->realpath($file->getFileUri());
        if ($path === FALSE || !is_file($path)) {
          continue;
        }

        $this->extractArchive($path, $target_folder, $wrap_into);
      }
    }
  }

  /**
   * Unpacks one zip archive, normalising how it wraps its contents.
   */
  protected function extractArchive(string $archive_path, string $target_folder, ?string $wrap_into): void {
    $zip = new \ZipArchive();
    if ($zip->open($archive_path) !== TRUE) {
      $this->getLogger('ppuc_games')->warning('Could not open @file as a zip archive; it was left out of the game folder.', [
        '@file' => basename($archive_path),
      ]);
      return;
    }

    // Unpacked next to the game folder first, so that a pack which wraps its
    // contents and one which does not can be told apart before anything lands
    // in the export.
    $staging = $this->fileSystem->getTempDirectory() . '/ppuc-archive-' . hash('xxh3', $archive_path . microtime());
    $this->fileSystem->deleteRecursive($staging);
    $this->fileSystem->prepareDirectory($staging, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // An entry that climbs out of the directory it is extracted into would
    // write anywhere the web server can, so the archive is rejected whole
    // rather than partly unpacked.
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = (string) $zip->getNameIndex($i);
      if (str_starts_with($name, '/') || str_contains($name, '..')) {
        $zip->close();
        $this->fileSystem->deleteRecursive($staging);
        $this->getLogger('ppuc_games')->warning('@file contains an entry pointing outside the archive (@entry) and was left out of the game folder.', [
          '@file' => basename($archive_path),
          '@entry' => $name,
        ]);
        return;
      }
    }

    $extracted = $zip->extractTo($staging);
    $zip->close();
    if (!$extracted) {
      $this->fileSystem->deleteRecursive($staging);
      $this->getLogger('ppuc_games')->warning('Could not unpack @file; it was left out of the game folder.', [
        '@file' => basename($archive_path),
      ]);
      return;
    }

    // Ignore the metadata directories a Mac adds when zipping from Finder.
    $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..', '__MACOSX', '.DS_Store']));
    $wrapper = count($entries) === 1 && is_dir($staging . '/' . $entries[0]) ? $entries[0] : NULL;

    if ($wrap_into === NULL) {
      // The contents belong in the target itself.
      $source = $wrapper !== NULL ? $staging . '/' . $wrapper : $staging;
      $destination = $target_folder;
    }
    else {
      // The contents belong in a directory inside the target. A pack that
      // brought its own keeps that name: pack authors name it for the rom, and
      // PUP looks it up by name.
      $source = $wrapper !== NULL ? $staging . '/' . $wrapper : $staging;
      $destination = $target_folder . '/' . ($wrapper ?? $wrap_into);
    }

    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->moveDirectoryContents($source, $destination);
    $this->fileSystem->deleteRecursive($staging);
  }

  /**
   * Moves everything in one directory into another, merging what is there.
   */
  protected function moveDirectoryContents(string $source, string $destination): void {
    foreach (array_diff(scandir($source) ?: [], ['.', '..', '__MACOSX', '.DS_Store']) as $entry) {
      $from = $source . '/' . $entry;
      $to = $destination . '/' . $entry;

      if (is_dir($from)) {
        $this->fileSystem->prepareDirectory($to, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $this->moveDirectoryContents($from, $to);
        continue;
      }

      // Moved rather than copied: the staging directory is ours and a pack can
      // be gigabytes, so there is no reason to write it twice.
      $this->fileSystem->move($from, $to, FileSystemInterface::EXISTS_REPLACE);
    }
  }

  /**
   * The files behind a media item's source field.
   *
   * @return \Drupal\file\FileInterface[]
   *   The referenced files, which may be none.
   */
  protected function mediaSourceFiles(MediaInterface $media): array {
    $media_type = \Drupal::entityTypeManager()
      ->getStorage('media_type')
      ->load($media->bundle());
    $source_field = $media_type?->getSource()
      ->getConfiguration()['source_field'] ?? NULL;

    if ($source_field === NULL || !$media->hasField($source_field)) {
      return [];
    }

    $files = [];
    foreach ($media->get($source_field) as $item) {
      $file = $item->entity ?? NULL;
      if ($file instanceof FileInterface) {
        $files[] = $file;
      }
    }

    return $files;
  }

  /**
   * Streams a tar.gz archive containing a runnable game folder.
   */
  public function streamGameFolderArchive(NodeInterface $node): Response {
    if ($node->bundle() !== 'game') {
      throw $this->createNotFoundException();
    }

    // The archive holds one folder per game, named for the game and its uuid so
    // two downloads never collide. Inside it the game folder itself is always
    // called 'ppuc', because that is the name the runtime looks for: on a
    // Raspberry Pi the whole install is "copy this ppuc folder onto a stick".
    $folder_name = self::GAME_FOLDER_ARCHIVE_PREFIX . $this->sanitizeGameFolderName($node) . '_' . $node->uuid();
    $tmp = $this->fileSystem->getTempDirectory() . '/ppuc-game-folder-' . $node->id();
    $this->fileSystem->deleteRecursive($tmp);
    $this->fileSystem->prepareDirectory($tmp, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $game_folder = $tmp . '/' . $folder_name . '/' . self::GAME_FOLDER_NAME;
    $rom = $this->getGameRomName($node);
    $this->prepareGameFolderSkeleton($game_folder, $rom);

    $objects = [];
    file_put_contents($game_folder . '/' . self::CONFIG_FILENAME, Yaml::encode($this->buildYaml($node, $objects)));
    file_put_contents($game_folder . '/' . self::PPUC_INI_FILENAME, $this->buildPpucIni($node));
    $this->writeRuleFiles($node, $game_folder . '/rules');
    $this->writeSlideFiles($node, $game_folder . '/slides');
    $this->copyGameFolderAssets($node, $game_folder, $rom);
    $this->writeGameFolderReadme($tmp . '/' . $folder_name, $rom);

    $tar = $this->fileSystem->getTempDirectory() . '/' . $folder_name . '-' . $node->id() . '.tar';
    $gz = $tar . '.gz';
    if (file_exists($tar)) {
      unlink($tar);
    }
    if (file_exists($gz)) {
      unlink($gz);
    }

    $archive = new \PharData($tar);
    $archive->buildFromDirectory($tmp);
    foreach ($this->getGameFolderSkeletonFolders($rom) as $folder) {
      if ($folder === '') {
        continue;
      }
      try {
        $archive->addEmptyDir($folder_name . '/' . self::GAME_FOLDER_NAME . '/' . $folder);
      }
      catch (\Exception) {
        // The directory may already have been added implicitly with files.
      }
    }
    $archive->compress(\Phar::GZ);

    // Streamed, not read into a string: a game folder carrying a PUP pack runs
    // to gigabytes and file_get_contents() would need all of it in memory at
    // once. The file is deleted after it has been sent.
    $response = new BinaryFileResponse($gz, 200, ['Content-Type' => 'application/gzip']);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $this->sanitizeGeneratedFilename($folder_name . '.tar.gz', 'tar.gz')
    );
    $response->deleteFileAfterSend(TRUE);

    return $response;
  }

  /**
   * Streams a tar.gz archive containing a complete game configuration.
   */
  public function streamGameZip(NodeInterface $node): Response {
    $export_folder = $this->fileSystem->getTempDirectory() . '/dcd/content';

    // Reuse of the shared temp directory would otherwise leak entities from
    // previous exports into the current game archive.
    $this->fileSystem->deleteRecursive($export_folder);

    $this->exporter->setFolder($export_folder);
    $this->exporter->setSkipEntityTypeIds(['user', 'taxonomy_term']);
    $this->exporter->setForceUpdate(TRUE);
    $this->exporter->exportEntity($node, TRUE);
    $this->exporter->setForceUpdate(FALSE);

    $objects = [];
    $yaml = $this->buildYaml($node, $objects);
    foreach ($objects as $object) {
      $this->exporter->exportEntity($object);
    }

    $project_folder = $export_folder . '/ppuc';
    $this->fileSystem->prepareDirectory($project_folder, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    file_put_contents($project_folder . '/game.yml', Yaml::encode($yaml));
    file_put_contents($project_folder . '/ppuc.ini', $this->buildPpucIni($node));

    foreach ($this->getRuleNodes($node) as $rule) {
      $this->exporter->exportEntity($rule, TRUE);
    }
    // With dependencies, which is what carries the photographs: a slide's
    // image is a file entity the slide references, exactly as the translite,
    // the ROM and the manual are files the game references.
    foreach ($this->getAllSlideNodes($node) as $slide) {
      $this->exporter->exportEntity($slide, TRUE);
    }
    $settings = $this->getIniSettingsNode($node);
    if ($settings instanceof NodeInterface) {
      $this->exporter->exportEntity($settings, TRUE);
    }
    $this->writeRuleFiles($node, $project_folder . '/rules');

    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '.tar.gz', '.tar.gz');
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_filename = $event->getFilename();

    // Redirect for download archive file.
    return $this->redirect('default_content_deploy.export.download', ['file_name' => $sanitized_filename]);
  }

  public function importGameZip(): array {
    return \Drupal::formBuilder()->getForm(GameImportForm::class);
  }

  public function title(NodeInterface $node): ?string {
    return $node->getTitle();
  }

}

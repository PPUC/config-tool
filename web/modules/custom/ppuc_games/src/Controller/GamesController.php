<?php

namespace Drupal\ppuc_games\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\default_content_deploy\ExporterInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Form\GameImportForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ConfigDownloadController.
 */
class GamesController extends ControllerBase {

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
      '01d97733-2522-4b50-aec8-862a7fb4f4c5' => 'slowStable',
      default => 'standard',
    };
  }

  protected function getBooleanFieldValue(NodeInterface $node, string $field_name): bool {
    return $node->hasField($field_name) && !$node->get($field_name)->isEmpty() && (bool) $node->get($field_name)->value;
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

  protected function getStringFieldValue(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $value = trim((string) $node->get($field_name)->value);
    return $value !== '' ? $value : NULL;
  }

  protected function getLedTypeName(NodeInterface $node): ?string {
    if (!$node->hasField('field_led_type') || $node->get('field_led_type')->isEmpty()) {
      return NULL;
    }

    return $node->get('field_led_type')->entity?->getName();
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
      'serialPort' => $node->field_serial_port->value ?? 'dummy',
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
              }
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $yaml['switchMatrix'] = [
                'description' => trim($device->label()),
                'board' => $i_o_board_number,
                'activeLow' => (bool) ($device->get('field_active_low')->value),
                'numRows' => (int) ($device->get('field_rows')->value),
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

              $yaml['pwmOutput'][] = $pwm_output;
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

        $yaml['boards'][] = [
          'description' => trim($i_o_board->label()),
          'number' => $i_o_board_number,
          'pollEvents' => $poll_events,
        ];
      }
    }

    return $yaml;
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
    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '.yml', 'yml');
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_filename = $event->getFilename();

    $objects = [];

    return new Response(
      Yaml::encode($this->buildYaml($node, $objects)),
      200,
      [
        'Content-Type' => 'application/yaml',
        'Content-Disposition' => 'attachment; filename=' . $sanitized_filename,
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

  protected function getRulesLua(NodeInterface $node): string {
    if (!$node->hasField('field_rules_lua') || $node->get('field_rules_lua')->isEmpty()) {
      return '';
    }

    return (string) $node->get('field_rules_lua')->value;
  }

  protected function getRulesBlocks(NodeInterface $node): string {
    if (!$node->hasField('field_rules_blocks') || $node->get('field_rules_blocks')->isEmpty()) {
      return '';
    }

    return (string) $node->get('field_rules_blocks')->value;
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

  protected function getRuleWeight(NodeInterface $rule): int {
    return $rule->hasField('field_weight') && !$rule->get('field_weight')->isEmpty() ? (int) $rule->get('field_weight')->value : 0;
  }

  protected function getRuleEditorMode(NodeInterface $rule): string {
    return $rule->hasField('field_rules_editor_mode') && !$rule->get('field_rules_editor_mode')->isEmpty()
      ? (string) $rule->get('field_rules_editor_mode')->value
      : 'blockly';
  }

  protected function buildRuleFilename(NodeInterface $rule, string $extension, bool $include_weight = true): string {
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($rule->getTitle()));
    $base = trim($base ?: 'rule', '-');
    $filename = ($include_weight ? sprintf('%04d-', $this->getRuleWeight($rule)) : '') . $base . '.' . $extension;
    $event = new FileUploadSanitizeNameEvent($filename, $extension);
    \Drupal::service('event_dispatcher')->dispatch($event);
    return $event->getFilename();
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

      if ($this->getRuleEditorMode($rule) === 'blockly') {
        $rules_blocks = $this->getRulesBlocks($rule);
        if ($rules_blocks !== '') {
          file_put_contents($rules_folder . '/' . $this->buildRuleFilename($rule, 'blockly.json'), $rules_blocks);
        }
      }
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

    foreach ($this->getRuleNodes($node) as $rule) {
      $this->exporter->exportEntity($rule, TRUE);
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

<?php

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\node\Form\NodeForm;
use Drupal\taxonomy\TermInterface;

/**
 * PPUC form handler for the node edit forms.
 *
 * @internal
 */
class PpucNodeForm extends NodeForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $this->configureWhiteChannelFields($form, $form_state);
    $this->configureGameFields($form);
    $this->configureRulesFields($form);
    $this->configurePpucSettingsFields($form);
    $this->configureSwitchGroupMembershipFields($form);

    return $form;
  }

  public function refreshForm(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  protected function configureGameFields(array &$form): void {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getEntity();
    if ($entity->bundle() !== 'game') {
      return;
    }

    if (isset($form['field_rom'])) {
      $form['field_rom']['#description'] = $this->t('PinMAME ROM zip file. Upload or select the ROM media item used by this game.');
    }
    if (isset($form['field_translite'])) {
      $form['field_translite']['#description'] = $this->t('Attract or off-state translite image. It is exported as translite-off when downloading a game folder.');
    }
    if (isset($form['field_translite_in_game'])) {
      $form['field_translite_in_game']['#description'] = $this->t('In-game or on-state translite image. It is exported as translite-on when downloading a game folder.');
    }
  }

  public function applyPpucIniDescriptionAfterBuild(array $element, FormStateInterface $form_state): array {
    if (isset($element['#ppuc_ini_description'])) {
      $this->applyDescriptionToFormElement($element, $element['#ppuc_ini_description']);
    }

    return $element;
  }

  protected function configureWhiteChannelFields(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getEntity();
    if (!in_array($entity->bundle(), ['addressable_led', 'led_effect'], TRUE)) {
      return;
    }

    $wrapper_id = $form['#id'] ?? 'ppuc-node-form-wrapper';
    if (!isset($form['#id'])) {
      $form['#prefix'] = '<div id="' . $wrapper_id . '">' . ($form['#prefix'] ?? '');
      $form['#suffix'] = ($form['#suffix'] ?? '') . '</div>';
    }

    if (isset($form['field_string']['widget'])) {
      $form['field_string']['widget']['#ajax'] = [
        'callback' => '::refreshForm',
        'wrapper' => $wrapper_id,
      ];
    }
    if (isset($form['field_effect']['widget'])) {
      $form['field_effect']['widget']['#ajax'] = [
        'callback' => '::refreshForm',
        'wrapper' => $wrapper_id,
      ];
    }

    $supports_white = $this->selectedLedStringSupportsWhite($entity, $form_state);
    $color_slots = $entity->bundle() === 'led_effect'
      ? $this->selectedLedEffectColorSlots($entity, $form_state)
      : 1;

    foreach ([2 => 'field_color_2', 3 => 'field_color_3'] as $slot => $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = $color_slots >= $slot;
      }
    }

    foreach ([1 => 'field_white', 2 => 'field_white_2', 3 => 'field_white_3'] as $slot => $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = $supports_white && $color_slots >= $slot;
      }
    }
  }

  protected function configureRulesFields(array &$form): void {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getEntity();
    if ($entity->bundle() !== 'rule') {
      return;
    }

    if (!isset($form['field_rules_lua'], $form['field_rules_blocks'], $form['field_rules_editor_mode'])) {
      return;
    }

    $form['#attached']['library'][] = 'ppuc_games/rules_editor';
    $form['#attributes']['class'][] = 'ppuc-rules-form';
    $mode = $entity->hasField('field_rules_editor_mode') && !$entity->get('field_rules_editor_mode')->isEmpty()
      ? (string) $entity->get('field_rules_editor_mode')->value
      : 'blockly';
    $form['#attributes']['data-ppuc-rules-mode'] = $mode;

    if ($entity->isNew() && isset($form['field_game']['widget'][0]['target_id'])) {
      $game_id = \Drupal::request()->query->get('game');
      if (is_numeric($game_id)) {
        $game = $this->entityTypeManager->getStorage('node')->load((int) $game_id);
        if ($game instanceof NodeInterface && $game->bundle() === 'game') {
          $form['field_game']['widget'][0]['target_id']['#default_value'] = $game;
        }
      }
    }

    $form['field_rules_lua']['#group'] = 'ppuc_rules';
    $form['field_rules_blocks']['#group'] = 'ppuc_rules';
    $form['field_rules_editor_mode']['#group'] = 'ppuc_rules';
    $form['field_rules_editor_mode']['#wrapper_attributes']['style'] = 'display:none;';
    if (isset($form['field_rules_editor_mode']['widget'])) {
      $form['field_rules_editor_mode']['widget']['#required'] = FALSE;
      foreach (['blockly', 'lua'] as $mode_value) {
        if (isset($form['field_rules_editor_mode']['widget'][$mode_value])) {
          $form['field_rules_editor_mode']['widget'][$mode_value]['#required'] = FALSE;
        }
      }
    }
    $form['field_rules_blocks']['#attributes']['class'][] = 'ppuc-rules-blockly-data';
    $form['field_rules_blocks']['#wrapper_attributes']['class'][] = 'ppuc-rules-blockly-data-wrapper';
    $form['field_rules_blocks']['#wrapper_attributes']['style'] = 'display:none;';
    if (isset($form['field_rules_blocks']['widget'][0]['value'])) {
      $form['field_rules_blocks']['widget'][0]['value']['#attributes']['class'][] = 'ppuc-rules-blockly-data-value';
      $form['field_rules_blocks']['widget'][0]['value']['#attributes']['style'] = 'display:none;';
    }

    $form['ppuc_rules'] = [
      '#type' => 'details',
      '#title' => $this->t('Rules'),
      '#open' => TRUE,
      '#weight' => 80,
      '#attributes' => [
        'class' => ['ppuc-rules-details'],
      ],
    ];

    $form['ppuc_rules']['workspace'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ppuc-rules-workspace'],
      ],
      '#weight' => -10,
    ];
    $form['ppuc_rules']['workspace']['toolbar'] = [
      '#markup' => '<div class="ppuc-rules-toolbar"><button type="button" class="button ppuc-rules-blockly-generate">Generate Lua</button><button type="button" class="button ppuc-rules-edit-lua">Edit Lua directly</button><button type="button" class="button ppuc-rules-use-blockly">Use Blockly</button><span class="ppuc-rules-status" aria-live="polite"></span></div>',
    ];
    $form['ppuc_rules']['workspace']['blockly'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Loading Blockly...'),
      '#attributes' => [
        'class' => ['ppuc-rules-blockly'],
        'data-ppuc-rules-blockly' => '',
      ],
    ];
  }

  protected function configurePpucSettingsFields(array &$form): void {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getEntity();
    if ($entity->bundle() !== 'ppuc_settings') {
      return;
    }

    if (isset($form['title']['widget'][0]['value'])) {
      $form['title']['widget'][0]['value']['#description'] = $this->t('Administrative title for this settings record. It is not written to ppuc.ini.');
    }

    if ($entity->isNew() && isset($form['field_game']['widget'][0]['target_id'])) {
      $game_id = \Drupal::request()->query->get('game');
      if (is_numeric($game_id)) {
        $game = $this->entityTypeManager->getStorage('node')->load((int) $game_id);
        if ($game instanceof NodeInterface && $game->bundle() === 'game') {
          $form['field_game']['widget'][0]['target_id']['#default_value'] = $game;
        }
      }
    }

    $form['field_game']['#description'] = $this->t('Game this ppuc.ini file belongs to.');

    $sections = $this->ppucIniFormSections();
    $descriptions = $this->ppucIniFieldDescriptions();
    $weight = 20;
    foreach ($sections as $section_id => $section) {
      $form[$section_id] = [
        '#type' => 'details',
        '#title' => $section['title'],
        '#description' => $section['description'],
        '#open' => in_array($section_id, ['ppuc_ini_game', 'ppuc_ini_paths', 'ppuc_ini_runtime'], TRUE),
        '#weight' => $weight++,
      ];

      foreach ($section['fields'] as $field_name) {
        if (!isset($form[$field_name])) {
          continue;
        }
        $form[$field_name]['#group'] = $section_id;
        if (isset($descriptions[$field_name])) {
          $form[$field_name]['#description'] = $descriptions[$field_name];
          $form[$field_name]['#ppuc_ini_description'] = $descriptions[$field_name];
          $form[$field_name]['#after_build'][] = [
            $this,
            'applyPpucIniDescriptionAfterBuild',
          ];
          $this->applyDescriptionToFormElement($form[$field_name], $descriptions[$field_name]);
        }
      }
    }
  }

  protected function applyDescriptionToFormElement(array &$element, mixed $description): void {
    $element['#description'] = $description;
    if (isset($element['#type']) && in_array($element['#type'], [
      'checkbox',
      'entity_autocomplete',
      'number',
      'textarea',
      'textfield',
    ], TRUE)) {
      $element['#description'] = $description;
    }

    foreach ($element as $key => &$child) {
      if (is_array($child) && (is_int($key) || !str_starts_with((string) $key, '#'))) {
        $this->applyDescriptionToFormElement($child, $description);
      }
    }
  }

  protected function ppucIniFormSections(): array {
    return [
      'ppuc_ini_game' => [
        'title' => $this->t('Game'),
        'description' => $this->t('Values written to the [Game] section.'),
        'fields' => [
          'field_ini_game_rom',
        ],
      ],
      'ppuc_ini_paths' => [
        'title' => $this->t('Paths'),
        'description' => $this->t('Files and folders used by ppuc-pinmame. Relative paths are resolved by the runtime environment.'),
        'fields' => [
          'field_ini_config_file',
          'field_ini_paths_rom',
          'field_ini_serial',
          'field_ini_pinmame_path',
          'field_ini_rules_path',
          'field_ini_music_files',
          'field_ini_music_gap_ms',
          'field_ini_translite',
          'field_ini_translite_attract',
        ],
      ],
      'ppuc_ini_backbox' => [
        'title' => $this->t('Backbox'),
        'description' => $this->t('Optional network backbox or DMD server connection.'),
        'fields' => [
          'field_ini_backbox_address',
          'field_ini_backbox_port',
        ],
      ],
      'ppuc_ini_runtime' => [
        'title' => $this->t('Runtime'),
        'description' => $this->t('Runtime behavior, diagnostics, media plugins, transport timing, and board startup options.'),
        'fields' => [
          'field_ini_no_serial',
          'field_ini_no_sound',
          'field_ini_debug',
          'field_ini_debug_errors',
          'field_ini_debug_switches',
          'field_ini_debug_coils',
          'field_ini_debug_lamps',
          'field_ini_debug_effects',
          'field_ini_runtime_rules',
          'field_ini_alt_color',
          'field_ini_serum_timeout',
          'field_ini_serum_skip_frames',
          'field_ini_pup',
          'field_ini_alt_sound',
          'field_ini_b2s',
          'field_ini_b2s_angle',
          'field_ini_b2s_glow',
          'field_ini_b2s_smoothing',
          'field_ini_plugin_dir',
          'field_ini_pup_folder',
          'field_ini_altsound_folder',
          'field_ini_console_display',
          'field_ini_dump_display',
          'field_ini_skip_boards',
          'field_ini_switch_reply_us',
          'field_ini_switch_refresh_ms',
          'field_ini_output_frame_ms',
          'field_ini_ball_search',
          'field_ini_ball_search_delay',
          'field_ini_ball_search_round',
          'field_ini_coil_hold_frames',
          'field_ini_close_coin_door',
          'field_ini_hard_reset',
        ],
      ],
      'ppuc_ini_output_filters' => [
        'title' => $this->t('Output Filters'),
        'description' => $this->t('Display-output postprocessing applied by ppuc-pinmame.'),
        'fields' => [
          'field_ini_rounded_corners',
        ],
      ],
      'ppuc_ini_zedmd' => [
        'title' => $this->t('ZeDMD'),
        'description' => $this->t('USB ZeDMD display settings.'),
        'fields' => [
          'field_ini_zedmd_enabled',
          'field_ini_zedmd_device',
          'field_ini_zedmd_debug',
          'field_ini_zedmd_brightness',
        ],
      ],
      'ppuc_ini_zedmd_wifi' => [
        'title' => $this->t('ZeDMD-WiFi'),
        'description' => $this->t('Network ZeDMD display settings.'),
        'fields' => [
          'field_ini_zedmd_wifi_enabled',
          'field_ini_zedmd_wifi_addr',
        ],
      ],
      'ppuc_ini_zedmd_spi' => [
        'title' => $this->t('ZeDMD-SPI'),
        'description' => $this->t('SPI-connected ZeDMD panel settings.'),
        'fields' => [
          'field_ini_zedmd_spi_enabled',
          'field_ini_zedmd_spi_speed',
          'field_ini_zedmd_spi_pause',
          'field_ini_zedmd_spi_width',
          'field_ini_zedmd_spi_height',
        ],
      ],
      'ppuc_ini_pixelcade' => [
        'title' => $this->t('Pixelcade'),
        'description' => $this->t('Pixelcade display settings.'),
        'fields' => [
          'field_ini_pixelcade_enabled',
          'field_ini_pixelcade_device',
        ],
      ],
      'ppuc_ini_pin2dmd' => [
        'title' => $this->t('PIN2DMD'),
        'description' => $this->t('PIN2DMD display settings.'),
        'fields' => [
          'field_ini_pin2dmd_enabled',
        ],
      ],
      'ppuc_ini_speech' => [
        'title' => $this->t('Speech'),
        'description' => $this->t('Speech callout and startup greeting settings.'),
        'fields' => [
          'field_ini_speech_enabled',
          'field_ini_speech_greeting',
          'field_ini_speech_backend',
          'field_ini_speech_voice',
          'field_ini_speech_rate',
          'field_ini_speech_pitch',
        ],
      ],
      'ppuc_ini_bench_test' => [
        'title' => $this->t('Bench Test'),
        'description' => $this->t('Optional startup test modes for switches, coils, lamps, GI, and flashers.'),
        'fields' => [
          'field_ini_switch_test',
          'field_ini_coil_test',
          'field_ini_lamp_test',
          'field_ini_gi_test',
          'field_ini_flasher_test',
          'field_ini_interactive_test',
          'field_ini_test_number',
        ],
      ],
      'ppuc_ini_translite' => [
        'title' => $this->t('Translite'),
        'description' => $this->t('Window placement and sizing for the translite display.'),
        'fields' => [
          'field_ini_translite_window',
          'field_ini_translite_width',
          'field_ini_translite_height',
          'field_ini_translite_screen',
        ],
      ],
      'ppuc_ini_virtual_dmd' => [
        'title' => $this->t('Virtual DMD'),
        'description' => $this->t('Window placement, sizing, and rendering mode for the virtual DMD.'),
        'fields' => [
          'field_ini_virtual_dmd',
          'field_ini_virtual_dmd_hd',
          'field_ini_virtual_dmd_window',
          'field_ini_virtual_dmd_width',
          'field_ini_virtual_dmd_height',
          'field_ini_virtual_dmd_screen',
          'field_ini_virtual_dmd_x',
          'field_ini_virtual_dmd_y',
          'field_ini_virtual_dmd_rotation',
          'field_ini_virtual_dmd_renderer',
        ],
      ],
    ];
  }

  protected function ppucIniFieldDescriptions(): array {
    return [
      'field_ini_game_rom' => $this->t('ROM name passed to PinMAME, written as Game.Rom. Leave empty to use the generated fallback.'),
      'field_ini_config_file' => $this->t('Optional path to the machine YAML config file.'),
      'field_ini_paths_rom' => $this->t('Optional ROM override in the Paths section.'),
      'field_ini_serial' => $this->t('Serial device used for RS485 communication, or dummy for no physical serial port.'),
      'field_ini_pinmame_path' => $this->t('Optional PinMAME base folder.'),
      'field_ini_rules_path' => $this->t('Optional Lua rules file or directory override.'),
      'field_ini_music_files' => $this->t('Comma-separated list of music files for gameplay background music.'),
      'field_ini_music_gap_ms' => $this->t('Delay between background music tracks, in milliseconds.'),
      'field_ini_translite' => $this->t('Image path used for the in-game translite.'),
      'field_ini_translite_attract' => $this->t('Image path used for the attract-mode translite.'),
      'field_ini_backbox_address' => $this->t('Optional DMD/backbox host name or IP address.'),
      'field_ini_backbox_port' => $this->t('TCP port for the DMD/backbox connection.'),
      'field_ini_no_serial' => $this->t('Skip serial communication to PPUC boards entirely.'),
      'field_ini_no_sound' => $this->t('Disable game audio output.'),
      'field_ini_debug' => $this->t('Enable full debug output.'),
      'field_ini_debug_errors' => $this->t('Enable communication and protocol error details without full debug output.'),
      'field_ini_debug_switches' => $this->t('Enable switch debug output.'),
      'field_ini_debug_coils' => $this->t('Enable coil debug output.'),
      'field_ini_debug_lamps' => $this->t('Enable lamp debug output.'),
      'field_ini_debug_effects' => $this->t('Enable effect trigger debug output.'),
      'field_ini_runtime_rules' => $this->t('Enable Lua rules at runtime.'),
      'field_ini_alt_color' => $this->t('Enable Serum or AltColor DMD colorization.'),
      'field_ini_serum_timeout' => $this->t('Serum timeout in milliseconds for ignoring unknown frames.'),
      'field_ini_serum_skip_frames' => $this->t('Number of unknown Serum frames to skip.'),
      'field_ini_pup' => $this->t('Enable PUP backglass video capture and matching.'),
      'field_ini_alt_sound' => $this->t('Enable AltSound through the media plugin host.'),
      'field_ini_b2s' => $this->t('Enable B2S backglass rendering.'),
      'field_ini_b2s_angle' => $this->t('B2S segment rendering angle in degrees.'),
      'field_ini_b2s_glow' => $this->t('B2S segment glow intensity.'),
      'field_ini_b2s_smoothing' => $this->t('Enable B2S segment smoothing.'),
      'field_ini_plugin_dir' => $this->t('Optional VPX plugin directory.'),
      'field_ini_pup_folder' => $this->t('Optional PinUp Player folder containing pupvideos.'),
      'field_ini_altsound_folder' => $this->t('Optional AltSound folder or base folder.'),
      'field_ini_console_display' => $this->t('Render the DMD in the terminal.'),
      'field_ini_dump_display' => $this->t('Write DMD text dump files.'),
      'field_ini_skip_boards' => $this->t('Comma-separated configured board numbers to skip.'),
      'field_ini_switch_reply_us' => $this->t('Per-board switch reply delay in microseconds.'),
      'field_ini_switch_refresh_ms' => $this->t('Re-read all switches after this many milliseconds without non-button switch updates.'),
      'field_ini_output_frame_ms' => $this->t('Runtime output frame interval in milliseconds. Lower values increase switch polling cadence.'),
      'field_ini_ball_search' => $this->t('Enable host-side ball search for older ROMs without native ball search.'),
      'field_ini_ball_search_delay' => $this->t('Idle time in milliseconds before ball search starts while a game is running.'),
      'field_ini_ball_search_round' => $this->t('Delay in milliseconds between complete ball-search coil rounds.'),
      'field_ini_coil_hold_frames' => $this->t('Number of runtime frames a coil hold stays asserted in libppuc.'),
      'field_ini_close_coin_door' => $this->t('Force the configured coin-door-closed switch closed when it is virtualized.'),
      'field_ini_hard_reset' => $this->t('Use hard reset instead of soft restart on board startup.'),
      'field_ini_rounded_corners' => $this->t('Radius in pixels for black rounded corners on local DMD outputs.'),
      'field_ini_zedmd_enabled' => $this->t('Enable a USB ZeDMD display.'),
      'field_ini_zedmd_device' => $this->t('Optional fixed ZeDMD serial device. Leave empty for auto-detection.'),
      'field_ini_zedmd_debug' => $this->t('Enable ZeDMD debug mode.'),
      'field_ini_zedmd_brightness' => $this->t('Override ZeDMD brightness from 0 to 15. Use -1 to leave the device setting unchanged.'),
      'field_ini_zedmd_wifi_enabled' => $this->t('Enable a ZeDMD-WiFi display.'),
      'field_ini_zedmd_wifi_addr' => $this->t('ZeDMD-WiFi network address, such as a fixed IP or resolvable host name.'),
      'field_ini_zedmd_spi_enabled' => $this->t('Enable a ZeDMD-SPI display.'),
      'field_ini_zedmd_spi_speed' => $this->t('SPI speed in Hz.'),
      'field_ini_zedmd_spi_pause' => $this->t('Forced pause between frames in milliseconds.'),
      'field_ini_zedmd_spi_width' => $this->t('SPI panel width in pixels.'),
      'field_ini_zedmd_spi_height' => $this->t('SPI panel height in pixels.'),
      'field_ini_pixelcade_enabled' => $this->t('Enable Pixelcade output.'),
      'field_ini_pixelcade_device' => $this->t('Optional fixed Pixelcade serial device. Leave empty for auto-detection.'),
      'field_ini_pin2dmd_enabled' => $this->t('Enable PIN2DMD output.'),
      'field_ini_speech_enabled' => $this->t('Enable speech callouts.'),
      'field_ini_speech_greeting' => $this->t('Speak a startup greeting for speech debugging.'),
      'field_ini_speech_backend' => $this->t('Speech backend: auto, flite, or espeak-ng.'),
      'field_ini_speech_voice' => $this->t('Optional speech voice name.'),
      'field_ini_speech_rate' => $this->t('Optional speech rate in words per minute, mainly for espeak-ng.'),
      'field_ini_speech_pitch' => $this->t('Optional speech pitch from 0 to 100, mainly for espeak-ng.'),
      'field_ini_switch_test' => $this->t('Start in switch test mode.'),
      'field_ini_coil_test' => $this->t('Start in coil test mode.'),
      'field_ini_lamp_test' => $this->t('Start in lamp test mode.'),
      'field_ini_gi_test' => $this->t('Start in GI test mode.'),
      'field_ini_flasher_test' => $this->t('Start in flasher test mode.'),
      'field_ini_interactive_test' => $this->t('Enable interactive selection mode for coil, lamp, and flasher tests.'),
      'field_ini_test_number' => $this->t('Optional specific coil, lamp, GI, or flasher number for bench tests.'),
      'field_ini_translite_window' => $this->t('Show the translite in a window instead of fullscreen.'),
      'field_ini_translite_width' => $this->t('Translite window width in pixels.'),
      'field_ini_translite_height' => $this->t('Translite window height in pixels.'),
      'field_ini_translite_screen' => $this->t('Target screen index, or -1 for default placement.'),
      'field_ini_virtual_dmd' => $this->t('Enable the virtual DMD window.'),
      'field_ini_virtual_dmd_hd' => $this->t('Use 256x64 HD virtual DMD output instead of 128x32.'),
      'field_ini_virtual_dmd_window' => $this->t('Show the virtual DMD in a window instead of fullscreen.'),
      'field_ini_virtual_dmd_width' => $this->t('Virtual DMD window width in pixels.'),
      'field_ini_virtual_dmd_height' => $this->t('Virtual DMD window height in pixels.'),
      'field_ini_virtual_dmd_screen' => $this->t('Target screen index, or -1 for default placement.'),
      'field_ini_virtual_dmd_x' => $this->t('X position relative to the selected screen.'),
      'field_ini_virtual_dmd_y' => $this->t('Y position relative to the selected screen.'),
      'field_ini_virtual_dmd_rotation' => $this->t('Virtual DMD rotation in degrees: 0, 90, 180, or 270.'),
      'field_ini_virtual_dmd_renderer' => $this->t('Renderer name, such as dots, squares, smooth, or xbrz.'),
    ];
  }

  protected function configureSwitchGroupMembershipFields(array &$form): void {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getEntity();
    if (!in_array($entity->bundle(), ['switch', 'switch_matrix_switch'], TRUE)) {
      return;
    }

    $game = $this->getSwitchGame($entity);
    if (!$game instanceof NodeInterface || !$game->hasField('field_switch_groups')) {
      return;
    }

    $groups = $this->parseSwitchGroupNamesField($game);
    if ($groups === []) {
      $form['ppuc_switch_groups'] = [
        '#type' => 'details',
        '#title' => $this->t('Switch groups'),
        '#open' => FALSE,
        '#weight' => 90,
        'empty' => [
          '#markup' => '<p>' . $this->t('Define switch groups on the game edit page first.') . '</p>',
        ],
      ];
      return;
    }

    $switch_number = $this->getSwitchNumber($entity);
    $memberships = $this->parseSwitchGroupMembershipsField($game);
    $default_value = [];
    if ($switch_number !== NULL) {
      foreach ($memberships as $name => $numbers) {
        if (in_array($switch_number, $numbers, TRUE)) {
          $default_value[] = $name;
        }
      }
    }

    $options = array_combine(array_keys($groups), array_keys($groups));
    $form['ppuc_switch_groups'] = [
      '#type' => 'details',
      '#title' => $this->t('Switch groups'),
      '#open' => TRUE,
      '#weight' => 90,
      'groups' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Groups'),
        '#options' => $options,
        '#default_value' => $default_value,
        '#description' => $this->t('Group names are defined on the game edit page. Saving this switch updates the game switch group list.'),
      ],
    ];
  }

  protected function getSwitchGame(NodeInterface $entity): ?NodeInterface {
    if ($entity->bundle() === 'switch' && $entity->hasField('field_i_o_board') && !$entity->get('field_i_o_board')->isEmpty()) {
      $board = $entity->get('field_i_o_board')->entity;
      if ($board instanceof NodeInterface && $board->hasField('field_game') && !$board->get('field_game')->isEmpty()) {
        $game = $board->get('field_game')->entity;
        return $game instanceof NodeInterface ? $game : NULL;
      }
    }

    if ($entity->bundle() === 'switch_matrix_switch' && $entity->hasField('field_switch_matrix') && !$entity->get('field_switch_matrix')->isEmpty()) {
      $matrix = $entity->get('field_switch_matrix')->entity;
      if ($matrix instanceof NodeInterface && $matrix->hasField('field_i_o_board') && !$matrix->get('field_i_o_board')->isEmpty()) {
        $board = $matrix->get('field_i_o_board')->entity;
        if ($board instanceof NodeInterface && $board->hasField('field_game') && !$board->get('field_game')->isEmpty()) {
          $game = $board->get('field_game')->entity;
          return $game instanceof NodeInterface ? $game : NULL;
        }
      }
    }

    return NULL;
  }

  protected function getSwitchNumber(NodeInterface $entity): ?int {
    if (!$entity->hasField('field_number') || $entity->get('field_number')->isEmpty()) {
      return NULL;
    }
    return (int) $entity->get('field_number')->value;
  }

  protected function parseSwitchGroupNamesField(NodeInterface $game): array {
    if (!$game->hasField('field_switch_groups') || $game->get('field_switch_groups')->isEmpty()) {
      return [];
    }

    $groups = [];
    $value = (string) $game->get('field_switch_groups')->value;
    foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
      $line = trim(preg_replace('/#.*/', '', $line) ?? '');
      if ($line === '' || !preg_match('/^([A-Za-z][A-Za-z0-9_-]*)/', $line, $matches)) {
        continue;
      }
      if ($matches[1] === 'buttons') {
        continue;
      }
      $groups[$matches[1]] = [];
    }

    return $groups;
  }

  protected function parseSwitchGroupMembershipsField(NodeInterface $game): array {
    $value = '';
    if ($game->hasField('field_switch_group_memberships') && !$game->get('field_switch_group_memberships')->isEmpty()) {
      $value = (string) $game->get('field_switch_group_memberships')->value;
    }
    elseif ($game->hasField('field_switch_groups') && !$game->get('field_switch_groups')->isEmpty()) {
      $value = (string) $game->get('field_switch_groups')->value;
    }

    if (trim($value) === '') {
      return [];
    }

    $groups = [];
    foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
      $line = trim(preg_replace('/#.*/', '', $line) ?? '');
      if ($line === '' || !preg_match('/^([A-Za-z][A-Za-z0-9_-]*)\s*[:=]\s*(.*)$/', $line, $matches)) {
        continue;
      }
      $numbers = [];
      foreach (preg_split('/[\s,]+/', trim($matches[2])) ?: [] as $part) {
        if ($part !== '' && preg_match('/^\d+$/', $part)) {
          $numbers[] = (int) $part;
        }
      }
      $groups[$matches[1]] = array_values(array_unique($numbers));
    }

    return $groups;
  }

  protected function formatSwitchGroupMembershipsField(array $groups): string {
    $lines = [];
    foreach ($groups as $name => $numbers) {
      sort($numbers, SORT_NUMERIC);
      $lines[] = $name . ': ' . implode(', ', array_values(array_unique($numbers)));
    }
    return implode("\n", $lines);
  }

  protected function formatSwitchGroupNamesField(array $groups): string {
    return implode("\n", array_keys($groups));
  }

  protected function saveSwitchGroupMemberships(FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getEntity();
    if (!in_array($entity->bundle(), ['switch', 'switch_matrix_switch'], TRUE)) {
      return;
    }

    $value = $form_state->getValue('ppuc_switch_groups');
    if (!is_array($value) || !isset($value['groups']) || !is_array($value['groups'])) {
      return;
    }

    $switch_number = $this->getSwitchNumber($entity);
    $game = $this->getSwitchGame($entity);
    if ($switch_number === NULL || !$game instanceof NodeInterface || !$game->hasField('field_switch_group_memberships')) {
      return;
    }

    $selected = array_filter($value['groups']);
    $groups = $this->parseSwitchGroupNamesField($game);
    $memberships = $this->parseSwitchGroupMembershipsField($game);
    foreach ($groups as $name => $numbers) {
      $groups[$name] = $memberships[$name] ?? [];
    }
    foreach ($groups as $name => &$numbers) {
      $numbers = array_values(array_diff($numbers, [$switch_number]));
      if (isset($selected[$name])) {
        $numbers[] = $switch_number;
      }
      $numbers = array_values(array_unique($numbers));
    }
    unset($numbers);

    $game->set('field_switch_groups', $this->formatSwitchGroupNamesField($groups));
    $game->set('field_switch_group_memberships', $this->formatSwitchGroupMembershipsField($groups));
    $game->save();
  }

  protected function selectedLedStringSupportsWhite(NodeInterface $entity, FormStateInterface $form_state): bool {
    $target_id = NULL;
    $value = $form_state->getValue('field_string');
    if (is_array($value)) {
      $first = reset($value);
      if (is_array($first) && isset($first['target_id'])) {
        $target_id = $first['target_id'];
      }
      elseif (isset($value['target_id'])) {
        $target_id = $value['target_id'];
      }
    }
    elseif ($value) {
      $target_id = $value;
    }

    if (!$target_id && $entity->hasField('field_string') && !$entity->get('field_string')->isEmpty()) {
      $target_id = $entity->get('field_string')->target_id;
    }

    if (!$target_id) {
      return FALSE;
    }

    $string = $this->entityTypeManager->getStorage('node')->load($target_id);
    if (!$string instanceof NodeInterface || !$string->hasField('field_led_type') || $string->get('field_led_type')->isEmpty()) {
      return FALSE;
    }

    return str_contains(strtoupper($string->get('field_led_type')->entity?->getName() ?? ''), 'W');
  }

  protected function selectedLedEffectColorSlots(NodeInterface $entity, FormStateInterface $form_state): int {
    $target_id = NULL;
    $value = $form_state->getValue('field_effect');
    if (is_array($value)) {
      $first = reset($value);
      if (is_array($first) && isset($first['target_id'])) {
        $target_id = $first['target_id'];
      }
      elseif (isset($value['target_id'])) {
        $target_id = $value['target_id'];
      }
    }
    elseif ($value) {
      $target_id = $value;
    }

    if (!$target_id && $entity->hasField('field_effect') && !$entity->get('field_effect')->isEmpty()) {
      $target_id = $entity->get('field_effect')->target_id;
    }

    if (!$target_id) {
      return 1;
    }

    $effect = $this->entityTypeManager->getStorage('taxonomy_term')->load($target_id);
    if (!$effect instanceof TermInterface || !$effect->hasField('field_number') || $effect->get('field_number')->isEmpty()) {
      return 1;
    }

    $effect_number = (int) $effect->get('field_number')->value;
    $three_color_effects = [
      54, // Tricolor Chase.
      55, // TwinkleFox.
      56, // Rain.
      59, // Dual Larson.
      64, // Trifade.
      65, // VU Meter.
      67, // Bits.
      68, // Multi Comet.
      71, // Oscillator.
    ];
    $two_color_effects = [
      1, // Blink.
      2, // Breath.
      3, // Color Wipe.
      4, // Color Wipe Inverse.
      5, // Color Wipe Reverse.
      6, // Color Wipe Reverse Inverse.
      13, // Scan.
      14, // Dual Scan.
      40, // Running Color.
      53, // Bicolor Chase.
      70, // Popcorn.
    ];

    if (in_array($effect_number, $three_color_effects, TRUE)) {
      return 3;
    }
    if (in_array($effect_number, $two_color_effects, TRUE)) {
      return 2;
    }

    return 1;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $element = parent::actions($form, $form_state);

    unset($element['preview']);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);
    $this->saveSwitchGroupMemberships($form_state);
    return $status;
  }

  /**
   * {@inheritdoc}
   *
   * Button-level validation handlers are highly discouraged for entity forms,
   * as they will prevent entity validation from running. If the entity is going
   * to be saved during the form submission, this method should be manually
   * invoked from the button-level validation handler, otherwise an exception
   * will be thrown.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);

    if ($entity->hasField('field_pin') && $entity->hasField('field_i_o_board')) {
      $i_o_board = $entity->field_i_o_board->entity;
      $i_o_board_type = $i_o_board->field_io_board_type->entity;
      $i_o_board_gpio_mapping = unserialize($i_o_board_type->field_gpio_mapping->value, ['allowed_classes' => FALSE]);
      if (!array_key_exists((int) ($entity->field_pin->value), $i_o_board_gpio_mapping)) {
        $form_state->setErrorByName('field_pin[0][value]', $this->t('The selected board has no port %pin.', ['%pin' => $entity->field_pin->value]));
      }
    }

    return $entity;
  }

}

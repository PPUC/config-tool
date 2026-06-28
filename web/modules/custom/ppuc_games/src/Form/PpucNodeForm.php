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
    $this->configureRulesFields($form);

    return $form;
  }

  public function refreshForm(array $form, FormStateInterface $form_state): array {
    return $form;
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

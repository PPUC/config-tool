<?php

/**
 * @file
 */

/**
 * Install quick_node_clone.
 */
function ppuc_post_update_8001_install_quick_node_clone(): void {
  /** @var \Drupal\Core\Extension\ModuleInstallerInterface $moduleInstaller */
  $moduleInstaller = \Drupal::service('module_installer');
  $moduleInstaller->install(['quick_node_clone']);
}

/**
 * Add per-LED RGB(W) order override field.
 */
function ppuc_post_update_8002_add_addressable_led_rgb_order_override(): void {
  $field_config_storage = \Drupal::entityTypeManager()->getStorage('field_config');
  if (!$field_config_storage->load('node.addressable_led.field_led_type')) {
    $field_config_storage->create([
      'field_name' => 'field_led_type',
      'entity_type' => 'node',
      'bundle' => 'addressable_led',
      'label' => 'RGB(W) Order',
      'description' => 'Overrides the RGB(W) order inherited from the LED string for this LED.',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => [
            'led_type' => 'led_type',
          ],
          'sort' => [
            'field' => 'name',
            'direction' => 'asc',
          ],
          'auto_create' => FALSE,
          'auto_create_bundle' => '',
        ],
      ],
    ])->save();
  }

  /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface|null $form_display */
  $form_display = \Drupal::entityTypeManager()
    ->getStorage('entity_form_display')
    ->load('node.addressable_led.default');
  if ($form_display) {
    $form_display->setComponent('field_led_type', [
      'type' => 'options_select',
      'weight' => 3,
      'region' => 'content',
      'settings' => [],
      'third_party_settings' => [],
    ])->save();
  }

  /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface|null $view_display */
  $view_display = \Drupal::entityTypeManager()
    ->getStorage('entity_view_display')
    ->load('node.addressable_led.default');
  if ($view_display) {
    $view_display->setComponent('field_led_type', [
      'type' => 'entity_reference_label',
      'label' => 'above',
      'weight' => 105,
      'region' => 'content',
      'settings' => [
        'link' => TRUE,
      ],
      'third_party_settings' => [],
    ])->save();
  }
}

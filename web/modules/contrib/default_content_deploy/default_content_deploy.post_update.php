<?php

/**
 * @file
 * Post-update hook for default_content_deploy module.
 */

/**
 * Rename a configuration key for default_content_deploy module.
 *
 * The configuration key `default_content_deploy.content_directory` is renamed
 * to `default_content_deploy.settings` to align with updated structure.
 */
function default_content_deploy_post_update_8001_rename_config() {
  $config_factory = \Drupal::configFactory();

  // Rename the configuration.
  $config_factory->rename('default_content_deploy.content_directory', 'default_content_deploy.settings');

  // Delete the old configuration entry.
  \Drupal::service('config.storage')->delete('default_content_deploy.content_directory');

  // Reset the configuration to apply changes.
  $config_factory->reset('default_content_deploy.settings');
}

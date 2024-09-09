<?php

/**
 * Rename default_content_deploy.content_directory to default_content_deploy.settings.
 */
function default_content_deploy_post_update_8001_rename_config() {
  $config_factory = \Drupal::configFactory();
  $config_factory->rename('default_content_deploy.content_directory', 'default_content_deploy.settings');
  \Drupal::service('config.storage')->delete('default_content_deploy.content_directory');
  $config_factory->reset('default_content_deploy.settings');
}

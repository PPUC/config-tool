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

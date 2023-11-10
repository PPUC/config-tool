<?php

namespace Drupal\default_content;

/**
 * An interface defining a default content importer.
 */
interface ImporterInterface {

  /**
   * Imports default content from a given module.
   *
   * @param string $module
   *   The module to create the default content from.
   * @param bool $update_existing
   *   Whether to update already existing entities with the imported values.
   *   Defaults to FALSE.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of created entities keyed by their UUIDs.
   */
  public function importContent($module, bool $update_existing = FALSE);

  /**
   * Imports default content from a given folder.
   *
   * @param string $folder
   *   The folder to create the default content from.
   * @param string $module
   *   The module to create the default content from.
   * @param bool $update_existing
   *    Whether to update already existing entities with the imported values.
   *    Defaults to FALSE.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of created entities keyed by their UUIDs.
   */
  public function importContentFromFolder($folder, $module = NULL, bool $update_existing = FALSE);

}

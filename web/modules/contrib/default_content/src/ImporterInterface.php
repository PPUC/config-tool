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
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of created entities keyed by their UUIDs.
   */
  public function importContent($module);

  /**
   * Imports default content from a given folder.
   *
   * @param string $folder
   *   The module to create the default content from.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of created entities keyed by their UUIDs.
   */
  public function importContentFromFolder($module);

}

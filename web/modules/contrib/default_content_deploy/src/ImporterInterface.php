<?php

namespace Drupal\default_content_deploy;


/**
 * A service for handling import of default content.
 */
interface ImporterInterface
{

  /**
   * Force override of existing content.
   *
   * @param bool $force
   *   TRUE if content should be overridden.
   */
  public function setForceOverride(bool $force): void;

  /**
   * Set directory to import.
   *
   * @param string $folder
   *   The content folder.
   */
  public function setFolder(string $folder): void;

  /**
   * Set option to preserve the original Entity IDs
   *
   * Note: this might lead to conflicts with existing content.
   *
   * @param bool $preserve
   *   Preserve the IDs.
   */
  public function setPreserveIds(bool $preserve): void;

  /**
   * Enable the incremental import mode.
   *
   * @param bool $incremental
   *   Incremental mode.
   */
  public function setIncremental(bool $incremental): void;

  /**
   * Get Imported data result.
   *
   * @return array
   *   The result.
   */
  public function getResult(): array;

  /**
   * Enable verbose mode.
   *
   * @param bool $verbose
   *   Verbose mode.
   */
  public function setVerbose(bool $verbose): void;

  /**
   * Import entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function import(): void;
}

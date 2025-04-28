<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines an interface for exporting default content.
 */
interface ExporterInterface {

  /**
   * Sets the entity type ID.
   *
   * @param string $entity_type
   *   The entity type ID.
   */
  public function setEntityTypeId(string $entity_type): void;

  /**
   * Sets the entity bundle.
   *
   * @param string $bundle
   *   The entity bundle.
   */
  public function setEntityBundle(string $bundle): void;

  /**
   * Sets entity IDs for export.
   *
   * @param array $entity_ids
   *   The entity IDs.
   */
  public function setEntityIds(array $entity_ids): void;

  /**
   * Sets entity IDs to be skipped.
   *
   * @param array $skip_entity_ids
   *   The entity IDs to skip.
   */
  public function setSkipEntityIds(array $skip_entity_ids): void;

  /**
   * Sets entity type IDs to be skipped.
   *
   * @param array $skip_entity_type_ids
   *   The entity type IDs to skip.
   */
  public function setSkipEntityTypeIds(array $skip_entity_type_ids): void;

  /**
   * Gets the entity type IDs to be skipped.
   *
   * @return array
   *   The entity type IDs.
   */
  public function getSkipEntityTypeIds(): array;

  /**
   * Sets the export mode.
   *
   * @param string $mode
   *   The export mode.
   *
   * @throws \Exception
   *   Throws an exception if an invalid mode is set.
   */
  public function setMode(string $mode): void;

  /**
   * Forces override of existing exported content.
   *
   * @param bool $force
   *   TRUE to force override.
   */
  public function setForceUpdate(bool $force): void;

  /**
   * Sets the domain for HAL format entity links.
   *
   * @param string $link_domain
   *   The domain to be set.
   */
  public function setLinkDomain(string $link_domain): void;

  /**
   * Gets the domain for HAL format entity links.
   *
   * @return string
   *   The link domain.
   */
  public function getLinkDomain(): string;

  /**
   * Sets the datetime for export filtering.
   *
   * All content changes before this datetime will be ignored.
   *
   * @param \DateTimeInterface $date_time
   *   The datetime to be set.
   */
  public function setDateTime(\DateTimeInterface $date_time): void;

  /**
   * Gets the datetime used for export filtering.
   *
   * @return \DateTimeInterface|null
   *   The datetime, or NULL if not set.
   */
  public function getDateTime(): ?\DateTimeInterface;

  /**
   * Gets the export datetime as a timestamp.
   *
   * @return int
   *   The timestamp.
   */
  public function getTime(): int;

  /**
   * Sets the value of the text dependencies option.
   *
   * @param bool|null $text_dependencies
   *   The text dependencies option. If NULL, it will be obtained
   *   from the configuration.
   */
  public function setTextDependencies(?bool $text_dependencies = NULL): void;

  /**
   * Gets the value of the text dependencies option.
   *
   * @return bool
   *   The text dependencies option value.
   */
  public function getTextDependencies(): ?bool;

  /**
   * Sets the directory for export.
   *
   * @param string $folder
   *   The folder path.
   */
  public function setFolder(string $folder): void;

  /**
   * Enables or disables verbose mode.
   *
   * @param bool $verbose
   *   TRUE to enable verbose mode.
   */
  public function setVerbose(bool $verbose): void;

  /**
   * Exports entities.
   */
  public function export(): void;

  /**
   * Prepares and exports a single entity to a JSON file.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be exported.
   * @param bool|null $with_references
   *   TRUE to export referenced entities.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function exportEntity(ContentEntityInterface $entity, ?bool $with_references = FALSE): bool;

  /**
   * Exports a single entity in a format compatible with importContent.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be exported.
   * @param bool $add_metadata
   *   TRUE to include metadata.
   *
   * @return string
   *   The serialized entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the entity definition is invalid.
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity plugin is not found.
   */
  public function getSerializedContent(ContentEntityInterface $entity, bool $add_metadata): string;

}

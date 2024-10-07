<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A service for handling export of default content.
 */
interface ExporterInterface {

  /**
   * Set entity type ID.
   *
   * @param string $entity_type
   *   Entity Type.
   */
  public function setEntityTypeId(string $entity_type): void;

  /**
   * Set type of entity content.
   *
   * @param string $bundle
   *  Bundle of the entity type.
   */
  public function setEntityBundle(string $bundle): void;

  /**
   * Set entity IDs for export.
   *
   * @param array $entity_ids
   *   The IDs of entity.
   */
  public function setEntityIds(array $entity_ids): void;

  /**
   * Set entity IDs which needs skip.
   *
   * @param array $skip_entity_ids
   *   The IDs of entity for skip.
   */
  public function setSkipEntityIds(array $skip_entity_ids): void;

  /**
   * Set entity type IDs which needs skip.
   *
   * @param array $skip_entity_type_ids
   *   The entity type IDs to skip.
   */
  public function setSkipEntityTypeIds(array $skip_entity_type_ids): void;

  /**
   * @return array
   *   The entity type IDs to skip.
   */
  public function getSkipEntityTypeIds(): array;

  /**
   * Set type of export.
   *
   * @param string $mode
   *  Value type of export.
   *
   * @throws \Exception
   */
  public function setMode(string $mode): void;

  /**
   * Force override of existing exported content.
   *
   * @param bool $force
   *   TRUE if content should be overridden.
   */
  public function setForceUpdate(bool $force): void;

  /**
   * Set the Domain of the links to other entities in the HAL format.
   *
   * @param string $link_domain
   *   The domain.
   */
  public function setLinkDomain(string $link_domain): void;

  /**
   * Set the Domain of the links to other entities in the HAL format.
   * *
   * @return string
   *   The link domain
   */
  public function getLinkDomain(): string;

  /**
   * Set the datetime. All content changes before will be ignored during the export.
   *
   * @param \DateTimeInterface $date_time
   */
  public function setDateTime(\DateTimeInterface $date_time): void;

  /**
   * Get the datetime. All content changes before will be ignored during the export.
   *
   * @return \DateTimeInterface|null
   *   The datetime.
   */
  public function getDateTime(): ?\DateTimeInterface;

  /**
   * Get the datetime as timestamp.
   *
   * @return int
   *   The timestamp.
   */
  public function getTime(): int;

  /**
   * Set the value of text_dependencies option.
   *
   * @param bool|null $text_dependencies
   *   The value of the text_dependencies option. If null, it will be
   *   obtained from the configuration.
   */
  public function setTextDependencies(?bool $text_dependencies = NULL): void;

  /**
   * Get the value of text_dependencies option.
   *
   * @return bool
   *   The value of the text_dependencies option.
   */
  public function getTextDependencies(): ?bool;

  /**
   * Set directory to export.
   *
   * @param string $folder
   *   The content folder.
   */
  public function setFolder(string $folder): void;

  /**
   * Enable the verbose mode.
   *
   * @param bool $verbose
   *   Set to TRUE for verbose mode.
   */
  public function setVerbose(bool $verbose): void;

  /**
   * Export entities.
   */
  public function export(): void;

  /**
   * Prepares and exports a single entity to a JSON file.
   *
   * @param ContentEntityInterface $entity
   *   The entity to be exported.
   * @param bool|null $with_references
   *   Indicates if export should consider referenced entities.
   */
  public function exportEntity(ContentEntityInterface $entity, ?bool $with_references = FALSE): bool;

  /**
   * Exports a single entity as importContent expects it.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be exported.
   * @param bool|null $add_metadata
   *   Include metadata.
   *
   * @return string
   *   The serialized Entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getSerializedContent(ContentEntityInterface $entity, ?bool $add_metadata = TRUE): string;
}

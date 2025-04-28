<?php

namespace Drupal\default_content_deploy;

use Laminas\Stdlib\ArrayUtils;

/**
 * Manages metadata for default content deployment.
 *
 * This service handles UUID mappings, export timestamps,
 * and correction status tracking for content entities.
 */
class DefaultContentDeployMetadataService {

  /**
   * Mapping from old entity IDs to UUIDs per content type.
   *
   * @var array<string, array<int, string>>
   */
  protected array $uuids = [];

  /**
   * The UUID currently being processed.
   *
   * @var string
   */
  protected string $currentUuid = '';

  /**
   * Export timestamps for each UUID.
   *
   * @var array<string, int>
   */
  protected array $exportTimestamps = [];

  /**
   * Flags indicating whether correction is required for a UUID.
   *
   * @var array<string, bool>
   */
  protected array $correctionRequired = [];

  /**
   * Adds metadata for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the entity.
   * @param array $metadata
   *   Metadata containing export timestamps and UUID mappings.
   */
  public function add(string $uuid, array $metadata): void {
    if (isset($metadata['uuids'])) {
      $this->uuids = $this->mergeUuids($metadata['uuids']);
    }
    if (isset($metadata['export_timestamp'])) {
      $this->exportTimestamps[$uuid] = $metadata['export_timestamp'];
    }
  }

  /**
   * Resets all stored metadata.
   */
  public function reset(): void {
    $this->uuids = [];
    $this->exportTimestamps = [];
    $this->correctionRequired = [];
  }

  /**
   * Gets the export timestamp for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the entity.
   *
   * @return int|null
   *   The export timestamp or NULL if not found.
   */
  public function getExportTimestamp(string $uuid): ?int {
    return $this->exportTimestamps[$uuid] ?? NULL;
  }

  /**
   * Adds a UUID mapping for an entity.
   *
   * @param string $entityType
   *   The type of entity.
   * @param int $entityId
   *   The entity ID.
   * @param string $uuid
   *   The UUID associated with the entity.
   */
  public function addUuid(string $entityType, int $entityId, string $uuid): void {
    $this->uuids[$entityType][$entityId] = $uuid;
  }

  /**
   * Retrieves the UUID for an entity.
   *
   * @param string $entityType
   *   The type of entity.
   * @param int $entityId
   *   The entity ID.
   *
   * @return string|null
   *   The UUID or NULL if not found.
   */
  public function getUuid(string $entityType, int $entityId): ?string {
    return $this->uuids[$entityType][$entityId] ?? NULL;
  }

  /**
   * Merges incoming UUIDs with existing mappings.
   *
   * @param array $uuids
   *   The UUID mappings to merge.
   *
   * @return array
   *   The merged UUID mappings.
   */
  public function mergeUuids(array $uuids): array {
    return ArrayUtils::merge($uuids, $this->uuids, TRUE);
  }

  /**
   * Marks whether correction is required for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the entity.
   * @param bool $value
   *   TRUE if correction is required, FALSE otherwise.
   */
  public function setCorrectionRequired(string $uuid, bool $value): void {
    $this->correctionRequired[$uuid] = $value;
  }

  /**
   * Checks if correction is required for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the entity.
   *
   * @return bool
   *   TRUE if correction is required, FALSE otherwise.
   */
  public function isCorrectionRequired(string $uuid): bool {
    return $this->correctionRequired[$uuid] ?? TRUE;
  }

  /**
   * Sets the current UUID being processed.
   *
   * @param string $uuid
   *   The UUID to set as the current one.
   */
  public function setCurrentUuid(string $uuid): void {
    $this->currentUuid = $uuid;
  }

  /**
   * Marks whether correction is required for the current UUID.
   *
   * @param bool $value
   *   TRUE if correction is required, FALSE otherwise.
   */
  public function setCorrectionRequiredForCurrentUuid(bool $value): void {
    if (!empty($this->currentUuid)) {
      $this->correctionRequired[$this->currentUuid] = $value;
    }
  }

}

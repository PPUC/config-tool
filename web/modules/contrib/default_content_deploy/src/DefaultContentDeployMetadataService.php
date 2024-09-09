<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Laminas\Stdlib\ArrayUtils;

class DefaultContentDeployMetadataService {

  /**
   * Mapping from old entity IDs to UUIDs per content type.
   *
   * @var array<string, array<int, string>>
   */
  protected array $uuids = [];

  /**
   * The timestamps when this metadata has been exported.
   *
   * @var array<string, int>
   */
  protected array $exportTimestamps;

  /**
   * The timestamps when this metadata has been exported.
   *
   * @var array<string, bool>
   */
  protected array $correctionRequired;

  public function add(string $uuid, array $metadata): void {
    if (isset($metadata['uuids'])) {
      $this->uuids = $this->mergeUuids($metadata['uuids']);
    }
    if  (isset($metadata['export_timestamp'])) {
      $this->exportTimestamps[$uuid] = $metadata['export_timestamp'];
    }
  }

  public function reset(): void {
    $this->uuids = [];
    $this->exportTimestamps = [];
    $this->correctionRequired = [];
  }

  public function getExportTimestamp(string $uuid): ?int {
    return $this->exportTimestamps[$uuid] ?? NULL;
  }

  public function addUuid(string $entity_type, int $entity_id, string $uuid): void {
    $this->uuids[$entity_type][$entity_id] = $uuid;
  }

  public function getUuid(string $entity_type, int $entity_id): ?string {
    return $this->uuids[$entity_type][$entity_id] ?? NULL;
  }

  public function mergeUuids(array $uuids): ?string {
    return ArrayUtils::merge($uuids, $this->uuids, TRUE);
  }

  public function setCorrectionRequired(string $uuid, bool $value): void {
    $this->correctionRequired[$uuid] = $value;
  }

  public function isCorrectionRequired(string $uuid): bool {
    return $this->correctionRequired[$uuid] ?? TRUE;
  }
}

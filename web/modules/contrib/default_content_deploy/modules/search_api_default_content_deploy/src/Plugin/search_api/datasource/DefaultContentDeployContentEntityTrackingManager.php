<?php

declare(strict_types=1);

namespace Drupal\search_api_default_content_deploy\Plugin\search_api\datasource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntityTaskManager;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility;

/**
 * Provides hook implementations on behalf of the Content Entity datasource.
 *
 * @see \Drupal\search_api_default_content_deploy\Plugin\search_api\datasource\DefaultContentDeployContentEntity
 */
class DefaultContentDeployContentEntityTrackingManager extends ContentEntityTrackingManager {

  protected const DATASOURCE_BASE_ID = 'dcd_entity';

  /**
   * {@inheritdoc}
   */
  public static function formatItemId(string $entity_type, string|int $entity_id, string $langcode): string {
    return DefaultContentDeployContentEntity::formatItemId($entity_type, $entity_id, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function trackEntityChange(ContentEntityInterface $entity, bool $new = FALSE) {
    // Check if the entity is a content entity.
    if (!empty($entity->search_api_skip_tracking)) {
      return;
    }

    $indexes = $this->getIndexesForEntity($entity);
    if (!$indexes) {
      return;
    }

    $datasource_id = static::DATASOURCE_BASE_ID . ':' . $entity->getEntityTypeId();
    $default_translation = $entity->getUntranslated();
    $item_id = self::formatItemId($entity->getEntityTypeId(), $entity->id(), $entity->language()->getId());

    foreach ($indexes as $index) {
      $filtered_item_ids = static::filterValidItemIds($index, $datasource_id, [$item_id]);
      if ($new) {
        $index->trackItemsInserted($datasource_id, $filtered_item_ids);
      }
      else {
        $index->trackItemsUpdated($datasource_id, $filtered_item_ids);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityDelete(EntityInterface $entity) {
    // Check if the entity is a content entity.
    if (!($entity instanceof ContentEntityInterface)
      || !empty($entity->search_api_skip_tracking)) {
      return;
    }

    $indexes = $this->getIndexesForEntity($entity);
    if (!$indexes) {
      return;
    }

    $datasource_id = static::DATASOURCE_BASE_ID . ':' . $entity->getEntityTypeId();
    // Don't use formatItemId() in this case because that function could not
    // load the entity anymore to get the UUID.
    $item_id = $entity->id() . ':' . $entity->language()->getId() . ':' . $entity->uuid();

    foreach ($indexes as $index) {
      $index->trackItemsDeleted($datasource_id, [$item_id]);
    }
  }
}

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
  public static function formatItemId($entity_type, $entity_id, $langcode) {
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

  /**
   * {@inheritdoc}
   *
   * @todo Remove this function when
   *   https://www.drupal.org/project/search_api/issues/3471987 gets committed.
   */
  public function getIndexesForEntity(ContentEntityInterface $entity): array {
    // @todo This is called for every single entity insert, update or deletion
    //   on the whole site. Should maybe be cached?
    $datasource_id = static::DATASOURCE_BASE_ID . ':' . $entity->getEntityTypeId();
    $entity_bundle = $entity->bundle();
    $has_bundles = $entity->getEntityType()->hasKey('bundle');

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = [];
    try {
      $indexes = $this->entityTypeManager->getStorage('search_api_index')
        ->loadMultiple();
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException) {
      // Can't really happen, but play it safe to appease static code analysis.
    }

    foreach ($indexes as $index_id => $index) {
      // Filter out indexes that don't contain the datasource in question.
      if (!$index->isValidDatasource($datasource_id)) {
        unset($indexes[$index_id]);
      }
      elseif ($has_bundles) {
        // If the entity type supports bundles, we also have to filter out
        // indexes that exclude the entity's bundle.
        try {
          $config = $index->getDatasource($datasource_id)->getConfiguration();
        }
        catch (SearchApiException) {
          // Can't really happen, but play it safe to appease static code
          // analysis.
          unset($indexes[$index_id]);
          continue;
        }
        if (!Utility::matches($entity_bundle, $config['bundles'])) {
          unset($indexes[$index_id]);
        }
      }
    }

    return $indexes;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Remove this function when
   *   https://www.drupal.org/project/search_api/issues/3471987 gets committed.
   */
  public function indexUpdate(IndexInterface $index) {
    if (!$index->status()) {
      return;
    }
    /** @var \Drupal\search_api\IndexInterface $original */
    $original = $index->original ?? NULL;
    if (!$original || !$original->status()) {
      return;
    }

    foreach ($index->getDatasources() as $datasource_id => $datasource) {
      if ($datasource->getBaseId() != static::DATASOURCE_BASE_ID
        || !$original->isValidDatasource($datasource_id)) {
        continue;
      }
      $old_datasource = $original->getDatasource($datasource_id);
      $old_config = $old_datasource->getConfiguration();
      $new_config = $datasource->getConfiguration();

      if ($old_config != $new_config) {
        // Bundles and languages share the same structure, so changes can be
        // processed in a unified way.
        $tasks = [];
        $insert_task = ContentEntityTaskManager::INSERT_ITEMS_TASK_TYPE;
        $delete_task = ContentEntityTaskManager::DELETE_ITEMS_TASK_TYPE;
        $settings = [];
        $entity_type = $this->entityTypeManager
          ->getDefinition($datasource->getEntityTypeId());
        if ($entity_type->hasKey('bundle')) {
          $settings['bundles'] = $datasource->getBundles();
        }
        if ($entity_type->isTranslatable()) {
          $settings['languages'] = $this->languageManager->getLanguages();
        }

        // Determine which bundles/languages have been newly selected or
        // deselected and then assign them to the appropriate actions depending
        // on the current "default" setting.
        foreach ($settings as $setting => $all) {
          $old_selected = array_flip($old_config[$setting]['selected']);
          $new_selected = array_flip($new_config[$setting]['selected']);

          // First, check if the "default" setting changed and invert the checked
          // items for the old config, so the following comparison makes sense.
          if ($old_config[$setting]['default'] != $new_config[$setting]['default']) {
            $old_selected = array_diff_key($all, $old_selected);
          }

          $newly_selected = array_keys(array_diff_key($new_selected, $old_selected));
          $newly_unselected = array_keys(array_diff_key($old_selected, $new_selected));
          if ($new_config[$setting]['default']) {
            $tasks[$insert_task][$setting] = $newly_unselected;
            $tasks[$delete_task][$setting] = $newly_selected;
          }
          else {
            $tasks[$insert_task][$setting] = $newly_selected;
            $tasks[$delete_task][$setting] = $newly_unselected;
          }
        }

        // This will keep only those tasks where at least one of "bundles" or
        // "languages" is non-empty.
        $tasks = array_filter($tasks, 'array_filter');
        foreach ($tasks as $task => $data) {
          $data += [
            'datasource' => $datasource_id,
            'page' => 0,
          ];
          $this->taskManager->addTask($task, NULL, $index, $data);
        }

        // If we added any new tasks, set a batch for them. (If we aren't in a
        // form submission, this will just be ignored.)
        if ($tasks) {
          $this->taskManager->setTasksBatch([
            'index_id' => $index->id(),
            'type' => array_keys($tasks),
          ]);
        }
      }
    }
  }

}

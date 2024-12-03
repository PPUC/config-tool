<?php

namespace Drupal\search_api_default_content_deploy\Plugin\search_api\datasource;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntity;
use Drupal\search_api\Utility\Utility;

/**
 * Represents a datasource which exposes the content entities.
 *
 * @SearchApiDatasource(
 *   id = "dcd_entity",
 *   deriver = "Drupal\search_api_default_content_deploy\Plugin\search_api\datasource\DefaultContentDeployContentEntityDeriver"
 * )
 */
class DefaultContentDeployContentEntity extends ContentEntity {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    if ($this->isTranslatable()) {
      $form['languages']['default']['#options'] = [
        1 => $this->t('All except those selected'),
      ];

      $form['languages']['selected']['#disabled'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item) {
    return parent::getItemId($item) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function formatItemId(string $entity_type, string|int $entity_id, string $langcode): string {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = \Drupal::service('entity_type.manager')->getStorage($entity_type)->load($entity_id);
    // We only need to handle the default translations as all translations will
    // be embedded in the HAL export. But we also need to check if the
    // translation exists as Search API added entries to the tracker for all
    // installed languages in case of an entity deletion.
    if ($entity->hasTranslation($langcode) && $entity->getTranslation($langcode)->isDefaultTranslation()) {
      return $entity_id . ':' . $langcode . ':' . $entity->uuid();
    }

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids) {
    $item_ids_without_uuid = [];
    foreach ($ids as $item_id) {
      $item_ids_without_uuid[preg_replace('/:[^:]+$/', '', $item_id)] = $item_id;
    }

    $items = [];
    $items_without_uuid = parent::loadMultiple(array_keys($item_ids_without_uuid));
    foreach ($items_without_uuid as $item_id => $item) {
      $items[$item_ids_without_uuid[$item_id]] = $item;
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getPartialItemIds($page = NULL, array $bundles = NULL, array $languages = NULL) {
    $item_ids = parent::getPartialItemIds($page, $bundles, $languages);

    if (is_array($item_ids)) {
      return array_filter($item_ids, function ($item_id) {
        return $item_id !== '';
      });
    }

    return $item_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getAffectedItemsForEntityChange(EntityInterface $entity, array $foreign_entity_relationship_map, EntityInterface $original_entity = NULL): array {
    $item_ids = parent::getAffectedItemsForEntityChange($entity, $foreign_entity_relationship_map, $original_entity);

    if (is_array($item_ids)) {
      return array_filter($item_ids, function ($item_id) {
        return $item_id !== '';
      });
    }

    return $item_ids;
  }

}

<?php

namespace Drupal\default_content_deploy\Normalizer;

use Drupal\hal\Normalizer\EntityReferenceItemNormalizer;

/**
 * Converts File items, including display and description values.
 */
class FileItemNormalizer extends EntityReferenceItemNormalizer {

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [
      'Drupal\file\Plugin\Field\FieldType\FileItem' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {
    $value = parent::constructValue($data, $context);
    if ($value) {
      // Copy across any additional field-specific properties.
      $value += $data;
      unset($value['_links'], $value['uuid']);
    }

    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($field_item, $format = NULL, array $context = []) : float|array|int|bool|\ArrayObject|string|null {
    /** @var \Drupal\file\Plugin\Field\FieldType\FileItem $field_item */

    $data = parent::normalize($field_item, $format, $context);

    // Copied from parent implementation.
    $field_name = $field_item->getParent()->getName();
    $entity = $field_item->getEntity();
    $field_uri = $this->linkManager->getRelationUri($entity->getEntityTypeId(), $entity->bundle(), $field_name, $context);

    // Add any field-specific data.
    $data['_embedded'][$field_uri][0] += $field_item->getValue();
    unset($data['_embedded'][$field_uri][0]['target_id']);

    return $data;
  }

}

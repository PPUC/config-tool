<?php

namespace Drupal\default_content_deploy\Normalizer;

/**
 * Converts the Drupal field structure to HAL array structure.
 */
class PasswordItemNormalizer extends ConfigurableFieldItemNormalizer {

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [
      'Drupal\Core\Field\Plugin\Field\FieldType\PasswordItem' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($field_items, $format = NULL, array $context = []): array|string|int|float|bool|\ArrayObject|NULL {
    if ($context['default_content_deploy'] ?? FALSE) {
      $normalized = parent::normalize($field_items, $format, $context);
      foreach ($normalized as $filed_name => &$list_items) {
        foreach ($list_items as &$item) {
          $item['pre_hashed'] = TRUE;
          unset($item['existing']);
        }
      }
      return $normalized;
    }

    return NULL;
  }

}

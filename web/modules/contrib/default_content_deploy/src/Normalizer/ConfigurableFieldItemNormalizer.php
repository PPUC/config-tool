<?php

namespace Drupal\default_content_deploy\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Drupal\default_content_deploy\Form\SettingsForm;
use Drupal\hal\Normalizer\FieldItemNormalizer;

/**
 * Converts the Drupal field item object structure to HAL array structure.
 */
class ConfigurableFieldItemNormalizer extends FieldItemNormalizer {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs a ContentEntityNormalizer object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config) {
    $this->config = $config;
  }

  /**
   * Normalizes field values for an item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $field_item
   *   The field item instance.
   * @param string|null $format
   *   The normalization format.
   * @param array $context
   *   The context passed into the normalizer.
   *
   * @return array
   *   An array of field item values, keyed by property name.
   */
  protected function normalizedFieldValues(FieldItemInterface $field_item, $format, array $context) {
    $config = $this->config->get(SettingsForm::CONFIG);
    if ($config->get('skip_processed_values') ?? FALSE) {
      $normalized = [];
      // We normalize each individual property, so each can do their own
      // casting, if needed.
      /** @var \Drupal\Core\TypedData\TypedDataInterface $property */
      $field_properties = !empty($field_item->getProperties(TRUE))
        ? TypedDataInternalPropertiesHelper::getNonInternalProperties($field_item)
        : $field_item->getValue();
      foreach ($field_properties as $property_name => $property) {
        if ($property->getName() !== 'processed') {
          $normalized[$property_name] = $this->serializer->normalize($property, $format, $context);
        }
      }

      if (isset($context['langcode'])) {
        $normalized['lang'] = $context['langcode'];
      }

      return $normalized;
    }

    return parent::normalizedFieldValues($field_item, $format, $context);
  }

}

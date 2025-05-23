<?php

namespace Drupal\range\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Plugin implementation of the 'range_integer' field type.
 *
 * @FieldType(
 *   id = "range_integer",
 *   label = @Translation("Range (integer)"),
 *   description = {
 *     @Translation("Numeric range without decimals"),
 *     @Translation("For example, 14-60"),
 *   },
 *   category = "range",
 *   weight = -50,
 *   default_widget = "range",
 *   default_formatter = "range_integer",
 *   constraints = {"RangeBothValuesRequired" = {}, "RangeFromGreaterTo" = {}}
 * )
 */
class RangeIntegerItem extends RangeItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    return static::propertyDefinitionsByType('integer');
  }

  /**
   * {@inheritdoc}
   */
  public static function getColumnSpecification(FieldStorageDefinitionInterface $field_definition) {
    return [
      'type' => 'int',
    ];
  }

}

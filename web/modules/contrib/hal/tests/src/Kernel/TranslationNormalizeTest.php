<?php

namespace Drupal\Tests\hal\Kernel;

use Drupal\entity_test\Entity\EntityTestMulChanged;

/**
 * Test normalizing and denormalizing an entity with a translation.
 *
 * @group hal
 */
class TranslationNormalizeTest extends NormalizerTestBase {

  /**
   * {@inheritdoc}
   */
  protected $entityClass = 'Drupal\entity_test\Entity\EntityTestMulChanged';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test_mul_changed');
  }

  /**
   * Tests normalizing and denormalizing an entity.
   */
  public function testTranslationNormalize() {
    $target_entity = EntityTestMulChanged::create((['langcode' => 'en', 'field_test_entity_reference' => NULL]));
    $target_entity->save();

    $target_entity->addTranslation('de', $target_entity->toArray());

    $this->assertEquals(1, count($target_entity->changed));
    $this->assertEquals(1, count($target_entity->getTranslation('en')->changed));
    $this->assertEquals(1, count($target_entity->getTranslation('de')->changed));

    $data = $this->serializer->normalize($target_entity, 'hal_json');
    $denormalized_entity = $this->serializer->denormalize($data, $this->entityClass, 'hal_json');

    $this->assertEquals(1, count($denormalized_entity->changed));
    $this->assertEquals(1, count($target_entity->getTranslation('en')->changed));
    $this->assertEquals(1, count($denormalized_entity->getTranslation('de')->changed));
  }

}

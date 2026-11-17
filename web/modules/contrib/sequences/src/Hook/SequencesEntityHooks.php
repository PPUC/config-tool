<?php

namespace Drupal\sequences\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sequences\Generator;

/**
 * Entity related hooks.
 */
final class SequencesEntityHooks {

  public function __construct(
    private readonly Generator $generator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Implements hook_entity_presave().
   *
   * Use different offsets for entity IDs.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @throws \Exception
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    // Only process entities without an ID.
    if ($entity->id()) {
      return;
    }

    $entity_type_id = $entity->getEntityTypeId();
    $config = $this->configFactory->get('sequences.settings');
    $entity_offsets = $config->get('entity_offsets') ?? [];

    // Check if this entity type has an offset configured.
    if (!isset($entity_offsets[$entity_type_id])) {
      return;
    }

    $offset = (int) $entity_offsets[$entity_type_id];
    $name = 'sequences_' . $entity_type_id;

    $next_id = $this->generator->nextId($name) + $offset;
    assert($next_id > $offset);
    // @phpstan-ignore-next-line
    $entity->set($entity->getEntityType()->getKey('id'), $next_id);
  }

}

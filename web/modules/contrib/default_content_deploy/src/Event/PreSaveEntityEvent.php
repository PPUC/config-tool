<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines a pre save entity event.
 */
class PreSaveEntityEvent extends IndexAwareEvent {

  /**
   * The entity to be created on import.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $entity;

  /**
   * The source or raw data.
   *
   * @var array
   */
  protected $data;

  /**
   * Constructors.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be created on import.
   * @param array $data
   *   The source or raw data.
   */
  public function __construct(ContentEntityInterface $entity, array $data) {
    $this->entity = $entity;
    $this->data = $data;
  }

  /**
   * Return entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity to be created on import.
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * Returns source data.
   *
   * @return array
   *   The source or raw data.
   */
  public function getData() {
    return $this->data;
  }

}

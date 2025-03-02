<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines a pre save entity event.
 */
abstract class SaveEntityEvent extends Event {

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
   * Indicates if it is the entity save within the correction phase.
   *
   * @var bool
   */
  protected $correction;

  /**
   * The context.
   *
   * @var array
   */
  protected $context;

  /**
   * Constructors.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be created on import.
   * @param array $data
   *   The source or raw data.
   * @param bool $correction
   *   Indicates if it is the entity save within the correction phase.
   * @param array $context
   *   The context.
   */
  public function __construct(ContentEntityInterface $entity, array $data, bool $correction, array $context) {
    $this->entity = $entity;
    $this->data = $data;
    $this->correction = $correction;
    $this->context = $context;
  }

  /**
   * Return entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity to be created on import.
   */
  public function getEntity(): ContentEntityInterface {
    return $this->entity;
  }

  /**
   * Returns source data.
   *
   * @return array
   *   The source or raw data.
   */
  public function getData(): array {
    return $this->data;
  }

  /**
   * Indicates if it is the entity save within the correction phase.
   *
   * @return bool
   *   True if correction phase is running.
   */
  public function isCorrection(): bool {
    return $this->correction;
  }

  /**
   * Get the context.
   *
   * @return array
   *   The context.
   */
  public function getContext(): array {
    return $this->context;
  }

}

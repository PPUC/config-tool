<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Event that triggers before an entity is serialized.
 *
 * This event allows modifications to an entity before it is serialized,
 * providing customization options based on mode and folder context.
 */
class PreSerializeEvent extends IndexAwareEvent {

  /**
   * The entity to be serialized.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|null
   */
  protected ?ContentEntityInterface $entity = NULL;

  /**
   * The mode of serialization.
   *
   * @var string
   */
  protected string $mode;

  /**
   * The folder where the serialized data will be stored.
   *
   * @var string
   */
  protected string $folder;

  /**
   * Constructs a PreSerializeEvent object.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be serialized.
   * @param string $mode
   *   The serialization mode.
   * @param string $folder
   *   The folder where the serialized data will be stored.
   */
  public function __construct(ContentEntityInterface $entity, string $mode, string $folder) {
    $this->entity = $entity;
    $this->mode = $mode;
    $this->folder = $folder;
  }

  /**
   * Gets the entity to be serialized.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity or NULL if not set.
   */
  public function getEntity(): ?ContentEntityInterface {
    return $this->entity;
  }

  /**
   * Sets the entity for serialization.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The entity to set, or NULL to unset.
   */
  public function setEntity(?ContentEntityInterface $entity = NULL): void {
    $this->entity = $entity;
  }

  /**
   * Unsets the entity.
   */
  public function unsetEntity(): void {
    $this->setEntity();
  }

  /**
   * Gets the serialization mode.
   *
   * @return string
   *   The serialization mode.
   */
  public function getMode(): string {
    return $this->mode;
  }

  /**
   * Gets the folder where the serialized data will be stored.
   *
   * @return string
   *   The folder name.
   */
  public function getFolder(): string {
    return $this->folder;
  }

}

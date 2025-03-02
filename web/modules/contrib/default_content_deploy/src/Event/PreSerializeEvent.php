<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * PreSerializeEvent.
 */
class PreSerializeEvent extends IndexAwareEvent {

  protected ?ContentEntityInterface $entity = NULL;
  protected string $mode;
  protected string $folder;

  /**
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param string $mode
   */
  public function __construct(ContentEntityInterface $entity, string $mode, string $folder) {
    $this->entity = $entity;
    $this->mode = $mode;
    $this->folder = $folder;
  }

  /**
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   */
  public function getEntity(): ?ContentEntityInterface {
    return $this->entity;
  }

  /**
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   */
  public function setEntity(?ContentEntityInterface $entity = NULL): void {
    $this->entity = $entity;
  }

  /**
   *
   */
  public function unsetEntity(): void {
    $this->setEntity();
  }

  /**
   *
   */
  public function getMode(): string {
    return $this->mode;
  }

  /**
   *
   */
  public function getFolder(): string {
    return $this->folder;
  }

}

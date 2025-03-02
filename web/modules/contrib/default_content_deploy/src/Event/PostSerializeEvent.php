<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * PostSerializeEvent.
 */
class PostSerializeEvent extends IndexAwareEvent {

  protected ?ContentEntityInterface $entity = NULL;
  protected string $content;
  protected string $mode;
  protected string $folder;

  public function __construct(ContentEntityInterface $entity, string $content, string $mode, string $folder) {
    $this->entity = $entity;
    $this->content = $content;
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
   *
   */
  public function getContent(): string {
    return $this->content;
  }

  /**
   *
   */
  public function setContent(string $content): void {
    $this->content = $content;
  }

  /**
   *
   */
  public function getContentDecoded(): array {
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');
    // Do not decode hal_json here!
    return $serializer->decode($this->content, 'json');
  }

  /**
   *
   */
  public function setContentDecoded(array $content): void {
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    $serializer = \Drupal::service('serializer');
    // Do not encode hal_json here!
    $this->content = $serializer->serialize($content, 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
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

<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Event triggered after an entity is serialized.
 *
 * This event allows modifications to serialized content,
 * providing customization options based on mode and folder context.
 */
class PostSerializeEvent extends IndexAwareEvent {

  /**
   * The entity that was serialized.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|null
   */
  protected ?ContentEntityInterface $entity = NULL;

  /**
   * The serialized content.
   *
   * @var string
   */
  protected string $content;

  /**
   * The serialization mode.
   *
   * @var string
   */
  protected string $mode;

  /**
   * The folder where the serialized data is stored.
   *
   * @var string
   */
  protected string $folder;

  /**
   * Constructs a PostSerializeEvent object.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity that was serialized.
   * @param string $content
   *   The serialized content.
   * @param string $mode
   *   The serialization mode.
   * @param string $folder
   *   The folder where the serialized data is stored.
   */
  public function __construct(ContentEntityInterface $entity, string $content, string $mode, string $folder) {
    $this->entity = $entity;
    $this->content = $content;
    $this->mode = $mode;
    $this->folder = $folder;
  }

  /**
   * Gets the entity that was serialized.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity or NULL if not set.
   */
  public function getEntity(): ?ContentEntityInterface {
    return $this->entity;
  }

  /**
   * Gets the serialized content.
   *
   * @return string
   *   The serialized content.
   */
  public function getContent(): string {
    return $this->content;
  }

  /**
   * Sets the serialized content.
   *
   * @param string $content
   *   The serialized content.
   */
  public function setContent(string $content): void {
    $this->content = $content;
  }

  /**
   * Gets the decoded content.
   *
   * @return array
   *   The decoded content array.
   */
  public function getContentDecoded(): array {
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    // @phpstan-ignore-next-line
    $serializer = \Drupal::service('serializer');
    // Do not decode hal_json here!
    return $serializer->decode($this->content, 'json');
  }

  /**
   * Sets the decoded content.
   *
   * @param array $content
   *   The content array to be encoded into JSON.
   */
  public function setContentDecoded(array $content): void {
    /** @var \Symfony\Component\Serializer\Serializer $serializer */
    // @phpstan-ignore-next-line
    $serializer = \Drupal::service('serializer');
    // Do not encode hal_json here!
    $this->content = $serializer->serialize($content, 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
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
   * Gets the folder where the serialized data is stored.
   *
   * @return string
   *   The folder name.
   */
  public function getFolder(): string {
    return $this->folder;
  }

}

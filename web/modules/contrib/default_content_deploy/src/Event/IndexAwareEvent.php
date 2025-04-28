<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Provides an event that allows tracking an index ID in event listeners.
 */
abstract class IndexAwareEvent extends Event implements IndexAwareEventInterface {

  /**
   * The index identifier.
   *
   * @var string
   */
  protected string $indexId = '';

  /**
   * Gets the index identifier.
   *
   * @return string
   *   The index identifier.
   */
  public function getIndexId(): string {
    return $this->indexId;
  }

  /**
   * Sets the index identifier.
   *
   * @param string $index_id
   *   The index identifier to set.
   */
  public function setIndexId(string $index_id): void {
    $this->indexId = $index_id;
  }

}

<?php

namespace Drupal\default_content_deploy\Event;

/**
 * Defines an interface for events that are aware of an index ID.
 */
interface IndexAwareEventInterface {

  /**
   * Gets the index ID associated with the event.
   *
   * @return string
   *   The index ID.
   */
  public function getIndexId(): string;

  /**
   * Sets the index ID for the event.
   *
   * @param string $index_id
   *   The index ID to set.
   */
  public function setIndexId(string $index_id): void;

}

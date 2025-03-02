<?php

namespace Drupal\default_content_deploy\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * IndexAwareEvent.
 */
abstract class IndexAwareEvent extends Event implements IndexAwareEventInterface {

  protected string $indexId = '';

  /**
   *
   */
  public function getIndexId(): string {
    return $this->indexId;
  }

  /**
   *
   */
  public function setIndexId(string $index_id): void {
    $this->indexId = $index_id;
  }

}

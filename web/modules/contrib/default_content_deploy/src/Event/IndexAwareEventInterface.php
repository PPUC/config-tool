<?php

namespace Drupal\default_content_deploy\Event;

/**
 *
 */
interface IndexAwareEventInterface {

  /**
   *
   */
  public function getIndexId(): string;

  /**
   *
   */
  public function setIndexId(string $index_id): void;

}

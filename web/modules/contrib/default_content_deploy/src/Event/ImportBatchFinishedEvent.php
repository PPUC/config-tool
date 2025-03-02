<?php

namespace Drupal\default_content_deploy\Event;

/**
 * ImportBatchFinishedEvent.
 */
class ImportBatchFinishedEvent extends IndexAwareEvent {

  protected bool $success;
  protected array $results;

  /**
   * @param bool $success
   * @param array $results
   */
  public function __construct(bool $success, array $results) {
    $this->success = $success;
    $this->results = $results;
  }

  /**
   *
   */
  public function getSuccess(): bool {
    return $this->success;
  }

  /**
   *
   */
  public function getResults(): array {
    return $this->results;
  }

}

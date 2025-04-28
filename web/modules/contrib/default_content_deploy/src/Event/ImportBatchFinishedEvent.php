<?php

namespace Drupal\default_content_deploy\Event;

/**
 * Event triggered when an import batch process finishes.
 */
class ImportBatchFinishedEvent extends IndexAwareEvent {

  /**
   * Indicates whether the import batch was successful.
   *
   * @var bool
   */
  protected bool $success;

  /**
   * Stores the results of the import batch process.
   *
   * @var array
   */
  protected array $results;

  /**
   * Constructs an ImportBatchFinishedEvent object.
   *
   * @param bool $success
   *   TRUE if the import batch completed successfully, FALSE otherwise.
   * @param array $results
   *   An array containing the results of the import batch process.
   */
  public function __construct(bool $success, array $results) {
    $this->success = $success;
    $this->results = $results;
  }

  /**
   * Gets the success status of the import batch.
   *
   * @return bool
   *   TRUE if the import batch was successful, FALSE otherwise.
   */
  public function getSuccess(): bool {
    return $this->success;
  }

  /**
   * Gets the results of the import batch process.
   *
   * @return array
   *   The results of the import batch.
   */
  public function getResults(): array {
    return $this->results;
  }

}

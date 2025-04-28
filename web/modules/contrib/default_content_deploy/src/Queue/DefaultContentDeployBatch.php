<?php

namespace Drupal\default_content_deploy\Queue;

use Drupal\Core\Queue\Batch;

/**
 * Custom batch process for default content deployment.
 *
 * Handles batch queue processing and garbage collection
 * for deployment-related tasks.
 */
class DefaultContentDeployBatch extends Batch {

  /**
   * The time-to-live (TTL) for queue items in seconds.
   *
   * @var int
   */
  protected int $ttl = 14400;

  /**
   * Sets the TTL for batch queue items.
   *
   * @param int $ttl
   *   The TTL value in seconds.
   */
  public function setTtl(int $ttl): void {
    $this->ttl = $ttl;
  }

  /**
   * Cleans up expired queue items.
   *
   * {@inheritdoc}
   */
  public function garbageCollection(): void {
    try {
      // Clean up the queue for failed batches.
      $this->connection->delete(static::TABLE_NAME)
        // @phpstan-ignore-next-line
        ->condition('created', \Drupal::time()->getRequestTime() - $this->ttl, '<')
        ->condition('name', 'default_content_deploy:%', 'LIKE')
        ->execute();
    }
    catch (\Exception $e) {
      $this->catchException($e);
    }
  }

}

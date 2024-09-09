<?php

namespace Drupal\default_content_deploy\Queue;

use Drupal\Core\Queue\Batch;

/**
 * {@inheritdoc}
 */
class DefaultContentDeployBatch extends Batch {

  // A dedicate table causes some issues with drush.	
  // const TABLE_NAME = 'default_content_deploy_queue';

  protected int $ttl = 14400;

  public function setTtl(int $ttl) {
    $this->ttl = $ttl;
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    try {
      // Clean up the queue for failed batches.
      $this->connection->delete(static::TABLE_NAME)
        ->condition('created', \Drupal::time()->getRequestTime() - $this->ttl, '<')
        ->condition('name', 'default_content_deploy:%', 'LIKE')
        ->execute();
    }
    catch (\Exception $e) {
      $this->catchException($e);
    }
  }

}

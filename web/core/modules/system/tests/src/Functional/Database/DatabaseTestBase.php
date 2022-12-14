<?php

namespace Drupal\Tests\system\Functional\Database;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\DatabaseTestSchemaDataTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Base class for databases database tests.
 */
abstract class DatabaseTestBase extends BrowserTestBase {

  use DatabaseTestSchemaDataTrait;

  /**
   * The database connection for testing.
   */
  protected $connection;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['database_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->connection = Database::getConnection();
    $this->addSampleData();
  }

}

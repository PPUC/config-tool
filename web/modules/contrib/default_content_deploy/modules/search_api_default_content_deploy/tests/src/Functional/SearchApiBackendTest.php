<?php

namespace Drupal\Tests\default_content_deploy\Functional;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * Test Search API integration.
 *
 * @group default_content_deploy
 */
class SearchApiBackendTest extends BrowserTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'search_api_default_content_deploy',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * Test server form.
   */
  public function testServerForm() {
    // Now let's log in with a user that can import content.
    $this->drupalLogin($this->createUser([
      'default content deploy import',
      'administer search_api',
    ]));
    $this->drupalGet('admin/config/search/search-api/add-server');
    $this->assertSession()->pageTextContains('Leverage the Search API infrastructure to track and incrementally export content. ');
  }

}

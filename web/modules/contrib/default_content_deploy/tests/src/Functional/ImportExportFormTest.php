<?php

namespace Drupal\Tests\default_content_deploy\Functional;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * Test that we can import content from that form.
 *
 * @group default_content_deploy
 */
class ImportExportFormTest extends BrowserTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'default_content_deploy',
    'node',
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
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();
    $this->config('default_content_deploy.settings')
      ->set('content_directory', 'public://content')
      ->save();
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);
    // We need this for both import and export.
    $this->fileSystem = $this->container->get('file_system');
    $this->fileSystem->mkdir('public://content/node', NULL, TRUE);
  }

  /**
   * Test that we can actually import known content from that form.
   */
  public function testImportForm() {
    // First let's just copy the actual assets we have and place it into the
    // public folder.
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $all_nodes = $node_storage->loadByProperties([]);
    self::assertCount(0, $all_nodes);
    $our_folder = $this->container->get('extension.list.module')->getPath('default_content_deploy');
    $this->fileSystem->copy(sprintf('%s/tests/assets/80403b48-08ac-44de-bf75-f3bae15db2c0.json', $our_folder), 'public://content/node/80403b48-08ac-44de-bf75-f3bae15db2c0.json');
    // Now let's log in with a user that can import content.
    $this->drupalLogin($this->createUser([
      'default content deploy import',
    ]));
    $this->drupalGet('admin/config/development/dcd/import');
    $this->submitForm([], 'Import content');
    $node_storage->resetCache();
    $all_nodes = $node_storage->loadByProperties([]);
    self::assertCount(1, $all_nodes);
    /** @var \Drupal\node\NodeInterface $node */
    $node = reset($all_nodes);
    self::assertSame(1, (int) $node->id(), 'nid has been corrected to 1.');
  }

  /**
   * Test that content we create can be exported with the form.
   */
  public function testExportForm() {
    $files = $this->fileSystem->scanDirectory('public://content/node', '/\.json$/');
    // We should start with 0 files in there.
    self::assertCount(0, $files);
    // Let's create a node we want to export.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Three of a Kind',
      'body' => [
        'value' => 'S06E20',
      ],
    ]);
    $node->save();
    // Now log in and export this node.
    $this->drupalLogin($this->createUser([
      'default content deploy export',
    ]));
    $this->drupalGet('admin/config/development/dcd/export');
    $this->submitForm([
      'entity_type' => 'node',
    ], 'Export content');
    // Let's make sure it's in the folder we expect.
    $files = $this->fileSystem->scanDirectory('public://content/node', '/\.json$/');
    self::assertCount(1, $files);
    // Let's also make sure the file is what we expect.
    $file = reset($files);
    $file_contents = file_get_contents($file->uri);
    $json = json_decode($file_contents, TRUE);
    self::assertEquals($node->label(), $json['title'][0]['value']);
  }

  /**
   * A test that first exports the content. Then imports it.
   */
  public function testExportImport() {
    // Let's create a node we want to export.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Within',
      'body' => [
        'value' => 'S08E01',
      ],
    ]);
    $node->save();
    // Now log in and export this node.
    $this->drupalLogin($this->createUser([
      'default content deploy export',
      'default content deploy import',
    ]));
    $this->drupalGet('admin/config/development/dcd/export');
    $this->submitForm([
      'entity_type' => 'node',
    ], 'Export content');
    // Now we should have a file in there. So let's delete the nodes we have,
    // and reimport.
    $node->delete();
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $all_nodes = $node_storage->loadByProperties([]);
    self::assertCount(0, $all_nodes);
    $this->drupalGet('admin/config/development/dcd/import');
    $this->submitForm([], 'Import content');
    $node_storage->resetCache();
    $all_nodes = $node_storage->loadByProperties([]);
    self::assertCount(1, $all_nodes);
    /** @var \Drupal\node\NodeInterface $node */
    $node = reset($all_nodes);
    // The node id is an auto-increment. The ID must have been increased during
    // import.
    self::assertSame(2, (int) $node->id(), 'nid has been incremented to 2.');
  }

}

<?php

declare(strict_types = 1);

namespace Drupal\Tests\default_content\Functional;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;
use Drush\TestTraits\DrushTestTrait;

/**
 * Tests 'Default content' module Drush commands.
 *
 * @group default_content
 */
class DefaultContentDrushTest extends BrowserTestBase {

  use ContentTypeCreationTrait;
  use DrushTestTrait;
  use TaxonomyTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'node',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createContentType(['type' => 'page']);

    // Enable the test module. It depends on the 'page' node type so we cannot
    // put it in the static::$modules array.
    $this->container->get('module_installer')->install(['default_content_test_yaml_updated']);

    // Create pre-existing content entities. This is used to check if the
    // 'default_content:import' command successfully ignores or updates
    // existing content.
    $nodes_to_create = [
      [
        'title' => 'Existing page',
        'type' => 'page',
        'body' => 'This is an existing page.',
        'uuid' => '65c412a3-b83f-4efb-8a05-5a6ecea10ad4',
      ],
      [
        'title' => 'Existing page 2',
        'type' => 'page',
        'body' => 'This is another existing page.',
        'uuid' => '78c412a3-b83f-4efb-8a05-5a6ecea10aee',
      ],
    ];
    foreach ($nodes_to_create as $node_to_create) {
      $this->drupalCreateNode($node_to_create);
    }

    $files_to_create = [
      [
        'filename' => 'test-file.txt',
        'uri' => 'public://test-file.txt',
        'uuid' => '806afcf6-05bf-4178-92dd-ae9445285770',
      ],
      [
        'filename' => 'test-file2.txt',
        'uri' => 'public://existing_file2.txt',
        'uuid' => '806afcf6-05bf-4178-92dd-ae9445285771',
      ],
    ];
    foreach ($files_to_create as $file_to_create) {
      $this->createFile($file_to_create);
    }

    // The 'tags' vocabulary has been created when we enabled the
    // 'default_content_test_yaml_updated' module.
    $tags_vocabulary = $this->container->get('entity_type.manager')->getStorage('taxonomy_vocabulary')->load('tags');

    // Create a pre-existing taxonomy term.
    $taxonomy_term_to_create = [
      'name' => 'A tag',
      'vid' => $tags_vocabulary->id(),
      'description' => '',
      'uuid' => '550f86ad-aa11-4047-953f-636d42889f85',
    ];
    $this->createTerm($tags_vocabulary, $taxonomy_term_to_create);
  }

  /**
   * Tests the 'default-content:import' command.
   *
   * @see \Drupal\default_content\Commands\DefaultContentCommands::import()
   *
   * @dataProvider importTestDataProvider
   */
  public function testImport(bool $pass_module_list): void {
    // Before we update the content, verify that the content has the original
    // data.
    $this->assertFieldValues($this->getOriginalFieldValues());

    // Enable the default_content module. This has not been enabled earlier in
    // the test setup because we need to test the 'default_content:import'
    // command using the 'default_content_test_yaml_updated' module. If the
    // default_content module was already enabled, it would try to import the
    // default content automatically, and we want to test doing this manually.
    $this->container->get('module_installer')->install(['default_content']);

    // At this point, the content should still be in their original state.
    $this->assertFieldValues($this->getOriginalFieldValues());

    // Run the import without allowing updates.
    $args = $pass_module_list ? ['default_content_test_yaml_updated'] : [];
    $this->drush('default-content:import', $args);
    $this->assertStringContainsString('1 entity imported from default_content_test_yaml_updated', $this->getErrorOutputRaw());

    // Check that entities were not updated.
    $this->assertFieldValues($this->getOriginalFieldValues());
    // Check that new entities were imported.
    $this->assertSame('Additional node', \Drupal::service('entity.repository')->loadEntityByUuid('node', '7a8563a8-15c9-4f60-9ebc-630b9562672c')->label());

    // Run again the import but allow updates.
    $this->drush('default-content:import', $args, ['update' => NULL]);
    $this->assertStringContainsString('6 entities imported from default_content_test_yaml_updated', $this->getErrorOutputRaw());

    // Check that entities were updated.
    $this->assertFieldValues($this->getUpdatedFieldValues());
  }

  /**
   * Data provider for ::testImport().
   *
   * Provides test cases to test the import command with and without passing
   * the module list as an argument.
   *
   * @return array
   *   An array of test cases.
   */
  public function importTestDataProvider(): array {
    return [[TRUE], [FALSE]];
  }

  /**
   * Asserts that a list of entities have expected field values.
   *
   * @param array $expected
   *   An associative array where the keys are entity type IDs and values are
   *   associative arrays keyed by entity UUIDs and having the expected labels
   *   as values.
   */
  protected function assertFieldValues(array $expected): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    /** @var \Drupal\Core\Entity\EntityRepositoryInterface $repository */
    $repository = \Drupal::service('entity.repository');
    foreach ($expected as $entity_type_id => $uuids) {
      // Need to get fresh copies of the entities.
      $entity_type_manager->getStorage($entity_type_id)->resetCache();
      foreach ($uuids as $uuid => $fields) {
        $entity = $repository->loadEntityByUuid($entity_type_id, $uuid);
        foreach ($fields as $field_name => $expected_field_value) {
          $this->assertSame($expected_field_value, $entity->get($field_name)->value, "Entity $entity_type_id:$uuid has the expected value for field $field_name.");
        }
      }
    }
  }

  /**
   * Returns the original field values of entities to be imported.
   *
   * This returns a curated list of test field values of default content in the
   * `default_content_test_yaml` module.
   *
   * @return string[][][]
   *   An associative array where the keys are entity type IDs and values are
   *   associative arrays keyed by entity UUIDs. The values are associative
   *   arrays keyed by field names and having the original field values as
   *   values.
   */
  protected function getOriginalFieldValues(): array {
    return [
      'file' => [
        '806afcf6-05bf-4178-92dd-ae9445285770' => [
          'filename' => 'test-file.txt',
          'uri' => 'public://test-file.txt',
        ],
        '806afcf6-05bf-4178-92dd-ae9445285771' => [
          'filename' => 'test-file2.txt',
          'uri' => 'public://existing_file2.txt',
        ],
      ],
      'node' => [
        '65c412a3-b83f-4efb-8a05-5a6ecea10ad4' => [
          'title' => 'Existing page',
          'body' => 'This is an existing page.',
        ],
        '78c412a3-b83f-4efb-8a05-5a6ecea10aee' => [
          'title' => 'Existing page 2',
          'body' => 'This is another existing page.',
        ],
      ],
      'taxonomy_term' => [
        '550f86ad-aa11-4047-953f-636d42889f85' => [
          'name' => 'A tag',
          'description' => NULL,
        ],
      ],
    ];
  }

  /**
   * Returns the updated field values of entities to be imported.
   *
   * This returns a curated list of test field values of default content in the
   * `default_content_test_yaml_updated` module.
   *
   * @return string[][][]
   *   Same as ::getOriginalFieldValues() but with updated field values.
   */
  protected function getUpdatedFieldValues(): array {
    return [
      'file' => [
        '806afcf6-05bf-4178-92dd-ae9445285770' => [
          'filename' => 'test-file.txt',
          'uri' => 'public://test-document.txt',
        ],
        '806afcf6-05bf-4178-92dd-ae9445285771' => [
          'filename' => 'test-file2.txt',
          'uri' => 'public://example/test-file2.txt',
        ],
      ],
      'node' => [
        '65c412a3-b83f-4efb-8a05-5a6ecea10ad4' => [
          'title' => 'Updated node',
          'body' => 'Crikey it changed!',
        ],
        '78c412a3-b83f-4efb-8a05-5a6ecea10aee' => [
          'title' => 'Updated node owned by user that does not exist',
          'body' => NULL,
        ],
      ],
      'taxonomy_term' => [
        '550f86ad-aa11-4047-953f-636d42889f85' => [
          'name' => 'Another tag',
          'description' => 'An actual description',
        ],
      ],
    ];
  }

  /**
   * Creates and saves a test file.
   *
   * @param array $values
   *   An array of values to set, keyed by property name.
   *
   * @return \Drupal\file\FileInterface
   *   A file entity.
   */
  protected function createFile(array $values): FileInterface {
    // Add defaults for missing properties.
    $values += [
      'uid' => 1,
      'filename' => 'default_content_test_file.txt',
      'uri' => 'public://default_content_test_file.txt',
      'filemime' => 'text/plain',
      'created' => 1,
      'changed' => 1,
    ];

    $file = File::create($values);
    $file->setPermanent();

    file_put_contents($file->getFileUri(), 'hello world');

    $file->save();

    return $file;
  }

}

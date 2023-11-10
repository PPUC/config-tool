<?php

declare(strict_types = 1);

namespace Drupal\Tests\default_content\Functional;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests that we can control whether existing content is updated on import.
 *
 * @coversDefaultClass \Drupal\default_content\Importer
 * @group default_content
 */
class DefaultContentImportExistingContentTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use TaxonomyTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'default_content',
    'field',
    'file',
    'filter',
    'node',
    'system',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['field', 'file', 'filter', 'node', 'system', 'taxonomy']);

    // Create the root user since this is used as the default owner for imported
    // content.
    $this->createUser([], 'root', FALSE, ['uid' => 1]);

    $this->createContentType(['type' => 'page']);

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
      $this->createNode($node_to_create);
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

    $tags_vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
    $tags_vocabulary->save();

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
   * Tests that existing content is only updated if $update_existing is TRUE.
   *
   * @covers ::importContent
   * @dataProvider importingExistingContentDataProvider
   */
  public function testImportingExistingContent(bool $update_existing): void {
    $this->container->get('default_content.importer')->importContent('default_content_test_yaml', $update_existing);

    $expected_values = $update_existing ? $this->getUpdatedFieldValues() : $this->getOriginalFieldValues();
    $this->assertFieldValues($expected_values);
  }

  /**
   * Data provider for ::testImportingExistingContent().
   *
   * @return array
   *   An array of test data for testing both states of the $update_existing
   *   parameter.
   */
  public function importingExistingContentDataProvider(): array {
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
          // Since a file already exists at that location, the updated file has
          // automatically been suffixed with '_0'.
          'uri' => 'public://test-file_0.txt',
        ],
        '806afcf6-05bf-4178-92dd-ae9445285771' => [
          'filename' => 'test-file1.txt',
          'uri' => 'public://example/test-file1.txt',
        ],
      ],
      'node' => [
        '65c412a3-b83f-4efb-8a05-5a6ecea10ad4' => [
          'title' => 'Imported node',
          'body' => 'Crikey it works!',
        ],
        '78c412a3-b83f-4efb-8a05-5a6ecea10aee' => [
          'title' => 'Imported node with owned by user that does not exist',
          'body' => 'Crikey it works!',
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

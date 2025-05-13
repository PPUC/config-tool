<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\default_content_deploy\Event\ImportBatchFinishedEvent;
use Drupal\default_content_deploy\Event\PostSaveEntityEvent;
use Drupal\default_content_deploy\Event\PreSaveEntityEvent;
use Drupal\default_content_deploy\Queue\DefaultContentDeployBatch;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Rogervila\ArrayDiffMultidimensional;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles the import of default content.
 */
class Importer implements ImporterInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Scanned files.
   *
   * @var object[]
   */
  private $files;

  /**
   * Directory to import.
   *
   * @var string
   */
  private $folder;

  /**
   * Data to import.
   *
   * @var array
   */
  private $dataToImport = [];

  /**
   * Data to correct.
   *
   * @var array
   */
  private $dataToCorrect = [];

  /**
   * Path aliases to import.
   *
   * @var array
   */
  private $pathAliasesToImport = [];

  /**
   * FIle entities to import.
   *
   * @var array
   */
  private $filesToImport = [];

  /**
   * Data to delete.
   *
   * @var array
   */
  private $dataToDelete = [];

  /**
   * Is remove changes of an old content.
   *
   * @var bool
   */
  protected $forceOverride;

  /**
   * Skip referenced entity ID correction.
   *
   * @var bool
   */
  protected $preserveIds = FALSE;

  /**
   * Incremental import.
   *
   * @var bool
   */
  protected $incremental = FALSE;

  /**
   * Delete during import.
   *
   * @var bool
   */
  protected $delete = FALSE;

  /**
   * Determines whether verbose mode is enabled.
   *
   * @var bool
   */
  protected $verbose = FALSE;

  /**
   * An admin account, required for full access on import.
   *
   * @var \Drupal\Core\Session\AccountInterface|null
   */
  private AccountInterface|null $adminAccount = NULL;

  /**
   * Constructs the default content deploy manager.
   */
  public function __construct(
    protected readonly SerializerInterface $serializer,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LinkManagerInterface $linkManager,
    protected readonly AdminAccountSwitcher $adminAccountSwitcher,
    protected readonly DeployManager $deployManager,
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly CacheBackendInterface $cache,
    protected readonly ExporterInterface $exporter,
    protected readonly Connection $database,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly DefaultContentDeployMetadataService $metadataService,
    protected readonly StateInterface $state,
    protected readonly MessengerInterface $messenger,
    protected readonly TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function setForceOverride(bool $force): void {
    $this->forceOverride = $force;
  }

  /**
   * {@inheritdoc}
   */
  public function setFolder(string $folder): void {
    $this->folder = $folder;
  }

  /**
   * Get directory to import.
   *
   * @return string
   *   The content folder.
   *
   * @throws \Exception
   */
  protected function getFolder(): string {
    $folder = $this->folder ?: $this->deployManager->getContentFolder();

    if (!isset($folder)) {
      throw new \Exception('Directory for content deploy is not set.');
    }

    return $folder;
  }

  /**
   * Get the state key to track max export timestamps in states.
   *
   * @return string
   *   The state key.
   *
   * @throws \Exception
   */
  protected function getStateKey(): string {
    return 'dcd.last_import.' . md5($this->getFolder());
  }

  /**
   * {@inheritdoc}
   */
  public function setPreserveIds(bool $preserve): void {
    $this->preserveIds = $preserve;
  }

  /**
   * {@inheritdoc}
   */
  public function setIncremental(bool $incremental): void {
    $this->incremental = $incremental;
  }

  /**
   * {@inheritdoc}
   */
  public function setDelete(bool $delete): void {
    $this->delete = $delete;
  }

  /**
   * {@inheritdoc}
   */
  public function getResult(): array {
    return $this->filesToImport + $this->dataToImport + $this->pathAliasesToImport + $this->dataToDelete;
  }

  /**
   * {@inheritdoc}
   */
  public function setVerbose(bool $verbose): void {
    $this->verbose = $verbose;
  }

  /**
   * Import data from JSON and create new entities, or update existing.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  public function prepareForImport(): void {
    $last_import_timestamp = (int) $this->state->get($this->getStateKey(), 0);
    $this->files = $this->scan($this->getFolder());

    foreach ($this->files as $file) {
      if (!isset($this->filesToImport[$file->uuid]) && !isset($this->dataToImport[$file->uuid]) && !isset($this->pathAliasesToImport[$file->uuid])) {
        if ($this->incremental) {
          $content = file_get_contents($file->uri);
          if (preg_match('/"export_timestamp"\s*:\s*(\d+)/', $content, $matches) && $last_import_timestamp >= $matches[1] && $this->entityExists($file->entity_type_id, $file->uuid)) {
            continue;
          }
          // Import the entity even with an old export timestamp if it does not
          // exist in database.
        }

        $this->addToImport($file);
      }
    }

    if ($this->delete) {
      $deleted_files = $this->scan($this->getFolder(), TRUE);

      foreach ($deleted_files as $file) {
        if (!isset($this->dataToDelete[$file->uuid])) {
          if ($this->incremental) {
            $content = file_get_contents($file->uri);
            if (preg_match('/"delete_timestamp"\s*:\s*(\d+)/', $content, $matches) && $last_import_timestamp >= $matches[1] && !$this->entityExists($file->entity_type_id, $file->uuid)) {
              continue;
            }
            // Delete the entity even with an old delete timestamp if it still
            // exists in database.
          }

          $this->addToDelete($file);
        }
      }
    }
  }

  /**
   * Checks if an entity exists in database.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $uuid
   *   The entity UUID.
   *
   * @return bool
   *   TRUE id the entity exists in database.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  protected function entityExists(string $entity_type_id, string $uuid): bool {
    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $table = $entity_type_definition->getBaseTable();
    $uuid_column = $entity_type_definition->getKey('uuid');
    return $this->database->select($table, 'e')
      ->fields('e', [$uuid_column])
      ->condition($uuid_column, $uuid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Returns a list of file objects.
   *
   * @param string $directory
   *   Absolute path to the directory to search.
   * @param bool $deleted
   *   List deleted files instead of those to be imported.
   *
   * @return object[]
   *   List of stdClass objects with name and uri properties.
   */
  public function scan(string $directory, bool $deleted = FALSE): array {
    if ($deleted) {
      $directory = rtrim($directory, '/') . '/_deleted';
    }
    if (!is_dir($directory)) {
      return [];
    }

    // Use Unix paths regardless of platform, skip dot directories, follow
    // symlinks (to allow extensions to be linked from elsewhere), and return
    // the RecursiveDirectoryIterator instance to have access to getSubPath(),
    // since SplFileInfo does not support relative paths.
    $flags = \FilesystemIterator::UNIX_PATHS;
    $flags |= \FilesystemIterator::SKIP_DOTS;
    $flags |= \FilesystemIterator::CURRENT_AS_SELF;
    $directory_iterator = new \RecursiveDirectoryIterator($directory, $flags);
    $iterator = new \RecursiveIteratorIterator($directory_iterator);
    $files = [];

    /** @var \SplFileInfo $file_info */
    foreach ($iterator as $file_info) {
      // Skip directories and non-json files.
      if ($file_info->isDir() || $file_info->getExtension() !== 'json' || (!$deleted && str_contains($file_info->getPathname(), '_deleted'))) {
        continue;
      }

      $file = new \stdClass();
      $file->name = $file_info->getFilename();
      $file->uuid = str_replace('.json', '', $file->name);
      $file->uri = $file_info->getPathname();
      $file->entity_type_id = basename(dirname($file->uri));
      $file->forceOverride = $this->forceOverride;
      $file->action = $deleted ? $this->t('delete') : $this->t('create or update');

      $files[$file->uri] = $file;
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function import(): void {
    // Process files in batches.
    $operations = [];
    $total = count($this->filesToImport) + count($this->dataToImport) + count($this->dataToCorrect) + count($this->pathAliasesToImport) + count($this->dataToDelete);
    $current = 1;

    if ($total === 0) {
      $this->messenger->addMessage($this->t('Nothing to import.'));
      return;
    }

    $context = [
      'skipCorrection' => [],
      'verbose' => $this->verbose,
      'preserveIds' => $this->preserveIds,
      'state_key' => $this->getStateKey(),
      'folder' => $this->getFolder(),
      'default_content_deploy_import' => TRUE,
    ];

    $operations[] = [
      [static::class, 'initializeContext'],
      [$context],
    ];

    foreach ($this->filesToImport as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, FALSE],
      ];
    }

    foreach ($this->dataToImport as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, FALSE],
      ];
    }

    foreach ($this->dataToCorrect as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, TRUE],
      ];
    }

    foreach ($this->pathAliasesToImport as $file) {
      $operations[] = [
        [static::class, 'importFile'],
        [$file, $current++, $total, FALSE],
      ];
    }

    foreach ($this->dataToDelete as $file) {
      $operations[] = [
        [static::class, 'deleteEntity'],
        [$file, $current++, $total, FALSE],
      ];
    }

    $batch_definition = [
      'title' => $this->t('Importing Content'),
      'operations' => $operations,
      'finished' => [static::class, 'importFinished'],
      'progressive' => TRUE,
      'queue' => [
        'class' => DefaultContentDeployBatch::class,
        'name' => 'default_content_deploy:import:' . $this->time->getCurrentMicroTime(),
      ],
    ];

    batch_set($batch_definition);
  }

  /**
   * Initializes the context for the import process.
   *
   * @param array $vars
   *   The variables to initialize in the context.
   * @param array &$context
   *   The reference to the batch context array.
   */
  public static function initializeContext(array $vars, array &$context): void {
    $context['results']['max_export_timestamp'] = 0;
    $context['results'] = array_merge($context['results'], $vars);
  }

  /**
   * Imports a file and processes its data.
   *
   * @param object $file
   *   The file object to import.
   * @param int $current
   *   Indicates progress of the batch operations.
   * @param int $total
   *   Total number of batch operations.
   * @param bool $correction
   *   Whether this is a second ID correction run.
   * @param array &$context
   *   The reference to the batch context array.
   */
  public static function importFile($file, $current, $total, $correction, &$context): void {
    // @phpstan-ignore-next-line
    $importer = \Drupal::service('default_content_deploy.importer');
    $importer->processFile($file, $current, $total, $correction, $context);
  }

  /**
   * Deletes an entity from the system.
   *
   * @param object $file
   *   The file object representing the entity to delete.
   * @param int $current
   *   Indicates progress of the batch operations.
   * @param int $total
   *   Total number of batch operations.
   * @param bool $correction
   *   Whether this is a second ID correction run.
   * @param array &$context
   *   The reference to the batch context array.
   */
  public static function deleteEntity($file, $current, $total, $correction, &$context): void {
    // @phpstan-ignore-next-line
    $importer = \Drupal::service('default_content_deploy.importer');
    $importer->processDelete($file, $current, $total, $context);
  }

  /**
   * Processes and imports a file.
   *
   * @param object $file
   *   The file object to use for import.
   * @param int $current
   *   Indicates progress of the batch operations.
   * @param int $total
   *   Total number of batch operations.
   * @param bool $correction
   *   Second ID correction run.
   * @param array &$context
   *   Reference to an array that stores the context of the batch process for
   *   status updates.
   *
   * @throws \Exception
   */
  protected function processFile(object $file, $current, $total, bool $correction, array &$context): void {
    $this->linkManager->setLinkDomain('default_content_deploy');

    $this->verbose = &$context['results']['verbose'];
    $this->preserveIds = &$context['results']['preserveIds'];

    if ($correction && array_key_exists($file->uuid, $context['results']['skipCorrection'] ?? [])) {
      if ($this->verbose) {
        $context['message'] = $this->t('@current of @total, skipped correction of @entity_type', [
          '@current' => $current,
          '@total' => $total,
          '@entity_type' => $file->entity_type_id,
        ]);
      }

      unset($context['results']['skipCorrection'][$file->uuid]);

      return;
    }

    if (!$correction) {
      $this->metadataService->setCurrentUuid($file->uuid);
    }

    if (!$this->adminAccount) {
      $this->adminAccount = $this->adminAccountSwitcher->switchToAdministrator();
    }
    else {
      $this->adminAccountSwitcher->switchTo($this->adminAccount);
    }

    try {
      $is_new = FALSE;
      $this->decodeFile($file);

      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->entityRepository->loadEntityByUuid($file->entity_type_id, $file->uuid);

      if ($entity) {
        $export_timestamp = $file->data['_dcd_metadata']['export_timestamp'] ?? -1;
        // Replace entity ID.
        $file->data[$file->key_id][0]['value'] = $entity->id();

        if (!$file->forceOverride && !$correction) {
          // Skip if the changed time the same or less in the file.
          if ($entity instanceof EntityChangedInterface) {
            // If an entity was refactored to implement the
            // EntityChangedInterface, older exports don't contain the changed
            // field.
            if (isset($file->data['changed'])) {
              $changed_time_file = 0;
              foreach ($file->data['changed'] as $changed) {
                $changed_time = strtotime($changed['value']);
                if ($changed_time > $changed_time_file) {
                  $changed_time_file = $changed_time;
                }
              }
              $changed_time = $entity->getChangedTimeAcrossTranslations();
              if ($changed_time_file <= $changed_time) {
                if ($this->verbose) {
                  $context['message'] = $this->t('@current of @total, skipped @entity_type @entity_id, file (@date_file) is not newer than database (@date_db)', [
                    '@current' => $current,
                    '@total' => $total,
                    '@entity_type' => $entity->getEntityTypeId(),
                    '@entity_id' => $entity->id(),
                    '@date_file' => date('Y-m-d H:i:s', $changed_time_file),
                    '@date_db' => date('Y-m-d H:i:s', $changed_time),
                  ]);
                }
                $context['results']['skipCorrection'][$file->uuid] = TRUE;
                if ($export_timestamp > $context['results']['max_export_timestamp']) {
                  $context['results']['max_export_timestamp'] = $export_timestamp;
                }

                return;
              }
            }
          }
          else {
            $current_entity_decoded = $this->serializer->decode($this->exporter->getSerializedContent($entity, FALSE), 'hal_json');
            $data = $file->data;
            unset($data['_dcd_metadata']);
            $diff = ArrayDiffMultidimensional::looseComparison($data, $current_entity_decoded);
            if (!$diff) {
              if ($this->verbose) {
                $context['message'] = $this->t('@current of @total, skipped @entity_type @entity_id, no changes compared to database', [
                  '@current' => $current,
                  '@total' => $total,
                  '@entity_type' => $entity->getEntityTypeId(),
                  '@entity_id' => $entity->id(),
                ]);
              }
              $context['results']['skipCorrection'][$file->uuid] = TRUE;
              if ($export_timestamp > $context['results']['max_export_timestamp']) {
                $context['results']['max_export_timestamp'] = $export_timestamp;
              }

              return;
            }
          }
        }
      }
      elseif (!$correction) {
        $is_new = TRUE;

        if (!$this->preserveIds) {
          // Ignore ID for creating a new entity.
          unset($file->data[$file->key_id]);
        }
        else {
          $entity_storage = $this->entityTypeManager->getStorage($file->entity_type_id);
          if ($entity_storage->load($file->data[$file->key_id][0]['value'])) {
            $context['message'] = $this->t('@current of @total, skipped @entity_type @entity_id, ID already exists in database', [
              '@current' => $current,
              '@total' => $total,
              '@entity_type' => $file->entity_type_id,
              '@entity_id' => $file->data[$file->key_id][0]['value'],
            ]);
            $context['results']['skipCorrection'][$file->uuid] = TRUE;

            return;
          }
        }
      }
      else {
        throw new \RuntimeException('Illegal state, an entity must not be created during ID correction.');
      }

      // All entities with entity references will be imported two times to
      // ensure that all entity references are present and valid. Path aliases
      // will be imported last to have a chance to rewrite them to the new ids
      // of newly created entities.
      $class = $this->entityTypeManager->getDefinition($file->entity_type_id)
        ->getClass();

      $this->updateTargetRevisionId($file->data);
      $this->metadataService->reset();
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->serializer->denormalize($file->data, $class, 'hal_json', [
        'request_method' => 'POST',
        'default_content_deploy' => TRUE,
      ]);
      $this->eventDispatcher->dispatch(new PreSaveEntityEvent($entity, $file->data, $correction, $context));
      $entity->enforceIsNew($is_new);
      $entity->save();
      $this->eventDispatcher->dispatch(new PostSaveEntityEvent($entity, $file->data, $correction, $context));

      if (!$correction && !$this->metadataService->isCorrectionRequired($file->uuid)) {
        $context['results']['skipCorrection'][$file->uuid] = TRUE;
      }

      // Invalidate the cache for updated entities.
      if (!$is_new) {
        $this->entityTypeManager->getStorage($entity->getEntityTypeId())->resetCache([$entity->id()]);
      }

      if ($this->verbose) {
        $context['message'] = $this->t('@current of @total, @operation @entity_type @entity_id', [
          '@current' => $current,
          '@total' => $total,
          '@operation' => $is_new ? $this->t('created') : $this->t('updated'),
          '@entity_type' => $entity->getEntityTypeId(),
          '@entity_id' => $entity->id(),
        ]);
      }

      $export_timestamp = $this->metadataService->getExportTimestamp($file->uuid);
      if ($export_timestamp > $context['results']['max_export_timestamp']) {
        $context['results']['max_export_timestamp'] = $export_timestamp;
      }

    }
    catch (\Exception $e) {
      $context['message'] = $this->t('@current of @total, error on importing @entity_type @uuid: @message', [
        '@current' => $current,
        '@total' => $total,
        '@entity_type' => $file->entity_type_id,
        '@uuid' => $file->uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $this->adminAccountSwitcher->switchBack();
    }
  }

  /**
   * Processes and imports a file for deletion.
   *
   * @param object $file
   *   The file object to use for import.
   * @param int $current
   *   Indicates progress of the batch operations.
   * @param int $total
   *   Total number of batch operations.
   * @param array &$context
   *   Reference to an array that stores the context of the batch process
   *   for status updates.
   *
   * @throws \Exception
   */
  protected function processDelete(object $file, $current, $total, array &$context): void {
    $this->linkManager->setLinkDomain('default_content_deploy');

    $this->verbose = &$context['results']['verbose'];

    if (!$this->adminAccount) {
      $this->adminAccount = $this->adminAccountSwitcher->switchToAdministrator();
    }
    else {
      $this->adminAccountSwitcher->switchTo($this->adminAccount);
    }

    try {
      $entity = $this->entityRepository->loadEntityByUuid($file->entity_type_id, $file->uuid);
      if ($entity instanceof ContentEntityInterface) {
        $id = $entity->id();
        $entity->delete();

        if ($this->verbose) {
          $context['message'] = $this->t('@current of @total, @operation @entity_type @entity_id', [
            '@current' => $current,
            '@total' => $total,
            '@operation' => $this->t('deleted'),
            '@entity_type' => $file->entity_type_id,
            '@entity_id' => $id,
          ]);
        }
      }
      elseif ($this->verbose) {
        $context['message'] = $this->t('@current of @total, @entity_type @uuid does not exist in database anymore', [
          '@current' => $current,
          '@total' => $total,
          '@entity_type' => $file->entity_type_id,
          '@uuid' => $file->uuid,
        ]);
      }

      $this->decodeFile($file);
      $delete_timestamp = $file->data['_dcd_metadata']['delete_timestamp'] ?? -1;
      if ($delete_timestamp > $context['results']['max_export_timestamp']) {
        $context['results']['max_export_timestamp'] = $delete_timestamp;
      }
    }
    catch (\Exception $e) {
      $context['message'] = $this->t('@current of @total, error on deleting @entity_type @uuid: @message', [
        '@current' => $current,
        '@total' => $total,
        '@entity_type' => $file->entity_type_id,
        '@uuid' => $file->uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $this->adminAccountSwitcher->switchBack();
    }
  }

  /**
   * Decodes the given file and prepares its data for import.
   *
   * {@inheritdoc}
   */
  public function decodeFile(object $file): void {
    // Get parsed data.
    $parsed_data = file_get_contents($file->uri);
    // Backward compatibility for exports before 2.2.x.
    if (preg_match('@"href": "(http[^"]+)/rest\\\\/type\\\\/[^"]+"@', $parsed_data, $matches)) {
      $parsed_data = preg_replace('@"href": "http[^"]+/rest\\\\/type\\\\/([^"?]+).*?"@', '"href": "$1"', $parsed_data);
      $parsed_data = preg_replace('@"http[^"]+/rest\\\\/(relation[^"?]+).*?"@', '"$1"', $parsed_data);
      $parsed_data = preg_replace('@"href": "' . preg_quote($matches[1], '@') . '([^"?]+).*?"@', '"href": "_dcd$1"', $parsed_data);
    }
    // Decode.
    try {
      $decode = $this->serializer->decode($parsed_data, 'hal_json');
    }
    catch (\Exception $e) {
      throw new \RuntimeException(sprintf('Unable to decode %s', $file->uri), $e->getCode(), $e);
    }

    // Prepare data for import.
    $file->data = $decode;
    $this->prepareData($file);
  }

  /**
   * Here we can edit data's value before importing.
   *
   * @param object $file
   *   The file object.
   */
  protected function prepareData(object $file): void {
    $entity_type_object = $this->entityTypeManager->getDefinition($file->entity_type_id);
    // Keys of entity.
    $file->key_id = $entity_type_object->getKey('id');

    // @see path_entity_base_field_info().
    // @todo offer an event to let third party modules register their content
    //   types. On the other hand, the path is only part of the export if
    //   computed fields are included, which could be turned off in 2.1.x.
    if (
      isset($file->data['path']) &&
      in_array(
        $file->entity_type_id,
        [
          'taxonomy_term',
          'node',
          'media',
          'commerce_product',
        ]
      )
    ) {
      unset($file->data['path']);
    }

    // Ignore revision and id of entity.
    if ($key_revision_id = $entity_type_object->getKey('revision')) {
      unset($file->data[$key_revision_id]);
    }
  }

  /**
   * Adding prepared data for import.
   *
   * @param object $file
   *   The file object.
   */
  protected function addToImport(object $file): void {
    switch ($file->entity_type_id) {
      case 'path_alias':
        $this->pathAliasesToImport[$file->uuid] = $file;
        break;

      case 'file':
        $this->filesToImport[$file->uuid] = $file;
        break;

      default:
        $this->dataToImport[$file->uuid] = $file;
        $this->dataToCorrect[$file->uuid] = $file;
        break;
    }
  }

  /**
   * Adding prepared data for deletion.
   *
   * @param object $file
   *   The file object.
   */
  protected function addToDelete(object $file): void {
    if (isset($file->entity_type_id)) {
      $this->dataToDelete[$file->uuid] = $file;
    }
  }

  /**
   * Get Entity type ID by link.
   *
   * @param string $link
   *   The link.
   *
   * @return string
   *   The entity type ID.
   */
  private function getEntityTypeByLink(string $link): string {
    $type = $this->linkManager->getTypeInternalIds($link);

    if ($type) {
      $entity_type_id = $type['entity_type'];
    }
    else {
      $components = array_reverse(explode('/', $link));
      $entity_type_id = $components[1];
      // @todo remove this line when core is >= 9.2
      $this->cache->invalidate('hal:links:types');
    }

    return $entity_type_id;
  }

  /**
   * Updates the target revision ID for reference fields.
   *
   * If this entity contains a reference field with a target revision ID value,
   * we update it to reflect the latest revision.
   *
   * @param array $decode
   *   The decoded entity array.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs during entity storage operations.
   */
  private function updateTargetRevisionId(array &$decode): void {
    if (isset($decode['_embedded'])) {
      foreach ($decode['_embedded'] as $link_key => $link) {
        if (array_column($link, 'target_revision_id')) {
          foreach ($link as $ref_key => $reference) {
            $url = $reference['_links']['type']['href'];
            $uuid = $reference['uuid'][0]['value'];
            $entity_type = $this->getEntityTypeByLink($url);
            $entity = $this->entityRepository->loadEntityByUuid($entity_type, $uuid);

            // Update the Target revision id if child entity exist on this site.
            if ($entity) {
              $revision_id = $entity->getRevisionId();
              $decode['_embedded'][$link_key][$ref_key]['target_revision_id'] = $revision_id;
            }
          }
        }
      }
    }
  }

  /**
   * Callback function to handle batch processing completion.
   *
   * @param bool $success
   *   Indicates whether the batch processing was successful.
   * @param array $results
   *   The results.
   * @param array $operations
   *   The operations.
   */
  public static function importFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      // Batch processing completed successfully.
      // @phpstan-ignore-next-line
      \Drupal::messenger()->addMessage(t('Batch import completed successfully.'));

      // @phpstan-ignore-next-line
      if ($results['max_export_timestamp'] > ((int) \Drupal::state()->get($results['state_key'], 0))) {
        // @phpstan-ignore-next-line
        \Drupal::state()
          ->set($results['state_key'], $results['max_export_timestamp']);
      }
    }
    else {
      // Batch processing encountered an error.
      // @phpstan-ignore-next-line
      \Drupal::messenger()->addMessage(t('An error occurred during the batch export process.'), 'error');
    }

    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
    // @phpstan-ignore-next-line
    $dispatcher = \Drupal::service('event_dispatcher');
    $dispatcher->dispatch(new ImportBatchFinishedEvent($success, $results));
  }

}

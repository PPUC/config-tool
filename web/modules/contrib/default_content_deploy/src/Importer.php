<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\default_content_deploy\Event\ImportBatchFinishedEvent;
use Drupal\default_content_deploy\Event\PostSaveEntityEvent;
use Drupal\default_content_deploy\Event\PreSaveEntityEvent;
use Drupal\default_content_deploy\Queue\DefaultContentDeployBatch;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Rogervila\ArrayDiffMultidimensional;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Serializer;

/**
 *
 */
class Importer implements ImporterInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use AdministratorTrait;

  /**
   * Deploy manager.
   *
   * @var \Drupal\default_content_deploy\DeployManager
   */
  protected $deployManager;

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
   * The Entity repository manager.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The cache data.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The link manager service.
   *
   * @var \Drupal\hal\LinkManager\LinkManagerInterface
   */
  protected $linkManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * DCD Exporter.
   *
   * @var ExporterInterface
   */
  protected $exporter;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The metadata service.
   *
   * @var \Drupal\default_content_deploy\DefaultContentDeployMetadataService
   */
  protected $metadataService;

  /**
   * The state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var bool
   */
  protected $verbose = FALSE;

  /**
   * Constructs the default content deploy manager.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\hal\LinkManager\LinkManagerInterface $link_manager
   *   The link manager service.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   The account switcher.
   * @param \Drupal\default_content_deploy\DeployManager $deploy_manager
   *   Deploy manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The Entity repository manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache data.
   * @param ExporterInterface $exporter
   *   The exporter.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\default_content_deploy\DefaultContentDeployMetadataService $metadata_service
   *   The metadata service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager, LinkManagerInterface $link_manager, AccountSwitcherInterface $account_switcher, DeployManager $deploy_manager, EntityRepositoryInterface $entity_repository, CacheBackendInterface $cache, ExporterInterface $exporter, Connection $database, EventDispatcherInterface $event_dispatcher, DefaultContentDeployMetadataService $metadata_service, StateInterface $state) {
    $this->serializer = $serializer;
    $this->entityTypeManager = $entity_type_manager;
    $this->linkManager = $link_manager;
    $this->accountSwitcher = $account_switcher;
    $this->deployManager = $deploy_manager;
    $this->entityRepository = $entity_repository;
    $this->cache = $cache;
    $this->exporter = $exporter;
    $this->database = $database;
    $this->eventDispatcher = $event_dispatcher;
    $this->metadataService = $metadata_service;
    $this->state = $state;
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
    return $this->dataToImport + $this->pathAliasesToImport + $this->dataToDelete;
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
    // @todo remove because of changes in core >= 9.2
    $this->cache->delete('hal:links:relations');
    $last_import_timestamp = (int) $this->state->get('dcd.last_import.' . md5($this->getFolder()), 0);
    $this->files = $this->scan($this->getFolder());

    foreach ($this->files as $file) {
      if (!isset($this->dataToImport[$file->uuid]) && !isset($this->pathAliasesToImport[$file->uuid])) {
        if ($this->incremental) {
          $this->decodeFile($file);
          if (($export_timestamp = $file->data['_dcd_metadata']['export_timestamp'] ?? NULL) && $last_import_timestamp >= $export_timestamp && $this->entityExists($file->entity_type_id, $file->uuid)) {
            continue;
          }
          // Delete the entity even with an old delete timestamp because it
          // still exists in database.
        }

        $this->addToImport($file);
      }
    }

    if ($this->delete) {
      $deleted_files = $this->scan($this->getFolder(), TRUE);

      foreach ($deleted_files as $file) {
        if (!isset($this->dataToDelete[$file->uuid])) {
          if ($this->incremental) {
            $this->decodeFile($file);
            if (($delete_timestamp = $file->data['_dcd_metadata']['delete_timestamp'] ?? NULL) && $last_import_timestamp >= $delete_timestamp && !$this->entityExists($file->entity_type_id, $file->uuid)) {
              continue;
            }
            // Delete the entity even with an old delete timestamp because it
            // still exists in database.
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
    return \Drupal::database()->select($table, 'e')
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
    $total = count($this->dataToImport) + count($this->dataToCorrect) + count($this->pathAliasesToImport) + count($this->dataToDelete);
    $current = 1;

    if ($total === 0) {
      \Drupal::messenger()->addMessage(t('Nothing to import.'));
      return;
    }

    $context = [
      'skipCorrection' => [],
      'verbose' => $this->verbose,
      'preserveIds' => $this->preserveIds,
      'state_key' => 'dcd.last_import.' . md5($this->getFolder()),
      'folder' => $this->getFolder(),
    ];

    $operations[] = [
      [static::class, 'initializeContext'],
      [$context],
    ];

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
        'name' => 'default_content_deploy:import:' . \Drupal::time()->getCurrentMicroTime(),
      ],
    ];

    batch_set($batch_definition);
  }

  /**
   *
   */
  public static function initializeContext(array $vars, array &$context): void {
    $context['results']['max_export_timestamp'] = 0;
    $context['results'] = array_merge($context['results'], $vars);
  }

  /**
   *
   */
  public static function importFile($file, $current, $total, $correction, &$context): void {
    $importer = \Drupal::service('default_content_deploy.importer');
    $importer->processFile($file, $current, $total, $correction, $context);
  }

  /**
   *
   */
  public static function deleteEntity($file, $current, $total, $correction, &$context): void {
    $importer = \Drupal::service('default_content_deploy.importer');
    $importer->processDelete($file, $current, $total, $context);
  }

  /**
   * Process and import file.
   *
   * @param $file
   *   The file object to use for import.
   * @param $current
   *   Indicates progress of the batch operations.
   * @param $total
   *   Total number of batch operations.
   * @param bool $correction
   *   Second ID correction run.
   * @param array &$context
   *   Reference to an array that stores the context of the batch process for status updates.
   *
   * @throws \Exception
   */
  protected function processFile(object $file, $current, $total, bool $correction, array &$context): void {
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

    if (PHP_SAPI === 'cli') {
      $root_user = $this->getAdministrator();
      $this->accountSwitcher->switchTo($root_user);
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
            // If an entity was refactored to implement the EntityChangedInterface,
            // older exports don't contain the changed field.
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
            $link_domain = $this->getLinkDomain($file);
            $this->linkManager->setLinkDomain($link_domain);
            $this->exporter->setLinkDomain($link_domain);
            $current_entity_decoded = $this->serializer->decode($this->exporter->getSerializedContent($entity, FALSE), 'hal_json');
            $diff = ArrayDiffMultidimensional::looseComparison($file->data, $current_entity_decoded);
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

        $this->linkManager->setLinkDomain(FALSE);
        $this->exporter->setLinkDomain('');
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

      // All entities with entity references will be imported two times to ensure
      // that all entity references are present and valid. Path aliases will be
      // imported last to have a chance to rewrite them to the new ids of newly
      // created entities.
      $this->linkManager->setLinkDomain($this->getLinkDomain($file));
      $class = $this->entityTypeManager->getDefinition($file->entity_type_id)
        ->getClass();

      $this->updateTargetRevisionId($file->data);
      $this->metadataService->reset();
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->serializer->denormalize($file->data, $class, 'hal_json', ['request_method' => 'POST']);
      $this->eventDispatcher->dispatch(new PreSaveEntityEvent($entity, $file->data, $correction, $context));
      $entity->enforceIsNew($is_new);
      $entity->save();
      $this->eventDispatcher->dispatch(new PostSaveEntityEvent($entity, $file->data, $correction, $context));

      if (!$correction && !$this->metadataService->isCorrectionRequired($file->uuid)) {
        $context['results']['skipCorrection'][$file->uuid] = TRUE;
      }

      if ($entity->getEntityTypeId() === 'user') {
        // Workaround: store the hashed password directly in the database
        // and avoid the entity API which doesn't provide support for
        // setting password hashes directly.
        $hashed_pass = $file->data['pass'][0]['value'] ?? FALSE;
        if ($hashed_pass) {
          $this->database->update('users_field_data')
            ->fields([
              'pass' => $hashed_pass,
            ])
            ->condition('uid', $entity->id(), '=')
            ->execute();
        }
      }

      // Invalidate the cache for updated entities.
      if (!$is_new) {
        $this->entityTypeManager->getStorage($entity->getEntityTypeId())->resetCache([$entity->id()]);
      }

      $this->linkManager->setLinkDomain(FALSE);

      if (PHP_SAPI === 'cli') {
        $this->accountSwitcher->switchBack();
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
  }

  /**
   * Process and import file.
   *
   * @param $file
   *   The file object to use for import.
   * @param $current
   *   Indicates progress of the batch operations.
   * @param $total
   *   Total number of batch operations.
   * @param array &$context
   *   Reference to an array that stores the context of the batch process for status updates.
   *
   * @throws \Exception
   */
  protected function processDelete(object $file, $current, $total, array &$context): void {
    $this->verbose = &$context['results']['verbose'];

    if (PHP_SAPI === 'cli') {
      $root_user = $this->getAdministrator();
      $this->accountSwitcher->switchTo($root_user);
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
  }

  /**
   * Gets url from file for set to Link manager.
   *
   * @param object $file
   *   The file object.
   */
  protected function getLinkDomain(object $file): string {
    $link = $file->data['_links']['type']['href'];
    $url_data = parse_url($link);
    $host = "{$url_data['scheme']}://{$url_data['host']}";
    return (!isset($url_data['port'])) ? $host : "{$host}:{$url_data['port']}";
  }

  /**
   * @{inheritdoc}
   */
  public function decodeFile(object $file): void {
    // Get parsed data.
    $parsed_data = file_get_contents($file->uri);

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
   * Here we can edit data`s value before importing.
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
    if (isset($file->data['path']) && in_array($file->entity_type_id, ['taxonomy_term', 'node', 'media', 'commerce_product'])) {
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
        $this->dataToImport[$file->uuid] = $file;
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
    switch ($file->entity_type_id) {
      default:
        $this->dataToDelete[$file->uuid] = $file;
        break;
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
   * If this entity contains a reference field with target revision is value,
   * we should to update it.
   *
   * @param array $decode
   *   The decoded entity array.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
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
      \Drupal::messenger()->addMessage(t('Batch import completed successfully.'));

      if ($results['max_export_timestamp'] > ((int) \Drupal::state()->get($results['state_key'], 0))) {
        \Drupal::state()
          ->set($results['state_key'], $results['max_export_timestamp']);
      }
    }
    else {
      // Batch processing encountered an error.
      \Drupal::messenger()->addMessage(t('An error occurred during the batch export process.'), 'error');
    }

    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
    $dispatcher = \Drupal::service('event_dispatcher');
    $dispatcher->dispatch(new ImportBatchFinishedEvent($success, $results));
  }

}

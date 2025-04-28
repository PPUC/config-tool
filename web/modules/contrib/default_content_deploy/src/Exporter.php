<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\default_content_deploy\Event\PostSerializeEvent;
use Drupal\default_content_deploy\Event\PreSerializeEvent;
use Drupal\default_content_deploy\Form\SettingsForm;
use Drupal\default_content_deploy\Queue\DefaultContentDeployBatch;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\Plugin\DataType\SectionData;
use Drupal\layout_builder\SectionComponent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles the export of content for deployment.
 *
 * This class provides methods for exporting entities and managing
 * the export process within the default_content_deploy module.
 */
class Exporter implements ExporterInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Text dependencies option.
   *
   * @var bool|null
   */
  private $includeTextDependencies;

  /**
   * Skip export timestamp option.
   *
   * @var bool|null
   */
  private $skipExportTimestamp;

  /**
   * Entity type ID.
   *
   * @var string
   */
  private $entityTypeId;

  /**
   * Type of a entity content.
   *
   * @var string
   */
  private $bundle;

  /**
   * Entity IDs for export.
   *
   * @var array
   */
  private $entityIds;

  /**
   * Directory to export.
   *
   * @var string
   */
  private $folder;

  /**
   * Entity IDs which needs skip.
   *
   * @var array
   */
  private $skipEntityIds;

  /**
   * Entity type IDs which needs skip.
   *
   * @var array
   */
  private $skipEntityTypeIds;

  /**
   * Array of entity types and with there values for export.
   *
   * @var array
   */
  private $exportedEntities = [];

  /**
   * Type of export.
   *
   * @var string
   */
  private $mode = 'default';

  /**
   * Is remove old content.
   *
   * @var bool
   */
  private $forceUpdate;

  /**
   * Stores the current date and time for export operations.
   *
   * This is used to timestamp the export process and track changes
   * across different entities.
   *
   * @var \DateTimeInterface
   */
  private $dateTime;

  /**
   * Determines whether verbose logging is enabled.
   *
   * If set to TRUE, additional details about the export process
   * will be logged for debugging purposes.
   *
   * @var bool
   */
  protected $verbose = FALSE;

  /**
   * An admin account, required for full access on export.
   *
   * @var \Drupal\Core\Session\AccountInterface|null
   */
  private AccountInterface|null $adminAccount = NULL;

  /**
   * Exporter constructor.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly DeployManager $deployManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly SerializerInterface $serializer,
    protected readonly AdminAccountSwitcher $adminAccountSwitcher,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly LinkManagerInterface $linkManager,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ConfigFactoryInterface $config,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly MessengerInterface $messenger,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
    protected readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityTypeId(string $entity_type): void {
    $content_entity_types = $this->deployManager->getContentEntityTypes();

    if (!array_key_exists($entity_type, $content_entity_types)) {
      throw new \InvalidArgumentException(sprintf('Entity type "%s" does not exist', $entity_type));
    }

    $this->entityTypeId = $entity_type;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityBundle(string $bundle): void {
    $this->bundle = $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityIds(array $entity_ids): void {
    $this->entityIds = $entity_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function setSkipEntityIds(array $skip_entity_ids): void {
    $this->skipEntityIds = $skip_entity_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function setSkipEntityTypeIds(array $skip_entity_type_ids): void {
    $this->skipEntityTypeIds = $skip_entity_type_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getSkipEntityTypeIds(): array {
    if (empty($this->skipEntityTypeIds)) {
      $config = $this->config->get(SettingsForm::CONFIG);
      return $config->get('skip_entity_types') ?? [];
    }

    return $this->skipEntityTypeIds;
  }

  /**
   * {@inheritdoc}
   */
  public function setMode(string $mode): void {
    $available_modes = ['all', 'reference', 'default'];

    if (in_array($mode, $available_modes)) {
      $this->mode = $mode;
    }
    else {
      throw new \Exception('The selected mode is not available');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setForceUpdate(bool $is_update): void {
    $this->forceUpdate = $is_update;
  }

  /**
   * {@inheritdoc}
   */
  public function setDateTime(\DateTimeInterface $date_time): void {
    $this->dateTime = $date_time;
  }

  /**
   * {@inheritdoc}
   */
  public function setTextDependencies(?bool $text_dependencies = NULL): void {
    if (is_null($text_dependencies)) {
      $config = $this->config->get(SettingsForm::CONFIG);
      $text_dependencies = (bool) $config->get('text_dependencies');
    }

    $this->includeTextDependencies = $text_dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function setSkipExportTimestamp(?bool $skip = NULL): void {
    if (is_null($skip)) {
      $config = $this->config->get(SettingsForm::CONFIG);
    }

    $this->skipExportTimestamp = $skip;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateTime(): ?\DateTimeInterface {
    return $this->dateTime;
  }

  /**
   * {@inheritdoc}
   */
  public function getTime(): int {
    return $this->dateTime ? $this->dateTime->getTimestamp() : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function setFolder(string $folder): void {
    $this->folder = $folder;
  }

  /**
   * Get directory to export.
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

    return rtrim($folder, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getTextDependencies(): bool {
    if (is_null($this->includeTextDependencies)) {
      $this->setTextDependencies();
    }

    return $this->includeTextDependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getSkipExportTimestamp(): bool {
    if (is_null($this->skipExportTimestamp)) {
      $this->setSkipExportTimestamp();
    }

    return (bool) $this->skipExportTimestamp;
  }

  /**
   * {@inheritdoc}
   */
  public function setVerbose(bool $verbose): void {
    $this->verbose = $verbose;
  }

  /**
   * {@inheritdoc}
   */
  public function export(): void {
    switch ($this->mode) {
      case 'default':
        $this->exportBatch();
        break;

      case 'reference':
        $this->exportBatch(TRUE);
        break;

      case 'all':
        $this->exportAllBatch();
        break;
    }
  }

  /**
   * Export content in batch.
   *
   * @param bool $with_references
   *   Indicates if export should consider referenced entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function exportBatch(bool $with_references = FALSE): void {
    $entity_type = $this->entityTypeId;
    $exported_entity_ids = $this->getEntityIdsForExport();

    // Exit if there is nothing to export.
    if (empty($exported_entity_ids)) {
      $this->messenger->addMessage($this->t('Nothing to export.'), 'error');
      return;
    }

    if ($this->forceUpdate) {
      $this->fileSystem->deleteRecursive($this->getFolder());
    }

    $operations[] = $this->getInitializeContextOperation();

    if ($total = count($exported_entity_ids)) {
      $current = 1;
      $export_type = $with_references ? 'exportBatchWithReferences' : 'exportBatchDefault';
      foreach ($exported_entity_ids as $entity_id) {
        $operations[] = [
          [static::class, 'exportFile'],
          [$export_type, $entity_type, $entity_id, $current++, $total],
        ];
      }
    }

    // Set up batch information.
    $batch_definition = [
      'title' => $this->t('Exporting content'),
      'operations' => $operations,
      'finished' => [static::class, 'exportFinished'],
      'progressive' => TRUE,
      'queue' => [
        'class' => DefaultContentDeployBatch::class,
        'name' => 'default_content_deploy:export:' . $this->time->getCurrentMicroTime(),
      ],
    ];

    batch_set($batch_definition);
  }

  /**
   * Prepares the operation for initializing the export context.
   *
   * This method constructs an array of parameters required to initialize the
   * export context, including metadata such as timestamps, folder paths,
   * export modes, and dependency settings.
   *
   * @return array
   *   A callable operation and its parameters for initializing the export
   *   context.
   *   - The first element is a callable reference to `initializeContext`.
   *   - The second element is an array containing:
   *     - `dateTime`: The timestamp of the export operation.
   *     - `folder`: The target directory for export files.
   *     - `includeTextDependencies`: Whether to include text dependencies.
   *     - `skipExportTimestamp`: Whether to exclude the export timestamp.
   *     - `skipEntityTypeIds`: An array of entity type IDs to be skipped.
   *     - `mode`: The export mode setting.
   *     - `verbose`: Whether verbose logging is enabled.
   *
   * @throws \Exception
   */
  protected function getInitializeContextOperation(): array {
    $context = [
      'dateTime' => $this->getDateTime(),
      'folder' => $this->getFolder(),
      'includeTextDependencies' => $this->getTextDependencies(),
      'skipExportTimestamp' => $this->getSkipExportTimestamp(),
      'skipEntityTypeIds' => $this->getSkipEntityTypeIds(),
      'mode' => $this->mode,
      'verbose' => $this->verbose,
      'uuids' => [],
      '_links' => [],
    ];

    return [
      [static::class, 'initializeContext'],
      [$context],
    ];

  }

  /**
   * Initializes or updates the export context with provided variables.
   *
   * This method merges the given variables into the export context, ensuring
   * that any missing parameters are properly initialized while retaining
   * existing context values.
   *
   * @param array $vars
   *   An associative array of variables to be added to the context.
   * @param array &$context
   *   The export context array, which stores processing parameters.
   *   - `results`: An array containing the current state of the export process.
   *
   * @return void
   *   This method does not return a value but modifies the context array.
   */
  public static function initializeContext(array $vars, array &$context): void {
    $context['results'] = array_merge($context['results'] ?? [], $vars);
  }

  /**
   * Synchronizes the export context with class properties.
   *
   * This method updates the object's properties by referencing values
   * stored in the export context, ensuring that all parameters remain
   * consistent throughout the export process.
   *
   * @param array $context
   *   The context array that contains export-related parameters, including:
   *   - `dateTime`: The timestamp of the export.
   *   - `folder`: The directory where export files are stored.
   *   - `includeTextDependencies`: Whether to include text dependencies.
   *   - `skipExportTimestamp`: Whether to skip adding export timestamps.
   *   - `skipEntityTypeIds`: An array of entity type IDs to exclude.
   *   - `mode`: The export mode (e.g., full or incremental).
   *   - `verbose`: Whether verbose logging is enabled.
   *
   * @return void
   *   This method does not return a value but modifies the context array.
   */
  protected function synchronizeContext(array &$context): void {
    $this->dateTime = &$context['results']['dateTime'];
    $this->folder = &$context['results']['folder'];
    $this->includeTextDependencies = &$context['results']['includeTextDependencies'];
    $this->skipExportTimestamp = &$context['results']['skipExportTimestamp'];
    $this->skipEntityTypeIds = &$context['results']['skipEntityTypeIds'];
    $this->mode = &$context['results']['mode'];
    $this->verbose = &$context['results']['verbose'];
  }

  /**
   * Exports a file for a given entity.
   *
   * This method uses the exporter service to process and export a specific
   * entity based on the provided parameters.
   *
   * @param string $export_type
   *   The type of export operation (e.g., 'export', 'exportRevision').
   * @param string $entity_type
   *   The entity type (e.g., 'node', 'taxonomy_term').
   * @param string|int $entity_id
   *   The entity ID or UUID.
   * @param int $current
   *   The current step in the export process.
   * @param int $total
   *   The total number of entities to be exported.
   * @param array $context
   *   The context array that tracks export progress and results.
   *
   * @return void
   *   This method does not return a value but modifies the context array.
   */
  public static function exportFile(string $export_type, string $entity_type, string|int $entity_id, int $current, int $total, array &$context): void {
    /** @var ExporterInterface $exporter */
    // @phpstan-ignore-next-line
    $exporter = \Drupal::service('default_content_deploy.exporter');
    $exporter->synchronizeContext($context);
    $exporter->{$export_type}($entity_type, $entity_id, $current, $total, $context);
  }

  /**
   * Prepares and exports a single entity to a JSON file.
   *
   * @param string $entity_type
   *   The type of the entity being exported.
   * @param int $entity_id
   *   The ID of the entity being exported.
   * @param int $current
   *   The current item.
   * @param int $total
   *   The total number of items.
   * @param array $context
   *   The batch context.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  public function exportBatchDefault(string $entity_type, string|int $entity_id, int $current, int $total, array &$context): void {
    $this->linkManager->setLinkDomain('default_content_deploy');

    $uuid = FALSE;
    if ($entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id)) {
      $uuid = $entity->get('uuid')->value;
      if (!$this->skipEntity($entity, $context)) {
        if ($serialized_entity = $this->getSerializedContent($entity, TRUE, $context)) {
          $this->writeSerializedEntity($entity_type, $serialized_entity, $uuid);
          $context['results']['exported_entities'][$entity_type][$entity_id] = $uuid;
          if ($this->verbose) {
            $context['message'] = $this->t('Exported @type entity (ID @id, bundle @bundle) @current of @total', [
              '@type' => $entity_type,
              '@id' => $entity_id,
              '@bundle' => $entity->bundle(),
              '@current' => $current,
              '@total' => $total,
            ]);
          }

          return;
        }
      }
    }

    $context['results']['skipped_entities'][$entity_type][] = $uuid;

    if ($this->verbose) {
      $context['message'] = $this->t('Skipped @type entity (ID @id, bundle @bundle) @current of @total', [
        '@type' => $entity_type,
        '@id' => $entity_id,
        '@bundle' => $entity->bundle(),
        '@current' => $current,
        '@total' => $total,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exportEntity(ContentEntityInterface $entity, ?bool $with_references = FALSE): void {
    $this->linkManager->setLinkDomain('default_content_deploy');

    $context = [];

    // Check if a batch is currently running. But ignore a Default Content
    // Deploy import batch that triggers this function.
    if (
      function_exists('batch_get') &&
      ($batch = &batch_get()) &&
      isset($batch['current_set']) &&
      ($current_set = &$batch['sets'][$batch['current_set']]) &&
      !isset($current_set['results']['default_content_deploy_import'])
    ) {
      if (isset($current_set['results'])) {
        // Search API batch.
        $context['results'] = &$current_set['results'];
      }

      if (!isset($context['results']['uuids'])) {
        $context['results']['uuids'] = [];
      }
      if (!isset($context['results']['_links'])) {
        $context['results']['_links'] = [];
      }
      if (!isset($context['results']['exported_entities'])) {
        $context['results']['exported_entities'] = [];
      }

      if ($context['results']['exported_entities'][$entity->getEntityTypeId()][$entity->id()] ?? FALSE) {
        // Already exported in current context.
        return;
      }
    }

    $this->setMode($with_references ? 'reference' : 'default');
    if ($serialized_entity = $this->getSerializedContent($entity, TRUE, $context)) {
      $this->writeSerializedEntity($entity->getEntityTypeId(), $serialized_entity, $entity->uuid());
      $context['results']['exported_entities'][$entity->getEntityTypeId()][$entity->id()] = $entity->uuid();
    }

    if ($with_references) {
      $indexed_dependencies = [$entity->uuid() => $entity];
      $referenced_entities = $this->getEntityReferencesRecursive($entity, $context, 0, $indexed_dependencies);

      foreach ($referenced_entities as $uuid => $referenced_entity) {
        if ($serialized_entity = $this->getSerializedContent($referenced_entity, TRUE, $context)) {
          $this->writeSerializedEntity($referenced_entity->getEntityTypeId(), $serialized_entity, $uuid);
          $context['results']['exported_entities'][$referenced_entity->getEntityTypeId()][$referenced_entity->id()] = $referenced_entity->uuid();
        }
      }
    }
  }

  /**
   * Prepares and exports a single entity to a JSON file with references.
   *
   * @param string $entity_type
   *   The type of the entity being exported.
   * @param string|int $entity_id
   *   The ID of the entity being exported.
   * @param int $current
   *   The current item.
   * @param int $total
   *   The total number of items.
   * @param array $context
   *   The batch context.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function exportBatchWithReferences(string $entity_type, string|int $entity_id, int $current, int $total, array &$context): void {
    $this->linkManager->setLinkDomain('default_content_deploy');

    if ($entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id)) {

      if (!$this->skipEntity($entity, $context)) {
        $indexed_dependencies = [$entity->uuid() => $entity];
        $referenced_entities = $this->getEntityReferencesRecursive($entity, $context, 0, $indexed_dependencies);
        $referenced_entities[$entity->get('uuid')->value] = $entity;

        foreach ($referenced_entities as $uuid => $referenced_entity) {
          $referenced_entity_type = $referenced_entity->getEntityTypeId();

          if ($serialized_entity = $this->getSerializedContent($referenced_entity, TRUE, $context)) {
            $this->writeSerializedEntity($referenced_entity_type, $serialized_entity, $uuid);
            $context['results']['exported_entities'][$referenced_entity_type][$referenced_entity->id()] = $uuid;
          }
        }

        if ($this->verbose) {
          $context['message'] = $this->t('Exported @type entity (ID @id, bundle @bundle) @current of @total', [
            '@type' => $entity_type,
            '@id' => $entity_id,
            '@bundle' => $entity->bundle(),
            '@current' => $current,
            '@total' => $total,
          ]);
        }
      }
      else {
        $context['results']['skipped_entities'][$entity->getEntityTypeId()][] = $entity->uuid();

        if ($this->verbose) {
          $context['message'] = $this->t('Skipped @type entity (ID @id, bundle @bundle) @current of @total', [
            '@type' => $entity_type,
            '@id' => $entity_id,
            '@bundle' => $entity->bundle(),
            '@current' => $current,
            '@total' => $total,
          ]);
        }
      }
    }
  }

  /**
   * Prepare all content on the site to export.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function exportAllBatch(): void {
    $content_entity_types = $this->deployManager->getContentEntityTypes();

    if ($this->forceUpdate) {
      $this->fileSystem->deleteRecursive($this->getFolder());
    }

    $operations[] = $this->getInitializeContextOperation();

    $total = 0;
    $current = 1;
    foreach ($content_entity_types as $entity_type => $label) {
      if (in_array($entity_type, $this->getSkipEntityTypeIds())) {
        continue;
      }

      $this->setEntityTypeId($entity_type);
      $entity_ids = $this->getEntityIdsForExport();
      $total += count($entity_ids);

      foreach ($entity_ids as $entity_id) {
        $operations[] = [
          [static::class, 'exportFile'],
          ['exportBatchDefault', $entity_type, $entity_id, $current++, $total],
        ];
      }
    }

    // Use the accumulated total count for batch operations.
    foreach ($operations as $key => &$operation) {
      if ($key === 0) {
        continue;
      }
      $operation[1][4] = $total;
    }
    unset($operation);

    // Set up batch information.
    $batch_definition = [
      'title' => $this->t('Exporting content'),
      'operations' => $operations,
      'finished' => [static::class, 'exportFinished'],
      'progressive' => TRUE,
      'queue' => [
        'class' => DefaultContentDeployBatch::class,
        'name' => 'default_content_deploy:export:' . $this->time->getCurrentMicroTime(),
      ],
    ];

    batch_set($batch_definition);
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
  public static function exportFinished($success, $results, $operations): void {
    if ($success) {
      // Batch processing completed successfully.
      // @phpstan-ignore-next-line
      \Drupal::messenger()->addMessage(t('Batch export completed successfully.'));

      $counts = [];
      if (isset($results['exported_entities'])) {
        foreach ($results['exported_entities'] as $entity_type => $entities) {
          // Count the number of entities per type.
          $counts[$entity_type]['exported'] = count($entities);
        }
      }
      if (isset($results['skipped_entities'])) {
        foreach ($results['skipped_entities'] as $entity_type => $entities) {
          // Count the number of entities per type.
          $counts[$entity_type]['skipped'] = count($entities);
        }
      }

      // Output result counts per entity type.
      foreach ($counts as $type => $count) {
        if ($count['skipped'] ?? 0) {
          // @phpstan-ignore-next-line
          \Drupal::messenger()
            ->addMessage(t('@type: @exported exported, @skipped skipped dynamically (excluded bundle, changed timestamp, event, erroneous, ...)', [
              '@type' => $type,
              '@exported' => $count['exported'] ?? 0,
              '@skipped' => $count['skipped'] ?? 0,
            ]));
        }
        else {
          // @phpstan-ignore-next-line
          \Drupal::messenger()
            ->addMessage(t('@type: @exported exported,', [
              '@type' => $type,
              '@exported' => $count['exported'] ?? 0,
            ]));
        }
      }
    }
    else {
      // Batch processing encountered an error.
      // @phpstan-ignore-next-line
      \Drupal::messenger()->addMessage(t('An error occurred during the batch export process.'), 'error');
    }
  }

  /**
   * Get all entity IDs for export.
   *
   * @return array
   *   Return array of entity ids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getEntityIdsForExport(): array {
    $entity_ids = $this->entityIds;

    // If the Entity IDs option is null then load all IDs.
    if (empty($entity_ids)) {
      $entity_type_definition = $this->entityTypeManager->getDefinition($this->entityTypeId);
      $key_bundle = $entity_type_definition->getKey('bundle');
      $entity_class = $entity_type_definition->getClass();

      $query = $this->entityTypeManager->getStorage($this->entityTypeId)->getQuery();
      $query->accessCheck(FALSE);

      if ($key_bundle && $this->bundle) {
        $query->condition($key_bundle, $this->bundle);
      }

      $time = $this->getTime();
      if ($time && in_array(EntityChangedInterface::class, class_implements($entity_class))) {
        $query->condition('changed', $time, '>=');
      }

      $entity_ids = $query->execute();
    }

    // Remove skipped entities from $exported_entity_ids.
    if (!empty($this->skipEntityIds)) {
      $entity_ids = array_diff($entity_ids, $this->skipEntityIds);
    }

    // For debugging, limit the number of entities.
    // return array_slice($entity_ids, 0, 10, true);.
    return $entity_ids;
  }

  /**
   * Determines whether an entity should be skipped during processing.
   *
   * This method checks if an entity has already been exported, skipped,
   * or is outdated based on its last changed time.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param array $context
   *   The processing context array, which stores exported and skipped entities.
   *
   * @return bool
   *   TRUE if the entity should be skipped, FALSE otherwise.
   */
  protected function skipEntity(EntityInterface $entity, array &$context): bool {
    if (!($entity instanceof ContentEntityInterface)) {
      return TRUE;
    }

    $type_id = $entity->getEntityTypeId();
    $uuid = $entity->uuid();

    // Do not process entity if it has already been written to the file system.
    if (
      !empty($context['results']['exported_entities'][$type_id]) &&
      in_array($uuid, $context['results']['exported_entities'][$type_id])
    ) {
      return TRUE;
    }

    // Do not process entity if it has already been skipped.
    if (
      !empty($context['results']['skipped_entities'][$type_id]) &&
      in_array($uuid, $context['results']['skipped_entities'][$type_id])
    ) {
      return TRUE;
    }

    $time = $this->getTime();
    if ($time && ($entity instanceof EntityChangedInterface && $entity->getChangedTimeAcrossTranslations() < $time)) {
      $context['results']['skipped_entities'][$type_id][] = $uuid;
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Writes serialized entity to a folder.
   *
   * @throws \Exception
   */
  private function writeSerializedEntity(string $entity_type, string $serialized_entity, string $uuid): void {
    // Ensure that the folder per entity type exists.
    $entity_type_folder = "{$this->getFolder()}/{$entity_type}";
    if (!$this->fileSystem->prepareDirectory($entity_type_folder, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \Exception("Unable to create folder {$entity_type_folder}.");
    }

    if (!file_put_contents("{$entity_type_folder}/{$uuid}.json", $serialized_entity)) {
      throw new \Exception("Unable to write serialized entity {$entity_type_folder}/{$uuid}.json to file system.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSerializedContent(ContentEntityInterface $entity, bool $add_metadata, array &$context = []): string {
    $folder = $this->getFolder();

    $event = new PreSerializeEvent($entity, $this->mode, $folder);
    $this->eventDispatcher->dispatch($event);
    $entity = $event->getEntity();

    // Entity could have been removed from the export by an event subscriber!
    if ($entity) {
      if (!$this->adminAccount) {
        $this->adminAccount = $this->adminAccountSwitcher->switchToAdministrator();
      }
      else {
        $this->adminAccountSwitcher->switchTo($this->adminAccount);
      }

      $content = $this->serializer->serialize($entity, 'hal_json', [
        'default_content_deploy' => TRUE,
        'account' => $this->currentUser,
        'uuids' => &$context['results']['uuids'],
        '_links' => &$context['results']['_links'],
      ]);

      $entity_array = $this->serializer->decode($content, 'json');

      // Remove revision.
      if ($entity->getEntityType()->hasKey('revision')) {
        unset($entity_array[$entity->getEntityType()->getKey('revision')]);
      }

      /*
       * Add user password hash manually. Access to that field is blocked by
       * core, so that our Normalizer doesn't get invoked.
       * @see \Drupal\user\UserAccessControlHandler::checkFieldAccess()
       * @see \Drupal\default_content_deploy\Normalizer\PasswordItemNormalizer
       */
      if ($entity->getEntityTypeId() === 'user') {
        $entity_array['pass'][0]['value'] = $entity->getPassword();
        $entity_array['pass'][0]['pre_hashed'] = TRUE;
      }

      if ($add_metadata) {
        if (!$this->getSkipExportTimestamp()) {
          $entity_array['_dcd_metadata']['export_timestamp'] = $this->time
            ->getRequestTime();
        }
      }
      else {
        unset($entity_array['_dcd_metadata']);
      }

      $content = $this->serializer->serialize($entity_array, 'json', [
        'json_encode_options' => JSON_PRETTY_PRINT,
      ]);

      $event = new PostSerializeEvent($entity, $content, $this->mode, $folder);
      $this->eventDispatcher->dispatch($event);

      $this->adminAccountSwitcher->switchBack();

      return $event->getContent();
    }

    return '';
  }

  /**
   * Gets the referenced entities without loading them.
   *
   * This method is faster than calling `referencedEntities()` on the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity from which to retrieve referenced entity IDs.
   *
   * @return array
   *   An associative array of referenced entity field names as keys, and arrays
   *   of entity IDs as values.
   */
  protected function getReferencedEntityIds(ContentEntityInterface $entity): array {
    $referenced_entities = [];
    $skip_entity_types = $this->getSkipEntityTypeIds();

    // Gather a list of referenced entities.
    foreach ($entity->getFields() as $field_items) {
      foreach ($field_items as $field_item) {
        // Loop over all properties of a field item.
        foreach ($field_item->getProperties(TRUE) as $property) {
          if ($property instanceof EntityReference) {
            $entity_type = $property->getTargetDefinition()->getEntityTypeId();
            if (!in_array($entity_type, $skip_entity_types)) {
              try {
                $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
                if ($entity_type_definition->getGroup() === 'content') {
                  // Some entities have "NULL" references, for example a top
                  // level taxonomy term references parent 0, which isn't an
                  // entity.
                  if ($id = $property->getTargetIdentifier()) {
                    $referenced_entities[$entity_type][] = $id;
                  }
                }
              }
              catch (\Exception $e) {
                // Ignore any broken definition.
              }
            }
          }
        }
      }
    }

    return $referenced_entities;
  }

  /**
   * Returns all layout builder referenced blocks of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Keyed array of entities indexed by entity type and ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getEntityLayoutBuilderDependencies(ContentEntityInterface $entity): array {
    $entity_dependencies = [];

    if ($this->moduleHandler->moduleExists('layout_builder') && !in_array('block_content', $this->getSkipEntityTypeIds())) {
      // Gather a list of referenced entities, modeled after
      // ContentEntityBase::referencedEntities().
      foreach ($entity->getFields() as $field_key => $field_items) {
        foreach ($field_items as $field_item) {
          // Loop over all properties of a field item.
          foreach ($field_item->getProperties(TRUE) as $property) {
            // Look only at LayoutBuilder SectionData fields.
            if ($property instanceof SectionData) {
              $section = $property->getValue();
              if ($section instanceof Section) {
                // Get list of components inside the LayoutBuilder Section.
                $components = $section->getComponents();
                foreach ($components as $component_uuid => $component) {
                  // Gather components of type "inline_block:html_block", by
                  // block revision_id.
                  if ($component instanceof SectionComponent) {
                    $configuration = $component->get('configuration');
                    if ($configuration['id'] === 'inline_block:html_block' && !empty($configuration['block_revision_id'])) {
                      $block_revision_id = $configuration['block_revision_id'];
                      $block_revision = $this->entityTypeManager
                        ->getStorage('block_content')
                        ->loadRevision($block_revision_id);
                      $entity_dependencies[] = $block_revision;
                    }
                    // Gather components of type 'block_content:*', by uuid.
                    else {
                      if (substr($configuration['id'], 0, 14) === 'block_content:') {
                        if ($block_uuid = substr($configuration['id'], 14)) {
                          $block_loaded_by_uuid = $this->entityTypeManager
                            ->getStorage('block_content')
                            ->loadByProperties(['uuid' => $block_uuid]);
                          $entity_dependencies[] = reset($block_loaded_by_uuid);
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }

    return $entity_dependencies;
  }

  /**
   * Returns all processed text references of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Keyed array of entities indexed by entity type and ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getEntityProcessedTextDependencies(ContentEntityInterface $entity): array {
    $skip_entity_types = $this->getSkipEntityTypeIds();
    $entity_dependencies = [];

    $field_definitions = $entity->getFieldDefinitions();
    $bundle = $entity->bundle();

    $languages = $entity->getTranslationLanguages();

    foreach ($languages as $langcode => $language) {
      $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);

      foreach ($field_definitions as $key => $field) {
        $field_config = $field->getConfig($bundle);
        $field_storage_definition = $field_config->getFieldStorageDefinition();
        $field_storage_property_definition = $field_storage_definition->getPropertyDefinitions();

        if (isset($field_storage_property_definition['processed'])) {
          $field_name = $field_config->getName();
          $field_data = $entity->get($field_name)->getString();

          $dom = Html::load($field_data);
          $xpath = new \DOMXPath($dom);

          // Iterate over all elements with a data-entity-type attribute.
          foreach ($xpath->query('//*[@data-entity-type and @data-entity-uuid]') as $node) {
            $entity_type = $node->getAttribute('data-entity-type');

            if (!in_array($entity_type, $skip_entity_types)) {
              $uuid = $node->getAttribute('data-entity-uuid');

              // Only add the dependency if it does not already exist.
              if (!isset($entity_dependencies[$uuid])) {
                $entity_loaded_by_uuid = $this->entityTypeManager
                  ->getStorage($entity_type)
                  ->loadByProperties(['uuid' => $uuid]);

                $dependency = reset($entity_loaded_by_uuid);

                if ($dependency instanceof EntityInterface) {
                  $entity_dependencies[$uuid] = $dependency;
                }
              }
            }
          }
        }
      }
    }

    return $entity_dependencies;
  }

  /**
   * Returns all referenced entities of an entity.
   *
   * This method is also recursive to support use-cases like a node -> media
   * -> file.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param array $context
   *   The batch context.
   * @param int $depth
   *   Guard against infinite recursion.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $indexed_dependencies
   *   Previously discovered dependencies.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Keyed array of entities indexed by UUID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getEntityReferencesRecursive(ContentEntityInterface $entity, array $context, ?int $depth = 0, ?array &$indexed_dependencies = []): array {
    $entity_dependencies = [];
    $languages = $entity->getTranslationLanguages();

    foreach (array_keys($languages) as $langcode) {
      $translation = $entity->getTranslation($langcode);
      $entityIds = $this->getReferencedEntityIds($translation);
      foreach ($entityIds as $entityTypeId => $entityIdsByType) {
        if (in_array($entityTypeId, $this->getSkipEntityTypeIds(), TRUE)) {
          continue;
        }
        foreach ($entityIdsByType as $entityId) {
          if ($context['results']['exported_entities'][$entityTypeId][$entityId] ?? FALSE) {
            continue;
          }
          // Ignore entity reference if the referenced entity could not be
          // loaded.
          if ($referenced_entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId)) {
            $entity_dependencies[$entityTypeId][$entityId] = $referenced_entity;
          }
        }
      }

      foreach ($this->getEntityLayoutBuilderDependencies($translation) as $referenced_entity) {
        if (in_array($referenced_entity->getEntityTypeId(), $this->getSkipEntityTypeIds(), TRUE)) {
          continue;
        }
        if ($context['results']['exported_entities'][$referenced_entity->getEntityTypeId()][$referenced_entity->id()] ?? FALSE) {
          continue;
        }
        $entity_dependencies[$referenced_entity->getEntityTypeId()][$referenced_entity->id()] = $referenced_entity;
      }
    }

    if ($this->getTextDependencies()) {
      foreach ($this->getEntityProcessedTextDependencies($entity) as $referenced_entity) {
        if (in_array($referenced_entity->getEntityTypeId(), $this->getSkipEntityTypeIds(), TRUE)) {
          continue;
        }
        if ($context['results']['exported_entities'][$referenced_entity->getEntityTypeId()][$referenced_entity->id()] ?? FALSE) {
          continue;
        }

        if ($referenced_entity instanceof ContentEntityInterface) {
          $entity_dependencies[$referenced_entity->getEntityTypeId()][$referenced_entity->id()] = $referenced_entity;
        }
        else {
          $this->logger->warning(
            $this->t('Invalid text dependency found in @entity_type with ID @id', [
              '@entity_type' => $entity->getEntityTypeId(),
              '@id' => $entity->id(),
            ])
          );
        }
      }
    }

    foreach ($entity_dependencies as $entity_type_dependencies) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $dependent_entity */
      foreach ($entity_type_dependencies as $dependent_entity) {
        // Using UUID to keep dependencies unique to prevent recursion.
        $key = $dependent_entity->uuid();
        if (!isset($indexed_dependencies[$key]) && !$this->skipEntity($dependent_entity, $context)) {
          $indexed_dependencies[$key] = $dependent_entity;
          // Build in some support against infinite recursion.
          if ($depth < 10) {
            $indexed_dependencies += $this->getEntityReferencesRecursive($dependent_entity, $context, $depth + 1, $indexed_dependencies);
          }
        }
      }
    }

    return $indexed_dependencies;
  }

}

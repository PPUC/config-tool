<?php

namespace Drupal\default_content_deploy\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\default_content_deploy\DeployManager;
use Drupal\default_content_deploy\ExporterInterface;
use Drupal\default_content_deploy\ImporterInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\Table;

/**
 * Provides Drush commands for deploying default content.
 *
 * This class contains Drush commands to manage the export, import,
 * and deployment of default content in a Drupal site.
 */
class DefaultContentDeployCommands extends DrushCommands {

  /**
   * DefaultContentDeployCommands constructor.
   *
   * @param \Drupal\default_content_deploy\ExporterInterface $exporter
   *   DCD Exporter.
   * @param \Drupal\default_content_deploy\ImporterInterface $importer
   *   DCD Importer.
   * @param \Drupal\default_content_deploy\DeployManager $deployManager
   *   DCD manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly ExporterInterface $exporter,
    protected readonly ImporterInterface $importer,
    protected readonly DeployManager $deployManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Exports a single entity or group of entities.
   *
   * @param string $entity_type
   *   The entity type to export. If a wrong content entity type is entered,
   *   module displays a list of all content entity types.
   * @param array $options
   *   An associative array of options whose values come
   *   from cli, aliases, config, etc.
   *
   * @command default-content-deploy:export
   *
   * @option entity_ids The IDs of the entities to export (comma-separated list).
   * @option bundle Write out the exported bundle of entity
   * @option skip_entities The ID of the entity to skip.
   * @option skip-export-timestamp Don't include the timestamp in exports.
   * @option force-update Deletes configurations files that are not used on the
   *   site.
   * @option folder Path to the export folder.
   * @option changes-since Only export entities that have been changed since a
   *   given date.
   * @usage drush dcde node
   *   Export all nodes
   * @usage drush dcde node --folder='../content'
   *   Export all nodes from the specified folder.
   * @usage drush dcde node --bundle=page
   *   Export all nodes with bundle page
   * @usage drush dcde node --bundle=page,article --entity_ids=2,3,4
   *   Export all nodes with bundle page or article plus nodes with entities id
   *   2, 3 and 4.
   * @usage drush dcde node --bundle=page,article --skip_entities=5,7
   *   Export all nodes with bundle page or article and skip nodes with entity
   *   id 5 and 7.
   * @usage drush dcde node --skip_entities=5,7
   *   Export all nodes and skip nodes with entity id 5 and 7.
   * @aliases dcde,default-content-deploy-export
   *
   * @throws \Exception
   */
  public function contentDeployExport(
    string $entity_type,
    array $options = [
      'entity_ids' => NULL,
      'bundle' => NULL,
      'skip_entities' => NULL,
      'skip-export-timestamp' => NULL,
      'force-update' => FALSE,
      'folder' => self::OPT,
      'changes-since' => self::OPT,
    ],
  ): void {
    $this->exporter->setVerbose($this->output()->isVerbose());

    $entity_ids = $this->processingArrayOption($options['entity_ids']);
    $skip_ids = $this->processingArrayOption($options['skip_entities']);

    $this->exporter->setEntityTypeId($entity_type);
    if (!empty($options['bundle'])) {
      $this->exporter->setEntityBundle($options['bundle']);
    }

    if (!empty($options['folder'])) {
      $this->exporter->setFolder($options['folder']);
    }

    $this->exporter->setMode('default');
    $this->exporter->setForceUpdate($options['force-update']);
    $this->exporter->setSkipExportTimestamp($options['skip-export-timestamp']);

    if ($entity_ids) {
      $this->exporter->setEntityIds($entity_ids);
    }

    if ($skip_ids) {
      $this->exporter->setSkipEntityIds($skip_ids);
    }

    if (!empty($options['changes-since'])) {
      $this->exporter->setDateTime(new \DateTime($options['changes-since']));
    }

    $this->exporter->export();
    drush_backend_batch_process();
  }

  /**
   * Exports a single entity with references.
   *
   * @param string $entity_type
   *   The entity type to export. If a wrong content entity
   *   type is entered, module displays a list of all content entity types.
   * @param array $options
   *   An associative array of options whose values come
   *   from cli, aliases, config, etc.
   *
   * @command default-content-deploy:export-with-references
   *
   * @option entity_ids The IDs of the entities to export (comma-separated list).
   * @option bundle Write out the exported bundle of entity
   * @option skip_entities The ID of the entity to skip.
   * @option skip-export-timestamp Don't include the timestamp in exports.
   * @option force-update Deletes configurations files that are not used on the
   *   site.
   * @option folder Path to the export folder.
   * @option text_dependencies Whether to include processed text dependencies.
   * @option skip_entity_type The referenced entity types to skip.
   *   Use 'drush dcd-entity-list' for list of all content entities.
   * @option changes-since Only export entities that have been changed since a
   *   given date.
   * @usage drush dcder node
   *   Export all nodes with references
   * @usage drush dcder node --folder='../content'
   *   Export all nodes with references from the specified folder.
   * @usage drush dcder node --bundle=page
   *   Export all nodes with references with bundle page
   * @usage drush dcder node --bundle=page,article --entity_ids=2,3,4
   *   Export all nodes with references with bundle page or article plus nodes
   *   with entity id 2, 3 and 4.
   * @usage drush dcder node --bundle=page,article --skip_entities=5,7
   *   Export all nodes with references with bundle page or article and skip
   *   nodes with entity id 5 and 7.
   * @usage drush dcder node --skip_entities=5,7
   *   Export all nodes and skip nodes with references with entity id 5 and 7.
   * @usage drush dcder node --text_dependencies=TRUE
   *   Export all nodes and include dependencies from processed text fields.
   * @aliases dcder,default-content-deploy-export-with-references
   *
   * @throws \Exception
   */
  public function contentDeployExportWithReferences(
    string $entity_type,
    array $options = [
      'entity_ids' => NULL,
      'bundle' => NULL,
      'skip_entities' => NULL,
      'skip-export-timestamp' => NULL,
      'skip_entity_type' => NULL,
      'force-update' => FALSE,
      'folder' => self::OPT,
      'text_dependencies' => NULL,
      'changes-since' => self::OPT,
    ],
  ): void {
    $this->exporter->setVerbose($this->output()->isVerbose());

    $entity_ids = $this->processingArrayOption($options['entity_ids']);
    $skip_ids = $this->processingArrayOption($options['skip_entities']);
    $skip_type_ids = $this->processingArrayOption($options['skip_entity_type']);

    $this->exporter->setEntityTypeId($entity_type);
    if (!empty($options['bundle'])) {
      $this->exporter->setEntityBundle($options['bundle']);
    }
    $this->exporter->setSkipExportTimestamp($options['skip-export-timestamp']);

    if (!empty($options['folder'])) {
      $this->exporter->setFolder($options['folder']);
    }

    // Set text_dependencies option.
    $text_dependencies = $options['text_dependencies'];

    if (!is_null($text_dependencies)) {
      $text_dependencies = filter_var($text_dependencies, FILTER_VALIDATE_BOOLEAN);
    }

    $this->exporter->setTextDependencies($text_dependencies);

    $this->exporter->setMode('reference');
    $this->exporter->setForceUpdate($options['force-update']);

    if ($entity_ids) {
      $this->exporter->setEntityIds($entity_ids);
    }

    if ($skip_ids) {
      $this->exporter->setSkipEntityIds($skip_ids);
    }

    if ($skip_type_ids) {
      $this->exporter->setSkipEntityTypeIds($skip_type_ids);
    }

    if (!empty($options['changes-since'])) {
      $this->exporter->setDateTime(new \DateTime($options['changes-since']));
    }

    $this->exporter->export();
    drush_backend_batch_process();
  }

  /**
   * Exports a whole site content.
   *
   * Config directory will be emptied
   * and all content of all entities will be exported.
   *
   * Use 'drush dcd-entity-list' for list of all content entities
   * on this system. You can exclude any entity type from export.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @command default-content-deploy:export-site
   *
   * @option add_entity_type DEPRECATED. Will be removed in beta. The dcdes
   *   command exports all entity types.
   * @option force-update Deletes configurations files that are not used on the
   *   site.
   * @option skip-export-timestamp Don't include the timestamp in exports.
   * @option folder Path to the export folder.
   * @option skip_entity_type The entity types to skip.
   *   Use 'drush dcd-entity-list' for list of all content entities.
   * @option changes-since Only export entities that have been changed since a
   *   given date.
   * @usage drush dcdes
   *   Export complete website.
   * @usage drush dcdes --folder='../content'
   *   Export complete website from the specified folder.
   * @usage drush dcdes --skip_entity_type=node,user
   *   Export complete website but skip nodes and users.
   * @usage drush dcdes --changes-since=2021-06-30T18:30:00+02:00
   *   Export all entities changed since the provided date.
   * @aliases dcdes,default-content-deploy-export-site
   *
   * @throws \Exception
   */
  public function contentDeployExportSite(
    array $options = [
      'skip_entity_type' => NULL,
      'force-update' => FALSE,
      'skip-export-timestamp' => NULL,
      'folder' => self::OPT,
      'changes-since' => self::OPT,
    ],
  ): void {
    $this->exporter->setVerbose($this->output()->isVerbose());

    $skip_type_ids = $this->processingArrayOption($options['skip_entity_type']);

    if (!empty($options['folder'])) {
      $this->exporter->setFolder($options['folder']);
    }

    if (!empty($options['changes-since'])) {
      $this->exporter->setDateTime(new \DateTime($options['changes-since']));
    }

    $this->exporter->setMode('all');
    $this->exporter->setForceUpdate($options['force-update']);
    $this->exporter->setSkipExportTimestamp($options['skip-export-timestamp']);

    if ($skip_type_ids) {
      $this->exporter->setSkipEntityTypeIds($skip_type_ids);
    }

    $this->exporter->export();
    drush_backend_batch_process();
  }

  /**
   * Import all the content defined in a content directory.
   *
   * @param array $options
   *   An associative array of options whose values come
   *   from cli, aliases, config, etc.
   *
   * @command default-content-deploy:import
   *
   * @option force-override
   *   All existing content will be overridden to the state
   *   defined in a content directory.
   * @option folder Path to the export folder.
   * @option preserve-ids
   *   Skip correction of IDs of referenced entities. You must be sure that
   *   there are no conflicts with existing entities.
   * @usage drush dcdi
   *   Import content. Existing older content with matching UUID will be
   *   updated. Newer content and existing content with different UUID will be
   *   ignored.
   * @usage drush dcdi --folder='../content'
   *   Import content from the specified folder.
   * @usage drush dcdi --force-override
   *   All existing content will be overridden (locally updated content will be
   *   reverted to the state defined in a content directory).
   * @usage drush dcdi --verbose
   *   Print detailed information about importing entities.
   * @aliases dcdi,default-content-deploy-import
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function contentDeployImport(
    array $options = [
      'force-override' => FALSE,
      'folder' => self::OPT,
      'preserve-ids' => FALSE,
      'incremental' => FALSE,
      'delete' => FALSE,
    ],
  ): void {
    $this->importer->setVerbose($this->output()->isVerbose());

    // Perform read only update.
    $this->importer->setForceOverride($options['force-override']);

    if (!empty($options['folder'])) {
      $this->importer->setFolder($options['folder']);
    }

    $this->importer->setPreserveIds($options['preserve-ids']);
    $this->importer->setIncremental($options['incremental']);
    $this->importer->setDelete($options['delete']);

    $this->importer->prepareForImport();

    $result = $this->importer->getResult();

    if ($this->output()->isVerbose()) {
      $table = new Table($this->output());
      $table->setHeaders(['Entity Type', 'UUID', 'Action']);
      foreach ($result as $uuid => $file) {
        $table->addRow([$file->entity_type_id, $uuid, $file->action]);
      }
      $table->render();
    }

    $count = count($result);
    $this->output()->writeln(dt('Content entities to be imported: @count', [
      '@count' => $count,
    ]));

    if ($count && $this->io()->confirm(dt('Do you really want to continue?'))) {
      $this->importer->import();
      drush_backend_batch_process();
      $this->io()->success(dt('Content has been imported.'));
    }
  }

  /**
   * Get UUID of entity.
   *
   * @param string $entity_type
   *   Entity type ID.
   * @param int $id
   *   ID of entity.
   *
   * @return string
   *   UUID value.
   *
   * @command default-content-deploy:uuid-info
   * @usage drush dcd-uuid-info node 1
   *   Displays the current UUID value of this entity.
   * @aliases dcd-uuid-info,default-content-deploy-uuid-info,dcd-uuid
   *
   * @throws \Exception
   */
  public function entityUuidInfo($entity_type, $id) {
    return $this->deployManager->getEntityUuidById($entity_type, $id);
  }

  /**
   * List current content entity types.
   *
   * @command default-content-deploy:entity-list
   * @usage drush dcd-entity-list
   *   Displays all current content entity types.
   * @aliases dcd-entity-list,default-content-deploy-entity-list
   */
  public function contentEntityList() {
    $content_entity_list = $this->getAvailableEntityTypes();

    $this->output()->writeln($content_entity_list);
  }

  /**
   * Helper for processing array drush options.
   *
   * @param array $option
   *   Drush option.
   *
   * @return array|null
   *   Processed value or NULL.
   */
  private function processingArrayOption($option) {
    if (!is_null($option) && $option != FALSE) {
      $array = explode(',', $option);
    }
    else {
      return NULL;
    }

    return $array;
  }

  /**
   * Converts the available entity list to a readable format.
   *
   * @return string
   *   A string containing the available entity types, formatted as
   *   "machine_name (label)" and separated by new lines.
   */
  private function getAvailableEntityTypes() {
    $function = function ($machine_name, $label) {
      return sprintf("%s (%s)", $machine_name, $label);
    };

    $entity_types = $this->deployManager->getContentEntityTypes();
    $map = array_map($function, array_keys($entity_types), $entity_types);

    return implode(PHP_EOL, $map);
  }

}

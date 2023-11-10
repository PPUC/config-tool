<?php

namespace Drupal\default_content\Commands;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\default_content\ExporterInterface;
use Drupal\default_content\ImporterInterface;
use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for 'Default content' module.
 */
class DefaultContentCommands extends DrushCommands {

  /**
   * The default content exporter.
   *
   * @var \Drupal\default_content\ExporterInterface
   */
  protected $defaultContentExporter;

  /**
   * The default content importer.
   *
   * @var \Drupal\default_content\ImporterInterface
   */
  protected $defaultContentImporter;

  /**
   * A full list of installed modules plus the active profile.
   *
   * @var string[]
   */
  protected $installedExtensions;

  /**
   * DefaultContentCommands constructor.
   *
   * @param \Drupal\default_content\ExporterInterface $default_content_exporter
   *   The default content exporter.
   * @param \Drupal\default_content\ImporterInterface $default_content_importer
   *   The default content importer.
   * @param array[] $installed_modules
   *   Installed modules list from the 'container.modules' container parameter.
   */
  public function __construct(ExporterInterface $default_content_exporter, ImporterInterface $default_content_importer, array $installed_modules) {
    parent::__construct();
    $this->defaultContentExporter = $default_content_exporter;
    $this->defaultContentImporter = $default_content_importer;
    $this->installedExtensions = array_keys($installed_modules);
  }

  /**
   * Exports a single entity.
   *
   * @param string $entity_type_id
   *   The entity type to export.
   * @param int $entity_id
   *   The ID of the entity to export.
   *
   * @command default-content:export
   * @option file Write out the exported content to a file (must end with .yml) instead of stdout.
   * @aliases dce
   */
  public function contentExport($entity_type_id, $entity_id, $options = ['file' => NULL]) {
    $export = $this->defaultContentExporter->exportContent($entity_type_id, $entity_id, $options['file']);

    if (!$options['file']) {
      $this->output()->write($export);
    }
  }

  /**
   * Exports an entity and all its referenced entities.
   *
   * @param string $entity_type_id
   *   The entity type to export.
   * @param int $entity_id
   *   The ID of the entity to export.
   *
   * @command default-content:export-references
   * @option folder Folder to export to, entities are grouped by entity type into directories.
   * @aliases dcer
   */
  public function contentExportReferences($entity_type_id, $entity_id = NULL, $options = ['folder' => NULL]) {
    $folder = $options['folder'];
    if (is_null($entity_id)) {
      $entities = \Drupal::entityQuery($entity_type_id)->accessCheck(FALSE)->execute();
    }
    else {
      $entities = [$entity_id];
    }
    // @todo Add paging.
    foreach ($entities as $entity_id) {
      $this->defaultContentExporter->exportContentWithReferences($entity_type_id, $entity_id, $folder);
    }
  }

  /**
   * Exports all the content defined in a module info file.
   *
   * @param string $module
   *   The name of the module.
   *
   * @command default-content:export-module
   * @aliases dcem
   */
  public function contentExportModule($module) {
    $this->checkExtensions([$module]);
    $module_folder = \Drupal::service('extension.list.module')
      ->get($module)
      ->getPath() . '/content';
    $this->defaultContentExporter->exportModuleContent($module, $module_folder);
  }

  /**
   * Imports default content from installed modules or active profile.
   *
   * @param string[] $extensions
   *   Space-delimited list of module which may contain also the active profile.
   *
   * @option update Overwrite existing entities with values from the default
   *   content.
   *
   * @usage drush default-content:import
   *   Imports default content from all installed modules, including the active
   *   profile.
   * @usage drush dcim my_module other_module custom_profile
   *   Imports default content from <info>my_module</info>,
   *   <info>other_module<info> modules and <info>custom_profile<info> active
   *   profile. Does not overwrite content that was already imported before.
   * @usage drush default-content:import my_module --update
   *   Imports all default content from <info>my_module</info> module, including
   *   content that has already been imported.
   *
   * @command default-content:import
   * @aliases dcim
   */
  public function import(array $extensions, array $options = ['update' => FALSE]): void {
    $count = 0;
    $import_from_extensions = [];
    foreach ($this->checkExtensions($extensions) as $extension) {
      if ($extension_count = count($this->defaultContentImporter->importContent($extension, $options['update']))) {
        $import_from_extensions[] = $extension;
        $count += $extension_count;
      }
    }
    if ($count) {
      $this->logger()->notice(new PluralTranslatableMarkup($count, '1 entity imported from @modules', '@count entities imported from @modules', [
        '@modules' => implode(', ', $import_from_extensions),
      ]));
      return;
    }
    $this->logger()->warning(dt('No content has been imported.'));
  }

  /**
   * Checks and returns a list of extension given the user input.
   *
   * @param array $extensions
   *   An array of modules and/or the active profile.
   *
   * @return array
   *   A list of modules and/or the active profile.
   */
  protected function checkExtensions(array $extensions): array {
    if (!$extensions) {
      return $this->installedExtensions;
    }

    if ($invalid_extensions = array_diff($extensions, $this->installedExtensions)) {
      throw new \InvalidArgumentException(sprintf('Invalid modules or profile passed: %s', implode(', ', $invalid_extensions)));
    }

    return $extensions;
  }

}

<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 *
 */
class DeployManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Site settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * The File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $request;

  /**
   * DeployManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory.
   * @param \Drupal\Core\Site\Settings $settings
   *   Site settings.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The File system.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config, Settings $settings, FileSystemInterface $file_system, RequestStack $request_stack) {
    $this->entityTypeManager = $entity_type_manager;
    $this->config = $config;
    $this->settings = $settings;
    $this->fileSystem = $file_system;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * Get UUID of entity.
   *
   * @param $entity_type
   *   Entity type ID.
   * @param $id
   *   ID of entity.
   *
   * @return string
   *   UUID value.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getEntityUuidById(string $entity_type, string|int $id): string {
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($id);

    return $entity->uuid();
  }

  /**
   * Get all Content Entity Types.
   *
   * @return array
   *   Array of available content entity definitions keyed by type ID.
   *   [entity_type => \Drupal\Core\Entity\EntityTypeInterface]
   */
  public function getContentEntityTypes(): array {
    $types = [];
    $entity_types = $this->entityTypeManager->getDefinitions();

    foreach ($entity_types as $id => $definition) {
      if ($definition instanceof ContentEntityType) {
        if ($definition->getKey('uuid')) {
          $types[$id] = $definition->getLabel();
        }
      }
    }

    ksort($types);

    return $types;
  }

  /**
   * Get content folder.
   *
   * Folder is automatically created on install inside files folder.
   * Or you can override content folder in settings.php file.
   *
   * If no configuration is found, directory is created
   * automatically at public://content_{hash_salt_derived_key};
   *
   * @return string|null
   *   Return path to the content folder or NULL if none is configured.
   *
   * @example override:
   *   $config['default_content_deploy.settings']['content_directory'] = '../content';
   */
  public function getContentFolder(): ?string {
    $config = $this->config->get('default_content_deploy.settings');

    if ($directory = $config->get('content_directory')) {
      return $directory;
    }

    return NULL;
  }

  /**
   * Gets host of current site.
   *
   * @return string
   */
  public function getCurrentHost(): string {
    $protocol = $this->request->getScheme();
    $host = $this->request->getHttpHost();

    return "{$protocol}://{$host}";
  }

  /**
   * Compress the content files to an archive.
   */
  public function compressContent(): void {
    $folder = $this->fileSystem->getTempDirectory() . '/dcd';
    $content_folder = $folder . '/content';

    // Remove old archive.
    $this->fileSystem->deleteRecursive($folder . '/content.tar.gz');

    $archive = new ArchiveTar($folder . '/content.tar.gz', 'gz');
    $archive->addModify($content_folder, basename($content_folder), $content_folder);
  }

  /**
   * Uncompressed an archive with content files.
   *
   * @param $file
   *
   * @throws \Exception
   */
  public function uncompressContent(string $file): void {
    $folder = $this->fileSystem->getTempDirectory() . '/dcd';
    $content_folder = $folder . '/content';

    // Remove old folder.
    $this->fileSystem->deleteRecursive($content_folder);

    $archive = new ArchiveTar($file, 'gz');
    $list = $archive->listContent();

    // Checking the folder structure.
    if (!stripos($list[0]['filename'], 'content/')) {
      $archive->extract($folder);
    }
    else {
      throw new \Exception('The wrong folder structure');
    }
  }

}

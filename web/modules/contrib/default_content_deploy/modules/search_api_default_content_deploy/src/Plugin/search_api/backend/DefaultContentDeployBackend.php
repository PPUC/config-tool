<?php

namespace Drupal\search_api_default_content_deploy\Plugin\search_api\backend;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\default_content_deploy\ExporterInterface;
use Drupal\default_content_deploy\ImporterInterface;
use Drupal\search_api\LoggerTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api_default_content_deploy\Plugin\search_api\datasource\DefaultContentDeployContentEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Default Content Deploy backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_default_content_deploy",
 *   label = @Translation("Default Content Deploy"),
 *   description = @Translation("'Leverage the Search API infrastructure to track and incrementally export content..")
 * )
 */
class DefaultContentDeployBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait {
    PluginFormTrait::submitConfigurationForm as traitSubmitConfigurationForm;
  }

  use PluginDependencyTrait;

  use StringTranslationTrait;

  use LoggerTrait;

  /**
   * The exporter.
   *
   * @var \Drupal\default_content_deploy\ExporterInterface
   */
  protected $exporter;

  /**
   * The importer.
   *
   * @var \Drupal\default_content_deploy\ImporterInterface
   */
  protected $importer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ExporterInterface $exporter, ImporterInterface $importer, EntityTypeManagerInterface $entityTypeManager, FileSystemInterface $file_system, Serializer $serializer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->exporter = $exporter;
    $this->importer = $importer;
    $this->entityTypeManager = $entityTypeManager;
    $this->fileSystem = $file_system;
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('default_content_deploy.exporter'),
      $container->get('default_content_deploy.importer'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('serializer')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getDiscouragedProcessors() {
    return [
      'content_access',
      'double_quote_workaround',
      'highlight',
      'html_filter',
      'ignorecase',
      'ignore_character',
      'number_field_boost',
      'snowball_stemmer',
      'solr_boost_more_recent',
      'solr_regex_replace',
      'solr_dummy_fields',
      'stemmer',
      'stopwords',
      'tokenizer',
      'transliteration',
      'type_boost',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod
    parent::removeIndex($index);

    // @todo delete directories? If not, remove this method.
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function indexItems(IndexInterface $index, array $items) {
    $ret = [];

    $index_third_party_settings = $index->getThirdPartySettings('search_api_default_content_deploy') + search_api_default_content_deploy_default_index_third_party_settings();
    $directory = rtrim($index_third_party_settings['content_directory'], '/') . '/';
    $this->exporter->setFolder($directory);
    $this->exporter->setTextDependencies($index_third_party_settings['text_dependencies']);
    $this->exporter->setSkipExportTimestamp($index_third_party_settings['skip_export_timestamp']);
    $this->exporter->setSkipEntityTypeIds($index_third_party_settings['skip_entity_types'] ?? []);
    $this->exporter->setLinkDomain($index_third_party_settings['link_domain']);

    foreach ($items as $item) {
      $datasource = $item->getDatasource();
      if ($datasource instanceof DefaultContentDeployContentEntity) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        $entity = $item->getOriginalObject()->getEntity();
        if ($this->exporter->exportEntity($entity, $index_third_party_settings['export_referenced_entities'])) {
          [$datasource_id, $item_id] = Utility::splitCombinedId($item->getId());
          if (preg_match('/^dcd_entity:(.+)$/', $datasource_id, $datasource_matches) && preg_match('/:([^:]+)$/', $item_id, $item_matches)) {
            $file_path = $directory . '_deleted/' . $datasource_matches[1] . '/' . $item_matches[1] . '.json';
            if (file_exists($file_path)) {
              $this->fileSystem->delete($file_path);
            }
          }
        }
        // Even if the export "failed" we need to mark the item as indexed
        // because a dcd event subscriber might have skipped the entity.
        $ret[] = $item->getId();
      }
    }

    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $ids) {
    $index_third_party_settings = $index->getThirdPartySettings('search_api_default_content_deploy') + search_api_default_content_deploy_default_index_third_party_settings();

    if ($index_third_party_settings['delete_single_file_allowed']) {
      $directory = rtrim($index_third_party_settings['content_directory'], '/') . '/';
      foreach ($ids as $id) {
        [$datasource_id, $item_id] = Utility::splitCombinedId($id);
        if (preg_match('/^dcd_entity:(.+)$/', $datasource_id, $datasource_matches) && preg_match('/:([^:]+)$/', $item_id, $item_matches)) {
          $file_name = '/' . $item_matches[1] . '.json';
          $file_path = $directory . $datasource_matches[1] . $file_name;
          $deleted_directory = $directory . '_deleted/' . $datasource_matches[1];

          if ($index_third_party_settings['move_deleted_single_file'] && file_exists($file_path) && $this->fileSystem->prepareDirectory($deleted_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
            $file = new \stdClass();
            $file->name = $file_name;
            $file->uuid = str_replace('.json', '', $file->name);
            $file->uri = $deleted_directory . $file->name;
            $file->entity_type_id = basename(dirname($file->uri));
            $file->forceOverride = FALSE;
            $file->action = $this->t('delete');

            $this->fileSystem->move($file_path, $file->uri, FileExists::Replace);

            try {
              $this->importer->decodeFile($file);
              $file->data['_dcd_metadata']['delete_timestamp'] = \Drupal::time()->getRequestTime();
              $content = $this->serializer->serialize($file->data, 'json', [
                'json_encode_options' => JSON_PRETTY_PRINT,
              ]);
              file_put_contents($file->uri, $content);
            }
            catch (\Exception $e) {
              // The file has been moved already, but adding metadata failed.
              // @todo Log the exception.
            }
          }
          else {
            $this->fileSystem->delete($file_path);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $index_third_party_settings = $index->getThirdPartySettings('search_api_default_content_deploy') + search_api_default_content_deploy_default_index_third_party_settings();

    if ($index_third_party_settings['delete_all_files_allowed']) {
      if ($datasource_id) {
        $datasource = $index->getDatasource($datasource_id);
        if ($entity_type_id = $datasource->getEntityTypeId()) {
          $this->fileSystem->deleteRecursive(rtrim($index_third_party_settings['content_directory'], '/') . '/' . $entity_type_id);
        }
      }
      else {
        $this->fileSystem->deleteRecursive($index_third_party_settings['content_directory']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
  }

}

<?php

namespace Drupal\default_content_deploy\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\default_content_deploy\DefaultContentDeployMetadataService;
use Drupal\default_content_deploy\Form\SettingsForm;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Drupal\hal\Normalizer\ContentEntityNormalizer;

/**
 * Configurable Normalizer for DCD edge-cases.
 */
class ConfigurableContentEntityNormalizer extends ContentEntityNormalizer {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The metadata service.
   *
   * @var \Drupal\default_content_deploy\DefaultContentDeployMetadataService
   */
  protected $metadataService;

  /**
   * Constructs a ContentEntityNormalizer object.
   *
   * @param \Drupal\hal\LinkManager\LinkManagerInterface $link_manager
   *   The hypermedia link manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\default_content_deploy\DefaultContentDeployMetadataService $metadata_service
   *   The metadata service.
   */
  public function __construct(LinkManagerInterface $link_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, EntityTypeRepositoryInterface $entity_type_repository, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config, EntityRepositoryInterface $entity_repository, DefaultContentDeployMetadataService $metadata_service) {
    parent::__construct($link_manager, $entity_type_manager, $module_handler, $entity_type_repository, $entity_field_manager);
    $this->config = $config;
    $this->entityRepository = $entity_repository;
    $this->metadataService = $metadata_service;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []) : float|array|int|bool|\ArrayObject|string|null {
    $config = $this->config->get(SettingsForm::CONFIG);
    if ($config->get('skip_computed_fields') ?? FALSE) {
      // Check if the entity has computed fields and remove them.
      foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
        if (isset($entity->$field_name) && $field_definition->isComputed()) {
          unset($entity->$field_name);
          if ($entity instanceof TranslatableInterface) {
            foreach ($entity->getTranslationLanguages(FALSE) as $langcode => $language) {
              $translation = $entity->getTranslation($langcode);
              unset($translation->$field_name);
            }
          }
        }
      }
    }

    $entity_array = parent::normalize($entity, $format, $context);

    if (is_array($entity_array)) {
      foreach ($entity_array as $field => $items) {
        if (!str_starts_with($field, '_')) {
          foreach ($items as $item) {
            foreach ($item as $name => $value) {
              if ('uri' === $name || ('path' === $field && 'value' === $name)) {
                if (preg_match('@^(internal:|entity:|)/?(\w+)/(\d+)([/?#].*|)$@', $value, $matches)) {
                  /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
                  $entity_type_manager = \Drupal::service('entity_type.manager');
                  try {
                    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
                    $storage = $entity_type_manager->getStorage($matches[2]);
                    if ($entity = $storage->load($matches[3])) {
                      $entity_array['_dcd_metadata']['uuids'][$matches[2]][$matches[3]] = $entity->uuid();
                    }
                  }
                  catch (\Exception $e) {
                    // Nop.
                  }
                }
              }
            }
          }
        }
      }
    }

    return $entity_array;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []): mixed {
    if (isset($data['_dcd_metadata'])) {
      $this->metadataService->add($data['uuid'][0]['value'], $data['_dcd_metadata']);
      $this->metadataService->setCorrectionRequired($data['uuid'][0]['value'], FALSE);
      foreach ($data['_dcd_metadata']['uuids'] ?? [] as $entity_type_id => $ids) {
        foreach ($ids as $id => $uuid) {
          try {
            // Load the storage to get an exception, if the entity type doesn't
            // exist. Otherwise, the entity repository will simply return FALSE.
            $this->entityTypeManager->getStorage($entity_type_id);
            if ($entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid)) {
              if (((int) $id) !== ((int) $entity->id())) {
                foreach ($data as $field => &$items) {
                  if (!str_starts_with($field, '_')) {
                    foreach ($items as &$item) {
                      foreach ($item as $name => &$value) {
                        if ('uri' === $name || ('path' === $field && 'value' === $name)) {
                          $value = preg_replace(
                            '@^(.*[/:])' . preg_quote($entity_type_id, '@') . '/' . preg_quote($id, '@') . '([/?#].*|)$@',
                            '$1' . $entity_type_id . '/' . $entity->id() . '$2',
                            $value
                          );
                        }
                      }
                      unset($value);
                    }
                    unset($item);
                  }
                }
                unset($items);
              }
            }
            else {
              $this->metadataService->setCorrectionRequired($data['uuid'][0]['value'], TRUE);
              break 2;
            }
          }
          catch (\Exception $e) {
            $this->metadataService->setCorrectionRequired($data['uuid'][0]['value'], TRUE);
            break 2;
          }
        }
      }

      unset($data['_dcd_metadata']);
    }

    return parent::denormalize($data, $class, $format, $context);
  }

}

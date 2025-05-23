<?php

namespace Drupal\default_content_deploy\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
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
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\default_content_deploy\DefaultContentDeployMetadataService $metadataService
   *   The metadata service.
   */
  public function __construct(LinkManagerInterface $link_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, EntityTypeRepositoryInterface $entity_type_repository, EntityFieldManagerInterface $entity_field_manager, protected ConfigFactoryInterface $config, protected EntityRepositoryInterface $entityRepository, protected DefaultContentDeployMetadataService $metadataService) {
    parent::__construct($link_manager, $entity_type_manager, $module_handler, $entity_type_repository, $entity_field_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []) : float|array|int|bool|\ArrayObject|string|null {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */

    $context += [
      'included_fields' => NULL,
      'uuids' => [],
      '_links' => [],
    ];

    // If the list of fields is not yet limited to specific fields, computed
    // fields might to be excluded. If the list is already limited, we're in a
    // recursion and must not touch the list of fields.
    if ($context['included_fields'] === NULL) {
      $config = $this->config->get(SettingsForm::CONFIG);
      if ($config->get('skip_computed_fields') ?? FALSE) {
        // Check if the entity has computed fields and remove them.
        foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
          if (isset($entity->$field_name) && !$field_definition->isComputed()) {
            $context['included_fields'][] = $field_name;
          }
        }
      }
    }
    elseif (count($context['included_fields']) === 1 && in_array('uuid', $context['included_fields'], TRUE) && isset($context['uuids'][$entity->getEntityTypeId()][$entity->id()])) {
      $normalized = [];
      if (isset($context['_links'][$entity->getEntityTypeId()][$entity->id()])) {
        $normalized['_links'] = $context['_links'][$entity->getEntityTypeId()][$entity->id()];
      }
      return $normalized + ['uuid' => $context['uuids'][$entity->getEntityTypeId()][$entity->id()]];
    }

    $entity_array = parent::normalize($entity, $format, $context);

    if (!empty($entity_array['_links'])) {
      $entity_array['_links'] = array_intersect_key($entity_array['_links'], array_flip(['type']));
      $entity_array['_links']['self']['href'] = '_dcd/' . $entity->getEntityTypeId() . '/' . $entity->id();
    }

    if (isset($entity_array['uuid'])) {
      $context['uuids'][$entity->getEntityTypeId()][$entity->id()] = $entity_array['uuid'];
      if (!empty($entity_array['_links'])) {
        $context['_links'][$entity->getEntityTypeId()][$entity->id()] = $entity_array['_links'];
      }
    }

    if (is_array($entity_array)) {
      foreach ($entity_array as $field => $items) {
        if (!str_starts_with($field, '_')) {
          foreach ($items as $item) {
            foreach ($item as $name => $value) {
              if ('uri' === $name || ('path' === $field && 'value' === $name)) {
                if (preg_match('@^(internal:|entity:|)/?(\w+)/(\d+)([/?#].*|)$@', $value, $matches)) {
                  try {
                    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
                    $storage = $this->entityTypeManager->getStorage($matches[2]);
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

    if (isset($data['_embedded']) && !$this->metadataService->isCorrectionRequired($data['uuid'][0]['value'])) {
      foreach (array_keys($data['_embedded']) as $relation) {
        $field_ids = $this->linkManager->getRelationInternalIds($relation, $context);
        if (empty($field_ids)) {
          $this->metadataService->setCorrectionRequired($data['uuid'][0]['value'], TRUE);
        }
      }
    }

    return parent::denormalize($data, $class, $format, $context);
  }

}

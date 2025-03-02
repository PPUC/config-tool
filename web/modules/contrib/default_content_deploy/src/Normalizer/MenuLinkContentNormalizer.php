<?php

namespace Drupal\default_content_deploy\Normalizer;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\default_content_deploy\DefaultContentDeployMetadataService;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Drupal\serialization\EntityResolver\UuidReferenceInterface;

/**
 * A normalizer to handle menu-link content links to entities.
 */
class MenuLinkContentNormalizer extends ConfigurableContentEntityNormalizer {

  /**
   * Psuedo field name for embedding target entity.
   *
   * @var string
   */
  const PSUEDO_FIELD_NAME = 'menu_link_content_target_entity';

  /**
   * Psuedo field name for embedding parent target entity.
   *
   * @var string
   */
  const PSUEDO_PARENT_FIELD_NAME = 'menu_link_content_parent_entity';

  /**
   * UUID Reference resolver.
   *
   * @var \Drupal\serialization\EntityResolver\UuidReferenceInterface
   */
  protected $uuidReference;

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
   * @param \Drupal\serialization\EntityResolver\UuidReferenceInterface $uuid_reference
   *   The file system service.
   */
  public function __construct(LinkManagerInterface $link_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, EntityTypeRepositoryInterface $entity_type_repository, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config, EntityRepositoryInterface $entity_repository, DefaultContentDeployMetadataService $metadata_service, UuidReferenceInterface $uuid_reference) {
    parent::__construct($link_manager, $entity_type_manager, $module_handler, $entity_type_repository, $entity_field_manager, $config, $entity_repository, $metadata_service);
    $this->uuidReference = $uuid_reference;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [
      'Drupal\menu_link_content\MenuLinkContentInterface' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []) : float|array|int|bool|\ArrayObject|string|null {
    $normalized = parent::normalize($entity, $format, $context);
    if (isset($normalized['link']) && is_array($normalized['link'])) {
      foreach ($normalized['link'] as $key => $link) {
        try {
          $stub = EntityStub::fromEntityUri($link['uri']);
          try {
            if ($target_entity = $this->entityTypeManager->getStorage($stub->getEntityTypeId())->load($stub->getEntityId())) {
              $normalized = $this->embedEntity($entity, $format, $context, $target_entity, $normalized, self::PSUEDO_FIELD_NAME);
              $normalized['link'][$key] += [
                'target_uuid' => $target_entity->uuid(),
              ];
            }
            else {
              // Entity ID no longer exists.
              continue;
            }
          }
          catch (PluginNotFoundException $e) {
            // Entity-type not found.
            continue;
          }
        }
        catch (\InvalidArgumentException $e) {
          // Not an Entity URI link.
          continue;
        }

      }
    }
    if (isset($normalized['parent']) && is_array($normalized['parent'])) {
      foreach ($normalized['parent'] as $parent) {
        if (strpos($parent['value'], PluginBase::DERIVATIVE_SEPARATOR) !== FALSE) {
          [$plugin_id, $parent_uuid] = explode(PluginBase::DERIVATIVE_SEPARATOR, $parent['value']);
          if ($plugin_id === 'menu_link_content' && $parent_entity = $this->entityRepository->loadEntityByUuid('menu_link_content', $parent_uuid)) {
            // This entity has a parent menu link entity, we embed it.
            $normalized = $this->embedEntity($entity, $format, $context, $parent_entity, $normalized, self::PSUEDO_PARENT_FIELD_NAME);
          }
        }
      }
    }
    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []): mixed {
    if (isset($data['link']) && is_array($data['link'])) {
      foreach ($data['link'] as $key => $link) {
        try {
          $stub = EntityStub::fromEntityUri($link['uri']);
          if (isset($link['target_uuid'])) {
            if ($entity = $this->entityRepository->loadEntityByUuid($stub->getEntityTypeId(), $link['target_uuid'])) {
              $data['link'][$key]['uri'] = 'entity:' . $stub->getEntityTypeId() . '/' . $entity->id();
            }
          }
        }
        catch (\InvalidArgumentException $e) {
          continue;
        }
      }
    }
    $entity = parent::denormalize($data, $class, $format, $context);
    return $entity;
  }

  /**
   * Embeds an entity in the normalized data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being serialized.
   * @param string $format
   *   The serialization format.
   * @param array $context
   *   Serializer context.
   * @param \Drupal\Core\Entity\EntityInterface $target_entity
   *   Entity being embedded.
   * @param array $normalized
   *   Current normalized values.
   * @param string $embedded_field_name
   *   Field name to embed the entity using.
   *
   * @return array
   *   Updated normalized values.
   */
  protected function embedEntity(EntityInterface $entity, string $format, array $context, EntityInterface $target_entity, array $normalized, string $embedded_field_name): array {
    // If the parent entity passed in a langcode, unset it before
    // normalizing the target entity. Otherwise, untranslatable fields
    // of the target entity will include the langcode.
    $langcode = $context['langcode'] ?? NULL;
    unset($context['langcode']);
    $context['included_fields'] = ['uuid'];

    // Normalize the target entity.
    $embedded = $this->serializer->normalize($target_entity, $format, $context);
    $link = $embedded['_links']['self'];
    // If the field is translatable, add the langcode to the link
    // relation object. This does not indicate the language of the
    // target entity.
    if ($langcode) {
      $embedded['lang'] = $link['lang'] = $langcode;
    }

    // The returned structure will be recursively merged into the
    // normalized entity so that the items are properly added to the
    // _links and _embedded objects.
    $embedded_field_uri = $this->linkManager->getRelationUri($entity->getEntityTypeId(), $entity->bundle(), $embedded_field_name, $context);
    $normalized['_links'][$embedded_field_uri] = [$link];
    $normalized['_embedded'][$embedded_field_uri] = [$embedded];

    return $normalized;
  }

}

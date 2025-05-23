<?php

namespace Drupal\hal\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\file\FileInterface;
use Drupal\hal\LinkManager\LinkManagerInterface;

/**
 * Converts the Drupal entity object structure to a HAL array structure.
 *
 * @internal
 */
class FileEntityNormalizer extends ContentEntityNormalizer {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = FileInterface::class;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The HAL settings config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $halSettings;

  /**
   * Constructs a FileEntityNormalizer object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\hal\LinkManager\LinkManagerInterface $link_manager
   *   The hypermedia link manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LinkManagerInterface $link_manager, ModuleHandlerInterface $module_handler, ConfigFactoryInterface $config_factory, EntityTypeRepositoryInterface $entity_type_repository, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($link_manager, $entity_type_manager, $module_handler, $entity_type_repository, $entity_field_manager);

    $this->halSettings = $config_factory->get('hal.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [
      FileInterface::class => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityUri(EntityInterface $entity, array $context = []) {
    assert($entity instanceof FileInterface);
    // https://www.drupal.org/project/drupal/issues/2277705 introduced a hack
    // in \Drupal\file\Entity\File::url(), but EntityInterface::url() was
    // deprecated in favor of ::toUrl(). The parent implementation now calls
    // ::toUrl(), but this normalizer (for File entities) needs to override that
    // back to the old behavior because it relies on said hack, not just to
    // generate the value for the 'uri' field of a file (see ::normalize()), but
    // also for the HAL normalization's '_links' value.
    return $entity->createFileUrl(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function hasCacheableSupportsMethod(): bool {
    return TRUE;
  }

}

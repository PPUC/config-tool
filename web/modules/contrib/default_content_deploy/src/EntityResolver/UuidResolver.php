<?php

namespace Drupal\default_content_deploy\EntityResolver;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\default_content_deploy\DefaultContentDeployMetadataService;
use Drupal\serialization\EntityResolver\UuidResolver as CoreUuidResolver;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Resolves entities from data that contains an entity UUID.
 */
class UuidResolver extends CoreUuidResolver {

  /**
   * Constructs a UuidResolver object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\default_content_deploy\DefaultContentDeployMetadataService $metadataService
   *   The metadata service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    protected readonly DefaultContentDeployMetadataService $metadataService,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_repository);
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(NormalizerInterface $normalizer, $data, $entity_type): ?string {
    $id = parent::resolve($normalizer, $data, $entity_type);
    if (NULL === $id) {
      /** @var \Drupal\Core\Entity\EntityTypeInterface $definition */
      $definition = $this->entityTypeManager->getDefinition($entity_type, FALSE);
      if ($definition instanceof ConfigEntityTypeInterface) {
        // Do not correct references to config entities.
        return NULL;
      }

      // In the case of a content entity reference, the referenced entity with
      // this ID is not imported yet. A later correction of the reference is
      // required to use the real ID.
      $this->metadataService->setCorrectionRequiredForCurrentUuid(TRUE);
    }
    return $id;
  }

}

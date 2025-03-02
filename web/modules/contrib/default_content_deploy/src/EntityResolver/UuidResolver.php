<?php

namespace Drupal\default_content_deploy\EntityResolver;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\default_content_deploy\DefaultContentDeployMetadataService;
use Drupal\serialization\EntityResolver\UuidResolver as CoreUuidResolver;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Resolves entities from data that contains an entity UUID.
 */
class UuidResolver extends CoreUuidResolver {

  /**
   * The metadata service.
   *
   * @var \Drupal\default_content_deploy\DefaultContentDeployMetadataService
   */
  protected DefaultContentDeployMetadataService $metadataService;

  /**
   * Constructs a UuidResolver object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, DefaultContentDeployMetadataService  $metadata_service) {
    parent::__construct($entity_repository);
    $this->metadataService = $metadata_service;
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(NormalizerInterface $normalizer, $data, $entity_type) {
    $id = parent::resolve($normalizer, $data, $entity_type);
    if (NULL === $id) {
      // In case of an entity reference, The referenced entity with this is not
      // imported yet. A later correction of the reference is required to use
      // the real ID.
      $this->metadataService->setCorrectionRequiredForCurrentUuid(TRUE);
    }
    return $id;
  }

}

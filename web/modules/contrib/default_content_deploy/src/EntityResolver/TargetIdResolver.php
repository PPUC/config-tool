<?php

namespace Drupal\default_content_deploy\EntityResolver;

use Drupal\serialization\EntityResolver\TargetIdResolver as CoreTargetIdResolver;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Resolves entities from data that contains an entity target ID.
 */
class TargetIdResolver extends CoreTargetIdResolver {

  /**
   * {@inheritdoc}
   */
  public function resolve(NormalizerInterface $normalizer, $data, $entity_type): ?string {
    if (!isset($data['target_id']) && preg_match('@^_dcd/.*/(.+)$@', $data['_links']['self']['href'] ?? '', $matches)) {
      return $matches[1];
    }
    return parent::resolve($normalizer, $data, $entity_type);
  }

}

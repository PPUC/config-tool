<?php

namespace Drupal\search_api_default_content_deploy\Plugin\search_api\datasource;

use Drupal\search_api\Plugin\search_api\datasource\ContentEntityDeriver;

/**
 * Derives a datasource plugin definition for every content entity type.
 *
 * @see \Drupal\search_api_default_content_deploy\Plugin\search_api\datasource\DefaultContentDeployContentEntity
 */
class DefaultContentDeployContentEntityDeriver extends ContentEntityDeriver {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = parent::getDerivativeDefinitions($base_plugin_definition);

    foreach ($this->derivatives as $entity_type_id => &$derivative) {
      $derivative['label'] = 'Default Content Deploy: ' . $derivative['label'];
      $derivative['description'] = $this->t('Exports entities to the %entity_type_id sub-folder.', ['%entity_type_id' => $entity_type_id]);
    }

    return $this->derivatives;
  }

}

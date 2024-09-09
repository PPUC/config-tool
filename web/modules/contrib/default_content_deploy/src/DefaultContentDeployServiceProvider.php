<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\default_content_deploy\Normalizer\ConfigurableContentEntityNormalizer;
use Drupal\default_content_deploy\Normalizer\ConfigurableFieldItemNormalizer;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Alter the container to replace default hal normalizers.
 */
class DefaultContentDeployServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('serializer.normalizer.entity.hal')) {
      // Override the existing service.
      $definition = $container->getDefinition('serializer.normalizer.entity.hal');
      $definition->setClass(ConfigurableContentEntityNormalizer::class);
      $definition->addArgument(new Reference('config.factory'));
      $definition->addArgument(new Reference('entity.repository'));
      $definition->addArgument(new Reference('default_content_deploy.metadata'));
    }

    if ($container->hasDefinition('serializer.normalizer.field_item.hal')) {
      // Override the existing service.
      $definition = $container->getDefinition('serializer.normalizer.field_item.hal');
      $definition->setClass(ConfigurableFieldItemNormalizer::class);
      $definition->addArgument(new Reference('config.factory'));
    }
  }

}

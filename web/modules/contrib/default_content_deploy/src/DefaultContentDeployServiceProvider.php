<?php

namespace Drupal\default_content_deploy;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\default_content_deploy\Normalizer\ConfigurableContentEntityNormalizer;
use Drupal\default_content_deploy\Normalizer\ConfigurableFieldItemNormalizer;
use Symfony\Component\DependencyInjection\Definition;
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

    // Add a normalizer service for file entities.
    $service_definition = new Definition('Drupal\default_content_deploy\Normalizer\FileEntityNormalizer', [
      new Reference('hal.link_manager'),
      new Reference('entity_type.manager'),
      new Reference('module_handler'),
      new Reference('entity_type.repository'),
      new Reference('entity_field.manager'),
      new Reference('config.factory'),
      new Reference('entity.repository'),
      new Reference('default_content_deploy.metadata'),
      new Reference('file_system'),
    ]);
    // The priority must be higher than that of
    // serializer.normalizer.file_entity.hal in hal.services.yml.
    $service_definition->addTag('normalizer', ['priority' => 30]);
    $service_definition->setPublic(TRUE);
    $container->setDefinition('serializer.normalizer.entity.file_entity', $service_definition);

    // Add a normalizer service for file fields.
    $service_definition = new Definition('Drupal\default_content_deploy\Normalizer\FileItemNormalizer', array(
      new Reference('hal.link_manager'),
      new Reference('serializer.entity_resolver'),
      new Reference('entity_type.manager'),
    ));
    // Supersede EntityReferenceItemNormalizer.
    $service_definition->addTag('normalizer', ['priority' => 20]);
    $service_definition->setPublic(TRUE);
    $container->setDefinition('serializer.normalizer.entity_reference_item.file_entity', $service_definition);
  }

}

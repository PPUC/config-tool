<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters PPUC game routes.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('view.game_all_switches.all_switches');
    if (!$route) {
      return;
    }

    $parameters = $route->getOption('parameters') ?: [];
    $parameters['node'] = ['type' => 'entity:node'];

    $route
      ->setRequirement('node', '\d+')
      ->setOption('parameters', $parameters)
      ->setOption('_node_operation_route', TRUE);
  }

}

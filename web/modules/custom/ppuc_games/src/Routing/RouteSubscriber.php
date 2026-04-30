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
    $route_names = [
      'view.game_all_switches.all_switches',
      'view.game_all_coils.all_coils',
      'view.game_all_lamps.all_lamps',
    ];

    foreach ($route_names as $route_name) {
      $route = $collection->get($route_name);
      if (!$route) {
        continue;
      }

      $parameters = $route->getOption('parameters') ?: [];
      $parameters['node'] = ['type' => 'entity:node'];

      $route
        ->setRequirement('node', '\d+')
        ->setOption('parameters', $parameters)
        ->setOption('_node_operation_route', TRUE);
    }
  }

}

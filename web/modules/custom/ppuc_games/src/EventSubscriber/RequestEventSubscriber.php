<?php

namespace Drupal\ppuc_games\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RequestEventSubscriber implements EventSubscriberInterface {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs the response subscriber.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest'];

    return $events;
  }

  /**
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function onRequest(RequestEvent $event): void {
    $current_route = $this->routeMatch->getRouteName();

    switch ($current_route) {
      case 'entity.node.canonical':
      case 'ppuc_games.zip':
      case 'ppuc_games.yaml':
        $node = $this->routeMatch->getParameter('node');

        if ($node instanceof NodeInterface) {
          $nid = NULL;
          if ($node->hasField('field_game')) {
            $nid = $node->field_game->target_id;
          }
          elseif ($node->hasField('field_i_o_board')) {
            $nid = $node->field_i_o_board->target_id;
          }
          elseif ($node->hasField('field_string')) {
            $nid = $node->field_string->target_id;
          }
          elseif ($node->hasField('field_switch_matrix')) {
            $nid = $node->field_switch_matrix->target_id;
          }
          elseif ($node->hasField('field_pwm_device')) {
            $nid = $node->field_pwm_device->target_id;
          }

          if ($nid) {
            $response = new RedirectResponse(str_replace('/node/' . $node->id(), '/node/' . $nid, $event->getRequest()->getUri()));
            $event->setResponse($response);
            $event->stopPropagation();
          }
        }

        break;
    }
  }

}

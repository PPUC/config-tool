<?php

namespace Drupal\search_api_default_content_deploy\EventSubscriber;

use Drupal\default_content_deploy\Event\DefaultContentDeployEvents;
use Drupal\default_content_deploy\Event\IndexAwareEventInterface;
use Drupal\default_content_deploy\Event\PostSerializeEvent;
use Drupal\default_content_deploy\ExporterInterface;
use Drupal\default_content_deploy\DeployManager;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api_default_content_deploy\Plugin\search_api\backend\DefaultContentDeployBackend;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriptions for events dispatched by default_content_deploy.
 */
class DefaultContentDeployEventSubscriber implements EventSubscriberInterface {

  /**
   * The index ID for identifying the search index.
   *
   * @var string
   */
  protected string $indexId = '';

  /**
   * Constructs the event subscriber.
   *
   * @param \Drupal\default_content_deploy\ExporterInterface $exporter
   *   The exporter service.
   * @param \Drupal\default_content_deploy\DeployManager $deployManager
   *   The deploy manager service.
   */
  public function __construct(
    protected ExporterInterface $exporter,
    protected DeployManager $deployManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[DefaultContentDeployEvents::PRE_SERIALIZE] = [['setIndexId', 1000]];
    $events[DefaultContentDeployEvents::POST_SERIALIZE] = [['setIndexId', 1000], ['adjustContent', 1000]];
    $events[SearchApiEvents::INDEXING_ITEMS] = [['identifyIndex']];

    return $events;
  }

  /**
   * Sets the index ID in the event.
   *
   * @param \Drupal\default_content_deploy\Event\IndexAwareEventInterface $event
   *   The event instance.
   */
  public function setIndexId(IndexAwareEventInterface $event): void {
    $event->setIndexId($this->indexId);
  }

  /**
   * Identifies the search index and stores its ID.
   *
   * @param \Drupal\search_api\Event\IndexingItemsEvent $event
   *   The event instance for indexing items.
   */
  public function identifyIndex(IndexingItemsEvent $event): void {
    $server = $event->getIndex()->getServerInstance();
    if ($server->hasValidBackend() && $server->getBackend() instanceof DefaultContentDeployBackend) {
      $this->indexId = $event->getIndex()->id();
    }
  }

  /**
   * Adjusts the content links and prevents redundant file writes.
   *
   * @param \Drupal\default_content_deploy\Event\PostSerializeEvent $event
   *   The event instance for post-serialization.
   */
  public function adjustContent(PostSerializeEvent $event): void {
    if ($event->getIndexId()) {
      $link_domain = $this->exporter->getLinkDomain();
      $current_host = $this->deployManager->getCurrentHost();

      if ($link_domain !== $current_host) {
        // Adjust the link domain.
        $link_domain = str_replace('/', '\/', $link_domain);
        $current_host = str_replace('/', '\/', $current_host);
        $event->setContent(str_replace($current_host, $link_domain, $event->getContent()));
      }

      $entity = $event->getEntity();
      $file_path = rtrim($event->getFolder(), '/') . '/' . $entity->getEntityTypeId() . '/' . $entity->uuid() . '.json';
      if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        if (preg_replace('/"export_timestamp": \d+/', '', $content) === preg_replace('/"export_timestamp": \d+/', '', $event->getContent())) {
          // Everything except the export timestamp is identical, so don't
          // write the file.
          $event->setContent('');
        }
      }
    }
  }

}

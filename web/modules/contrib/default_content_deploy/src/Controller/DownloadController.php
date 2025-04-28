<?php

namespace Drupal\default_content_deploy\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\FileSystemInterface;
use Drupal\default_content_deploy\DeployManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Returns responses for config module routes.
 */
class DownloadController implements ContainerInjectionInterface {

  /**
   * The DCD manager.
   *
   * @var \Drupal\default_content_deploy\DeployManager
   */
  protected $deployManager;

  /**
   * The File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The Event dispatcher.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * DownloadController constructor.
   *
   * @param \Drupal\default_content_deploy\DeployManager $deploy_manager
   *   The DCD manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The File system.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The Event dispatcher.
   */
  public function __construct(DeployManager $deploy_manager, FileSystemInterface $file_system, EventDispatcherInterface $event_dispatcher) {
    $this->deployManager = $deploy_manager;
    $this->fileSystem = $file_system;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('default_content_deploy.manager'),
      $container->get('file_system'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * Return binary archive file for download.
   *
   * @param string|null $file_name
   *   The name of the file to be used in the response, if provided.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The response containing the compressed content file for download.
   */
  public function downloadCompressedContent(?string $file_name = NULL) {
    $this->deployManager->compressContent();
    $path = $this->fileSystem->getTempDirectory() . '/dcd/content.tar.gz';

    $sanitized_filename = 'content.tar.gz';
    if ($file_name) {
      $event = new FileUploadSanitizeNameEvent($file_name, 'tar.gz');
      $this->eventDispatcher->dispatch($event);
      $sanitized_filename = $event->getFilename();
    }

    $headers = [
      'Content-Type' => 'application/tar+gzip',
      'Content-Description' => 'File Download',
      'Content-Disposition' => 'attachment; filename=' . $sanitized_filename,
    ];

    return new BinaryFileResponse($path, 200, $headers);
  }

}

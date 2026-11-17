<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\views\area;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Counts the LEDs a view lists per LED kind.
 *
 * Meant for "Game: All Lamps", to see how many bullet pixels, rings and strip
 * LEDs a game needs. It counts the rows of the view, so the exposed filters
 * apply: pick a string and the summary is for that string. That only adds up
 * because the view has no pager; with one, it would count the current page.
 */
#[ViewsArea('ppuc_led_kind_summary')]
final class LedKindSummary extends AreaPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }

    $counts = [];
    $without = 0;
    foreach ($this->view->result as $row) {
      $led = $row->_entity ?? NULL;
      if (!$led instanceof NodeInterface || !$led->hasField('field_led_kind')) {
        continue;
      }
      $kind = $led->get('field_led_kind')->target_id;
      if ($kind === NULL) {
        $without++;
      }
      else {
        $counts[(int) $kind] = ($counts[(int) $kind] ?? 0) + 1;
      }
    }

    $rows = [];
    $tags = ['taxonomy_term_list:led_kind'];
    // In vocabulary order, and every kind, so a kind the game does not use
    // shows as 0 rather than not at all.
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($storage->loadTree('led_kind', 0, NULL, TRUE) as $term) {
      $tags = array_merge($tags, $term->getCacheTags());
      $image = $this->thumbnail($term->get('field_image_media')->entity);
      $rows[] = [
        ['data' => $image ?? ''],
        ['data' => $term->toLink()->toRenderable()],
        ['data' => $counts[(int) $term->id()] ?? 0, 'class' => ['ppuc-led-kind-summary__count']],
      ];
    }
    $rows[] = [
      '',
      $this->t('No kind'),
      ['data' => $without, 'class' => ['ppuc-led-kind-summary__count']],
    ];

    return [
      '#type' => 'table',
      '#caption' => $this->t('LEDs per kind'),
      '#header' => ['', $this->t('Kind'), $this->t('LEDs')],
      '#rows' => $rows,
      '#footer' => [[
        '',
        ['data' => $this->t('Total'), 'header' => TRUE],
        ['data' => count($this->view->result), 'header' => TRUE, 'class' => ['ppuc-led-kind-summary__count']],
      ]],
      '#attributes' => ['class' => ['ppuc-led-kind-summary']],
      '#attached' => ['library' => ['ppuc_games/led_kind_summary']],
      '#cache' => ['tags' => $tags],
    ];
  }

  /**
   * Renders the kind's image in the small style the Kind column uses.
   */
  private function thumbnail(mixed $media): ?array {
    if (!$media || !$media->hasField('field_media_image')) {
      return NULL;
    }
    $file = $media->get('field_media_image')->entity;
    if (!$file) {
      return NULL;
    }
    return [
      '#theme' => 'image_style',
      '#style_name' => 'ppuc_led_kind_thumbnail',
      '#uri' => $file->getFileUri(),
      '#alt' => $media->get('field_media_image')->alt ?? $media->label(),
      '#cache' => ['tags' => array_merge($media->getCacheTags(), $file->getCacheTags())],
    ];
  }

}

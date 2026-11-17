<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Offers the "LEDs per kind" summary as a header or footer for views.
 */
final class LedKindSummaryViewsData {

  /**
   * Implements hook_views_data_alter().
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $data['views']['ppuc_led_kind_summary'] = [
      'title' => new TranslatableMarkup('LEDs per kind'),
      'help' => new TranslatableMarkup('Counts the addressable LEDs the view lists per LED kind.'),
      'area' => [
        'id' => 'ppuc_led_kind_summary',
      ],
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Sorts "Game: All Lamps" by string first when sorting by string position.
 *
 * A position only means something within its string: every string has an LED
 * at position 1. Sorted by position alone, the first LEDs of all strings come
 * together, then all the second ones, which is no order anybody wants. So the
 * click sort on String Position sorts by string first, in the order the String
 * filter lists them - board number, then string title, with the string ID
 * keeping two strings of the same name apart - and by position within each.
 */
final class AllLampsStringPositionSort {

  /**
   * Implements hook_views_query_alter().
   */
  #[Hook('views_query_alter')]
  public function viewsQueryAlter(ViewExecutable $view, QueryPluginBase $query): void {
    if ($view->id() !== 'game_all_lamps' || !$query instanceof Sql) {
      return;
    }
    if (($view->style_plugin->active ?? NULL) !== 'field_string_position') {
      return;
    }
    $string = $view->relationship['field_string']->alias ?? NULL;
    if ($string === NULL) {
      return;
    }

    $direction = strtolower((string) $view->getRequest()->query->get('sort')) === 'desc' ? 'DESC' : 'ASC';

    // addOrderBy() appends, and the position sort is already there, so the
    // string keys are collected on their own and put in front of it.
    $position_sorts = $query->orderby;
    $query->orderby = [];

    $board = $view->relationship['field_i_o_board']->alias ?? NULL;
    if ($board !== NULL) {
      $board_number = $query->ensureTable('node__field_number', $board);
      if ($board_number) {
        $query->addOrderBy($board_number, 'field_number_value', $direction, 'ppuc_string_board_number');
      }
    }
    $query->addOrderBy($string, 'title', $direction, 'ppuc_string_title');
    $query->addOrderBy($string, 'nid', $direction, 'ppuc_string_nid');

    $query->orderby = array_merge($query->orderby, $position_sorts);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Limits the String filter on "Game: All Lamps" to the game's own strings.
 *
 * The view's entity reference filter offers every addressable LED string of
 * every game, and strings are typically named alike ("String 1"), so the list
 * would be long and ambiguous. The view's argument is the game, which narrows
 * it to the strings that can actually match.
 */
final class AllLampsStringFilter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_views_data_alter().
   *
   * Views offers entity reference columns only as a numeric filter, which
   * wants a node ID typed in. Core's entity_reference filter gives the select
   * list instead, but has to be opted into per column; the view's config naming
   * the plugin is not enough, because handlers are built from views data.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (isset($data['node__field_string']['field_string_target_id']['filter'])) {
      $data['node__field_string']['field_string_target_id']['filter']['id'] = 'entity_reference';
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for views_exposed_form.
   */
  #[Hook('form_views_exposed_form_alter')]
  public function exposedFormAlter(array &$form, FormStateInterface $form_state): void {
    $view = $form_state->get('view');
    if ($view?->id() !== 'game_all_lamps' || !isset($form['string']['#options'])) {
      return;
    }

    $game_id = $view->args[0] ?? NULL;
    if (!is_numeric($game_id)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'addressable_leds')
      ->condition('field_i_o_board.entity.field_game.target_id', (int) $game_id)
      ->sort('field_i_o_board.entity.field_number.value')
      ->sort('title')
      ->execute();

    $options = array_intersect_key($form['string']['#options'], ['All' => TRUE]);
    foreach ($storage->loadMultiple($ids) as $string) {
      $options[$string->id()] = $string->label();
    }
    $form['string']['#options'] = $options;
  }

}

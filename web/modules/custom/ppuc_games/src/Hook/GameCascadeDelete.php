<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Takes everything a game owns with it when the game is deleted.
 *
 * A game is not one node. Its boards point at it, switches, PWM devices and
 * LED strings point at the boards, and LEDs point at the strings - around 160
 * nodes for a game the wizard built. Deleting only the game left all of them
 * behind, pointing at nothing: still listed, still editable, and exported by
 * nobody. Building the game again then produced a second set beside the first.
 *
 * Only a game starts this. Deleting a single board leaves its devices alone,
 * because somebody doing that may be about to move them to another board.
 *
 * Media - the ROM, the music, the location images - is not touched: a media
 * item can be referenced by more than one game.
 */
class GameCascadeDelete {

  use StringTranslationTrait;

  /**
   * What points at what: bundle to the field its children reference it by.
   *
   * Every one of these fields is single-valued, so a child has exactly one
   * parent and nothing found this way is shared with another game.
   */
  private const CHILDREN_BY = [
    'game' => 'field_game',
    'i_o_board' => 'field_i_o_board',
    'addressable_leds' => 'field_string',
    'pwm_device' => 'field_pwm_device',
    'switch_matrix' => 'field_switch_matrix',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Implements hook_node_delete().
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    if ($node->bundle() !== 'game') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = array_merge(...array_values($this->descendants($node)));
    // In pieces, so that a game's worth of nodes is never loaded at once.
    foreach (array_chunk($ids, 50) as $chunk) {
      $storage->delete($storage->loadMultiple($chunk));
    }
  }

  /**
   * Says on the confirmation form what else is about to go.
   */
  #[Hook('form_node_game_delete_form_alter')]
  public function deleteFormAlter(array &$form, FormStateInterface $form_state): void {
    $game = $form_state->getFormObject()->getEntity();
    $descendants = $this->descendants($game);
    if (!$descendants) {
      return;
    }

    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple(array_keys($descendants));
    $items = [];
    foreach ($descendants as $bundle => $ids) {
      $items[] = sprintf('%d × %s', count($ids), isset($types[$bundle]) ? $types[$bundle]->label() : $bundle);
    }

    $form['ppuc_cascade'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Everything that belongs to this game is deleted with it:'),
      '#items' => $items,
      '#weight' => -10,
    ];
  }

  /**
   * Every node under a game, at any depth.
   *
   * @return array<string, int[]>
   *   Bundle to node ids. The game itself is not included.
   */
  private function descendants(NodeInterface $game): array {
    if ($game->id() === NULL) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');

    $found = [];
    $parents = ['game' => [(int) $game->id()]];
    while ($parents) {
      $next = [];
      foreach ($parents as $bundle => $parentIds) {
        $field = self::CHILDREN_BY[$bundle] ?? NULL;
        if ($field === NULL) {
          continue;
        }
        $ids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition($field . '.target_id', $parentIds, 'IN')
          ->execute();
        if (!$ids) {
          continue;
        }
        // The bundle is what decides whether to look further down, and loading
        // the nodes just to ask for it would be the expensive way to find out.
        foreach ($this->bundlesOf($ids) as $childBundle => $childIds) {
          $found[$childBundle] = array_merge($found[$childBundle] ?? [], $childIds);
          $next[$childBundle] = array_merge($next[$childBundle] ?? [], $childIds);
        }
      }
      $parents = $next;
    }

    return $found;
  }

  /**
   * Groups node ids by bundle.
   *
   * @return array<string, int[]>
   */
  private function bundlesOf(array $ids): array {
    $grouped = [];
    $rows = $this->database->select('node', 'n')
      ->fields('n', ['nid', 'type'])
      ->condition('n.nid', $ids, 'IN')
      ->execute();
    foreach ($rows as $row) {
      $grouped[$row->type][] = (int) $row->nid;
    }
    return $grouped;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Finds and creates the ppuc.ini settings record belonging to a game.
 *
 * Every game has exactly one. That was not always true: the settings record
 * used to be something you added by hand from an "Add PPUC Settings" button, so
 * in practice no game had one and every exported ppuc.ini was built entirely
 * from the hard-coded fallbacks in GamesController::buildPpucIni(). Editing a
 * value meant editing the exported file, which the next export overwrote.
 *
 * They are created with the game instead, and this class is the one place that
 * knows how. The controller, the node hooks and the update hook that backfills
 * existing games all come through here so that a settings record is always
 * shaped the same way.
 */
class GameSettings {

  use StringTranslationTrait;

  /**
   * The bundle holding the ini values.
   */
  public const BUNDLE = 'ppuc_settings';

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Returns the settings record of a game, or NULL if it has none.
   *
   * The newest wins. A site that created several by hand before these were
   * managed automatically keeps whichever was saved last, which is the one its
   * exports were already using.
   *
   * @param \Drupal\node\NodeInterface $game
   *   The game node.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The settings node, or NULL.
   */
  public function find(NodeInterface $game): ?NodeInterface {
    if ($game->bundle() !== 'game' || $game->id() === NULL) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLE)
      ->condition('field_game.target_id', $game->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $node = $storage->load(reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Returns the settings record of a game, creating it if there is none.
   *
   * Called when a game is created, when an existing game is backfilled, and as
   * a safety net when the settings tab is opened - a game imported from an
   * archive written before this existed has no settings record, and the tab has
   * to lead somewhere rather than 404.
   *
   * @param \Drupal\node\NodeInterface $game
   *   The game node.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The settings node, or NULL if the game cannot have one.
   */
  public function getOrCreate(NodeInterface $game): ?NodeInterface {
    if ($game->bundle() !== 'game' || $game->id() === NULL) {
      return NULL;
    }

    $settings = $this->find($game);
    if ($settings instanceof NodeInterface) {
      return $settings;
    }

    $settings = $this->entityTypeManager->getStorage('node')->create([
      'type' => self::BUNDLE,
      // Administrative only; it is never written to ppuc.ini. Naming it after
      // the game is what makes a list of settings records readable.
      'title' => $this->t('@game settings', ['@game' => $game->getTitle()]),
      'field_game' => ['target_id' => $game->id()],
      'uid' => $game->getOwnerId(),
      // Left unpublished would hide it from the export query's own site.
      'status' => 1,
    ]);
    $settings->save();

    return $settings;
  }

  /**
   * Deletes the settings record of a game.
   *
   * A settings record is meaningless without its game: field_game is required,
   * and nothing else ever looks one up. Since they are now created with the
   * game rather than by hand, they are removed with it too, instead of being
   * left behind as a node nobody can reach.
   *
   * @param \Drupal\node\NodeInterface $game
   *   The game node being deleted.
   */
  public function deleteFor(NodeInterface $game): void {
    if ($game->bundle() !== 'game' || $game->id() === NULL) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLE)
      ->condition('field_game.target_id', $game->id())
      ->execute();

    if ($ids !== []) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\GameSettings;

/**
 * Keeps a game and its ppuc.ini settings record together.
 *
 * The settings record used to be optional, added from a button, and so in
 * practice never present - every exported ppuc.ini came from the fallbacks in
 * the exporter and nothing an operator changed could be stored. Creating it
 * with the game removes that whole state: the settings tab always has
 * something to edit, and the values travel with the game through the
 * default_content_deploy export like any other node.
 *
 * Creation happens on insert rather than on load. Loading a game must stay a
 * read - games are loaded by views, autocompletes and the exporter, often on
 * cached anonymous requests, and creating a node from inside a load hook would
 * write on GET and recurse through entity loading.
 */
class GameSettingsLifecycle {

  public function __construct(protected GameSettings $gameSettings) {}

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if ($node->bundle() !== 'game') {
      return;
    }

    // An import brings its own settings record along, so this only creates one
    // where the archive predates them.
    $this->gameSettings->getOrCreate($node);
  }

  /**
   * Implements hook_node_delete().
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    if ($node->bundle() !== 'game') {
      return;
    }

    $this->gameSettings->deleteFor($node);
  }

}

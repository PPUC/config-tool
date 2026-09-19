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

    // An import is not an authoring action, and it brings its own settings
    // record along a moment later. default_content_deploy orders an archive by
    // the source site's node ids, and a game always has a lower id than the
    // settings record created with it, so the game lands first. Creating one
    // here would therefore always beat the archive's - and default_content_
    // deploy skips an entity whose stored copy is newer than the file, so the
    // record just made would win and the imported values would be dropped
    // without a word. The archive's record is the authority; wait for it.
    if ($node->isSyncing()) {
      return;
    }

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

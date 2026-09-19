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

  /**
   * Namespace for deriving a settings record's uuid from its game's.
   *
   * A game has exactly one settings record, so the record's identity is a
   * function of the game's rather than something independent. That matters on
   * import: default_content_deploy sorts the archive by the source site's node
   * ids and a game always has a lower id than the settings record created with
   * it, so the game is imported first, hook_node_insert fires, and a record is
   * created before the archive's own arrives. With independent uuids that is
   * two records, the newer empty one wins the lookup, and importing a game
   * silently discards every ini value it was carrying. With a derived uuid the
   * two are the same entity and the import updates it in place.
   */
  protected const UUID_NAMESPACE = '6f0d4e46-5b3a-4c52-9f19-7a5a2f2e1c80';

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

    // By uuid first. During an import the record may already be there while
    // its field_game reference is still waiting for the correction pass, and
    // the query below would not see it yet.
    $by_uuid = $storage->loadByProperties([
      'type' => self::BUNDLE,
      'uuid' => $this->uuidFor($game),
    ]);
    if ($by_uuid !== []) {
      $node = reset($by_uuid);
      if ($node instanceof NodeInterface) {
        return $node;
      }
    }

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
      // Derived, not random: see UUID_NAMESPACE.
      'uuid' => $this->uuidFor($game),
      // Administrative only; it is never written to ppuc.ini. Naming it after
      // the game is what makes a list of settings records readable.
      'title' => $this->t('@game settings', ['@game' => $game->getTitle()]),
      'field_game' => ['target_id' => $game->id()],
      'uid' => $game->getOwnerId(),
      // Left unpublished would hide it from the export query's own site.
      'status' => 1,
    ]);

    // A record nobody has edited must lose to one that carries real settings.
    // default_content_deploy imports an entity only when the file is newer
    // than the stored copy, so a placeholder stamped with the current time
    // would make an archive's settings unimportable for that game. Saying
    // "never modified" literally keeps the placeholder from outranking them.
    $settings->setChangedTime(1);
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

  /**
   * The uuid a game's settings record has, on every site that holds the game.
   *
   * @param \Drupal\node\NodeInterface $game
   *   The game node.
   *
   * @return string
   *   A version 5 uuid derived from the game's own.
   */
  public function uuidFor(NodeInterface $game): string {
    return $this->uuidV5(self::UUID_NAMESPACE, (string) $game->uuid());
  }

  /**
   * RFC 4122 version 5 uuid: the SHA-1 of a namespace and a name.
   *
   * Drupal's uuid service only generates version 4, which is random and so
   * cannot give two sites the same answer for the same game.
   */
  protected function uuidV5(string $namespace, string $name): string {
    $hex = str_replace('-', '', $namespace);
    $bytes = '';
    for ($i = 0; $i < strlen($hex); $i += 2) {
      $bytes .= chr((int) hexdec(substr($hex, $i, 2)));
    }

    $hash = sha1($bytes . $name);

    return sprintf(
      '%08s-%04s-%04x-%04x-%12s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      // Version 5.
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
      // RFC 4122 variant.
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
      substr($hash, 20, 12)
    );
  }

}

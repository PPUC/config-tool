<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The default content export and its _thumbs index must list the same entities.
 *
 * default_content_deploy builds the import list from _thumbs, not from the
 * entity folders. An entity file with no thumb is therefore never imported -
 * silently, because the importer does not know it is there - and a thumb with
 * no entity file makes every import log a decode error for a file that no
 * longer exists.
 *
 * Both drifts have happened, and both are the kind that only shows up as a
 * feature quietly not working: the Opto_16 board type shipped without its
 * thumb, so the game wizard refused to allocate opto switches on a site whose
 * content had been imported exactly as the README said to.
 *
 * A thumb is also only ever a copy of its entity file's `_dcd_metadata`, so an
 * entity file missing that property has a thumb that nothing can reproduce:
 * `drush default-content-deploy:sync-thumbs` would empty it and put the drift
 * straight back.
 *
 * Adding or removing default content by hand is what breaks all three. A real
 * `drush dcde` export writes every half.
 */
#[Group('ppuc_games')]
class DefaultContentIndexTest extends TestCase {

  private const DEFAULT_CONTENT = __DIR__ . '/../../../../../../sites/default/files/default_content';

  /**
   * @return array<string, string[]>
   *   Entity type to the uuids its folder holds.
   */
  private function exportedEntities(): array {
    $found = [];
    foreach (glob(self::DEFAULT_CONTENT . '/*', GLOB_ONLYDIR) as $folder) {
      $type = basename($folder);
      if ($type === '_thumbs') {
        continue;
      }
      $found[$type] = $this->uuidsIn($folder);
    }
    $this->assertNotEmpty($found, 'the default content folder has moved or been removed');
    return $found;
  }

  /**
   * @return string[]
   */
  private function uuidsIn(string $folder): array {
    $uuids = array_map(
      static fn (string $path): string => basename($path, '.json'),
      glob($folder . '/*.json') ?: []
    );
    sort($uuids);
    return $uuids;
  }

  public function testEveryExportedEntityIsListedInTheIndex(): void {
    foreach ($this->exportedEntities() as $type => $uuids) {
      $indexed = $this->uuidsIn(self::DEFAULT_CONTENT . '/_thumbs/' . $type);

      $this->assertSame([], array_values(array_diff($uuids, $indexed)), sprintf(
        'these %s entities have no _thumbs entry, so an import skips them without a word',
        $type
      ));
    }
  }

  public function testTheIndexListsNothingThatIsNotExported(): void {
    foreach ($this->exportedEntities() as $type => $uuids) {
      $indexed = $this->uuidsIn(self::DEFAULT_CONTENT . '/_thumbs/' . $type);

      $this->assertSame([], array_values(array_diff($indexed, $uuids)), sprintf(
        'these %s entities are indexed but their export file is gone, which makes every import log a decode error',
        $type
      ));
    }
  }

  /**
   * A thumb holds nothing but the _dcd_metadata of its entity file.
   *
   * `drush default-content-deploy:sync-thumbs` rebuilds the whole index from
   * the entity files by copying that one property out of each. An entity file
   * without it - a hand-written one - therefore loses its thumb's contents the
   * next time anybody runs that command.
   */
  public function testEveryThumbIsWhatSyncThumbsWouldWrite(): void {
    foreach ($this->exportedEntities() as $type => $uuids) {
      foreach ($uuids as $uuid) {
        $entity = $this->decode(self::DEFAULT_CONTENT . '/' . $type . '/' . $uuid . '.json');
        $thumb = self::DEFAULT_CONTENT . '/_thumbs/' . $type . '/' . $uuid . '.json';
        if (!file_exists($thumb)) {
          // Reported by testEveryExportedEntityIsListedInTheIndex.
          continue;
        }

        $this->assertArrayHasKey('_dcd_metadata', $entity,
          "$type/$uuid was not written by an export: it carries no _dcd_metadata, so sync-thumbs would empty its thumb");
        $this->assertSame(['_dcd_metadata' => $entity['_dcd_metadata']], $this->decode($thumb),
          "$type/$uuid disagrees with its thumb");
      }
    }
  }

  /**
   * A sort_key is the entity id, which is what --preserve-ids restores.
   *
   * The importer orders by it, and the id is the reason the taxonomy a game
   * refers to survives an import: get it wrong and the term arrives under a
   * different id than every exported game expects.
   */
  public function testEverySortKeyIsTheEntityId(): void {
    foreach ($this->exportedEntities() as $type => $uuids) {
      foreach ($uuids as $uuid) {
        $entity = $this->decode(self::DEFAULT_CONTENT . '/' . $type . '/' . $uuid . '.json');
        $id = $entity['_links']['self']['href'] ?? '';

        $this->assertMatchesRegularExpression('#^_dcd/' . preg_quote($type, '#') . '/\d+$#', $id,
          "$type/$uuid has no exported entity id to preserve");
        $this->assertSame((int) substr($id, strrpos($id, '/') + 1), $entity['_dcd_metadata']['sort_key'] ?? NULL,
          "$type/$uuid has a sort_key that is not its entity id");
      }
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function decode(string $path): array {
    $decoded = json_decode(file_get_contents($path), TRUE);
    $this->assertIsArray($decoded, $path . ' is not readable as JSON');
    return $decoded;
  }

}

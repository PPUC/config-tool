<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\GameSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * One settings record per game, created with the game and gone with it.
 *
 * The record used to be optional - an "Add PPUC Settings" button nobody
 * pressed - so in practice no game had one, every exported ppuc.ini came from
 * the exporter's fallbacks, and nothing an operator changed could be stored or
 * travel with the game. Making it automatic only works if creation is exactly
 * once: a second record would shadow the first, since the export reads the
 * newest and the operator may well have been editing the other one.
 */
#[CoversClass(GameSettings::class)]
#[Group('ppuc_games')]
class GameSettingsTest extends TestCase {

  private EntityStorageInterface $storage;

  /**
   * Node ids the query will report for the game.
   */
  private array $found = [];

  /**
   * Node id the uuid lookup will report, or NULL for none.
   */
  private ?int $foundByUuid = NULL;

  /**
   * Entities the storage was asked to create.
   */
  private array $created = [];

  /**
   * The changed time stamped on the record that was created.
   */
  private ?int $changedTime = NULL;

  private function settings(): GameSettings {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturnCallback(fn(): array => $this->found);

    $this->storage = $this->createMock(EntityStorageInterface::class);
    $this->storage->method('getQuery')->willReturn($query);
    $this->storage->method('loadByProperties')->willReturnCallback(
      fn(): array => $this->foundByUuid === NULL ? [] : [$this->existing($this->foundByUuid)]
    );
    $this->storage->method('load')->willReturnCallback(
      fn($id) => $this->existing((int) $id)
    );
    $this->storage->method('create')->willReturnCallback(
      function (array $values): NodeInterface {
        $node = $this->createMock(NodeInterface::class);
        $node->method('id')->willReturn(999);
        $node->method('getTitle')->willReturn((string) $values['title']);
        $node->method('setChangedTime')->willReturnCallback(
          function (int $time) use (&$node): NodeInterface {
            $this->changedTime = $time;
            return $node;
          }
        );
        $this->created[] = $values;
        return $node;
      }
    );

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($this->storage);

    $settings = new GameSettings($entity_type_manager);

    // StringTranslationTrait only needs something that hands the string back;
    // the record's title is the one translated string this class builds.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn($translated_string) => $translated_string->getUntranslatedString()
    );
    $settings->setStringTranslation($translation);

    return $settings;
  }

  private function existing(int $id): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('bundle')->willReturn('ppuc_settings');
    return $node;
  }

  private function game(string $bundle = 'game', ?int $id = 195, string $uuid = '08f51efc-f25c-4fa9-bef3-ed8451131db4'): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn($uuid);
    $node->method('getTitle')->willReturn('Flash');
    $node->method('getOwnerId')->willReturn(1);
    return $node;
  }

  public function testAGameWithoutSettingsGetsOne(): void {
    $this->found = [];

    $settings = $this->settings()->getOrCreate($this->game());

    $this->assertInstanceOf(NodeInterface::class, $settings);
    $this->assertCount(1, $this->created);
    $this->assertSame('ppuc_settings', $this->created[0]['type']);
    $this->assertSame(['target_id' => 195], $this->created[0]['field_game']);
  }

  public function testASecondRecordIsNeverCreated(): void {
    // The property the whole feature rests on. The export reads the newest
    // record, so a duplicate silently shadows whatever the operator edited.
    $this->found = [42];

    $settings = $this->settings()->getOrCreate($this->game());

    $this->assertSame(42, $settings->id());
    $this->assertSame([], $this->created, 'an existing record is reused, not duplicated');
  }

  public function testTheRecordIsNamedAfterItsGame(): void {
    $this->found = [];

    $this->settings()->getOrCreate($this->game());

    // Administrative only - it is never written to ppuc.ini - but it is what
    // makes a list of settings records tell you which game each belongs to.
    $this->assertStringContainsString('Flash', (string) $this->created[0]['title']);
  }

  public function testOnlyGamesHaveSettings(): void {
    $this->found = [];

    $this->assertNull($this->settings()->getOrCreate($this->game('switch')));
    $this->assertSame([], $this->created);
  }

  public function testAnUnsavedGameIsNotGivenSettings(): void {
    // hook_node_insert runs after the id exists; anything else asking early
    // would otherwise create a record pointing at no game at all.
    $this->found = [];

    $this->assertNull($this->settings()->getOrCreate($this->game('game', NULL)));
    $this->assertSame([], $this->created);
  }

  public function testFindReturnsNothingForAGameThatHasNone(): void {
    $this->found = [];

    $this->assertNull($this->settings()->find($this->game()));
  }

  public function testFindReturnsTheRecordOfTheGame(): void {
    $this->found = [42];

    $this->assertSame(42, $this->settings()->find($this->game())?->id());
  }

  // --- identity across sites --------------------------------------------

  public function testTheUuidIsDerivedFromTheGame(): void {
    $settings = $this->settings();
    $game = $this->game();

    // Stable, so two sites holding the same game agree on which entity its
    // settings record is, and an import updates it instead of adding a second.
    $this->assertSame($settings->uuidFor($game), $settings->uuidFor($game));
    $this->assertMatchesRegularExpression(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
      $settings->uuidFor($game),
      'a version 5 uuid'
    );
  }

  public function testDifferentGamesGetDifferentUuids(): void {
    $settings = $this->settings();

    $this->assertNotSame(
      $settings->uuidFor($this->game('game', 195, '08f51efc-f25c-4fa9-bef3-ed8451131db4')),
      $settings->uuidFor($this->game('game', 361, 'df0edd82-e3af-4e13-a3f8-606813b39f34'))
    );
  }

  public function testANewRecordCarriesTheDerivedUuid(): void {
    $this->found = [];
    $settings = $this->settings();

    $settings->getOrCreate($this->game());

    $this->assertSame($settings->uuidFor($this->game()), $this->created[0]['uuid']);
  }

  public function testARecordFoundByUuidIsNotDuplicated(): void {
    // An import may have put the record in place while its field_game
    // reference is still waiting for the correction pass, so the query by
    // game finds nothing and only the uuid lookup can see it.
    $this->found = [];
    $this->foundByUuid = 42;

    $this->assertSame(42, $this->settings()->getOrCreate($this->game())?->id());
    $this->assertSame([], $this->created);
  }

  public function testAPlaceholderIsStampedAsNeverModified(): void {
    // default_content_deploy imports an entity only when the file is newer
    // than the stored copy. A placeholder stamped with the current time would
    // make an archive's settings unimportable for that game.
    $this->found = [];

    $this->settings()->getOrCreate($this->game());

    $this->assertSame(1, $this->changedTime);
  }

}

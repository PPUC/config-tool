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
   * Entities the storage was asked to create.
   */
  private array $created = [];

  private function settings(): GameSettings {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturnCallback(fn(): array => $this->found);

    $this->storage = $this->createMock(EntityStorageInterface::class);
    $this->storage->method('getQuery')->willReturn($query);
    $this->storage->method('load')->willReturnCallback(
      fn($id) => $this->existing((int) $id)
    );
    $this->storage->method('create')->willReturnCallback(
      function (array $values): NodeInterface {
        $node = $this->createMock(NodeInterface::class);
        $node->method('id')->willReturn(999);
        $node->method('getTitle')->willReturn((string) $values['title']);
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

  private function game(string $bundle = 'game', ?int $id = 195): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('id')->willReturn($id);
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

}

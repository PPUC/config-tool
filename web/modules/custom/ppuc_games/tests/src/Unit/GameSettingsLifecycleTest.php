<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\node\NodeInterface;
use Drupal\ppuc_games\GameSettings;
use Drupal\ppuc_games\Hook\GameSettingsLifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * When a game gains and loses its settings record.
 *
 * The case that matters is import. default_content_deploy orders an archive by
 * the source site's node ids, and a game always has a lower id than the
 * settings record created alongside it, so the game is always imported first.
 * Creating a record on that insert therefore beat the archive's own every
 * time - and because default_content_deploy skips an entity whose stored copy
 * is newer than the file, the record just created won and the imported values
 * were dropped in silence. A game handed to someone else arrived with its
 * ppuc.ini settings emptied.
 */
#[CoversClass(GameSettingsLifecycle::class)]
#[Group('ppuc_games')]
class GameSettingsLifecycleTest extends TestCase {

  private function node(string $bundle, bool $syncing = FALSE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isSyncing')->willReturn($syncing);
    return $node;
  }

  public function testANewGameGetsItsSettings(): void {
    $settings = $this->createMock(GameSettings::class);
    $settings->expects($this->once())->method('getOrCreate');

    (new GameSettingsLifecycle($settings))->nodeInsert($this->node('game'));
  }

  public function testAnImportedGameWaitsForTheArchivesRecord(): void {
    $settings = $this->createMock(GameSettings::class);
    $settings->expects($this->never())->method('getOrCreate');

    (new GameSettingsLifecycle($settings))->nodeInsert($this->node('game', TRUE));
  }

  public function testOtherContentIsIgnoredOnInsert(): void {
    $settings = $this->createMock(GameSettings::class);
    $settings->expects($this->never())->method('getOrCreate');

    (new GameSettingsLifecycle($settings))->nodeInsert($this->node('switch'));
  }

  public function testDeletingAGameTakesItsSettingsWithIt(): void {
    // field_game is required and nothing else looks a record up, so one left
    // behind is a node nobody can reach.
    $settings = $this->createMock(GameSettings::class);
    $settings->expects($this->once())->method('deleteFor');

    (new GameSettingsLifecycle($settings))->nodeDelete($this->node('game'));
  }

  public function testOtherContentIsIgnoredOnDelete(): void {
    $settings = $this->createMock(GameSettings::class);
    $settings->expects($this->never())->method('deleteFor');

    (new GameSettingsLifecycle($settings))->nodeDelete($this->node('switch'));
  }

}

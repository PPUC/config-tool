<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Controller\GamesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Export of the ROM-less (GameCore) blocks.
 *
 * Two properties matter more than the rest.
 *
 * First, a role can only ever name a device that was actually exported, because
 * roles are collected while the devices are walked rather than typed as numbers
 * on the game. That is what makes "emGame.startSwitch references switch 11,
 * which is not declared" impossible to produce from the UI.
 *
 * Second, a game left on the default PinMAME engine must export exactly as it
 * did before. If that ever stops being true, every existing game's YAML changes
 * on the next save.
 */
#[CoversClass(GamesController::class)]
#[Group('ppuc_games')]
class GamesControllerGameCoreTest extends TestCase {

  private GamesController $controller;

  protected function setUp(): void {
    parent::setUp();
    $this->controller = (new ReflectionClass(GamesController::class))
      ->newInstanceWithoutConstructor();
  }

  private function call(string $method, ...$args) {
    $reflection = (new ReflectionClass(GamesController::class))->getMethod($method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($this->controller, $args);
  }

  /**
   * A node whose fields answer from the given map.
   *
   * Any field not in the map behaves as absent, which is how every game saved
   * before these fields existed will behave until the config import has run.
   */
  private function node(array $values): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnCallback(
      static fn(string $name): bool => array_key_exists($name, $values)
    );
    $node->method('get')->willReturnCallback(
      function (string $name) use ($values): FieldItemListInterface {
        $item = $this->createMock(FieldItemListInterface::class);
        $value = $values[$name] ?? NULL;
        $item->method('isEmpty')->willReturn($value === NULL);
        $item->method('__get')->with('value')->willReturn($value);
        return $item;
      }
    );
    return $node;
  }

  // --- roles ------------------------------------------------------------

  public function testARoleIsReadFromTheDeviceThatCarriesIt(): void {
    $this->assertSame('start', $this->call('gameRoleOf', $this->node(['field_game_role' => 'start']), 3));
  }

  public function testADeviceWithNoRoleReadsAsNone(): void {
    $this->assertNull($this->call('gameRoleOf', $this->node(['field_game_role' => NULL]), 3));
    // The case every device saved before the field existed is in.
    $this->assertNull($this->call('gameRoleOf', $this->node([]), 4));
  }

  public function testDeviceNumberZeroIsRefused(): void {
    // Switch 0 is not a real switch number, and a role pointing at nothing is
    // worse than no role at all.
    $this->assertNull($this->call('gameRoleOf', $this->node(['field_game_role' => 'start']), 0));
  }

  public function testAMultiValuedRoleKeepsEveryDeviceInOrder(): void {
    $roles = [];
    foreach ([21, 22, 23] as $number) {
      $role = $this->call('gameRoleOf', $this->node(['field_game_role' => 'trough']), $number);
      $roles[$role][] = $number;
    }
    $this->assertSame([21, 22, 23], $this->call('roleNumbers', $roles, 'trough'));
  }

  public function testASingleValuedRoleGivenTwiceIsDeterministic(): void {
    // A misconfiguration, but it must not produce a different export each time
    // depending on which device was loaded first.
    $roles = ['start' => [7, 3]];
    $this->assertSame(3, $this->call('roleNumber', $roles, 'start'));
  }

  public function testAnAbsentRoleIsZero(): void {
    $this->assertSame(0, $this->call('roleNumber', [], 'knocker'));
    $this->assertSame([], $this->call('roleNumbers', [], 'coin'));
  }

  // --- engine -----------------------------------------------------------

  public function testTheEngineDefaultsToPinmame(): void {
    $this->assertSame('pinmame', $this->call('getEngine', $this->node([])));
    $this->assertSame('pinmame', $this->call('getEngine', $this->node(['field_engine' => NULL])));
    $this->assertSame('pinmame', $this->call('getEngine', $this->node(['field_engine' => 'pinmame'])));
  }

  public function testGameCoreIsSelectedExplicitly(): void {
    $this->assertSame('gamecore', $this->call('getEngine', $this->node(['field_engine' => 'gamecore'])));
  }

  // --- tilt -------------------------------------------------------------

  public function testTiltIsNotEmittedWithoutTiltSwitches(): void {
    // Warnings with nothing to count are not a feature, and an empty block
    // would make ppuc-pinmame enable the assist layer for nothing.
    $this->assertSame([], $this->call('buildTiltYaml', $this->node([]), []));
  }

  public function testEveryTiltSwitchIsExported(): void {
    // A machine usually has more than one: a plumb bob and a ball-roll tilt.
    $roles = ['tilt' => [4, 5], 'slamTilt' => [3]];
    $tilt = $this->call('buildTiltYaml', $this->node([]), $roles);

    $this->assertSame([4, 5], $tilt['switches']);
    $this->assertSame([3], $tilt['slamSwitches']);
  }

  public function testTiltTimingDefaultsAreExportedWhenUnset(): void {
    $tilt = $this->call('buildTiltYaml', $this->node([]), ['tilt' => [4]]);

    $this->assertSame(2, $tilt['warnings']);
    $this->assertSame(500, $tilt['debounceMs']);
    $this->assertSame(2000, $tilt['warningBlankingMs']);
  }

  public function testTiltTimingIsTakenFromTheGameWhenSet(): void {
    $node = $this->node([
      'field_tilt_warnings' => 1,
      'field_tilt_debounce_ms' => 400,
      'field_tilt_blanking_ms' => 3000,
    ]);
    $tilt = $this->call('buildTiltYaml', $node, ['tilt' => [4]]);

    $this->assertSame(1, $tilt['warnings']);
    $this->assertSame(400, $tilt['debounceMs']);
    $this->assertSame(3000, $tilt['warningBlankingMs']);
  }

  public function testZeroWarningsSurvivesTheDefault(): void {
    // An unforgiving machine that tilts on the first hit is a real setting, and
    // must not be overwritten by the default of 2.
    $tilt = $this->call('buildTiltYaml', $this->node(['field_tilt_warnings' => 0]), ['tilt' => [4]]);
    $this->assertSame(0, $tilt['warnings']);
  }

  public function testASlamOnlyMachineStillExportsTilt(): void {
    $tilt = $this->call('buildTiltYaml', $this->node([]), ['slamTilt' => [3]]);
    $this->assertArrayNotHasKey('switches', $tilt);
    $this->assertSame([3], $tilt['slamSwitches']);
  }

  // --- ball save --------------------------------------------------------

  public function testBallSaveIsNotEmittedWhenDisabled(): void {
    $roles = ['trough' => [11], 'troughKick' => [1]];
    $this->assertSame([], $this->call('buildBallSaveYaml', $this->node([]), $roles));
  }

  public function testBallSaveNeedsSomewhereToDrainAndSomethingToKickWith(): void {
    // Emitting it half-configured would fail at ppuc-pinmame startup instead,
    // which is a far worse place to discover a missing role.
    $node = $this->node(['field_ball_save_enabled' => TRUE]);

    $this->assertSame([], $this->call('buildBallSaveYaml', $node, ['troughKick' => [1]]));
    $this->assertSame([], $this->call('buildBallSaveYaml', $node, ['trough' => [11]]));
  }

  public function testBallSaveExportsItsRolesAndDefaults(): void {
    $node = $this->node(['field_ball_save_enabled' => TRUE]);
    $roles = [
      'trough' => [11],
      'troughKick' => [1],
      'shooterLane' => [12],
      'playfield' => [22, 23],
      'ballSave' => [45],
    ];
    $save = $this->call('buildBallSaveYaml', $node, $roles);

    $this->assertTrue($save['enabled']);
    $this->assertSame(8, $save['seconds']);
    $this->assertSame('shooterLane', $save['startOn']);
    $this->assertSame([11], $save['drainSwitches']);
    $this->assertSame(1, $save['kickCoil']);
    $this->assertSame(12, $save['shooterLaneSwitch']);
    $this->assertSame([22, 23], $save['playfieldSwitches']);
    $this->assertSame(45, $save['lamp']);
  }

  public function testALaneSwitchIsOmittedWhenTheMachineHasNone(): void {
    // ppuc-pinmame then falls back to trough exit. Emitting 0 would look like a
    // real switch number.
    $node = $this->node(['field_ball_save_enabled' => TRUE]);
    $save = $this->call('buildBallSaveYaml', $node, ['trough' => [11], 'troughKick' => [1]]);

    $this->assertArrayNotHasKey('shooterLaneSwitch', $save);
    $this->assertArrayNotHasKey('lamp', $save);
  }

  // --- emGame -----------------------------------------------------------

  public function testEmGameCarriesTheRolesItWasGiven(): void {
    $roles = [
      'start' => [1],
      'coin' => [2],
      'serviceCredit' => [6],
      'trough' => [11],
      'troughKick' => [1],
      'shooterLane' => [12],
      'gameOn' => [10],
      'knocker' => [4],
      'gameOver' => [40],
      'tilt' => [41],
      'tiltInhibit' => [250],
      'playerUp' => [46, 47],
    ];
    $em = $this->call('buildEmGameYaml', $this->node([]), $roles);

    $this->assertTrue($em['enabled']);
    $this->assertSame(1, $em['startSwitch']);
    $this->assertSame([2], $em['coinSwitches']);
    $this->assertSame(6, $em['serviceCreditSwitch']);
    $this->assertSame(10, $em['gameOnCoil']);
    $this->assertSame(4, $em['knockerCoil']);
    $this->assertSame(40, $em['gameOverLamp']);
    $this->assertSame([46, 47], $em['playerUpLamps']);
    $this->assertSame([11], $em['trough']['switches']);
    $this->assertSame(1, $em['trough']['kickCoil']);
    $this->assertSame(12, $em['shooterLane']['switch']);
    $this->assertSame(250, $em['tilt']['inhibitSwitch']);
  }

  public function testEmGameOmitsRolesTheMachineDoesNotHave(): void {
    $em = $this->call('buildEmGameYaml', $this->node([]), ['start' => [1]]);

    $this->assertArrayNotHasKey('knockerCoil', $em);
    $this->assertArrayNotHasKey('gameOnCoil', $em);
    $this->assertArrayNotHasKey('trough', $em);
    $this->assertArrayNotHasKey('shooterLane', $em);
    $this->assertArrayNotHasKey('inhibitSwitch', $em['tilt']);
  }

  public function testEmGameDefaultsMatchAThreeBallMachine(): void {
    $em = $this->call('buildEmGameYaml', $this->node([]), []);

    $this->assertSame(3, $em['ballsPerGame']);
    $this->assertSame(4, $em['maxPlayers']);
    $this->assertSame(1, $em['ballCount']);
    $this->assertSame(1, $em['addPlayerThroughBall']);
    $this->assertSame(6, $em['scoreDigits']);
  }

  public function testReplayThresholdsAreParsedSortedAndDeduplicated(): void {
    $node = $this->node(['field_em_replay_scores' => "100000\n50000\n50000\n"]);
    $em = $this->call('buildEmGameYaml', $node, []);

    $this->assertSame([50000, 100000], $em['replay']['thresholds']);
  }

  public function testNoReplayBlockWithoutThresholds(): void {
    $this->assertArrayNotHasKey('replay', $this->call('buildEmGameYaml', $this->node([]), []));
  }

  public function testReplayIgnoresNonNumericLines(): void {
    $node = $this->node(['field_em_replay_scores' => "50000\n# a comment\nnonsense\n"]);
    $em = $this->call('buildEmGameYaml', $node, []);

    $this->assertSame([50000], $em['replay']['thresholds']);
  }
}

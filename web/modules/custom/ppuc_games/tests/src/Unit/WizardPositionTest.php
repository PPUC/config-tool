<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\DeviceDataParser;
use Drupal\ppuc_games\Wizard\HardwareAllocator;
use Drupal\ppuc_games\Wizard\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Where devices are, when the manual says.
 *
 * A location diagram is a bonus, not a requirement: plenty of manuals have no
 * such page, so everything here has to work identically without one. What a
 * position buys is a board near the devices it drives, and an LED string
 * ordered along a plausible run rather than by matrix number.
 *
 * The one thing it must never do is undo the spreading of the coils that fire
 * in bursts. Three jet bumpers are inches apart, so nearest-board would put all
 * three together - which is exactly the arrangement that empties a driver
 * board's capacitor.
 */
#[CoversClass(Position::class)]
#[CoversClass(HardwareAllocator::class)]
#[Group('ppuc_games')]
class WizardPositionTest extends TestCase {

  private static function ioMapping(): array {
    return array_combine(range(1, 25), [
      3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18,
      19, 20, 21, 22, 23, 24, 26, 27, 29,
    ]);
  }

  private static function optoMapping(): array {
    return array_combine(range(1, 16), range(3, 18)) + [25 => 29];
  }

  private function allocate(array $document): array {
    $parser = new DeviceDataParser();
    $devices = $parser->parse(json_encode($document + [
      'game' => ['title' => 'Test', 'platform' => 'WPC', 'rom' => ''],
      'switches' => [], 'coils' => [], 'flippers' => [],
      'flashers' => [], 'lamps' => [], 'gi' => [],
    ]));
    $this->assertNotNull($devices, implode("\n", $parser->errors()));

    return (new HardwareAllocator(self::ioMapping(), self::optoMapping()))->allocate($devices);
  }

  private static function boardOfCoil(array $plan, int $number): ?int {
    foreach ($plan['coils'] as $coil) {
      if ($coil['number'] === $number) {
        return $coil['board'];
      }
    }
    return NULL;
  }

  // --- the position value ----------------------------------------------------

  public function testAPositionIsAFractionOfThePlayfield(): void {
    $position = Position::fromArray(['x' => 0.25, 'y' => 0.75]);

    $this->assertSame(0.25, $position->x);
    $this->assertSame(0.75, $position->y);
  }

  public function testNoPositionIsNotAnError(): void {
    $this->assertNull(Position::fromArray(NULL));
  }

  public function testACoordinateOutsideThePlayfieldIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    Position::fromArray(['x' => 0.5, 'y' => 1.4]);
  }

  public function testHalfAPositionIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    Position::fromArray(['x' => 0.5]);
  }

  /**
   * A playfield is about twice as long as it is wide, so a step along it covers
   * twice the distance a step across it does.
   */
  public function testDistanceAccountsForThePlayfieldBeingLong(): void {
    $origin = new Position(0.0, 0.0);
    $across = new Position(0.1, 0.0);
    $along = new Position(0.0, 0.1);

    $this->assertGreaterThan(
      $origin->distanceTo($across),
      $origin->distanceTo($along),
      'the same fraction along the playfield is a longer way than across it'
    );
  }

  public function testTheCentreOfNoPositionsIsNothing(): void {
    $this->assertNull(Position::centre([]));
  }

  // --- board choice ----------------------------------------------------------

  /**
   * Devices at the same end of the playfield belong on the same board.
   */
  public function testADeviceGoesToTheBoardNearestIt(): void {
    // Two clusters, eight coils each: exactly two boards' worth of outputs, so
    // capacity cannot decide it - only proximity can.
    $coils = [];
    for ($n = 1; $n <= 8; $n++) {
      $coils[] = [
        'number' => $n, 'description' => 'Bottom ' . $n, 'class' => 'lowPower',
        'position' => ['x' => 0.5, 'y' => 0.05 + ($n / 200)],
      ];
    }
    for ($n = 9; $n <= 16; $n++) {
      $coils[] = [
        'number' => $n, 'description' => 'Top ' . $n, 'class' => 'lowPower',
        'position' => ['x' => 0.5, 'y' => 0.95 - ($n / 200)],
      ];
    }

    $plan = $this->allocate(['coils' => $coils]);

    $bottom = [];
    $top = [];
    foreach ($plan['coils'] as $coil) {
      if (str_starts_with($coil['description'], 'Bottom')) {
        $bottom[] = $coil['board'];
      }
      else {
        $top[] = $coil['board'];
      }
    }

    $this->assertCount(1, array_unique($bottom), 'the bottom cluster was split across boards');
    $this->assertCount(1, array_unique($top), 'the top cluster was split across boards');
    $this->assertNotSame($bottom[0], $top[0], 'both clusters landed on one board');
  }

  /**
   * The rule that positions must not overturn.
   */
  public function testBumpersAreStillSpreadEvenThoughTheyAreNeighbours(): void {
    $switches = [];
    $coils = [];
    foreach ([63 => 'Left Jet', 64 => 'Middle Jet', 65 => 'Right Jet'] as $number => $name) {
      // Inches apart, in the middle of the playfield.
      $switches[] = [
        'number' => $number, 'description' => $name,
        'position' => ['x' => 0.45 + (($number - 63) * 0.05), 'y' => 0.62],
      ];
      $coils[] = [
        'number' => $number - 52, 'description' => $name, 'class' => 'lowPower',
        'fastFlipSwitch' => $number,
        'position' => ['x' => 0.45 + (($number - 63) * 0.05), 'y' => 0.62],
      ];
    }
    for ($n = 100; $n < 118; $n++) {
      $coils[] = [
        'number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower',
        'position' => ['x' => 0.5, 'y' => 0.5],
      ];
    }

    $plan = $this->allocate(['switches' => $switches, 'coils' => $coils]);

    $boards = [];
    foreach ($plan['coils'] as $coil) {
      if (str_contains($coil['description'], 'Jet')) {
        $boards[] = $coil['board'];
      }
    }

    $this->assertCount(3, array_unique($boards), sprintf(
      'the bumpers were grouped by proximity (%s); being neighbours is exactly '
      . 'why they must not share a board', implode('/', $boards)
    ));
  }

  /**
   * A flipper's four devices still travel together, position or not.
   */
  public function testAFlipperIsNotSplitByProximity(): void {
    $plan = $this->allocate([
      'coils' => [
        [
          'number' => 29, 'description' => 'LR Power', 'class' => 'highPower',
          'position' => ['x' => 0.7, 'y' => 0.05],
        ],
        [
          'number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower',
          // Deliberately at the far end, to see if proximity pulls it away.
          'position' => ['x' => 0.1, 'y' => 0.95],
        ],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]);

    $this->assertSame(self::boardOfCoil($plan, 29), self::boardOfCoil($plan, 30));
  }

  // --- LED string order ------------------------------------------------------

  /**
   * A string is one run of wire; matrix order says nothing about where it goes.
   */
  public function testAStringIsOrderedAlongAPathWhenPositionsAreKnown(): void {
    $plan = $this->allocate([
      'lamps' => [
        ['number' => 11, 'description' => 'Far', 'position' => ['x' => 0.5, 'y' => 0.9]],
        ['number' => 12, 'description' => 'Near', 'position' => ['x' => 0.1, 'y' => 0.1]],
        ['number' => 13, 'description' => 'Middle', 'position' => ['x' => 0.3, 'y' => 0.5]],
      ],
    ]);

    $lamps = array_values(array_filter($plan['stripes'], static fn ($s) => $s['label'] === 'Lamps'));
    $this->assertSame(
      ['Near', 'Middle', 'Far'],
      array_column($lamps[0]['leds'], 'description'),
      'the string should run from the bottom left outwards, not in matrix order'
    );
    $this->assertSame([0, 1, 2], array_column($lamps[0]['leds'], 'position'));
  }

  /**
   * Half a path and half a matrix would be worse than either.
   */
  public function testTheListedOrderIsKeptWhenOnlySomeLedsArePositioned(): void {
    $plan = $this->allocate([
      'lamps' => [
        ['number' => 11, 'description' => 'Far', 'position' => ['x' => 0.5, 'y' => 0.9]],
        ['number' => 12, 'description' => 'Unknown'],
        ['number' => 13, 'description' => 'Near', 'position' => ['x' => 0.1, 'y' => 0.1]],
      ],
    ]);

    $lamps = array_values(array_filter($plan['stripes'], static fn ($s) => $s['label'] === 'Lamps'));
    $this->assertSame(['Far', 'Unknown', 'Near'], array_column($lamps[0]['leds'], 'description'));
  }

  /**
   * The summary has to say which of the two orders was used.
   */
  public function testTheNotesSayWhetherPositionsWereUsed(): void {
    $withPositions = $this->allocate([
      'lamps' => [['number' => 11, 'description' => 'A', 'position' => ['x' => 0.1, 'y' => 0.1]]],
    ]);
    $notes = implode("\n", array_map('strval', $withPositions['notes']));
    $this->assertStringContainsString('Lamps string(s) are ordered along a path', $notes);

    $without = $this->allocate(['lamps' => [['number' => 11, 'description' => 'A']]]);
    $notes = implode("\n", array_map('strval', $without['notes']));
    $this->assertStringContainsString('in the order the LEDs were listed', $notes);
    $this->assertStringNotContainsString('nearest to nearest', $notes);
  }

  /**
   * One string can follow a path while another cannot.
   *
   * The playfield lamps come off a location diagram; the cabinet's coin door
   * lights are in no diagram at all. Saying "in the order listed" about both
   * would be wrong about the first.
   */
  public function testTheNotesDistinguishStringsThatFollowAPath(): void {
    $plan = $this->allocate([
      'lamps' => [
        ['number' => 11, 'description' => 'A', 'position' => ['x' => 0.1, 'y' => 0.1]],
        ['number' => 12, 'description' => 'B', 'position' => ['x' => 0.9, 'y' => 0.9]],
        ['number' => 100, 'description' => 'Coin Return Light', 'location' => 'cabinet'],
      ],
    ]);

    $notes = implode("\n", array_map('strval', $plan['notes']));
    $this->assertStringContainsString('Lamps string(s) are ordered along a path', $notes);
    $this->assertStringContainsString('Cabinet string(s) are in the order the LEDs were listed', $notes);
  }

  /**
   * Every manual without a location page has to keep working.
   */
  public function testAnAllocationWithoutAnyPositionsIsUnchanged(): void {
    $document = [
      'switches' => [['number' => 11, 'description' => 'Trigger']],
      'coils' => [['number' => 1, 'description' => 'Ball Release', 'class' => 'highPower']],
      'lamps' => [['number' => 11, 'description' => 'Left Rollover']],
    ];

    $bare = $this->allocate($document);

    $notes = implode("\n", array_map('strval', $bare['notes']));
    $this->assertStringNotContainsString('location diagrams', $notes);
    $this->assertCount(1, $bare['coils']);
    $this->assertCount(1, $bare['switches']);
  }

}

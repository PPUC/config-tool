<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\BoardCapacity;
use Drupal\ppuc_games\Wizard\DeviceDataParser;
use Drupal\ppuc_games\Wizard\DeviceDefaults;
use Drupal\ppuc_games\Wizard\HardwareAllocator;
use Drupal\ppuc_games\Wizard\PlatformNumbers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Where every device ends up, and why.
 *
 * The constraints here are physical, not stylistic. A coil only reacts to a
 * switch without waiting for the host when the board owns both, and a wire run
 * from the cabinet to a board under the playfield is a wire someone has to
 * pull. An allocation that violates either builds a game that works on paper.
 */
#[CoversClass(HardwareAllocator::class)]
#[Group('ppuc_games')]
class WizardHardwareAllocatorTest extends TestCase {

  /**
   * IO_16_8_1's real mapping: 16 inputs, 8 outputs, one LED pin.
   */
  private static function ioMapping(): array {
    $gpios = [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18,
              19, 20, 21, 22, 23, 24, 26, 27, 29];
    return array_combine(range(1, 25), $gpios);
  }

  /**
   * Opto_16: 16 inputs on the same GPIOs, plus the LED connector every board has.
   */
  private static function optoMapping(): array {
    return array_combine(range(1, 16), range(3, 18)) + [25 => 29];
  }

  private function allocate(array $document): array {
    $parser = new DeviceDataParser();
    $devices = $parser->parse(json_encode($document));
    $this->assertNotNull($devices, implode("\n", $parser->errors()));

    $plan = (new HardwareAllocator(self::ioMapping(), self::optoMapping()))->allocate($devices);
    return [$devices, $plan];
  }

  private static function document(array $overrides = []): array {
    return $overrides + [
      'game' => ['title' => 'Test', 'platform' => 'WPC', 'rom' => ''],
      'switches' => [],
      'coils' => [],
      'flippers' => [],
      'flashers' => [],
      'lamps' => [],
      'gi' => [],
    ];
  }

  private static function boardOfSwitch(array $plan, int $number): ?int {
    foreach ($plan['switches'] as $switch) {
      if ($switch['number'] === $number) {
        return $switch['board'];
      }
    }
    return NULL;
  }

  private static function coil(array $plan, int $number): ?array {
    foreach ($plan['coils'] as $coil) {
      if ($coil['number'] === $number) {
        return $coil;
      }
    }
    return NULL;
  }

  private static function boardType(array $plan, int $index): string {
    foreach ($plan['boards'] as $board) {
      if ($board['index'] === $index) {
        return $board['type'];
      }
    }
    return '';
  }

  private static function boardGroup(array $plan, int $index): string {
    foreach ($plan['boards'] as $board) {
      if ($board['index'] === $index) {
        return $board['group'];
      }
    }
    return '';
  }

  /**
   * The reason this feature exists at all.
   */
  public function testAFastFlipCoilSharesABoardWithItsSwitch(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [['number' => 61, 'description' => 'Left Sling']],
      'coils' => [
        ['number' => 9, 'description' => 'Left Sling', 'class' => 'lowPower', 'fastFlipSwitch' => 61],
      ],
    ]));

    $this->assertSame(
      self::boardOfSwitch($plan, 61),
      self::coil($plan, 9)['board'],
      'a fast-flip coil that is not on its switch\'s board waits for the next poll'
    );
  }

  /**
   * Still true when packing alone would put them on different boards.
   *
   * Arranged so plain capacity packing splits the pair: the coil is first of
   * twenty, so it lands on the first board, while its switch is last of forty,
   * which is three boards further along. Only placing the pair together before
   * anything else keeps them on one board - which is the whole point, and
   * which a test with the pair listed first cannot tell apart from luck.
   */
  public function testFastFlipPairsSurviveAFullMachine(): void {
    $coils = [['number' => 9, 'description' => 'Left Sling', 'class' => 'lowPower', 'fastFlipSwitch' => 61]];
    for ($n = 100; $n < 120; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }
    $switches = [];
    for ($n = 11; $n <= 49; $n++) {
      $switches[] = ['number' => $n, 'description' => 'Switch ' . $n];
    }
    $switches[] = ['number' => 61, 'description' => 'Left Sling'];

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $this->assertSame(
      self::boardOfSwitch($plan, 61),
      self::coil($plan, 9)['board'],
      'the pair was split, so the coil waits for the host to poll the switch'
    );
  }

  // --- spreading the devices that fire in bursts -----------------------------
  //
  // A jet bumper fires again the moment the ball comes back. Three of them
  // around one ball, or a ball trapped between the slingshots, will drain a
  // driver board's capacitor faster than it recharges. Which board a fast-flip
  // group lands on has no other consequence - its switch travels with it - so
  // spreading them is free.

  /**
   * Three bumpers must not end up on one board.
   */
  public function testBurstyCoilsAreSpreadAcrossBoards(): void {
    $switches = [];
    $coils = [];
    foreach ([63 => 'Left Jet', 64 => 'Middle Jet', 65 => 'Right Jet'] as $number => $name) {
      $switches[] = ['number' => $number, 'description' => $name];
      $coils[] = [
        'number' => $number - 52, 'description' => $name, 'class' => 'lowPower',
        'fastFlipSwitch' => $number,
      ];
    }
    // Enough other coils that more than one board exists to spread over.
    for ($n = 100; $n < 118; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $boards = [];
    foreach ($plan['coils'] as $coil) {
      if (str_contains($coil['description'], 'Jet')) {
        $boards[] = $coil['board'];
      }
    }

    $this->assertCount(3, $boards);
    $this->assertCount(3, array_unique($boards), sprintf(
      'the bumpers share boards (%s); a ball between them drains one capacitor',
      implode('/', $boards)
    ));
  }

  /**
   * Slingshots too, for the same reason.
   */
  public function testSlingshotsDoNotShareABoard(): void {
    $switches = [
      ['number' => 61, 'description' => 'Left Slingshot'],
      ['number' => 62, 'description' => 'Right Slingshot'],
    ];
    $coils = [
      ['number' => 9, 'description' => 'Left Slingshot', 'class' => 'lowPower', 'fastFlipSwitch' => 61],
      ['number' => 10, 'description' => 'Right Slingshot', 'class' => 'lowPower', 'fastFlipSwitch' => 62],
    ];
    for ($n = 100; $n < 110; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $this->assertNotSame(
      self::coil($plan, 9)['board'],
      self::coil($plan, 10)['board'],
      'both slingshots on one board'
    );
  }

  /**
   * Spreading must not cost a board: it chooses among those that will exist.
   */
  public function testSpreadingDoesNotAddBoards(): void {
    $switches = [];
    $coils = [];
    foreach ([61, 62, 63, 64, 65] as $i => $number) {
      $switches[] = ['number' => $number, 'description' => 'Switch ' . $number];
      $coils[] = [
        'number' => $i + 1, 'description' => 'Bursty ' . $number, 'class' => 'lowPower',
        'fastFlipSwitch' => $number,
      ];
    }
    for ($n = 100; $n < 111; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    // 16 coils over 8 outputs is 2 boards, plus the cabinet's for its string.
    $this->assertCount(3, $plan['boards']);
  }

  /**
   * Each flipper on its own board where there is room, for the same reason:
   * both windings fire together, and both flippers are often held at once.
   */
  public function testFlippersAreSpreadAcrossBoards(): void {
    $coils = [];
    $flippers = [];
    foreach ([
      ['Lower Right', 'lowerRight', 29, 30],
      ['Lower Left', 'lowerLeft', 31, 32],
      ['Upper Right', 'upperRight', 33, 34],
    ] as [$name, $position, $power, $hold]) {
      $coils[] = ['number' => $power, 'description' => $name . ' Power', 'class' => 'highPower'];
      $coils[] = ['number' => $hold, 'description' => $name . ' Hold', 'class' => 'lowPower'];
      $flippers[] = ['name' => $name, 'position' => $position, 'powerCoil' => $power, 'holdCoil' => $hold];
    }
    for ($n = 100; $n < 118; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['coils' => $coils, 'flippers' => $flippers]));

    $boards = [];
    foreach ([29, 31, 33] as $power) {
      $boards[] = self::coil($plan, $power)['board'];
    }
    $this->assertCount(3, array_unique($boards), 'two flippers share a board');

    // Each flipper's own four devices still travel together.
    foreach ([[29, 30], [31, 32], [33, 34]] as [$power, $hold]) {
      $this->assertSame(self::coil($plan, $power)['board'], self::coil($plan, $hold)['board']);
    }
  }

  /**
   * A flipper is four devices that must not be split.
   */
  public function testAFlipperButtonEosAndBothWindingsShareOneBoard(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    $boards = [];
    foreach ($plan['switches'] as $switch) {
      if (($switch['flipper'] ?? NULL) === 'Lower Right') {
        $boards[] = $switch['board'];
      }
    }
    foreach ($plan['coils'] as $coil) {
      if (($coil['flipper'] ?? NULL) === 'Lower Right') {
        $boards[] = $coil['board'];
      }
    }

    $this->assertCount(4, $boards, 'expected a button, an EOS and two windings');
    $this->assertCount(1, array_unique($boards), 'the flipper was split across boards');
  }

  /**
   * Cabinet hardware, deliberately on a playfield board.
   */
  public function testAFlipperButtonGoesOnAPlayfieldBoard(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    $board = self::boardOfSwitch($plan, 112);
    $this->assertNotNull($board);
    $this->assertSame(HardwareAllocator::GROUP_PLAYFIELD, self::boardGroup($plan, $board));
  }

  /**
   * Every other cabinet switch stays in the cabinet.
   */
  public function testCabinetSwitchesGoOnACabinetBoard(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [
        ['number' => 5, 'description' => 'Service Credit/Escape', 'direct' => TRUE],
        ['number' => 11, 'description' => 'Gun Handle Trigger'],
      ],
    ]));

    $this->assertSame(
      HardwareAllocator::GROUP_CABINET,
      self::boardGroup($plan, self::boardOfSwitch($plan, 5))
    );
    $this->assertSame(
      HardwareAllocator::GROUP_PLAYFIELD,
      self::boardGroup($plan, self::boardOfSwitch($plan, 11))
    );
  }

  /**
   * Backbox devices ride with the cabinet rather than getting a board.
   */
  public function testABackboxCoilGoesOnTheCabinetBoard(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 7, 'description' => 'Knocker', 'class' => 'highPower', 'location' => 'backbox'],
      ],
    ]));

    $this->assertSame(
      HardwareAllocator::GROUP_CABINET,
      self::boardGroup($plan, self::coil($plan, 7)['board'])
    );
  }

  public function testOptoSwitchesGoOnAnOptoBoard(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [
        ['number' => 31, 'description' => 'Trough Jam', 'opto' => TRUE],
        ['number' => 11, 'description' => 'Gun Handle Trigger'],
      ],
    ]));

    $this->assertSame(BoardCapacity::OPTO_16, self::boardType($plan, self::boardOfSwitch($plan, 31)));
    $this->assertSame(BoardCapacity::IO_16_8_1, self::boardType($plan, self::boardOfSwitch($plan, 11)));
  }

  /**
   * Each board has one LED connector, so each string needs its own board.
   */
  public function testEachStripeGetsItsOwnBoardAndTheLedPin(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [['number' => 11, 'description' => 'Lamp 11']],
      'flashers' => [['number' => 17, 'description' => 'Headquarters']],
      'gi' => [['number' => 1, 'description' => 'Right String']],
    ]));

    // Three under the playfield, plus the cabinet's.
    $this->assertCount(4, $plan['stripes']);
    $boards = array_column($plan['stripes'], 'board');
    $this->assertCount(4, array_unique($boards), 'two strings were put on one board');
    foreach ($plan['stripes'] as $stripe) {
      $this->assertSame(25, $stripe['pin']);
    }
  }

  public function testAnEmptyPlayfieldRoleGetsNoStripe(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [['number' => 11, 'description' => 'Lamp 11']],
    ]));

    $labels = array_column($plan['stripes'], 'label');
    $this->assertSame(['Lamps', 'Cabinet'], $labels);
  }

  /**
   * A cabinet has illumination the manual's matrices do not describe - coin
   * return button lights, backbox lamps - so the string is planned for whether
   * or not the input mentions any.
   */
  public function testTheCabinetAlwaysGetsAStripe(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [['number' => 11, 'description' => 'Lamp 11']],
    ]));

    $cabinet = array_values(array_filter($plan['stripes'], static fn ($s) => $s['label'] === 'Cabinet'));
    $this->assertCount(1, $cabinet);
    $this->assertSame([], $cabinet[0]['leds']);
    $this->assertSame(
      HardwareAllocator::GROUP_CABINET,
      self::boardGroup($plan, $cabinet[0]['board']),
      'a cabinet string driven from a playfield board is a data wire across the machine'
    );

    $notes = implode("\n", array_map('strval', $plan['notes']));
    $this->assertStringContainsString('cabinet string was created empty', $notes);
  }

  /**
   * The cabinet string carries whatever roles the cabinet has, together.
   *
   * Splitting a start button lamp, a backbox GI string and a coin door light
   * across three strings would cost three boards for a handful of LEDs.
   */
  public function testTheCabinetStripeCarriesMixedRoles(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [
        ['number' => 11, 'description' => 'Left Rollover'],
        ['number' => 88, 'description' => 'Start Button', 'location' => 'cabinet'],
        ['number' => 100, 'description' => 'Coin Return Light', 'location' => 'cabinet'],
      ],
      'gi' => [['number' => 5, 'description' => 'Backbox', 'location' => 'backbox']],
    ]));

    $cabinet = array_values(array_filter($plan['stripes'], static fn ($s) => $s['label'] === 'Cabinet'));
    $this->assertCount(1, $cabinet);

    $numbers = array_column($cabinet[0]['leds'], 'number');
    sort($numbers);
    $this->assertSame([5, 88, 100], $numbers, 'a cabinet or backbox LED belongs on the cabinet string');

    $roles = array_unique(array_column($cabinet[0]['leds'], 'role'));
    sort($roles);
    $this->assertSame(['GI', 'Lamp'], $roles);

    // And the playfield lamp stayed where it was.
    $lamps = array_values(array_filter($plan['stripes'], static fn ($s) => $s['label'] === 'Lamps'));
    $this->assertSame([11], array_column($lamps[0]['leds'], 'number'));
  }

  /**
   * Positions run from zero within each string, not across all of them.
   */
  public function testEachStripeNumbersItsOwnPositionsFromZero(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [
        ['number' => 11, 'description' => 'A'],
        ['number' => 88, 'description' => 'Start Button', 'location' => 'cabinet'],
      ],
      'gi' => [['number' => 1, 'description' => 'Backbox', 'location' => 'cabinet']],
    ]));

    foreach ($plan['stripes'] as $stripe) {
      $positions = array_column($stripe['leds'], 'position');
      $this->assertSame(range(0, count($positions) - 1), $positions, $stripe['label']);
    }
  }

  public function testLedsAreNumberedInMatrixOrderFromPositionZero(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [
        ['number' => 11, 'description' => 'A'],
        ['number' => 12, 'description' => 'B'],
        ['number' => 21, 'description' => 'C'],
      ],
    ]));

    $leds = $plan['stripes'][0]['leds'];
    $this->assertSame([0, 1, 2], array_column($leds, 'position'));
    $this->assertSame([11, 12, 21], array_column($leds, 'number'));
  }

  /**
   * Power and pulse time come from the manual's solenoid type.
   */
  public function testCoilPowerFollowsTheSolenoidType(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 1, 'description' => 'Ball Release', 'class' => 'highPower'],
        ['number' => 9, 'description' => 'Left Sling', 'class' => 'lowPower'],
        ['number' => 26, 'description' => 'Popper', 'class' => 'genPurpose'],
      ],
    ]));

    $this->assertSame(255, self::coil($plan, 1)['power']);
    $this->assertSame(128, self::coil($plan, 9)['power']);
    $this->assertSame(128, self::coil($plan, 26)['power']);
  }

  /**
   * A coil with no bound is one the ROM can leave energised.
   */
  public function testEveryCoilIsBoundedExceptAHoldWinding(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 1, 'description' => 'Ball Release', 'class' => 'highPower'],
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    foreach ($plan['coils'] as $coil) {
      if ($coil['holdWinding']) {
        $this->assertSame(0, $coil['maxPulseTime'], 'bounding a hold winding drops the flipper');
        continue;
      }
      $this->assertGreaterThan(0, $coil['maxPulseTime'], sprintf(
        'coil %d ("%s") has nothing bounding it', $coil['number'], $coil['description']
      ));
    }

    $this->assertTrue(self::coil($plan, 30)['holdWinding']);
    $this->assertFalse(self::coil($plan, 29)['holdWinding']);
    $this->assertSame(DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS, self::coil($plan, 29)['maxPulseTime']);
  }

  // --- motors ----------------------------------------------------------------
  //
  // A gun or cannon is turned by a small motor, 12 V on a 48 V rail, and driven
  // between two end positions. The switches at those positions are what should
  // cut it the moment it arrives, which they can only do from its own board.

  public function testAMotorIsDrivenAtItsOwnVoltageNotACoils(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 20, 'description' => 'Gun Motor', 'class' => 'lowPower', 'type' => 'motor'],
        ['number' => 1, 'description' => 'Ball Release', 'class' => 'lowPower'],
      ],
    ]));

    $this->assertSame(DeviceDefaults::MOTOR_POWER, self::coil($plan, 20)['power']);
    $this->assertLessThan(
      self::coil($plan, 1)['power'],
      self::coil($plan, 20)['power'],
      'a motor at coil power is four times its rated voltage'
    );
  }

  /**
   * High Power on the driver does not make the motor a high-power device.
   */
  public function testAMotorsPowerIgnoresTheSolenoidType(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 20, 'description' => 'Gun Motor', 'class' => 'highPower', 'type' => 'motor'],
      ],
    ]));

    $this->assertSame(DeviceDefaults::MOTOR_POWER, self::coil($plan, 20)['power']);
  }

  /**
   * An end switch cannot stop a motor from another board.
   */
  public function testEndSwitchesShareTheMotorsBoard(): void {
    $switches = [
      ['number' => 76, 'description' => 'Gun Position'],
      ['number' => 77, 'description' => 'Gun Lockup'],
    ];
    // Enough other coils to fill several boards, so the motor could easily end
    // up somewhere else.
    $coils = [['number' => 20, 'description' => 'Gun Motor', 'class' => 'lowPower',
               'type' => 'motor', 'endSwitches' => [76, 77]]];
    for ($n = 100; $n < 120; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $motorBoard = self::coil($plan, 20)['board'];
    $this->assertSame($motorBoard, self::boardOfSwitch($plan, 76));
    $this->assertSame($motorBoard, self::boardOfSwitch($plan, 77));
  }

  /**
   * An end switch must not be wired as a fast-flip switch.
   *
   * The polarity is the other way round: a fast-flip switch runs the coil while
   * it is closed, so using one here would drive the motor whenever it was
   * already at the end of its travel.
   */
  public function testAnEndSwitchDoesNotDriveTheMotor(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [['number' => 76, 'description' => 'Gun Position']],
      'coils' => [['number' => 20, 'description' => 'Gun Motor', 'class' => 'lowPower',
                   'type' => 'motor', 'endSwitches' => [76]]],
    ]));

    $this->assertNull(self::coil($plan, 20)['fastFlipSwitch']);
  }

  /**
   * What is left to do by hand has to be said, not left to be discovered.
   *
   * The end switches stop the motor now, so what remains is the travel time:
   * the default pulse time is shorter than a traverse, and left alone the
   * assembly stops part way.
   */
  public function testAMotorIsReportedWithWhatItStillNeeds(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [['number' => 76, 'description' => 'Gun Position']],
      'coils' => [['number' => 20, 'description' => 'Gun Motor', 'class' => 'lowPower',
                   'type' => 'motor', 'endSwitches' => [76]]],
    ]));

    $notes = implode("\n", array_map('strval', $plan['notes']));
    $this->assertStringContainsString('Gun Motor', $notes);
    $this->assertStringContainsString('stop it the moment it reaches either end', $notes);
    $this->assertStringContainsString('time the travel and set it', $notes);
  }

  public function testAMotorWithoutEndSwitchesSaysSo(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [['number' => 20, 'description' => 'Gun Motor', 'class' => 'lowPower', 'type' => 'motor']],
    ]));

    $this->assertStringContainsString(
      'no end-position switches',
      implode("\n", array_map('strval', $plan['notes']))
    );
  }

  /**
   * Two coils sharing a switch are one group, however they came to share it.
   */
  public function testCoilsSharingASwitchAreMergedIntoOneGroup(): void {
    $switches = [['number' => 76, 'description' => 'Shared End Position']];
    $coils = [
      ['number' => 20, 'description' => 'Motor A', 'class' => 'lowPower', 'type' => 'motor', 'endSwitches' => [76]],
      ['number' => 21, 'description' => 'Motor B', 'class' => 'lowPower', 'type' => 'motor', 'endSwitches' => [76]],
    ];
    for ($n = 100; $n < 118; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $board = self::boardOfSwitch($plan, 76);
    $this->assertSame($board, self::coil($plan, 20)['board']);
    $this->assertSame($board, self::coil($plan, 21)['board']);
  }

  /**
   * A hold winding that is not a flipper's.
   *
   * Dirty Harry's trap door is one coil assembly driven as two outputs, "high"
   * and "hold", with no button and no EOS. It needs the same treatment as a
   * flipper's hold winding: no bound, because cutting it drops the door.
   */
  public function testAHoldWindingOutsideAFlipperIsUnbounded(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 8, 'description' => 'Trap Door High', 'class' => 'lowPower'],
        ['number' => 16, 'description' => 'Trap Door Hold', 'class' => 'lowPower', 'holdWinding' => TRUE],
      ],
    ]));

    $this->assertSame(0, self::coil($plan, 16)['maxPulseTime']);
    $this->assertTrue(self::coil($plan, 16)['holdWinding']);

    // Its partner is an ordinary coil and still needs a bound.
    $this->assertGreaterThan(0, self::coil($plan, 8)['maxPulseTime']);
    $this->assertFalse(self::coil($plan, 8)['holdWinding']);
  }

  // --- stop switches ---------------------------------------------------------

  /**
   * The EOS cuts the power winding, and only that one.
   */
  public function testTheEosStopsThePowerWindingButNotTheHold(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    $eos = NULL;
    foreach ($plan['switches'] as $switch) {
      if (($switch['role'] ?? '') === 'flipperEos') {
        $eos = $switch['number'];
      }
    }
    $this->assertNotNull($eos);

    $this->assertSame([$eos], self::coil($plan, 29)['stopSwitches']);
    // Cutting the hold winding would drop the finger the moment it arrived.
    $this->assertSame([], self::coil($plan, 30)['stopSwitches']);
  }

  /**
   * A stop switch only works from the board that owns the output.
   */
  public function testAStopSwitchSharesItsOutputsBoard(): void {
    $switches = [
      ['number' => 76, 'description' => 'Gun Position'],
      ['number' => 77, 'description' => 'Gun Lockup'],
    ];
    $coils = [['number' => 20, 'description' => 'Gun Motor', 'class' => 'lowPower',
               'type' => 'motor', 'endSwitches' => [76, 77]]];
    for ($n = 100; $n < 120; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $motor = self::coil($plan, 20);
    $this->assertSame([76, 77], $motor['stopSwitches']);
    foreach ($motor['stopSwitches'] as $number) {
      $this->assertSame($motor['board'], self::boardOfSwitch($plan, $number));
    }
  }

  /**
   * An ordinary coil gets none, so nothing changes for the rest of a machine.
   */
  public function testAnOrdinaryCoilHasNoStopSwitches(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [['number' => 1, 'description' => 'Ball Release', 'class' => 'highPower']],
    ]));

    $this->assertSame([], self::coil($plan, 1)['stopSwitches']);
  }

  /**
   * Both windings are driven by the button, which is what makes them flip.
   */
  public function testBothFlipperWindingsFastFlipOnTheButton(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    $this->assertSame(112, self::coil($plan, 29)['fastFlipSwitch']);
    $this->assertSame(112, self::coil($plan, 30)['fastFlipSwitch']);
  }

  public function testFlipperEosSwitchesUseTheCustomRange(): void {
    [, $plan] = $this->allocate(self::document([
      'coils' => [
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
        ['number' => 31, 'description' => 'LL Power', 'class' => 'highPower'],
        ['number' => 32, 'description' => 'LL Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
        ['name' => 'Lower Left', 'position' => 'lowerLeft', 'powerCoil' => 31, 'holdCoil' => 32],
      ],
    ]));

    $eos = [];
    foreach ($plan['switches'] as $switch) {
      if (($switch['role'] ?? '') === 'flipperEos') {
        $eos[] = $switch['number'];
      }
    }

    $this->assertSame([200, 201], $eos);
    foreach ($eos as $number) {
      $this->assertLessThanOrEqual(PlatformNumbers::CUSTOM_NUMBER_LIMIT, $number,
        'numbers from 240 up are read as negative');
    }
  }

  /**
   * Pins come from the mapping, so a board type with fewer pins allocates fewer.
   */
  public function testPinsComeFromTheGpioMappingNotFromConstants(): void {
    $parser = new DeviceDataParser();
    $devices = $parser->parse(json_encode(self::document([
      'switches' => [
        ['number' => 11, 'description' => 'One'],
        ['number' => 12, 'description' => 'Two'],
        ['number' => 13, 'description' => 'Three'],
      ],
    ])));
    $this->assertNotNull($devices, implode("\n", $parser->errors()));

    // A board whose mapping stops after two input pins.
    $truncated = [1 => 3, 2 => 4];
    $plan = (new HardwareAllocator($truncated, self::optoMapping()))->allocate($devices);

    $pinsPerBoard = [];
    foreach ($plan['switches'] as $switch) {
      $pinsPerBoard[$switch['board']][] = $switch['pin'];
      $this->assertContains($switch['pin'], [1, 2], 'allocated a pin the mapping does not define');
    }
    $this->assertCount(2, $pinsPerBoard, 'three switches must not fit on two pins');
  }

  public function testNoPinIsUsedTwiceOnOneBoard(): void {
    $switches = [];
    for ($n = 11; $n <= 58; $n++) {
      $switches[] = ['number' => $n, 'description' => 'Switch ' . $n];
    }
    $coils = [];
    for ($n = 1; $n <= 20; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $used = [];
    foreach (array_merge($plan['switches'], $plan['coils']) as $device) {
      $key = $device['board'] . ':' . $device['pin'];
      $this->assertArrayNotHasKey($key, $used, 'board ' . $device['board'] . ' pin ' . $device['pin'] . ' used twice');
      $used[$key] = TRUE;
    }
  }

  /**
   * Every device that went in comes out placed, or the count is a lie.
   */
  public function testEveryDeviceIsPlaced(): void {
    [$devices, $plan] = $this->allocate(self::document([
      'switches' => [
        ['number' => 11, 'description' => 'One'],
        ['number' => 31, 'description' => 'Opto', 'opto' => TRUE],
        ['number' => 5, 'description' => 'Escape', 'direct' => TRUE],
      ],
      'coils' => [
        ['number' => 1, 'description' => 'Ball Release', 'class' => 'highPower'],
        ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    // Three from the document, plus a button and an EOS per flipper.
    $this->assertCount(count($devices['switches']) + 2, $plan['switches']);
    $this->assertCount(count($devices['coils']), $plan['coils']);
    foreach (array_merge($plan['switches'], $plan['coils']) as $device) {
      $this->assertNotNull($device['pin'], 'a device was placed without a pin');
      $this->assertNotNull($device['board']);
    }
  }

  /**
   * Space under a playfield is tight, so a board must never be there for
   * headroom.
   *
   * The minimum is set by whichever resource runs out first: 8 outputs or 16
   * inputs per board, counted separately for the cabinet and the playfield
   * because a device cannot cross between them.
   */
  public function testTheBoardCountIsTheMinimumTheDevicesNeed(): void {
    $switches = [];
    for ($n = 11; $n <= 68; $n++) {
      $switches[] = ['number' => $n, 'description' => 'Switch ' . $n];
    }
    $coils = [];
    for ($n = 1; $n <= 25; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    // Playfield boards for the devices, plus the one the cabinet string needs -
    // this machine has nothing else in the cabinet.
    $expected = (int) max(ceil(count($coils) / 8), ceil(count($switches) / 16)) + 1;
    $this->assertCount($expected, $plan['boards'], sprintf(
      '%d coils and %d switches need %d boards',
      count($coils), count($switches), $expected
    ));
  }

  /**
   * The leftover board is what made the allocation look wasteful.
   *
   * Filling boards to the brim in order leaves the last one holding whatever
   * did not fit - one coil, on a board that then reads as spare IO even though
   * it is needed. Spreading costs no boards and leaves every one with the same
   * headroom for a device added later.
   */
  public function testTheLoadIsSpreadRatherThanFillingBoardsInOrder(): void {
    $coils = [];
    for ($n = 1; $n <= 25; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['coils' => $coils]));

    $perBoard = [];
    foreach ($plan['boards'] as $board) {
      if ($board['group'] === HardwareAllocator::GROUP_PLAYFIELD) {
        $perBoard[$board['index']] = 0;
      }
    }
    foreach ($plan['coils'] as $coil) {
      $perBoard[$coil['board']]++;
    }

    // 25 coils over 4 playfield boards is 7/6/6/6, not 8/8/8/1.
    $this->assertSame(4, count($perBoard));
    $this->assertLessThanOrEqual(1, max($perBoard) - min($perBoard), sprintf(
      'boards carry %s, which is not an even spread', implode('/', $perBoard)
    ));
  }

  /**
   * Three stripes need three boards, since each board has one LED connector.
   * On a small machine those boards carry nothing else, and that is worth
   * saying rather than leaving to be found during the build.
   */
  public function testABoardCarryingOnlyAStripeIsReported(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [['number' => 11, 'description' => 'One']],
      'lamps' => [['number' => 11, 'description' => 'Lamp']],
      'flashers' => [['number' => 17, 'description' => 'Flasher']],
      'gi' => [['number' => 1, 'description' => 'GI']],
    ]));

    $notes = implode("\n", array_map('strval', $plan['notes']));
    $this->assertStringContainsString('carries no switches or coils', $notes);
    $this->assertStringContainsString('would save a board', $notes);
  }

  /**
   * And is not reported when every board is doing real work.
   */
  public function testNoSuchNoteWhenEveryBoardCarriesDevices(): void {
    $switches = [];
    for ($n = 11; $n <= 68; $n++) {
      $switches[] = ['number' => $n, 'description' => 'Switch ' . $n];
    }
    $coils = [];
    for ($n = 1; $n <= 25; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document([
      'switches' => $switches,
      'coils' => $coils,
      'lamps' => [['number' => 11, 'description' => 'Lamp']],
      'flashers' => [['number' => 17, 'description' => 'Flasher']],
      'gi' => [['number' => 1, 'description' => 'GI']],
    ]));

    $notes = implode("\n", array_map('strval', $plan['notes']));
    // The cabinet board carries the cabinet string and nothing else here, which
    // is exactly what the note is for, so it is expected to name that one only.
    $this->assertSame(
      1,
      substr_count($notes, 'carries no switches or coils'),
      'only the cabinet board should be reported as carrying no devices'
    );
  }

  // --- borrowing spare cabinet outputs ---------------------------------------
  //
  // A board under a playfield costs space there is none of. A coil driven from
  // an output already going spare in the cabinet costs a few wires, and wires
  // are cheap. So the cabinet's leftovers are spent before a board is added -
  // but only on coils that can stand being far from their switch.

  /**
   * One coil short of a full board is a whole board, unless the cabinet has room.
   */
  public function testASpareCabinetOutputIsUsedBeforeAddingAPlayfieldBoard(): void {
    // 8 cabinet switches force a cabinet board, whose 8 outputs are then spare
    // apart from the knocker. 9 playfield coils would otherwise need two
    // playfield boards for the sake of the ninth.
    $switches = [];
    foreach (range(1, 8) as $n) {
      $switches[] = ['number' => $n, 'description' => 'Direct ' . $n, 'direct' => TRUE];
    }
    $coils = [['number' => 7, 'description' => 'Knocker', 'class' => 'highPower', 'location' => 'backbox']];
    for ($n = 10; $n < 19; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $playfieldBoards = array_filter(
      $plan['boards'],
      static fn ($b) => $b['group'] === HardwareAllocator::GROUP_PLAYFIELD
    );
    $this->assertCount(1, $playfieldBoards, 'a second playfield board was added for one coil');

    $inCabinet = array_filter($plan['coils'], static fn ($c) => self::boardGroup($plan, $c['board']) === HardwareAllocator::GROUP_CABINET);
    $this->assertCount(2, $inCabinet, 'expected the knocker plus one borrowed coil');
  }

  /**
   * Borrowing is reported, because it is a wire somebody has to run.
   */
  public function testBorrowingACabinetOutputIsReported(): void {
    $switches = [];
    foreach (range(1, 8) as $n) {
      $switches[] = ['number' => $n, 'description' => 'Direct ' . $n, 'direct' => TRUE];
    }
    $coils = [];
    for ($n = 10; $n < 19; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $notes = implode("\n", array_map('strval', $plan['notes']));
    $this->assertStringContainsString('driven from board', $notes);
    $this->assertStringContainsString('Run the wires', $notes);
  }

  /**
   * A fast-flip coil must never be borrowed: the point of its placement is that
   * its switch is on the same board.
   */
  public function testAFastFlipCoilIsNeverDrivenFromTheCabinet(): void {
    $switches = [];
    foreach (range(1, 8) as $n) {
      $switches[] = ['number' => $n, 'description' => 'Direct ' . $n, 'direct' => TRUE];
    }
    $switches[] = ['number' => 61, 'description' => 'Left Sling'];
    // Nine playfield coils, and the one that would be left over has a fast-flip
    // switch, so it has to stay with it.
    $coils = [];
    for ($n = 10; $n < 18; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }
    $coils[] = ['number' => 9, 'description' => 'Left Sling', 'class' => 'lowPower', 'fastFlipSwitch' => 61];

    [, $plan] = $this->allocate(self::document(['switches' => $switches, 'coils' => $coils]));

    $sling = self::coil($plan, 9);
    $this->assertSame(
      HardwareAllocator::GROUP_PLAYFIELD,
      self::boardGroup($plan, $sling['board']),
      'a fast-flip coil in the cabinet cannot react to its switch locally'
    );
    $this->assertSame(self::boardOfSwitch($plan, 61), $sling['board']);
  }

  /**
   * Flipper windings are fast-flip coils, so the rule covers them already - but
   * this is the case that would hurt most, so it is stated on its own.
   */
  public function testFlipperWindingsAreNeverDrivenFromTheCabinet(): void {
    $switches = [];
    foreach (range(1, 8) as $n) {
      $switches[] = ['number' => $n, 'description' => 'Direct ' . $n, 'direct' => TRUE];
    }
    $coils = [];
    for ($n = 10; $n < 17; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }
    $coils[] = ['number' => 29, 'description' => 'LR Power', 'class' => 'highPower'];
    $coils[] = ['number' => 30, 'description' => 'LR Hold', 'class' => 'lowPower'];

    [, $plan] = $this->allocate(self::document([
      'switches' => $switches,
      'coils' => $coils,
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
    ]));

    foreach ([29, 30] as $number) {
      $this->assertSame(
        HardwareAllocator::GROUP_PLAYFIELD,
        self::boardGroup($plan, self::coil($plan, $number)['board']),
        'a flipper winding was moved away from its button'
      );
    }
  }

  /**
   * The backstop that keeps a fast-flip coil out of the cabinet.
   *
   * In normal use it never fires: a coil whose fast-flip switch resolves is
   * placed with that switch before borrowing is considered at all, and the
   * parser guarantees it resolves. This calls the allocator directly with a
   * coil whose switch is missing, which is the one path that reaches the guard,
   * so the rule is pinned by something other than the ordering that currently
   * makes it unreachable.
   */
  public function testACoilWithAFastFlipSwitchIsNeverBorrowedEvenWithoutItsSwitch(): void {
    $devices = [
      'game' => ['title' => 'Test', 'platform' => 'WPC', 'rom' => ''],
      'switches' => [],
      'coils' => [],
      'flippers' => [],
      'flashers' => [],
      'lamps' => [],
      'gi' => [],
    ];
    // A cabinet board with outputs going spare.
    foreach (range(1, 8) as $n) {
      $devices['switches'][] = [
        'number' => $n, 'description' => 'Direct ' . $n, 'opto' => FALSE,
        'direct' => TRUE, 'button' => FALSE, 'location' => DeviceDataParser::LOCATION_CABINET,
      ];
    }
    // Nine playfield coils, so one would otherwise be borrowed - and the ninth
    // names a fast-flip switch that is not in the list, so it is not placed
    // with a group first.
    for ($n = 10; $n < 18; $n++) {
      $devices['coils'][] = [
        'number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower',
        'type' => 'coil', 'location' => DeviceDataParser::LOCATION_PLAYFIELD,
        'fastFlipSwitch' => NULL,
      ];
    }
    $devices['coils'][] = [
      'number' => 9, 'description' => 'Orphaned Sling', 'class' => 'lowPower',
      'type' => 'coil', 'location' => DeviceDataParser::LOCATION_PLAYFIELD,
      'fastFlipSwitch' => 61,
    ];

    $plan = (new HardwareAllocator(self::ioMapping(), self::optoMapping()))->allocate($devices);

    $this->assertSame(
      HardwareAllocator::GROUP_PLAYFIELD,
      self::boardGroup($plan, self::coil($plan, 9)['board']),
      'a coil with a fast-flip switch must not be driven from the cabinet'
    );
  }

  /**
   * Borrowing spends outputs that are already spare; it never adds a cabinet
   * board to create them.
   */
  public function testNoCabinetBoardIsAddedToHoldPlayfieldCoils(): void {
    $coils = [];
    for ($n = 10; $n < 19; $n++) {
      $coils[] = ['number' => $n, 'description' => 'Coil ' . $n, 'class' => 'lowPower'];
    }

    [, $plan] = $this->allocate(self::document(['coils' => $coils]));

    // A cabinet board exists for the cabinet string, but no playfield coil may
    // have been put on it: borrowing spends outputs that were already spare, it
    // does not create a board to make some.
    foreach ($plan['coils'] as $coil) {
      $this->assertSame(
        HardwareAllocator::GROUP_PLAYFIELD,
        self::boardGroup($plan, $coil['board']),
        sprintf('coil %d was put in the cabinet, which had no spare outputs to begin with', $coil['number'])
      );
    }
  }

  /**
   * An opto board has the same LED connector, so a string can sit on it rather
   * than forcing another board under the playfield.
   */
  public function testAnOptoBoardCanCarryAnLedStripe(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [['number' => 31, 'description' => 'Trough Jam', 'opto' => TRUE]],
      'lamps' => [['number' => 11, 'description' => 'Lamp']],
    ]));

    // One playfield board - the opto board, carrying the lamp string - plus the
    // cabinet board its own string needs.
    $playfield = array_filter(
      $plan['boards'],
      static fn ($b) => $b['group'] === HardwareAllocator::GROUP_PLAYFIELD
    );
    $this->assertCount(1, $playfield, 'the opto board should have carried the string');

    $lamps = array_values(array_filter($plan['stripes'], static fn ($s) => $s['label'] === 'Lamps'));
    $this->assertCount(1, $lamps);
    $this->assertSame(BoardCapacity::OPTO_16, self::boardType($plan, $lamps[0]['board']));
    $this->assertSame(25, $lamps[0]['pin']);
  }

  public function testBoardsAreNamedAfterTheirGroup(): void {
    [, $plan] = $this->allocate(self::document([
      'switches' => [
        ['number' => 5, 'description' => 'Escape', 'direct' => TRUE],
        ['number' => 11, 'description' => 'Trigger'],
      ],
    ]));

    $descriptions = array_column($plan['boards'], 'description');
    $this->assertNotEmpty(preg_grep('/^Cabinet 1 /', $descriptions));
    $this->assertNotEmpty(preg_grep('/^Playfield 1 /', $descriptions));
  }

}

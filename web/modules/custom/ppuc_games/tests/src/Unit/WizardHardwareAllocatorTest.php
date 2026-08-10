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
   * Opto_16: 16 inputs on the same GPIOs, and nothing else.
   */
  private static function optoMapping(): array {
    return array_combine(range(1, 16), range(3, 18));
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
   * Every existing game does one stripe per board, on the LED pin.
   */
  public function testEachStripeGetsItsOwnBoardAndTheLedPin(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [['number' => 11, 'description' => 'Lamp 11']],
      'flashers' => [['number' => 17, 'description' => 'Headquarters']],
      'gi' => [['number' => 1, 'description' => 'Right String']],
    ]));

    $this->assertCount(3, $plan['stripes']);
    $boards = array_column($plan['stripes'], 'board');
    $this->assertCount(3, array_unique($boards), 'two stripes were put on one board');
    foreach ($plan['stripes'] as $stripe) {
      $this->assertSame(25, $stripe['pin']);
    }
  }

  public function testAnEmptyRoleGetsNoStripe(): void {
    [, $plan] = $this->allocate(self::document([
      'lamps' => [['number' => 11, 'description' => 'Lamp 11']],
    ]));

    $this->assertCount(1, $plan['stripes']);
    $this->assertSame('Lamps', $plan['stripes'][0]['label']);
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

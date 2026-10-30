<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\DeviceDefaults;
use Drupal\ppuc_games\Wizard\PlatformNumbers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The two tables that cannot be read off a manual page.
 *
 * A switch outside the matrix is only read by the ROM at the number PinMAME
 * defines, and a coil is only safe at the power its solenoid type calls for.
 * Both are things a wrong value gets wrong silently: a switch nothing polls
 * looks exactly like a broken switch, and an overdriven winding looks fine
 * until it burns.
 */
#[CoversClass(PlatformNumbers::class)]
#[CoversClass(DeviceDefaults::class)]
#[Group('ppuc_games')]
class WizardPlatformNumbersTest extends TestCase {

  /**
   * From PinMAME: swCoin1..4, swCancel, swDown, swUp, swEnter.
   */
  public function testWpcDirectSwitches(): void {
    $direct = PlatformNumbers::directSwitches('WPC');

    $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_keys($direct));
    $this->assertSame('Service Credit/Escape', $direct[5]);
    $this->assertSame('Begin Test', $direct[8]);
  }

  /**
   * From PinMAME: swLRFlip 112, swLLFlip 114, swURFlip 116, swULFlip 118.
   */
  public function testWpcFliptronicButtons(): void {
    $this->assertSame(112, PlatformNumbers::flipperButtonNumber('WPC', 'lowerRight'));
    $this->assertSame(114, PlatformNumbers::flipperButtonNumber('WPC', 'lowerLeft'));
    $this->assertSame(116, PlatformNumbers::flipperButtonNumber('WPC', 'upperRight'));
    $this->assertSame(118, PlatformNumbers::flipperButtonNumber('WPC', 'upperLeft'));
  }

  /**
   * From PinMAME: swLREOS 111, swLLEOS 113, swUREOS 115, swULEOS 117.
   */
  public function testWpcFliptronicEosSwitches(): void {
    $this->assertSame(111, PlatformNumbers::flipperEosNumber('WPC', 'lowerRight'));
    $this->assertSame(113, PlatformNumbers::flipperEosNumber('WPC', 'lowerLeft'));
    $this->assertSame(115, PlatformNumbers::flipperEosNumber('WPC', 'upperRight'));
    $this->assertSame(117, PlatformNumbers::flipperEosNumber('WPC', 'upperLeft'));
  }

  /**
   * From PinMAME: sLRFlipPow 45 .. sLLFlip 48, and sURFlipPow 33 .. sULFlip 36.
   */
  public function testWpcFlipperWindingsGetPinmameNumbers(): void {
    $this->assertSame(
      [45, 46, 47, 48, 33, 34, 35, 36],
      array_map(static fn ($n) => PlatformNumbers::flipperCoilNumber('WPC', $n), range(29, 36))
    );
    // Another platform's numbers are not WPC's to move.
    $this->assertSame(29, PlatformNumbers::flipperCoilNumber('SYS11', 29));
  }

  /**
   * An unknown platform must not inherit WPC's numbers.
   */
  public function testAnUnknownPlatformHasNoNumbers(): void {
    $this->assertFalse(PlatformNumbers::isKnown('SYS11'));
    $this->assertSame([], PlatformNumbers::directSwitches('SYS11'));
    $this->assertSame([], PlatformNumbers::flipperButtons('SYS11'));
    $this->assertNull(PlatformNumbers::flipperButtonNumber('SYS11', 'lowerRight'));
  }

  public function testAnUnknownFlipperPositionHasNoNumber(): void {
    $this->assertNull(PlatformNumbers::flipperButtonNumber('WPC', 'middleLeft'));
  }

  public function testDirectSwitchNumbersAreRecognised(): void {
    $this->assertTrue(PlatformNumbers::isDirectSwitchNumber('WPC', 1));
    $this->assertTrue(PlatformNumbers::isDirectSwitchNumber('WPC', 8));
    $this->assertFalse(PlatformNumbers::isDirectSwitchNumber('WPC', 9));
    $this->assertFalse(PlatformNumbers::isDirectSwitchNumber('WPC', 11));
  }

  /**
   * On WPC every solenoid type is a +50 V coil; the type names the driver.
   *
   * Low Power at 128 once gave Dirty Harry's slingshots and jets a quarter of
   * their force.
   */
  public function testEverySolenoidTypeRunsAtFullPower(): void {
    $this->assertSame(255, DeviceDefaults::power(DeviceDefaults::CLASS_HIGH_POWER));
    $this->assertSame(255, DeviceDefaults::power(DeviceDefaults::CLASS_LOW_POWER));
    $this->assertSame(255, DeviceDefaults::power(DeviceDefaults::CLASS_GENERAL_PURPOSE));
  }

  /**
   * Defaulting would drive a coil at a power nobody chose.
   */
  public function testAnUnknownSolenoidTypeThrowsRatherThanDefaulting(): void {
    $this->expectException(\InvalidArgumentException::class);
    DeviceDefaults::power('mediumPower');
  }

  public function testKnownClassesAreExactlyTheOnesWithAPower(): void {
    foreach (DeviceDefaults::COIL_CLASSES as $class) {
      $this->assertTrue(DeviceDefaults::isKnownClass($class));
      $this->assertGreaterThan(0, DeviceDefaults::power($class));
    }
    $this->assertFalse(DeviceDefaults::isKnownClass('flasher'));
  }

  /**
   * A power value above 255 is not a PWM duty cycle at all.
   */
  public function testEveryPowerIsAValidPwmValue(): void {
    foreach (DeviceDefaults::COIL_CLASSES as $class) {
      $this->assertLessThanOrEqual(255, DeviceDefaults::power($class));
    }
    $this->assertLessThanOrEqual(255, DeviceDefaults::FLIPPER_POWER);
    $this->assertLessThanOrEqual(255, DeviceDefaults::FLIPPER_HOLD);
  }

  /**
   * The hold output drives both windings in series, which Fliptronic fed the
   * same +50 V as the power winding. Its resistance is what lets it sit on.
   */
  public function testTheFlipperHoldWindingGetsTheSameVoltageAsThePowerWinding(): void {
    $this->assertSame(DeviceDefaults::FLIPPER_POWER, DeviceDefaults::FLIPPER_HOLD);
  }

  public function testOrdinaryCoilsAreBoundedAndFlipperPowerIsBoundedHarder(): void {
    $this->assertGreaterThan(0, DeviceDefaults::MAX_PULSE_TIME_MS);
    $this->assertGreaterThan(0, DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS);
    $this->assertLessThan(
      DeviceDefaults::MAX_PULSE_TIME_MS,
      DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS
    );
  }

  /**
   * The power winding must outlast the stroke and stay inside WPC's own timeout.
   *
   * Below the stroke time the flip is cut off before the finger arrives; above
   * 40 ms it is longer than the timeout WPC used to stop the winding burning.
   */
  public function testTheFlipperTimeoutClearsTheStrokeAndStaysInsideWpcRange(): void {
    $timeout = DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS;

    $this->assertLessThan(
      DeviceDefaults::FLIPPER_STROKE_MAX_MS,
      DeviceDefaults::FLIPPER_STROKE_MIN_MS,
      'the stroke range is the wrong way round'
    );
    $this->assertGreaterThan(
      DeviceDefaults::FLIPPER_STROKE_MAX_MS,
      $timeout,
      'a timeout at or below the slowest stroke cuts the flip off before the finger arrives'
    );
    // WPC's own range for the safety timeout. Outside it we are either weaker
    // than the machine was, or holding the winding longer than its designers
    // thought safe.
    $this->assertGreaterThanOrEqual(30, $timeout);
    $this->assertLessThanOrEqual(40, $timeout);
  }

}

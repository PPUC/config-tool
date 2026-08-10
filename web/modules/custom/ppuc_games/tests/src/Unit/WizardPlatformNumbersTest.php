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
   * The custom range has to sit clear of both the matrix and the negatives.
   *
   * Above 240 a switch number is read as negative - 243 means -3 - so an EOS
   * allocated up there is not the switch that was written down.
   */
  public function testTheCustomRangeIsClearOfMatrixAndNegativeNumbers(): void {
    $this->assertGreaterThan(88, PlatformNumbers::CUSTOM_NUMBER_BASE);
    $this->assertGreaterThan(118, PlatformNumbers::CUSTOM_NUMBER_BASE);
    // 240 is the first number read as negative, so the last usable one is 239.
    $this->assertLessThan(240, PlatformNumbers::CUSTOM_NUMBER_LIMIT);
    $this->assertGreaterThan(PlatformNumbers::CUSTOM_NUMBER_BASE, PlatformNumbers::CUSTOM_NUMBER_LIMIT);
  }

  /**
   * High Power and Low Power are what the manual's own column means.
   */
  public function testSolenoidTypesSetThePower(): void {
    $this->assertSame(255, DeviceDefaults::power(DeviceDefaults::CLASS_HIGH_POWER));
    $this->assertSame(128, DeviceDefaults::power(DeviceDefaults::CLASS_LOW_POWER));
    $this->assertSame(128, DeviceDefaults::power(DeviceDefaults::CLASS_GENERAL_PURPOSE));
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
   * The hold winding holds; the power winding throws. Reversing them would
   * cook the hold winding and give a weak flip.
   */
  public function testTheFlipperHoldWindingIsWeakerThanThePowerWinding(): void {
    $this->assertLessThan(DeviceDefaults::FLIPPER_POWER, DeviceDefaults::FLIPPER_HOLD);
  }

  public function testOrdinaryCoilsAreBoundedAndFlipperPowerIsBoundedHarder(): void {
    $this->assertGreaterThan(0, DeviceDefaults::MAX_PULSE_TIME_MS);
    $this->assertGreaterThan(0, DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS);
    $this->assertLessThan(
      DeviceDefaults::MAX_PULSE_TIME_MS,
      DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS
    );
  }

}

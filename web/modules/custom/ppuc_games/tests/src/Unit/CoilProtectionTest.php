<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\CoilProtection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A coil edited by hand must still have something that stops it.
 */
#[CoversClass(CoilProtection::class)]
#[Group('ppuc_games')]
class CoilProtectionTest extends TestCase {

  private static function coil(array $overrides = []): array {
    return $overrides + [
      'coil' => TRUE,
      'maxPulseTime' => 150,
      'holdPower' => 0,
      'holdPowerActivationTime' => 0,
      'holdWinding' => FALSE,
      'dualWinding' => FALSE,
      'fastSwitch' => FALSE,
      'stopSwitches' => 0,
    ];
  }

  /**
   * Dirty Harry's lower right flipper, as it is configured.
   */
  private static function flipperPower(array $overrides = []): array {
    return self::coil($overrides + ['maxPulseTime' => 40, 'fastSwitch' => TRUE, 'stopSwitches' => 1]);
  }

  private static function field(?array $refusal): ?string {
    return $refusal[0] ?? NULL;
  }

  public function testAnOrdinaryCoilWithAPulseTimeIsAccepted(): void {
    $this->assertNull(CoilProtection::refusal(self::coil()));
  }

  public function testACoilWithNothingStoppingItIsRefused(): void {
    $this->assertSame('field_max_pulse_time', self::field(CoilProtection::refusal(self::coil(['maxPulseTime' => 0]))));
  }

  /**
   * Hold power is a bound of its own: the coil drops to a current it survives.
   */
  public function testHoldPowerBoundsACoilToo(): void {
    $this->assertNull(CoilProtection::refusal(self::coil([
      'maxPulseTime' => 0, 'holdPower' => 64, 'holdPowerActivationTime' => 40,
    ])));
    // Without its activation time the power is never reduced.
    $this->assertNotNull(CoilProtection::refusal(self::coil(['maxPulseTime' => 0, 'holdPower' => 64])));
  }

  public function testAHoldWindingAndADualWoundCoilNeedNoPulseTime(): void {
    $this->assertNull(CoilProtection::refusal(self::coil(['maxPulseTime' => 0, 'holdWinding' => TRUE, 'fastSwitch' => TRUE])));
    $this->assertNull(CoilProtection::refusal(self::coil(['maxPulseTime' => 0, 'dualWinding' => TRUE])));
  }

  /**
   * A motor is the ROM's to stop, and lamps and shakers are not windings.
   */
  public function testOnlyCoilsAreChecked(): void {
    $this->assertNull(CoilProtection::refusal(self::coil(['coil' => FALSE, 'maxPulseTime' => 0])));
  }

  public function testAFlipperPowerWindingAtFortyMillisecondsIsAccepted(): void {
    $this->assertNull(CoilProtection::refusal(self::flipperPower()));
  }

  /**
   * The end-of-stroke switch alone is not enough: it can be unplugged.
   */
  public function testAFlipperPowerWindingWithoutATimeoutIsRefused(): void {
    $this->assertSame('field_max_pulse_time', self::field(CoilProtection::refusal(self::flipperPower(['maxPulseTime' => 0]))));
  }

  /**
   * An ordinary coil's 150 ms is far beyond what WPC let a power winding run.
   */
  public function testAFlipperPowerWindingWithACoilsTimeoutIsRefused(): void {
    $this->assertSame('field_max_pulse_time', self::field(CoilProtection::refusal(self::flipperPower(['maxPulseTime' => 150]))));
    $this->assertNotNull(CoilProtection::refusal(self::flipperPower(['maxPulseTime' => 41])));
  }

  /**
   * The checkbox must not be a way around the timeout.
   */
  public function testAHoldWindingWithTheEndOfStrokeSwitchIsRefused(): void {
    $this->assertSame('field_stop_switches', self::field(CoilProtection::refusal(self::flipperPower([
      'holdWinding' => TRUE, 'maxPulseTime' => 0,
    ]))));
  }

}

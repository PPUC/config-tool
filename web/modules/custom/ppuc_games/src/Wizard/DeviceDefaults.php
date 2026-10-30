<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * Power and pulse time for a coil, from what the manual says about it.
 *
 * The solenoid table names a type for every row - High Power, Low Power,
 * Gen. Purpose, Flasher - and that is what sets the drive power. Getting this
 * wrong in the generous direction damages hardware, so nothing here is inferred
 * from a description or a part number.
 *
 * The pulse time is not in the manual at all. It is what stops a coil burning
 * when the ROM leaves it on, so every coil the wizard creates gets one: a value
 * that is too short breaks gameplay visibly, while one that is too long damages
 * things silently, and the wizard should fail in the direction someone notices.
 */
final class DeviceDefaults {

  /**
   * Solenoid types, as the manual's SOLENOID TYPE column names them.
   */
  public const CLASS_HIGH_POWER = 'highPower';
  public const CLASS_LOW_POWER = 'lowPower';
  public const CLASS_GENERAL_PURPOSE = 'genPurpose';

  /**
   * The classes a coil entry may declare.
   *
   * Flasher is deliberately absent: a flasher is an LED in a stripe, never a
   * PWM output, so it never reaches this class.
   */
  public const COIL_CLASSES = [
    self::CLASS_HIGH_POWER,
    self::CLASS_LOW_POWER,
    self::CLASS_GENERAL_PURPOSE,
  ];

  /**
   * Drive power per solenoid type.
   *
   * On WPC the type names the driver transistor and the current it can take,
   * not the voltage: all three run their coils from +50 V. Dirty Harry's fuse
   * list has F105 "Solenoid #1-#8", F104 "#9-#16" and F103 "#25-#28" on the
   * +50 V lines J107-2, -3 and -1. The 20 V rail on J107-6 is for flashlamps
   * only, which are LEDs here. On a 48 V rail that is full power for all three;
   * 128 would give a slingshot a quarter of its original force.
   */
  private const POWER = [
    self::CLASS_HIGH_POWER => 255,
    self::CLASS_LOW_POWER => 255,
    self::CLASS_GENERAL_PURPOSE => 255,
  ];

  /**
   * Maximum pulse time for an ordinary coil, in milliseconds.
   */
  public const MAX_PULSE_TIME_MS = 150;

  /**
   * Drive power for a motor, whatever the solenoid table calls its driver.
   *
   * A gun or cannon assembly is turned by a small motor - 12 V is typical - on
   * a machine whose driver rail is 48 V. Running it at a coil's power would put
   * four times its rated voltage across it. 64 of 255 is about a quarter, which
   * is the ratio those two voltages want.
   *
   * This overrides the solenoid type, because the type describes the driver
   * transistor while this is about what is on the end of it.
   */
  public const MOTOR_POWER = 64;

  /**
   * The flipper power winding.
   *
   * On a real WPC Fliptronic machine the power winding is cut by whichever
   * comes first:
   *
   *  - the EOS contact closing, which is the normal path. The flipper finger
   *    reaches its stop in about 15 to 30 ms, and the CPU drops the power
   *    winding the moment the contact closes, leaving the hold winding on.
   *  - a fixed software timeout of 30 to 40 ms, which is the safety net for an
   *    EOS that is broken, misadjusted or unplugged. Without it the winding
   *    burns.
   *
   * PPUC does the same on the board: the EOS is the power winding's stop
   * switch, and maxPulseTime is the safety net. 40 ms is used because it clears
   * the 15-30 ms mechanical travel with margin, so a flip whose EOS never
   * closes is not cut short, and because it is the top of the range WPC itself
   * considered safe for the winding.
   */
  public const FLIPPER_POWER = 255;
  public const FLIPPER_POWER_MAX_PULSE_TIME_MS = 40;

  /**
   * How long the flipper stroke itself takes, for documentation and checks.
   *
   * The timeout has to clear this, or a flip is cut off before the finger
   * arrives.
   */
  public const FLIPPER_STROKE_MIN_MS = 15;
  public const FLIPPER_STROKE_MAX_MS = 30;

  /**
   * The flipper hold winding.
   *
   * No maximum pulse time, deliberately: a hold winding is wound to sit
   * energised for as long as the player holds the button, and bounding it would
   * drop the flipper mid-game. It is declared with holdWinding instead, which
   * is what tells libppuc's coil validator that this is correct and not an
   * unprotected coil.
   *
   * Full power, as on the original: the hold output drives both windings in
   * series, and that resistance - not a lower voltage - is what lets it sit on.
   * Fliptronic fed it the same +50 V as the power winding. Less makes a finger
   * sag under a cradled ball.
   */
  public const FLIPPER_HOLD = 255;

  /**
   * Drive power for a coil of this class.
   *
   * @throws \InvalidArgumentException
   *   If the class is not one the manual defines. Better than defaulting: a
   *   coil driven at a power nobody chose is how a winding burns.
   */
  /**
   * Drive power for a device of this solenoid type and device type.
   */
  public static function powerFor(string $class, string $type): int {
    return $type === 'motor' ? self::MOTOR_POWER : self::power($class);
  }

  public static function power(string $class): int {
    if (!isset(self::POWER[$class])) {
      throw new \InvalidArgumentException(sprintf(
        'unknown solenoid type "%s"; expected one of %s',
        $class,
        implode(', ', self::COIL_CLASSES)
      ));
    }
    return self::POWER[$class];
  }

  /**
   * Whether this is a solenoid type the manual defines.
   */
  public static function isKnownClass(string $class): bool {
    return isset(self::POWER[$class]);
  }

}

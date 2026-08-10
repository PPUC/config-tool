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
   * High and Low Power are what the manual's own column means. Gen. Purpose is
   * treated as Low Power - the manual does not say, and the lower of the two is
   * the safe direction to be wrong in.
   */
  private const POWER = [
    self::CLASS_HIGH_POWER => 255,
    self::CLASS_LOW_POWER => 128,
    self::CLASS_GENERAL_PURPOSE => 128,
  ];

  /**
   * Maximum pulse time for an ordinary coil, in milliseconds.
   */
  public const MAX_PULSE_TIME_MS = 150;

  /**
   * The flipper power winding.
   *
   * Held only long enough to throw the flipper up; the hold winding keeps it
   * there. This is the one number worth checking on a bench before trusting it,
   * which is why the wizard says so in its summary rather than burying it here.
   */
  public const FLIPPER_POWER = 255;
  public const FLIPPER_POWER_MAX_PULSE_TIME_MS = 50;

  /**
   * The flipper hold winding.
   *
   * No maximum pulse time, deliberately: a hold winding is wound to sit
   * energised for as long as the player holds the button, and bounding it would
   * drop the flipper mid-game. It is declared with holdWinding instead, which
   * is what tells libppuc's coil validator that this is correct and not an
   * unprotected coil.
   */
  public const FLIPPER_HOLD = 128;

  /**
   * Drive power for a coil of this class.
   *
   * @throws \InvalidArgumentException
   *   If the class is not one the manual defines. Better than defaulting: a
   *   coil driven at a power nobody chose is how a winding burns.
   */
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

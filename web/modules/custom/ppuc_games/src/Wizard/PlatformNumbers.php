<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * Switch numbers that are fixed by the platform rather than read off a manual.
 *
 * Most of a game's numbers come straight from the manual: a matrix switch is
 * column x 10 + row, a coil is its solenoid number, a lamp is its lamp matrix
 * number. The switches outside the matrix are the exception. The ROM reads them
 * at numbers PinMAME defines per platform, and a switch given the wrong one is
 * wired to something the ROM never looks at - which looks identical to a broken
 * switch and is a miserable thing to debug.
 *
 * So they are written down once, here, per platform. A platform this class does
 * not know returns nothing, and the wizard refuses rather than falling back to
 * numbers that happen to be right for WPC.
 */
final class PlatformNumbers {

  /**
   * The D column: direct (dedicated grounded) switches, read outside the matrix.
   */
  private const DIRECT = [
    'WPC' => [
      1 => 'Left Coin Chute',
      2 => 'Center Coin Chute',
      3 => 'Right Coin Chute',
      4 => '4th Coin Chute',
      5 => 'Service Credit/Escape',
      6 => 'Volume Down',
      7 => 'Volume Up',
      8 => 'Begin Test',
    ],
  ];

  /**
   * Flipper button switches, by flipper position.
   *
   * WPC Fliptronic: swLRFlip 112, swLLFlip 114, swURFlip 116, swULFlip 118.
   * These are the switches the ROM reads to know a button is held.
   */
  private const FLIPPER_BUTTONS = [
    'WPC' => [
      'lowerRight' => 112,
      'lowerLeft' => 114,
      'upperRight' => 116,
      'upperLeft' => 118,
    ],
  ];

  /**
   * Flipper end-of-stroke switches, by flipper position.
   *
   * WPC Fliptronic: swLREOS 111, swLLEOS 113, swUREOS 115, swULEOS 117. The ROM
   * reads them, as the original Fliptronic board reported them: to cut the
   * power winding early, and to fire it again when a ball knocks a held finger
   * down. The board uses the same switch to cut the winding itself.
   */
  private const FLIPPER_EOS = [
    'WPC' => [
      'lowerRight' => 111,
      'lowerLeft' => 113,
      'upperRight' => 115,
      'upperLeft' => 117,
    ],
  ];

  /**
   * Flipper winding numbers from the manual to the numbers PinMAME drives.
   *
   * A WPC manual prints the Fliptronic block as solenoids 29-36. PinMAME keeps
   * the upper flippers there, 33-36, but moves the lower ones to 45-48: it uses
   * 29-31 itself, for state bits and the game-on solenoid. A lower flipper left
   * on its manual number never hears from the ROM, and fires whenever those
   * state bits change - the left flipper at every game start, through 31.
   */
  private const FLIPPER_COILS = [
    'WPC' => [
      29 => 45,
      30 => 46,
      31 => 47,
      32 => 48,
    ],
  ];

  /**
   * Whether anything is known about this platform's non-matrix switches.
   */
  public static function isKnown(string $platform): bool {
    return isset(self::DIRECT[$platform]) || isset(self::FLIPPER_BUTTONS[$platform]);
  }

  /**
   * Direct switch number to its conventional name, or an empty list.
   *
   * @return array<int, string>
   */
  public static function directSwitches(string $platform): array {
    return self::DIRECT[$platform] ?? [];
  }

  /**
   * Whether this number is one of the platform's direct switches.
   */
  public static function isDirectSwitchNumber(string $platform, int $number): bool {
    return isset(self::DIRECT[$platform][$number]);
  }

  /**
   * Flipper position to button switch number, or an empty list.
   *
   * @return array<string, int>
   */
  public static function flipperButtons(string $platform): array {
    return self::FLIPPER_BUTTONS[$platform] ?? [];
  }

  /**
   * The button switch number for one flipper position, or NULL if unknown.
   *
   * NULL rather than a guess: a flipper button on the wrong number leaves the
   * ROM believing the button is never pressed.
   */
  public static function flipperButtonNumber(string $platform, string $position): ?int {
    return self::FLIPPER_BUTTONS[$platform][$position] ?? NULL;
  }

  /**
   * The EOS switch number for one flipper position, or NULL if unknown.
   */
  public static function flipperEosNumber(string $platform, string $position): ?int {
    return self::FLIPPER_EOS[$platform][$position] ?? NULL;
  }

  /**
   * The number PinMAME drives a flipper winding on, given the manual's.
   *
   * A number the platform does not move is returned as it is.
   */
  public static function flipperCoilNumber(string $platform, int $number): int {
    return self::FLIPPER_COILS[$platform][$number] ?? $number;
  }

  /**
   * The flipper positions this platform defines, in a stable order.
   *
   * @return array<int, string>
   */
  public static function flipperPositions(string $platform): array {
    return array_keys(self::FLIPPER_BUTTONS[$platform] ?? []);
  }

}

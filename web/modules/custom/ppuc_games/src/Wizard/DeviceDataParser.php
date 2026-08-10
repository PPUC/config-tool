<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * Reads the wizard's JSON input into a checked device list.
 *
 * This is the last point at which a bad transcription can be refused for free.
 * After it, roughly 150 nodes get created from whatever it returned, and a
 * mistake becomes 150 things to fix by hand. So it is deliberately strict:
 *
 *  - unknown keys are rejected, not ignored, because a misspelled key is
 *    silently dropped otherwise and the field it meant to set never appears;
 *  - duplicate numbers are rejected, because two switches with one number is a
 *    config that cannot work and the wizard would happily build it;
 *  - a direct switch or flipper whose number the platform does not define is
 *    rejected, because the ROM would never read it.
 *
 * Every message names the entry it is about. Someone correcting a 150-line JSON
 * needs to be told which line, not that something somewhere is wrong.
 */
final class DeviceDataParser {

  public const LOCATION_PLAYFIELD = 'playfield';
  public const LOCATION_CABINET = 'cabinet';
  public const LOCATION_BACKBOX = 'backbox';

  private const LOCATIONS = [
    self::LOCATION_PLAYFIELD,
    self::LOCATION_CABINET,
    self::LOCATION_BACKBOX,
  ];

  /**
   * PWM device types a coil entry may declare.
   *
   * Matches the pwm_device vocabulary. Flasher is absent on purpose: a flasher
   * is an LED in a stripe, not a PWM output.
   */
  private const COIL_TYPES = ['coil', 'lamp', 'motor', 'shaker'];

  private const GAME_KEYS = ['title', 'platform', 'rom'];
  private const SWITCH_KEYS = ['number', 'description', 'opto', 'direct', 'location', 'button', 'position'];
  private const COIL_KEYS = ['number', 'description', 'class', 'type', 'location', 'fastFlipSwitch', 'holdWinding', 'position'];
  private const FLIPPER_KEYS = ['name', 'position', 'powerCoil', 'holdCoil'];
  private const LED_KEYS = ['number', 'description', 'location', 'position'];

  /**
   * Problems found, each naming the entry it came from.
   *
   * @var string[]
   */
  private array $errors = [];

  /**
   * Parses a JSON document into a normalised device list.
   *
   * @return array|null
   *   The device list, or NULL when anything was wrong. Callers must check
   *   errors() rather than assuming a partial result is usable.
   */
  public function parse(string $json): ?array {
    $this->errors = [];

    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      $this->errors[] = sprintf('The input is not valid JSON: %s.', json_last_error_msg());
      return NULL;
    }

    $unknown = array_diff(array_keys($data), ['game', 'switches', 'coils', 'flippers', 'flashers', 'lamps', 'gi']);
    foreach ($unknown as $key) {
      $this->errors[] = sprintf('Unknown top-level section "%s".', $key);
    }

    $game = $this->parseGame($data['game'] ?? NULL);
    if ($game === NULL) {
      return NULL;
    }
    $platform = $game['platform'];

    $result = [
      'game' => $game,
      'switches' => $this->parseSwitches($data['switches'] ?? [], $platform),
      'coils' => $this->parseCoils($data['coils'] ?? []),
      'flippers' => $this->parseFlippers($data['flippers'] ?? [], $platform),
      'flashers' => $this->parseLeds($data['flashers'] ?? [], 'flashers'),
      'lamps' => $this->parseLeds($data['lamps'] ?? [], 'lamps'),
      'gi' => $this->parseLeds($data['gi'] ?? [], 'gi'),
    ];

    $this->checkFlipperCoilsExist($result);
    $this->checkFastFlipSwitchesExist($result);

    return $this->errors ? NULL : $result;
  }

  /**
   * @return string[]
   */
  public function errors(): array {
    return $this->errors;
  }

  private function parseGame($game): ?array {
    if (!is_array($game)) {
      $this->errors[] = 'The "game" section is missing.';
      return NULL;
    }
    $this->rejectUnknownKeys($game, self::GAME_KEYS, 'game');

    $title = trim((string) ($game['title'] ?? ''));
    if ($title === '') {
      $this->errors[] = 'game.title is required.';
    }
    $platform = trim((string) ($game['platform'] ?? ''));
    if ($platform === '') {
      $this->errors[] = 'game.platform is required.';
    }
    elseif (!PlatformNumbers::isKnown($platform)) {
      // Not a hard stop by itself - a game with no direct switches and no
      // flippers needs nothing from the table - but the entries that do need it
      // will fail below, and this says why.
      $this->errors[] = sprintf(
        'Platform "%s" has no direct-switch or flipper-button numbers defined. '
        . 'Add them to PlatformNumbers before importing a game that uses them.',
        $platform
      );
    }

    return $this->errors ? NULL : [
      'title' => $title,
      'platform' => $platform,
      'rom' => trim((string) ($game['rom'] ?? '')),
    ];
  }

  private function parseSwitches(array $switches, string $platform): array {
    $parsed = [];
    $seen = [];
    foreach ($switches as $index => $item) {
      $path = sprintf('switches[%s]', $index);
      if (!is_array($item)) {
        $this->errors[] = sprintf('%s is not an object.', $path);
        continue;
      }
      $this->rejectUnknownKeys($item, self::SWITCH_KEYS, $path);

      $number = $this->requireNumber($item, $path);
      $description = $this->requireDescription($item, $path);
      if ($number === NULL || $description === NULL) {
        continue;
      }
      if (isset($seen[$number])) {
        $this->errors[] = sprintf(
          '%s ("%s") reuses switch number %d, already used by "%s".',
          $path, $description, $number, $seen[$number]
        );
        continue;
      }
      $seen[$number] = $description;

      $direct = (bool) ($item['direct'] ?? FALSE);
      if ($direct && !PlatformNumbers::isDirectSwitchNumber($platform, $number)) {
        $this->errors[] = sprintf(
          '%s ("%s") is marked direct but %d is not a %s direct switch number. '
          . 'Expected one of: %s.',
          $path, $description, $number, $platform,
          implode(', ', array_keys(PlatformNumbers::directSwitches($platform)))
        );
        continue;
      }

      $location = $this->parseLocation($item, $path, $direct ? self::LOCATION_CABINET : self::LOCATION_PLAYFIELD);
      if ($location === NULL) {
        continue;
      }

      $position = $this->parsePosition($item, $path, $description);
      if ($position === FALSE) {
        continue;
      }

      $parsed[] = [
        'number' => $number,
        'description' => $description,
        'opto' => (bool) ($item['opto'] ?? FALSE),
        'direct' => $direct,
        'button' => (bool) ($item['button'] ?? FALSE),
        'location' => $location,
        'position' => $position,
      ];
    }
    return $parsed;
  }

  private function parseCoils(array $coils): array {
    $parsed = [];
    $seen = [];
    foreach ($coils as $index => $item) {
      $path = sprintf('coils[%s]', $index);
      if (!is_array($item)) {
        $this->errors[] = sprintf('%s is not an object.', $path);
        continue;
      }
      $this->rejectUnknownKeys($item, self::COIL_KEYS, $path);

      $number = $this->requireNumber($item, $path);
      $description = $this->requireDescription($item, $path);
      if ($number === NULL || $description === NULL) {
        continue;
      }
      if (isset($seen[$number])) {
        $this->errors[] = sprintf(
          '%s ("%s") reuses coil number %d, already used by "%s".',
          $path, $description, $number, $seen[$number]
        );
        continue;
      }
      $seen[$number] = $description;

      $class = (string) ($item['class'] ?? '');
      if (!DeviceDefaults::isKnownClass($class)) {
        $this->errors[] = sprintf(
          '%s ("%s") has solenoid type "%s". Expected one of: %s. '
          . 'This decides the drive power, so it is not guessed.',
          $path, $description, $class, implode(', ', DeviceDefaults::COIL_CLASSES)
        );
        continue;
      }

      $type = (string) ($item['type'] ?? 'coil');
      if (!in_array($type, self::COIL_TYPES, TRUE)) {
        $this->errors[] = sprintf(
          '%s ("%s") has device type "%s". Expected one of: %s. '
          . 'A flasher is an LED in a stripe, not a PWM output.',
          $path, $description, $type, implode(', ', self::COIL_TYPES)
        );
        continue;
      }

      $location = $this->parseLocation($item, $path, self::LOCATION_PLAYFIELD);
      if ($location === NULL) {
        continue;
      }

      $fastFlip = NULL;
      if (array_key_exists('fastFlipSwitch', $item)) {
        $fastFlip = $this->toNumber($item['fastFlipSwitch']);
        if ($fastFlip === NULL) {
          $this->errors[] = sprintf('%s ("%s") has a non-numeric fastFlipSwitch.', $path, $description);
          continue;
        }
      }

      $position = $this->parsePosition($item, $path, $description);
      if ($position === FALSE) {
        continue;
      }

      $parsed[] = [
        'number' => $number,
        'description' => $description,
        'class' => $class,
        'type' => $type,
        'location' => $location,
        'fastFlipSwitch' => $fastFlip,
        'position' => $position,
        // The hold half of a pair the CPU drives as two outputs. Flippers are
        // the common case and are declared in the flippers section, but they
        // are not the only one: a trap door on Dirty Harry is the same coil
        // assembly driven as "high" and "hold".
        'holdWinding' => (bool) ($item['holdWinding'] ?? FALSE),
      ];
    }
    return $parsed;
  }

  private function parseFlippers(array $flippers, string $platform): array {
    $parsed = [];
    $seenPosition = [];
    foreach ($flippers as $index => $item) {
      $path = sprintf('flippers[%s]', $index);
      if (!is_array($item)) {
        $this->errors[] = sprintf('%s is not an object.', $path);
        continue;
      }
      $this->rejectUnknownKeys($item, self::FLIPPER_KEYS, $path);

      $name = trim((string) ($item['name'] ?? ''));
      if ($name === '') {
        $this->errors[] = sprintf('%s has no name.', $path);
        continue;
      }

      $position = (string) ($item['position'] ?? '');
      $button = PlatformNumbers::flipperButtonNumber($platform, $position);
      if ($button === NULL) {
        $this->errors[] = sprintf(
          '%s ("%s") has position "%s", which %s does not define. Expected one of: %s.',
          $path, $name, $position, $platform,
          implode(', ', PlatformNumbers::flipperPositions($platform)) ?: '(none)'
        );
        continue;
      }
      if (isset($seenPosition[$position])) {
        $this->errors[] = sprintf(
          '%s ("%s") reuses position "%s", already used by "%s".',
          $path, $name, $position, $seenPosition[$position]
        );
        continue;
      }
      $seenPosition[$position] = $name;

      $power = $this->toNumber($item['powerCoil'] ?? NULL);
      $hold = $this->toNumber($item['holdCoil'] ?? NULL);
      if ($power === NULL || $hold === NULL) {
        $this->errors[] = sprintf('%s ("%s") needs both powerCoil and holdCoil numbers.', $path, $name);
        continue;
      }
      if ($power === $hold) {
        $this->errors[] = sprintf(
          '%s ("%s") uses %d for both windings. They are two separate outputs.',
          $path, $name, $power
        );
        continue;
      }

      $parsed[] = [
        'name' => $name,
        'position' => $position,
        'buttonSwitch' => $button,
        'powerCoil' => $power,
        'holdCoil' => $hold,
      ];
    }
    return $parsed;
  }

  private function parseLeds(array $leds, string $section): array {
    $parsed = [];
    $seen = [];
    foreach ($leds as $index => $item) {
      $path = sprintf('%s[%s]', $section, $index);
      if (!is_array($item)) {
        $this->errors[] = sprintf('%s is not an object.', $path);
        continue;
      }
      $this->rejectUnknownKeys($item, self::LED_KEYS, $path);

      $number = $this->requireNumber($item, $path);
      $description = $this->requireDescription($item, $path);
      if ($number === NULL || $description === NULL) {
        continue;
      }
      if (isset($seen[$number])) {
        $this->errors[] = sprintf(
          '%s ("%s") reuses number %d, already used by "%s".',
          $path, $description, $number, $seen[$number]
        );
        continue;
      }
      $seen[$number] = $description;

      $location = $this->parseLocation($item, $path, self::LOCATION_PLAYFIELD);
      if ($location === NULL) {
        continue;
      }

      $position = $this->parsePosition($item, $path, $description);
      if ($position === FALSE) {
        continue;
      }

      $parsed[] = [
        'number' => $number,
        'description' => $description,
        'location' => $location,
        'position' => $position,
      ];
    }
    return $parsed;
  }

  /**
   * A flipper naming coils that are not in the coil list builds nothing.
   */
  private function checkFlipperCoilsExist(array $result): void {
    $numbers = array_column($result['coils'], 'number');
    foreach ($result['flippers'] as $flipper) {
      foreach (['powerCoil', 'holdCoil'] as $key) {
        if (!in_array($flipper[$key], $numbers, TRUE)) {
          $this->errors[] = sprintf(
            'Flipper "%s" names %s %d, which is not in the coils section.',
            $flipper['name'], $key, $flipper[$key]
          );
        }
      }
    }
  }

  /**
   * A fast-flip switch that does not exist cannot be co-located with its coil.
   *
   * Worth catching here rather than in the allocator: the allocator's whole
   * job is putting the two on one board, and it cannot report this as clearly.
   */
  private function checkFastFlipSwitchesExist(array $result): void {
    $numbers = array_column($result['switches'], 'number');
    foreach ($result['flippers'] as $flipper) {
      $numbers[] = $flipper['buttonSwitch'];
    }
    foreach ($result['coils'] as $coil) {
      if ($coil['fastFlipSwitch'] !== NULL && !in_array($coil['fastFlipSwitch'], $numbers, TRUE)) {
        $this->errors[] = sprintf(
          'Coil %d ("%s") uses switch %d for fast flip, which is not in the switches section.',
          $coil['number'], $coil['description'], $coil['fastFlipSwitch']
        );
      }
    }
  }

  /**
   * Reads an optional position.
   *
   * Returns NULL when the entry has none - which is the common case, since
   * plenty of manuals have no location diagram - and FALSE when it has one that
   * makes no sense, which is an error the caller reports and skips.
   */
  private function parsePosition(array $item, string $path, string $description): Position|NULL|FALSE {
    if (!array_key_exists('position', $item)) {
      return NULL;
    }
    try {
      return Position::fromArray($item['position']);
    }
    catch (\InvalidArgumentException $e) {
      $this->errors[] = sprintf('%s ("%s") has a bad position: %s.', $path, $description, $e->getMessage());
      return FALSE;
    }
  }

  private function parseLocation(array $item, string $path, string $default): ?string {
    $location = (string) ($item['location'] ?? $default);
    if (!in_array($location, self::LOCATIONS, TRUE)) {
      $this->errors[] = sprintf(
        '%s has location "%s". Expected one of: %s.',
        $path, $location, implode(', ', self::LOCATIONS)
      );
      return NULL;
    }
    return $location;
  }

  private function rejectUnknownKeys(array $item, array $allowed, string $path): void {
    foreach (array_diff(array_keys($item), $allowed) as $key) {
      $this->errors[] = sprintf(
        '%s has unknown key "%s". Allowed: %s.',
        $path, $key, implode(', ', $allowed)
      );
    }
  }

  private function requireNumber(array $item, string $path): ?int {
    $number = $this->toNumber($item['number'] ?? NULL);
    if ($number === NULL) {
      $this->errors[] = sprintf('%s has no usable number.', $path);
    }
    return $number;
  }

  private function requireDescription(array $item, string $path): ?string {
    $description = trim((string) ($item['description'] ?? ''));
    if ($description === '') {
      $this->errors[] = sprintf('%s has no description.', $path);
      return NULL;
    }
    return $description;
  }

  /**
   * Numbers arrive from JSON as ints or as strings, never as floats we want.
   */
  private function toNumber($value): ?int {
    if (is_int($value)) {
      return $value >= 0 ? $value : NULL;
    }
    if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
      return (int) trim($value);
    }
    return NULL;
  }

}

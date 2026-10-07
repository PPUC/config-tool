<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * What one board type can carry, read from its GPIO mapping.
 *
 * The mapping on the i_o_board taxonomy term is the only place that knows which
 * connector pin is which GPIO, and it is what the exporter already uses to turn
 * a pin into a port. Hard-coding capacities here instead would mean a board
 * whose mapping changes silently keeps its old capacity, and devices allocated
 * to pins that no longer exist.
 *
 * The split between input, output and LED pins is a property of the board's
 * hardware rather than of the mapping, so it is stated per type - but the pins
 * themselves always come from the mapping.
 */
final class BoardCapacity {

  public const IO_16_8_1 = 'IO_16_8_1';
  public const OPTO_16 = 'Opto_16';
  public const IO_16X8_MATRIX = 'IO_16x8_matrix';
  public const OUT_8X10 = 'Out_8x10';

  /**
   * The PWM device type term that is a lamp, by uuid.
   *
   * The same uuid GamesController exports as type "lamp".
   */
  public const PWM_TYPE_LAMP_UUID = '08c2b5ce-2209-4e13-89be-e95f2d4acdb4';

  /**
   * First and last connector pin of each range, per board type.
   *
   * IO_16_8_1: pins 1-16 are switch inputs, 17-24 the high-power outputs, and
   * 25 the dedicated LED connector (GPIO 29), which is where every existing
   * game puts its LED stripes - one per board.
   *
   * Opto_16: 16 opto-isolated inputs and the same LED connector. No outputs
   * means no PWM devices, but a WS2812 string needs a connector rather than a
   * driver, so an opto board can carry one.
   *
   * IO_16x8_matrix: 16 inputs and 8 signal outputs on pins 17-24. Either the
   * returns and strobes of a switch matrix, or direct inputs and low-power
   * outputs.
   *
   * Out_8x10: pins 1-10 are low-side and 11-18 high-side lamp drivers. They
   * are a range of their own rather than outputs: the board has no PWM, so
   * nothing but a lamp can go on them, and the wizard must never put a coil
   * there.
   *
   * These must agree with io-boards/src/PPUCBoardTypes.h, which is what the
   * firmware and libppuc judge an exported game by.
   */
  private const RANGES = [
    self::IO_16_8_1 => ['input' => [1, 16], 'output' => [17, 24], 'lamp' => NULL, 'led' => [25, 25]],
    self::OPTO_16 => ['input' => [1, 16], 'output' => NULL, 'lamp' => NULL, 'led' => [25, 25]],
    self::IO_16X8_MATRIX => ['input' => [1, 16], 'output' => [17, 24], 'lamp' => NULL, 'led' => [25, 25]],
    self::OUT_8X10 => ['input' => NULL, 'output' => NULL, 'lamp' => [1, 18], 'led' => [25, 25]],
  ];

  private string $type;

  /**
   * @var array<int, int>
   *   Connector pin to GPIO, as stored on the taxonomy term.
   */
  private array $gpioMapping;

  /**
   * @param array<int, int> $gpioMapping
   *   Connector pin to GPIO. Pass the unserialised field_gpio_mapping.
   */
  public function __construct(string $type, array $gpioMapping) {
    if (!isset(self::RANGES[$type])) {
      throw new \InvalidArgumentException(sprintf(
        'unknown board type "%s"; the wizard can allocate %s',
        $type,
        implode(' and ', array_keys(self::RANGES))
      ));
    }
    $this->type = $type;
    $this->gpioMapping = $gpioMapping;
  }

  public function type(): string {
    return $this->type;
  }

  /**
   * Whether this is a board type whose pin roles are known.
   *
   * A site can add board types of its own to the taxonomy. Those are not
   * judged, and their name is not exported as a type libppuc would reject.
   */
  public static function knows(string $type): bool {
    return isset(self::RANGES[$type]);
  }

  /**
   * Columns and allowed row counts of the matrix a board type can run.
   *
   * IO_16_8_1 scans a switch matrix of 4 columns on its own inputs, with 4 or
   * 8 rows. IO_16x8_matrix strobes 8 columns and reads up to 16 rows.
   * Out_8x10 drives a lamp matrix of 8 high-side columns by up to 10 low-side
   * rows. The same numbers as io-boards/src/PPUCBoardTypes.h.
   */
  private const MATRICES = [
    'switch' => [
      self::IO_16_8_1 => ['columns' => 4, 'rows' => [4, 8]],
      self::IO_16X8_MATRIX => ['columns' => 8, 'rows' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]],
    ],
    'lamp' => [
      self::OUT_8X10 => ['columns' => 8, 'rows' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
    ],
  ];

  /**
   * Why a board type cannot run this matrix with this many rows, or NULL.
   *
   * @param string $kind
   *   switch or lamp.
   */
  public static function matrixRefusal(string $type, string $kind, int $rows): ?string {
    $matrix = self::MATRICES[$kind][$type] ?? NULL;
    if ($matrix === NULL) {
      return sprintf(
        '%s cannot run a %s matrix. Put it on %s.',
        $type,
        $kind,
        implode(' or ', array_keys(self::MATRICES[$kind] ?? []))
      );
    }
    if (!in_array($rows, $matrix['rows'], TRUE)) {
      $allowed = count($matrix['rows']) > 2
        ? sprintf('%d to %d', min($matrix['rows']), max($matrix['rows']))
        : implode(' or ', $matrix['rows']);
      return sprintf('A %s matrix on %s has %s rows.', $kind, $type, $allowed);
    }
    return NULL;
  }

  /**
   * How many positions a matrix has, or 0 when the board cannot run it.
   *
   * Positions are zero-based and counted column * rows + row.
   */
  public static function matrixPositions(string $type, string $kind, int $rows): int {
    if (self::matrixRefusal($type, $kind, $rows) !== NULL) {
      return 0;
    }
    return self::MATRICES[$kind][$type]['columns'] * $rows;
  }

  /**
   * Why a device of this bundle cannot sit on this connector pin, or NULL.
   *
   * The exporter turns a pin into a GPIO whatever the pin is, so a switch on
   * an output or a coil on an input used to export cleanly and drive the wrong
   * thing. libppuc refuses such a game now; saying so here is friendlier than
   * a machine that will not start.
   *
   * @param string $bundle
   *   switch, pwm_device or addressable_leds.
   * @param bool $isLamp
   *   For a pwm_device, whether its type is lamp.
   */
  public function refusal(string $bundle, int $pin, bool $isLamp = FALSE): ?string {
    switch ($bundle) {
      case 'switch':
        return in_array($pin, $this->inputPins(), TRUE) ? NULL
          : sprintf('Port %d is not a switch input on %s.', $pin, $this->type);

      case 'addressable_leds':
        return $pin === $this->ledPin() ? NULL
          : sprintf('LED strings go on port %d on %s.', (int) $this->ledPin(), $this->type);

      case 'pwm_device':
        $lampPins = $this->pinsIn('lamp');
        if ($lampPins) {
          if (!$isLamp) {
            return sprintf('%s drives lamps only.', $this->type);
          }
          return in_array($pin, $lampPins, TRUE) ? NULL
            : sprintf('Port %d is not an output on %s.', $pin, $this->type);
        }
        $pins = $this->outputPins();
        if ($this->type === self::IO_16_8_1) {
          // Its inputs double as low-power outputs.
          $pins = array_merge($this->inputPins(), $pins);
        }
        return in_array($pin, $pins, TRUE) ? NULL
          : sprintf('Port %d is not an output on %s.', $pin, $this->type);
    }
    return NULL;
  }

  /**
   * Connector pins usable for switches, in order.
   *
   * @return int[]
   */
  public function inputPins(): array {
    return $this->pinsIn('input');
  }

  /**
   * Connector pins usable for PWM devices, in order.
   *
   * @return int[]
   */
  public function outputPins(): array {
    return $this->pinsIn('output');
  }

  /**
   * The LED stripe pin, or NULL when the board has none.
   */
  public function ledPin(): ?int {
    $pins = $this->pinsIn('led');
    return $pins ? reset($pins) : NULL;
  }

  /**
   * The GPIO a connector pin maps to, or NULL if the mapping has no such pin.
   */
  public function gpio(int $pin): ?int {
    return $this->gpioMapping[$pin] ?? NULL;
  }

  /**
   * Pins in one range that the board's mapping actually defines.
   *
   * Intersecting with the mapping rather than trusting the range: a term whose
   * mapping is shorter than expected would otherwise hand out pins that cannot
   * be turned into a port, and the game YAML would carry a null.
   *
   * @return int[]
   */
  private function pinsIn(string $range): array {
    $bounds = self::RANGES[$this->type][$range] ?? NULL;
    if ($bounds === NULL) {
      return [];
    }
    $pins = [];
    for ($pin = $bounds[0]; $pin <= $bounds[1]; $pin++) {
      if (isset($this->gpioMapping[$pin])) {
        $pins[] = $pin;
      }
    }
    return $pins;
  }

}

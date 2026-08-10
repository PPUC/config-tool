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

  /**
   * First and last connector pin of each range, per board type.
   *
   * IO_16_8_1: pins 1-16 are switch inputs, 17-24 the high-power outputs, and
   * 25 the dedicated LED connector (GPIO 29), which is where every existing
   * game puts its LED stripes - one per board.
   *
   * Opto_16: 16 opto-isolated inputs and nothing else. No outputs means no PWM
   * devices and no LED stripe.
   */
  private const RANGES = [
    self::IO_16_8_1 => ['input' => [1, 16], 'output' => [17, 24], 'led' => [25, 25]],
    self::OPTO_16 => ['input' => [1, 16], 'output' => NULL, 'led' => NULL],
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

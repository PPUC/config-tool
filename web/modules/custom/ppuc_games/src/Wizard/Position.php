<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * Where a device sits, as a fraction of the playfield.
 *
 * Manuals that have location diagrams - a playfield outline with the item
 * numbers called out on it - can say where each switch, coil and lamp actually
 * is. Plenty of manuals have no such page, so every position is optional and
 * everything works without one; what a position buys is shorter wire runs and
 * a sensible starting order for an LED string.
 *
 * x runs left to right, y from the flipper end upwards, both 0 to 1. Normalised
 * rather than measured, because a diagram is the source and its scale is
 * arbitrary.
 */
final class Position {

  /**
   * How much longer a playfield is than it is wide.
   *
   * x and y are both fractions, so a step in y covers roughly twice the
   * distance a step in x does on a standard body. Without this, "nearest" would
   * think two devices on opposite sides of the playfield were closer than two a
   * few inches apart along it.
   */
  private const ASPECT = 2.0;

  public function __construct(
    public readonly float $x,
    public readonly float $y,
  ) {}

  /**
   * Reads a position from a parsed JSON value, or NULL if there is none.
   *
   * @throws \InvalidArgumentException
   *   If there is something there but it is not a position. Silently ignoring
   *   it would leave the device unpositioned with no indication why.
   */
  public static function fromArray(mixed $value): ?self {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_array($value) || !array_key_exists('x', $value) || !array_key_exists('y', $value)) {
      throw new \InvalidArgumentException('a position needs both x and y');
    }

    $coordinates = [];
    foreach (['x', 'y'] as $axis) {
      $raw = $value[$axis];
      if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
        throw new \InvalidArgumentException(sprintf('%s is not a number', $axis));
      }
      $coordinate = (float) $raw;
      if ($coordinate < 0.0 || $coordinate > 1.0) {
        throw new \InvalidArgumentException(sprintf(
          '%s is %s; positions are fractions of the playfield, from 0 to 1',
          $axis,
          rtrim(rtrim(number_format($coordinate, 3, '.', ''), '0'), '.')
        ));
      }
      $coordinates[$axis] = $coordinate;
    }

    return new self($coordinates['x'], $coordinates['y']);
  }

  /**
   * Distance to another position, with the playfield's shape accounted for.
   *
   * Squared, because everything here only compares distances and the square
   * root changes no ordering.
   */
  public function distanceTo(self $other): float {
    $dx = $this->x - $other->x;
    $dy = ($this->y - $other->y) * self::ASPECT;
    return ($dx * $dx) + ($dy * $dy);
  }

  /**
   * The middle of a set of positions, or NULL if there are none.
   *
   * @param self[] $positions
   */
  public static function centre(array $positions): ?self {
    if (!$positions) {
      return NULL;
    }
    $x = 0.0;
    $y = 0.0;
    foreach ($positions as $position) {
      $x += $position->x;
      $y += $position->y;
    }
    return new self($x / count($positions), $y / count($positions));
  }

  public function toArray(): array {
    return ['x' => $this->x, 'y' => $this->y];
  }

}

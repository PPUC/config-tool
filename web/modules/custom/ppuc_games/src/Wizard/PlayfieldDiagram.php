<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * Where the playfield is on a scanned location page.
 *
 * A device's position is a fraction of the playfield, which says nothing about
 * where to draw it on a scan: the page has margins, a title and a legend around
 * the outline, and it may be rotated or printed sideways. Four corners tie the
 * two together. With them a position can be turned into a point on the image,
 * which is what a switch test or a tutorial slide needs in order to point at a
 * part.
 *
 * The corners are named by where they are on the playfield, not on the page,
 * so a sideways scan needs no separate orientation: flipperLeft is the
 * playfield's (0, 0) wherever it ended up. Each is a fraction of the image,
 * from its top left - fractions for the same reason positions are, since
 * whatever reads the scan does not reliably know its size in pixels.
 */
final class PlayfieldDiagram {

  /**
   * The location pages, by the JSON section whose devices each one shows.
   *
   * Flashers are called out on the solenoid page, which is why there is no
   * fourth kind. The media type and the game's field already existed; this is
   * what fills them.
   */
  public const KINDS = [
    'switches' => [
      'page' => 'Switch Locations',
      'mediaType' => 'switch_locations',
      'gameField' => 'field_switch_locations',
    ],
    'lamps' => [
      'page' => 'Lamp Locations',
      'mediaType' => 'lamp_locations',
      'gameField' => 'field_lamp_locations',
    ],
    'coils' => [
      'page' => 'Solenoid/Flashlamp Locations',
      'mediaType' => 'solenoid_locations',
      'gameField' => 'field_solenoid_locations',
    ],
  ];

  /**
   * The corners, with the playfield position each one is.
   */
  public const CORNERS = [
    'flipperLeft' => [0.0, 0.0],
    'flipperRight' => [1.0, 0.0],
    'farRight' => [1.0, 1.0],
    'farLeft' => [0.0, 1.0],
  ];

  /**
   * The field on a location image that holds its corners, as JSON.
   */
  public const CORNERS_FIELD = 'field_playfield_corners';

  /**
   * Reads the four corners from a parsed JSON value.
   *
   * @return array<string, array{x: float, y: float}>
   *   Corner name to its place on the image.
   *
   * @throws \InvalidArgumentException
   *   If any corner is missing or is not a place on the image. Three corners
   *   are not a usable outline, so there is no partial result.
   */
  public static function cornersFromArray(mixed $value): array {
    if (!is_array($value)) {
      throw new \InvalidArgumentException('it needs the corners ' . implode(', ', array_keys(self::CORNERS)));
    }
    $unknown = array_diff(array_keys($value), array_keys(self::CORNERS));
    if ($unknown) {
      throw new \InvalidArgumentException(sprintf(
        'unknown corner "%s"; the corners are %s',
        reset($unknown),
        implode(', ', array_keys(self::CORNERS))
      ));
    }

    $corners = [];
    foreach (array_keys(self::CORNERS) as $name) {
      if (!array_key_exists($name, $value)) {
        throw new \InvalidArgumentException(sprintf('corner %s is missing', $name));
      }
      try {
        // A corner is a pair of fractions exactly as a position is, only of
        // the image rather than of the playfield.
        $point = Position::fromArray($value[$name]);
      }
      catch (\InvalidArgumentException $e) {
        throw new \InvalidArgumentException(sprintf('corner %s: %s', $name, str_replace(
          'positions are fractions of the playfield',
          'corners are fractions of the image',
          $e->getMessage()
        )));
      }
      if ($point === NULL) {
        throw new \InvalidArgumentException(sprintf('corner %s is missing', $name));
      }
      $corners[$name] = $point->toArray();
    }

    return $corners;
  }

  /**
   * Where a playfield position falls on the image.
   *
   * Interpolates between the four corners, so a scan that is skewed or on its
   * side still puts the marker on the part.
   *
   * @param array<string, array{x: float, y: float}> $corners
   *   As returned by cornersFromArray().
   *
   * @return array{x: float, y: float}
   *   Fractions of the image, from its top left.
   */
  public static function toImage(array $corners, Position $position): array {
    $point = [];
    foreach (['x', 'y'] as $axis) {
      $near = $corners['flipperLeft'][$axis]
        + ($corners['flipperRight'][$axis] - $corners['flipperLeft'][$axis]) * $position->x;
      $far = $corners['farLeft'][$axis]
        + ($corners['farRight'][$axis] - $corners['farLeft'][$axis]) * $position->x;
      $point[$axis] = $near + ($far - $near) * $position->y;
    }
    return $point;
  }

}

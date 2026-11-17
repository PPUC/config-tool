<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Keeps an LED node for every position inside the segments of an LED string.
 *
 * Segments are ranges of positions on a string that LED effects run on, for
 * example the backbox sky between two rows of lamps. The ROM does not know
 * those LEDs, so nobody created a node for them, and "All Lamps" showed a gap
 * where they are: no way to see what is at position 20, and nothing to give
 * a kind to.
 *
 * So every position covered by a segment gets an addressable LED with the
 * Segment role. These nodes are derived from the string: saving the string
 * creates the missing ones and deletes the ones a segment no longer covers.
 * What is set on them by hand, like the kind, stays as long as the position
 * stays in a segment. A lamp, flasher or GI LED at a position always wins; the
 * segment LED there is removed, and it comes back when that LED is deleted.
 *
 * The game YAML skips Segment LEDs: the string's segments already describe
 * them to the controller. The game archive keeps them, so an import brings
 * their kinds back.
 *
 * Positions are what WS2812FX gets as segment start and stop: zero based and
 * inclusive, the same numbering as the string position of an LED.
 */
final class SegmentLeds {

  /**
   * The uuid of the Segment term in the led_role vocabulary.
   */
  public const ROLE_UUID = '15575b23-a83a-4dd7-8f4e-12da96dab8be';

  /**
   * Set while this class saves or deletes LEDs, so it does not react to itself.
   */
  private static bool $syncing = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->react($node);
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->react($node);

    // An LED moved to another string frees its position on the old one.
    if ($node->bundle() === 'addressable_led' && !self::$syncing) {
      $original = method_exists($node, 'getOriginal') ? $node->getOriginal() : ($node->original ?? NULL);
      $old_string = $original?->get('field_string')->entity;
      if ($old_string instanceof NodeInterface && (int) $old_string->id() !== (int) $node->get('field_string')->target_id) {
        $this->sync($old_string);
      }
    }
  }

  /**
   * Implements hook_node_delete().
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    if (self::$syncing || $node->bundle() !== 'addressable_led' || $this->isSegmentLed($node)) {
      return;
    }
    $string = $node->get('field_string')->entity;
    if ($string instanceof NodeInterface) {
      $this->sync($string);
    }
  }

  /**
   * Whether an LED node is one of the derived segment LEDs.
   */
  public static function isSegmentLed(NodeInterface $led): bool {
    return $led->hasField('field_role') && $led->get('field_role')->entity?->uuid() === self::ROLE_UUID;
  }

  /**
   * The positions the segments of a string cover.
   *
   * @return array<int, int>
   *   Position to the number of the first segment covering it, counted from 1
   *   as in the game YAML.
   */
  public static function segmentPositions(NodeInterface $string): array {
    $positions = [];
    if (!$string->hasField('field_segments')) {
      return $positions;
    }
    foreach ($string->get('field_segments') as $delta => $segment) {
      if ($segment->from === NULL || $segment->from === '' || $segment->to === NULL || $segment->to === '') {
        continue;
      }
      $from = (int) $segment->from;
      $to = (int) $segment->to;
      if ($to < $from) {
        [$from, $to] = [$to, $from];
      }
      for ($position = max(0, $from); $position <= $to; $position++) {
        $positions[$position] ??= ((int) $delta) + 1;
      }
    }
    ksort($positions);
    return $positions;
  }

  private function react(NodeInterface $node): void {
    if (self::$syncing) {
      return;
    }
    if ($node->bundle() === 'addressable_leds') {
      $this->sync($node);
      return;
    }
    if ($node->bundle() !== 'addressable_led') {
      return;
    }
    $string = $node->get('field_string')->entity;
    if (!$string instanceof NodeInterface) {
      return;
    }
    if ($this->isSegmentLed($node)) {
      // A segment LED saved from outside, by a game import for example. The
      // string may have been imported first and got its segment LEDs already;
      // the imported one carries what was set on it, so it is the one to keep.
      $this->removeOtherSegmentLeds($string, $node);
      return;
    }
    $this->sync($string);
  }

  /**
   * Creates and deletes the segment LEDs of a string.
   */
  public function sync(NodeInterface $string): void {
    $role = $this->entityRepository->loadEntityByUuid('taxonomy_term', self::ROLE_UUID);
    if (!$role instanceof TermInterface) {
      // The default content with the role has not been imported yet.
      return;
    }

    $wanted = self::segmentPositions($string);
    $taken = [];
    $existing = [];
    foreach ($this->ledsOf($string) as $led) {
      $position = (int) $led->get('field_string_position')->value;
      if ($this->isSegmentLed($led)) {
        $existing[$position][] = $led;
      }
      else {
        $taken[$position] = TRUE;
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    self::$syncing = TRUE;
    try {
      foreach ($existing as $position => $leds) {
        $keep = isset($wanted[$position]) && !isset($taken[$position]) ? array_shift($leds) : NULL;
        if ($leds) {
          $storage->delete($leds);
        }
        if ($keep) {
          $title = $this->title($string, $wanted[$position], $position);
          if ($keep->label() !== $title) {
            $keep->setTitle($title)->save();
          }
          unset($wanted[$position]);
        }
      }

      foreach ($wanted as $position => $segment) {
        if (isset($taken[$position])) {
          continue;
        }
        $storage->create([
          'type' => 'addressable_led',
          'title' => $this->title($string, $segment, $position),
          'status' => 1,
          'field_string' => ['target_id' => $string->id()],
          'field_string_position' => ['value' => $position],
          'field_role' => ['target_id' => $role->id()],
          // Both are required on the form. Neither means anything for an LED
          // an effect drives, and the form hides them for this role.
          'field_number' => ['value' => 0],
          'field_color' => ['color' => '000000'],
        ])->save();
      }
    }
    finally {
      self::$syncing = FALSE;
    }
  }

  private function removeOtherSegmentLeds(NodeInterface $string, NodeInterface $led): void {
    $position = (int) $led->get('field_string_position')->value;
    $others = array_filter(
      $this->ledsOf($string),
      fn (NodeInterface $other): bool => (int) $other->id() !== (int) $led->id()
        && $this->isSegmentLed($other)
        && (int) $other->get('field_string_position')->value === $position
    );
    if (!$others) {
      return;
    }
    self::$syncing = TRUE;
    try {
      $this->entityTypeManager->getStorage('node')->delete($others);
    }
    finally {
      self::$syncing = FALSE;
    }
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  private function ledsOf(NodeInterface $string): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'addressable_led')
      ->condition('field_string', $string->id())
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  private function title(NodeInterface $string, int $segment, int $position): string {
    return sprintf('%s segment %d LED %d', trim($string->label()), $segment, $position);
  }

}

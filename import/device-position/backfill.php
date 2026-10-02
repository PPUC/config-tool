<?php

/**
 * @file
 * Gives the devices of already-built games the position the wizard discarded.
 *
 *   ddev drush php:script import/device-position/backfill.php
 *
 * A game the wizard built keeps the document it was built from, and the
 * positions are still in there. This reads them back out and writes them to
 * the devices, matched by number - and, for an LED, by role as well, because a
 * lamp and a flasher can share a number.
 *
 * Only fills what is empty, so it is safe to run again and never overwrites a
 * position somebody corrected by hand.
 */

use Drupal\ppuc_games\Wizard\DeviceDataParser;
use Drupal\ppuc_games\Wizard\Position;

$storage = \Drupal::entityTypeManager()->getStorage('node');

$positionsOf = static function (array $items): array {
  $positions = [];
  foreach ($items as $item) {
    if (($item['position'] ?? NULL) instanceof Position) {
      $positions[$item['number']] = $item['position'];
    }
  }
  return $positions;
};

$fill = static function ($node, ?Position $position): int {
  if ($position === NULL || !$node->get('field_playfield_x')->isEmpty() || !$node->get('field_playfield_y')->isEmpty()) {
    return 0;
  }
  $node->set('field_playfield_x', round($position->x, 4));
  $node->set('field_playfield_y', round($position->y, 4));
  $node->save();
  return 1;
};

$load = static function (string $type, string $field, array $targets) use ($storage): array {
  if (!$targets) {
    return [];
  }
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $type)
    ->condition($field . '.target_id', $targets, 'IN')
    ->execute();
  return $storage->loadMultiple($ids);
};

foreach ($storage->loadByProperties(['type' => 'game']) as $game) {
  $source = (string) $game->get('field_wizard_source')->value;
  if ($source === '') {
    continue;
  }
  $parser = new DeviceDataParser();
  $devices = $parser->parse($source);
  if ($devices === NULL) {
    printf("%s: stored source no longer parses, skipped\n", $game->label());
    continue;
  }

  $boards = array_keys($load('i_o_board', 'field_game', [$game->id()]));
  $filled = ['switch' => 0, 'pwm_device' => 0, 'addressable_led' => 0];

  $switches = $positionsOf($devices['switches']);
  foreach ($load('switch', 'field_i_o_board', $boards) as $node) {
    $filled['switch'] += $fill($node, $switches[(int) $node->get('field_number')->value] ?? NULL);
  }

  $coils = $positionsOf($devices['coils']);
  foreach ($load('pwm_device', 'field_i_o_board', $boards) as $node) {
    $filled['pwm_device'] += $fill($node, $coils[(int) $node->get('field_number')->value] ?? NULL);
  }

  $byRole = [
    'Lamp' => $positionsOf($devices['lamps']),
    'Flasher' => $positionsOf($devices['flashers']),
    'GI' => $positionsOf($devices['gi']),
  ];
  $strings = array_keys($load('addressable_leds', 'field_i_o_board', $boards));
  foreach ($load('addressable_led', 'field_string', $strings) as $node) {
    $role = $node->get('field_role')->entity?->label();
    $filled['addressable_led'] += $fill($node, $byRole[$role][(int) $node->get('field_number')->value] ?? NULL);
  }

  printf(
    "%s: %d switch(es), %d PWM device(s), %d LED(s) given a position\n",
    $game->label(), $filled['switch'], $filled['pwm_device'], $filled['addressable_led']
  );
}

print "done\n";

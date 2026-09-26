<?php
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'switch')->execute();
$rows = [];
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $s) {
  $board = $s->get('field_i_o_board')->entity ?? NULL;
  if (!$board) continue;
  $game = NULL;
  foreach ($board->getFieldDefinitions() as $n => $d) {
    if ($d->getType() === 'entity_reference' && $d->getSetting('target_type') === 'node' && !$board->get($n)->isEmpty()) {
      $e = $board->get($n)->entity;
      if ($e && $e->bundle() === 'game') { $game = $e; break; }
    }
  }
  if (!$game || stripos($game->getTitle(), 'flash') === FALSE) continue;
  $num = (!$s->get('field_number')->isEmpty()) ? (int) $s->get('field_number')->value : 0;
  $rows[$num] = $s->getTitle();
}
ksort($rows);
foreach ($rows as $n => $t) printf("%4d  %s\n", $n, $t);

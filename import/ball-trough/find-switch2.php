<?php
// switch -> field_i_o_board -> (board's game) ; list Flash's trough candidates
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'switch')->execute();
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
  $num = (!$s->get('field_number')->isEmpty()) ? $s->get('field_number')->value : '?';
  if ($num == 48 || preg_match('/outhole|trough|drain/i', $s->getTitle())) {
    printf("  switch %-4s %-24s board=%s\n", $num, $s->getTitle(), $board->getTitle());
  }
}

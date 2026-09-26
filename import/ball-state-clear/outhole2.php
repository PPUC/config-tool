<?php
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'switch')->execute();
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $s) {
  // resolve the owning game however this switch is attached
  $game = '';
  foreach ($s->getFieldDefinitions() as $name => $def) {
    if ($def->getType() !== 'entity_reference' || $s->get($name)->isEmpty()) continue;
    $e = $s->get($name)->entity;
    if (!$e) continue;
    if ($e->getEntityTypeId() === 'node' && $e->bundle() === 'game') { $game = $e->getTitle(); break; }
    // one hop: switch -> board -> game
    foreach ($e->getFieldDefinitions() as $n2 => $d2) {
      if ($d2->getType() !== 'entity_reference' || $e->get($n2)->isEmpty()) continue;
      $g = $e->get($n2)->entity;
      if ($g && $g->getEntityTypeId() === 'node' && $g->bundle() === 'game') { $game = $g->getTitle(); break 2; }
    }
  }
  if (stripos($game, 'flash') === FALSE) continue;
  $num = ($s->hasField('field_number') && !$s->get('field_number')->isEmpty()) ? $s->get('field_number')->value : '?';
  if (!preg_match('/trough|outhole|drain/i', $s->getTitle()) && $num != 48) continue;
  printf("  %-4s %-22s game=%s\n", $num, $s->getTitle(), $game);
}

<?php
$n = \Drupal\node\Entity\Node::load(395);
printf("node 395 vid=%s default_revision=%s changed=%s\n",
  $n->getRevisionId(), $n->isDefaultRevision() ? 'yes' : 'no', date('c', $n->getChangedTime()));
foreach (['field_rules_editor_mode', 'field_enabled', 'field_weight'] as $f) {
  printf("  %-26s %s\n", $f, $n->hasField($f) ? var_export($n->get($f)->value, TRUE) : 'MISSING');
}
$d = json_decode($n->get('field_rules_blocks')->value, TRUE);
foreach ($d['blocks']['blocks'] as $i => $b) {
  printf("  block[%d] type=%-24s x=%s y=%s\n", $i, $b['type'], $b['x'] ?? '-', $b['y'] ?? '-');
}
printf("  lua has onBallChanged: %s\n",
  str_contains($n->get('field_rules_lua')->value, 'onBallChanged') ? 'yes' : 'no');

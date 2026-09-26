<?php
// Switches hang off boards, not off the game, so walk that way.
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'switch')->execute();
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $s) {
  $t = $s->getTitle();
  if (!preg_match('/trough|outhole|drain|ball.?through/i', $t)) continue;
  $num = ($s->hasField('field_number') && !$s->get('field_number')->isEmpty()) ? $s->get('field_number')->value : '?';
  $btn = ($s->hasField('field_button') && !$s->get('field_button')->isEmpty() && $s->get('field_button')->value) ? ' button' : '';
  printf("  %-4s %s%s\n", $num, $t, $btn);
}

<?php
// Arm the safety net for Flash: switch 48 is the Outhole on Flash IO 4.
$n = \Drupal\node\Entity\Node::load(567);   // Flash settings
if ($n->get('field_ini_ball_trough_switch')->value == 48) { print "already armed\n"; return; }
$n->set('field_ini_ball_trough_switch', 48)->save();
printf("armed: BallTroughSwitch=%s BallTroughGraceMs=%s\n",
  $n->get('field_ini_ball_trough_switch')->value,
  $n->get('field_ini_ball_trough_grace')->value ?? '(default)');

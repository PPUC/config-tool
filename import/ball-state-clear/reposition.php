<?php
// Put the new handler somewhere it can actually be seen.
//
// It was dropped at y=1200, which is inside the coil handler's own footprint:
// that block carries four players by three balls of nested conditions and is
// thousands of pixels tall, so the new one sat behind it. Blockly does not
// scroll to fit on load, so it looked as though nothing had been added.
$n = \Drupal\node\Entity\Node::load(395);
$d = json_decode($n->get('field_rules_blocks')->value, TRUE);
foreach ($d['blocks']['blocks'] as &$b) {
  if ($b['type'] === 'ppuc_on_ball_changed') {
    $b['x'] = 1600;   // to the right of the coil handler, clear of it
    $b['y'] = 72;     // level with its top
    printf("moved %s to x=%d y=%d\n", $b['type'], $b['x'], $b['y']);
  }
}
unset($b);
$n->set('field_rules_blocks', json_encode($d));
$n->save();
print "saved\n";

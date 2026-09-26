<?php
// Generates the Lua for the when-ball-changed handler added to the Blockly.
//
// The Lua field is normally produced by Blockly in the browser, so it only
// changes when somebody opens the node and saves it. Writing it here means the
// game folder can be exported straight away. The text matches what the generator
// emits -- `function ppuc.onBallChanged(ball)`, a two-space indented body from
// statementToCode, and single-quoted strings from the text block -- so opening
// and saving the form later reproduces the same thing rather than fighting it.
$n = \Drupal\node\Entity\Node::load(395);
$lua = $n->get('field_rules_lua')->value;

if (str_contains($lua, 'onBallChanged')) {
  print "onBallChanged already present; nothing to do\n";
  return;
}

$body = '';
for ($p = 1; $p <= 4; $p++) {
  for ($b = 1; $b <= 3; $b++) {
    $body .= sprintf("  ppuc.clearState('player %d ball %d served')\n", $p, $b);
  }
}

$lua = rtrim($lua, "\n") . "\n\nfunction ppuc.onBallChanged(ball)\n" . $body . "end\n";
$n->set('field_rules_lua', $lua);
$n->save();
print "appended onBallChanged with 12 clearState calls\n";

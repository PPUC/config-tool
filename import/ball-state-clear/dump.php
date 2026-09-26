<?php
$n = \Drupal\node\Entity\Node::load(395);
$d = json_decode($n->get('field_rules_blocks')->value, TRUE);
$mine = $d['blocks']['blocks'][1];
// just the handler and its first two statements
$c = $mine['inputs']['DO']['block'];
$trim = $c; if (isset($trim['next']['block']['next'])) unset($trim['next']['block']['next']);
$mine['inputs']['DO']['block'] = $trim;
print "MINE:\n" . json_encode($mine, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n\n";
// the equivalent shape from the existing handler: its DO block and that block's own first statement
$coilDo = $d['blocks']['blocks'][0]['inputs']['DO']['block'];
print "EXISTING handler's first DO block: type=" . $coilDo['type'] . ", keys=" . implode(',', array_keys($coilDo)) . "\n";

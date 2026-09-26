<?php
$n = \Drupal\node\Entity\Node::load(395);
$d = json_decode($n->get('field_rules_blocks')->value, TRUE);
print "top-level container keys: " . implode(', ', array_keys($d['blocks'])) . "\n\n";
$coil = $d['blocks']['blocks'][0];
print "existing handler keys: " . implode(', ', array_keys($coil)) . "\n";
foreach (['inputs', 'statements', 'next'] as $k) {
  if (isset($coil[$k])) print "  has '$k' -> " . implode(', ', array_keys($coil[$k])) . "\n";
}
print "\nmine keys: " . implode(', ', array_keys($d['blocks']['blocks'][1])) . "\n";
foreach (['inputs', 'statements'] as $k) {
  if (isset($d['blocks']['blocks'][1][$k])) print "  has '$k' -> " . implode(', ', array_keys($d['blocks']['blocks'][1][$k])) . "\n";
}

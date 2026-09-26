<?php
// Adds a "when ball changed" handler that clears the per-ball "served" states.
//
// Those states guard the ball callouts and carry a 60s TTL that nothing ever
// clears, so a game started within a minute of the last one found them still
// active and skipped the whole branch -- scene and speech together. Clearing on
// a ball change ties their lifetime to the ball they describe. A ball save
// re-serves without changing the ball number, so the guard still suppresses the
// duplicate it was added for.
$n = \Drupal\node\Entity\Node::load(395);
$d = json_decode($n->get('field_rules_blocks')->value, TRUE);

foreach ($d['blocks']['blocks'] as $b) {
  if (($b['type'] ?? '') === 'ppuc_on_ball_changed') { print "already present; nothing to do\n"; return; }
}

$names = [];
for ($p = 1; $p <= 4; $p++) for ($b = 1; $b <= 3; $b++) $names[] = "player $p ball $b served";

$chain = NULL;
foreach (array_reverse($names) as $i => $name) {
  $blk = [
    'type' => 'ppuc_clear_state',
    'id'   => 'clr' . (count($names) - $i),
    'inputs' => ['NAME' => ['block' => [
      'type' => 'text', 'id' => 'clrt' . (count($names) - $i),
      'fields' => ['TEXT' => $name],
    ]]],
  ];
  if ($chain !== NULL) $blk['next'] = ['block' => $chain];
  $chain = $blk;
}

$d['blocks']['blocks'][] = [
  'type' => 'ppuc_on_ball_changed',
  'id'   => 'onballclear',
  'x'    => 40,
  'y'    => 1200,
  'inputs' => ['DO' => ['block' => $chain]],
];

$n->set('field_rules_blocks', json_encode($d));
$n->save();
printf("added when-ball-changed with %d clear-state blocks\n", count($names));

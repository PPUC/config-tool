<?php
$c = \Drupal::classResolver(\Drupal\ppuc_games\Controller\GamesController::class);
$game = NULL;
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'game')->execute();
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $g) {
  if (stripos($g->getTitle(), 'flash') !== FALSE) { $game = $g; break; }
}
$m = new \ReflectionMethod($c, 'getRuleNodes'); $m->setAccessible(TRUE);
$fn = new \ReflectionMethod($c, 'buildRuleFilename'); $fn->setAccessible(TRUE);
$lua = new \ReflectionMethod($c, 'getRulesLua'); $lua->setAccessible(TRUE);
printf("game: %s (node %d)\n", $game->getTitle(), $game->id());
foreach ($m->invoke($c, $game, TRUE) as $r) {
  $body = $lua->invoke($c, $r);
  printf("  %-32s node %-4d %s\n", $fn->invoke($c, $r, 'lua'), $r->id(),
    str_contains($body, 'onBallChanged') ? '<- has onBallChanged' : '');
}

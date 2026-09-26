<?php
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'game')->execute();
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $g) {
  if (stripos($g->getTitle(), 'flash') === FALSE) continue;
  $c = \Drupal::classResolver(\Drupal\ppuc_games\Controller\GamesController::class);
  $m = new \ReflectionMethod($c, 'buildPpucIni'); $m->setAccessible(TRUE);
  foreach (explode("\n", $m->invoke($c, $g)) as $line) {
    if (preg_match('/^BallTrough|^BallSearch|^\[Runtime\]/', $line)) print "  $line\n";
  }
}

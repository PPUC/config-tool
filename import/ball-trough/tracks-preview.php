<?php
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'game')->execute();
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $g) {
  if (stripos($g->getTitle(), 'flash') === FALSE) continue;
  $dir = '/tmp/musicpreview';
  \Drupal::service('file_system')->prepareDirectory($dir, 1 | 2);
  $c = \Drupal::classResolver(\Drupal\ppuc_games\Controller\GamesController::class);
  $m = new \ReflectionMethod($c, 'writeMusicTrackInfo'); $m->setAccessible(TRUE);
  $m->invoke($c, $g, $dir);
  print file_exists("$dir/tracks.yaml") ? file_get_contents("$dir/tracks.yaml") : "(no tracks.yaml written)\n";
}

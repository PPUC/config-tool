<?php
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'game')->execute();
$game = NULL;
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $g) {
  if (stripos($g->getTitle(), 'flash') !== FALSE) { $game = $g; break; }
}
$c = \Drupal::classResolver(\Drupal\ppuc_games\Controller\GamesController::class);
$sv = new \ReflectionMethod($c, 'getIniSettingValue'); $sv->setAccessible(TRUE);
foreach (['field_ini_ball_search' => 'BallSearch',
          'field_ini_ball_search_delay' => 'BallSearchDelayMs',
          'field_ini_ball_search_round' => 'BallSearchRoundDelayMs'] as $f => $key) {
  printf("%-24s = %s\n", $key, $sv->invoke($c, $game, $f, '(unset -> default)'));
}
// which coils are flagged as ball-search devices
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'coil')->execute();
$flagged = [];
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $d) {
  if ($d->hasField('field_ball_search') && !$d->get('field_ball_search')->isEmpty() && $d->get('field_ball_search')->value) {
    $num = $d->hasField('field_number') && !$d->get('field_number')->isEmpty() ? $d->get('field_number')->value : '?';
    $flagged[] = "$num " . $d->getTitle();
  }
}
printf("coils flagged ballSearch (all games): %s\n", $flagged ? implode('; ', $flagged) : 'NONE');

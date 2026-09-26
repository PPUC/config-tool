<?php
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'game')->execute();
$game = NULL;
foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $g) {
  if (stripos($g->getTitle(), 'flash') !== FALSE) { $game = $g; break; }
}
$c = \Drupal::classResolver(\Drupal\ppuc_games\Controller\GamesController::class);
$sv = new \ReflectionMethod($c, 'getIniSettingValue'); $sv->setAccessible(TRUE);
printf("SlideNextSwitch     = %s\n", $sv->invoke($c, $game, 'field_ini_slide_next_switch', '(unset)'));
printf("SlidePreviousSwitch = %s\n", $sv->invoke($c, $game, 'field_ini_slide_prev_switch', '(unset)'));

$sids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'switch')
  ->condition('field_game.target_id', $game->id())->execute();
$buttons = [];
foreach (\Drupal\node\Entity\Node::loadMultiple($sids) as $s) {
  $num = $s->hasField('field_number') && !$s->get('field_number')->isEmpty() ? $s->get('field_number')->value : '?';
  $isBtn = $s->hasField('field_button') && !$s->get('field_button')->isEmpty() && $s->get('field_button')->value;
  if ($isBtn) $buttons[] = "$num (" . $s->getTitle() . ")";
}
printf("switches flagged button: %s\n", $buttons ? implode(', ', $buttons) : 'NONE');
printf("total switches: %d\n", count($sids));

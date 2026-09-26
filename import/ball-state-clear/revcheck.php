<?php
$storage = \Drupal::entityTypeManager()->getStorage('node');
$vids = $storage->revisionIds(\Drupal\node\Entity\Node::load(395));
printf("revisions: %s\n", implode(', ', array_slice($vids, -5)));
$latest = $storage->loadRevision(end($vids));
printf("latest vid=%s default=%s\n", $latest->getRevisionId(), $latest->isDefaultRevision() ? 'yes' : 'no');
$d = json_decode($latest->get('field_rules_blocks')->value, TRUE);
printf("latest revision block count: %d\n", count($d['blocks']['blocks']));
printf("latest revision lua has onBallChanged: %s\n",
  str_contains($latest->get('field_rules_lua')->value, 'onBallChanged') ? 'yes' : 'no');
$item = $latest->get('field_rules_blocks')->first()->getValue();
printf("field properties: %s\n", implode(', ', array_keys($item)));
printf("format: %s\n", var_export($item['format'] ?? null, TRUE));

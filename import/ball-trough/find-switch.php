<?php
// How is a switch tied to a game? Show the reference fields on the bundle.
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'switch');
foreach ($defs as $name => $d) {
  if ($d->getType() === 'entity_reference' && str_starts_with($name, 'field_')) {
    printf("  %-28s -> %s\n", $name, $d->getSetting('target_type'));
  }
}

<?php

/**
 * @file
 * The attribution field on a music media item.
 *
 *   ddev drush php:script import/music-attribution/create-field.php
 *
 * Royalty-free music is free on a condition: that it is credited. The machine
 * is where the music is heard, so the machine is where the credit has to
 * appear, and this is where it is written down. The game folder export turns
 * whatever is in here into slides at the end of the attract loop.
 *
 * Run once; the resulting config is exported to the sync directory.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

const FIELD = 'field_attribution';

if (!FieldStorageConfig::loadByName('media', FIELD)) {
  FieldStorageConfig::create([
    'field_name' => FIELD,
    'entity_type' => 'media',
    'type' => 'string_long',
    'cardinality' => 1,
  ])->save();
  print "storage created\n";
}

if (!FieldConfig::loadByName('media', 'music', FIELD)) {
  FieldConfig::create([
    'field_name' => FIELD,
    'entity_type' => 'media',
    'bundle' => 'music',
    'label' => 'Attribution',
    'description' => 'The credit this track has to carry, exactly as the source asks for it. It is shown on the backbox screen at the end of the attract loop. For example:<br><code>Music track: In Flight by Alegend<br>Source: https://freetouse.com/music<br>Royalty Free Background Music</code>',
    'required' => FALSE,
  ])->save();
  print "field created\n";
}

// Plain text, not a rich text format: this is a credit to be printed on a
// screen with no markup of any kind, and a format selector would only invite
// something the machine cannot draw.
$form = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('media.music.default');
if ($form && !$form->getComponent(FIELD)) {
  $form->setComponent(FIELD, [
    'type' => 'string_textarea',
    'weight' => 10,
    'region' => 'content',
    'settings' => ['rows' => 4, 'placeholder' => ''],
  ])->save();
  print "form display\n";
}

$view = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('media.music.default');
if ($view && !$view->getComponent(FIELD)) {
  $view->setComponent(FIELD, [
    'type' => 'basic_string',
    'weight' => 10,
    'region' => 'content',
    'label' => 'above',
  ])->save();
  print "view display\n";
}

print "done\n";

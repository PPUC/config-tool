<?php

/**
 * @file
 * The two [Audio] settings ppuc reads that the tool could not yet write.
 *
 *   ddev drush php:script import/song-select/create-fields.php
 *
 * MusicDuckPercent is how far the background music drops under the game's own
 * sound while a ball is in play. It is the one number that decides whether the
 * music can be heard at all during a game, and it differs per cabinet, because
 * it is really a statement about the speakers. ppuc has read it for a while; the
 * tool has not been able to set it, so every machine has been running the
 * built-in default whether it suited the cabinet or not.
 *
 * SongSelect offers the playlist to the player at the start of a game, steered
 * by the same two buttons as the attract slides. Off by default: a game with one
 * track has nothing to choose, and an operator who wants a menu in front of a
 * fresh ball should have to ask for it.
 *
 * Run once; the resulting config is exported to the sync directory.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

const DUCK = 'field_ini_music_duck_percent';
const SELECT = 'field_ini_song_select';

if (!FieldStorageConfig::loadByName('node', DUCK)) {
  FieldStorageConfig::create([
    'field_name' => DUCK,
    'entity_type' => 'node',
    'type' => 'integer',
    'settings' => ['unsigned' => FALSE, 'size' => 'normal'],
    'cardinality' => 1,
  ])->save();
  print "storage created: " . DUCK . "\n";
}

if (!FieldConfig::loadByName('node', 'ppuc_settings', DUCK)) {
  FieldConfig::create([
    'field_name' => DUCK,
    'entity_type' => 'node',
    'bundle' => 'ppuc_settings',
    'label' => 'Audio: MusicDuckPercent',
    'description' => 'How loud the background music is while a ball is in play, in percent of its normal level. Leave empty for the built-in default of 29. Raise it if the music disappears under the ROM sounds on this cabinet; lower it if it fights them.',
    'required' => FALSE,
    'settings' => ['min' => 0, 'max' => 100, 'prefix' => '', 'suffix' => '%'],
  ])->save();
  print "field created: " . DUCK . "\n";
}

if (!FieldStorageConfig::loadByName('node', SELECT)) {
  FieldStorageConfig::create([
    'field_name' => SELECT,
    'entity_type' => 'node',
    'type' => 'boolean',
    'cardinality' => 1,
  ])->save();
  print "storage created: " . SELECT . "\n";
}

if (!FieldConfig::loadByName('node', 'ppuc_settings', SELECT)) {
  FieldConfig::create([
    'field_name' => SELECT,
    'entity_type' => 'node',
    'bundle' => 'ppuc_settings',
    'label' => 'Audio: SongSelect',
    'description' => 'Offer the music playlist on the backbox screen at the start of a game. The player walks it with the two buttons set under Attract, hears each track as it is highlighted, and both buttons together gets on with the game. The first playfield switch dismisses it as well. Needs at least two tracks.',
    'required' => FALSE,
    'default_value' => [['value' => 0]],
  ])->save();
  print "field created: " . SELECT . "\n";
}

// On the form and on the page, with the rest of the INI settings. A field
// nobody can see is a field nobody can set.
$form = \Drupal::entityTypeManager()->getStorage('entity_form_display')
  ->load('node.ppuc_settings.default');
$view = \Drupal::entityTypeManager()->getStorage('entity_view_display')
  ->load('node.ppuc_settings.default');

$components = [
  DUCK => [
    'form' => ['type' => 'number', 'settings' => ['placeholder' => '29']],
    'view' => ['type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE]],
  ],
  SELECT => [
    'form' => ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE]],
    'view' => ['type' => 'boolean', 'settings' => ['format' => 'default']],
  ],
];

$weight = 110;
foreach ($components as $name => $spec) {
  if ($form && !$form->getComponent($name)) {
    $form->setComponent($name, $spec['form'] + ['weight' => $weight, 'region' => 'content'])->save();
    print "form display: $name\n";
  }
  if ($view && !$view->getComponent($name)) {
    $view->setComponent($name, $spec['view'] + ['weight' => $weight, 'region' => 'content', 'label' => 'inline'])->save();
    print "view display: $name\n";
  }
  $weight++;
}

print "done\n";

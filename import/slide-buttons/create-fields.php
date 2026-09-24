<?php

/**
 * @file
 * The two [Attract] switch numbers on the PPUC settings node.
 *
 *   ddev drush php:script import/slide-buttons/create-fields.php
 *
 * Without these, the slideshow's navigation buttons can only be set by editing
 * ppuc.ini on the machine by hand -- and the config tool rewrites that file on
 * every export, so the setting would survive exactly until the next download.
 *
 * Plain switch numbers rather than references to switch nodes. A reference
 * would be nicer to fill in, but the list would offer the switches of every
 * game in the tool, and picking one from the wrong game writes a number that is
 * right or wrong by luck. The number is what ppuc.ini carries, it is printed
 * next to every switch on the All Switches page, and it cannot be wrong in a
 * way that looks right.
 *
 * Run once; the resulting config is exported to the sync directory.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$fields = [
  'field_ini_slide_next_switch' => [
    'label' => 'Attract: SlideNextSwitch',
    'description' => 'Switch number of the button that steps forward through the attract slides, normally the right flipper button. 0 for none. Pressing both buttons together holds the current slide.',
  ],
  'field_ini_slide_prev_switch' => [
    'label' => 'Attract: SlidePreviousSwitch',
    'description' => 'Switch number of the button that steps back through the attract slides, normally the left flipper button. 0 for none.',
  ],
];

foreach ($fields as $name => $spec) {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => 'integer',
      'settings' => ['unsigned' => FALSE, 'size' => 'normal'],
      'cardinality' => 1,
    ])->save();
    print "storage created: $name\n";
  }

  if (!FieldConfig::loadByName('node', 'ppuc_settings', $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'ppuc_settings',
      'label' => $spec['label'],
      'description' => $spec['description'],
      'required' => FALSE,
      'default_value' => [['value' => 0]],
      'settings' => ['min' => 0, 'max' => NULL, 'prefix' => '', 'suffix' => ''],
    ])->save();
    print "field created: $name\n";
  }
}

// On the form and on the page, next to the rest of the INI settings. A field
// nobody can see is a field nobody can set.
$form = \Drupal::entityTypeManager()->getStorage('entity_form_display')
  ->load('node.ppuc_settings.default');
$view = \Drupal::entityTypeManager()->getStorage('entity_view_display')
  ->load('node.ppuc_settings.default');

$weight = 100;
foreach (array_keys($fields) as $name) {
  if ($form && !$form->getComponent($name)) {
    $form->setComponent($name, [
      'type' => 'number',
      'weight' => $weight,
      'region' => 'content',
      'settings' => ['placeholder' => ''],
    ])->save();
    print "form display: $name\n";
  }
  if ($view && !$view->getComponent($name)) {
    $view->setComponent($name, [
      'type' => 'number_integer',
      'weight' => $weight,
      'region' => 'content',
      'label' => 'inline',
      'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE],
    ])->save();
    print "view display: $name\n";
  }
  $weight++;
}

print "done\n";

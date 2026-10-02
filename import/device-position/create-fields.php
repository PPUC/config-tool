<?php

/**
 * @file
 * Where a switch, a PWM device or an LED sits on the playfield.
 *
 *   ddev drush php:script import/device-position/create-fields.php
 *
 * The wizard has always read a position for each device from the manual's
 * location diagrams, used it to choose boards and to order the LED strings,
 * and then thrown it away. Kept on the device, the same two numbers can point
 * at the part: in a switch or lamp test, or on a tutorial slide.
 *
 * x runs left to right and y from the flipper end upwards, both as a fraction
 * of the playfield from 0 to 1 - the convention Wizard\Position defines.
 * Optional everywhere, because plenty of manuals have no location diagram.
 *
 * And, on the three location images, where the playfield is on the scan - which
 * is what lets one of those positions be drawn on it.
 *
 * Run once; the resulting config is exported to the sync directory.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\ppuc_games\Wizard\PlayfieldDiagram;

$fields = [
  'field_playfield_x' => [
    'label' => 'Playfield X',
    'description' => 'Where this sits across the playfield, as a fraction from 0 (left edge) to 1 (right edge). Leave empty if unknown.',
  ],
  'field_playfield_y' => [
    'label' => 'Playfield Y',
    'description' => 'Where this sits along the playfield, as a fraction from 0 (flipper end) to 1 (top). Leave empty if unknown.',
  ],
];
$bundles = ['switch', 'pwm_device', 'addressable_led'];

$weight = 40;
foreach ($fields as $name => $definition) {
  // Decimal rather than float: these are read off a diagram to two or three
  // places, and a float would show 0.42 back as 0.41999998688698.
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => 'decimal',
      'cardinality' => 1,
      'settings' => ['precision' => 5, 'scale' => 4],
    ])->save();
    print "$name: storage created\n";
  }

  foreach ($bundles as $bundle) {
    if (!FieldConfig::loadByName('node', $bundle, $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $definition['label'],
        'description' => $definition['description'],
        'required' => FALSE,
        'settings' => ['min' => 0, 'max' => 1, 'prefix' => '', 'suffix' => ''],
      ])->save();
      print "$name: field created on $bundle\n";
    }

    $form = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("node.$bundle.default");
    if ($form && !$form->getComponent($name)) {
      $form->setComponent($name, [
        'type' => 'number',
        'weight' => $weight,
        'region' => 'content',
        'settings' => ['placeholder' => ''],
      ])->save();
      print "$name: form display on $bundle\n";
    }

    $view = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load("node.$bundle.default");
    if ($view && !$view->getComponent($name)) {
      $view->setComponent($name, [
        'type' => 'number_decimal',
        'weight' => $weight,
        'region' => 'content',
        'label' => 'inline',
        'settings' => ['thousand_separator' => '', 'decimal_separator' => '.', 'scale' => 4, 'prefix_suffix' => FALSE],
      ])->save();
      print "$name: view display on $bundle\n";
    }
  }
  $weight++;
}

// Where the playfield is on a scanned location page: the four corners that turn
// a position above into a point on the image. One text field holding JSON
// rather than eight numbers, because the corners are only ever read and written
// as a set. See Wizard\PlayfieldDiagram for the shape.
if (!FieldStorageConfig::loadByName('media', PlayfieldDiagram::CORNERS_FIELD)) {
  FieldStorageConfig::create([
    'field_name' => PlayfieldDiagram::CORNERS_FIELD,
    'entity_type' => 'media',
    'type' => 'string_long',
    'cardinality' => 1,
  ])->save();
  print "corners: storage created\n";
}

foreach (PlayfieldDiagram::KINDS as $kind) {
  $bundle = $kind['mediaType'];
  if (!FieldConfig::loadByName('media', $bundle, PlayfieldDiagram::CORNERS_FIELD)) {
    FieldConfig::create([
      'field_name' => PlayfieldDiagram::CORNERS_FIELD,
      'entity_type' => 'media',
      'bundle' => $bundle,
      'label' => 'Playfield corners',
      'description' => 'Where the four corners of the playfield are on this image, as JSON: <code>flipperLeft</code>, <code>flipperRight</code>, <code>farRight</code> and <code>farLeft</code>, each <code>{ "x", "y" }</code> as a fraction of the image from its top left. Device positions are drawn on the image from these.',
      'required' => FALSE,
    ])->save();
    print "corners: field created on $bundle\n";
  }

  $form = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("media.$bundle.default");
  if ($form && !$form->getComponent(PlayfieldDiagram::CORNERS_FIELD)) {
    $form->setComponent(PlayfieldDiagram::CORNERS_FIELD, [
      'type' => 'string_textarea',
      'weight' => 10,
      'region' => 'content',
      'settings' => ['rows' => 6, 'placeholder' => ''],
    ])->save();
    print "corners: form display on $bundle\n";
  }
}

print "done\n";

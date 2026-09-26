<?php

/**
 * @file
 * The two [Runtime] keys that arm the ball-trough safety net.
 *
 *   ddev drush php:script import/ball-trough/create-fields.php
 *
 * Switch state reaches the ROM as changes and never as a level, so a transition
 * the ROM does not act on is gone: the ball rests in the trough with every layer
 * agreeing the switch is closed and nothing serving it. Naming the switch here
 * lets ppuc present that edge again after a grace period, which is the manual
 * cure -- lift the ball out, drop it back -- without the glass coming off.
 *
 * A plain switch number rather than a reference to a switch node, for the same
 * reason the attract buttons are: a reference would list every switch in the
 * tool, and picking one from the wrong game writes a number that is right or
 * wrong by luck. The number is what ppuc.ini carries and it is printed beside
 * every switch on the All Switches page.
 *
 * Run once; the resulting config is exported to the sync directory.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$fields = [
  'field_ini_ball_trough_switch' => [
    'label' => 'Runtime: BallTroughSwitch',
    'description' => 'Switch number of the trough or outhole a ball rests on while it waits to be served. When that switch stays closed during a game for longer than the grace period below, the machine presents the switch to the ROM again so it serves the ball. 0 turns the safety net off, which is the default: a machine whose trough has not been named here must not have switches synthesised into it.',
    'default' => 0,
    'min' => 0,
  ],
  'field_ini_ball_trough_grace' => [
    'label' => 'Runtime: BallTroughGraceMs',
    'description' => 'How long a ball may sit on that switch during a game before the ROM is told about it again, in milliseconds. 5000 by default: far longer than a normal serve takes, short enough that nobody watching decides the machine has died.',
    'default' => 5000,
    'min' => 0,
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
      'default_value' => [['value' => $spec['default']]],
      'settings' => ['min' => $spec['min'], 'max' => NULL, 'prefix' => '', 'suffix' => ''],
    ])->save();
    print "field created: $name\n";
  }
}

$form = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.ppuc_settings.default');
$view = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('node.ppuc_settings.default');
$weight = 120;
foreach (array_keys($fields) as $name) {
  if ($form && !$form->getComponent($name)) {
    $form->setComponent($name, ['type' => 'number', 'weight' => $weight, 'region' => 'content', 'settings' => ['placeholder' => '']])->save();
    print "form display: $name\n";
  }
  if ($view && !$view->getComponent($name)) {
    $view->setComponent($name, ['type' => 'number_integer', 'weight' => $weight, 'region' => 'content', 'label' => 'inline', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE]])->save();
    print "view display: $name\n";
  }
  $weight++;
}

print "done\n";

<?php

/**
 * @file
 * Builds the "All Slides" view from the "All Switches" one.
 *
 *   ddev drush php:script import/flash-slides/create-slides-view.php
 *
 * Cloned rather than written from scratch so the slides table behaves exactly
 * like every other device table in the tool: the same bulk operations form, the
 * same published column, the same edit links, the same contextual filter
 * through the game. A hand-built view would differ in small ways that are
 * annoying to discover one at a time.
 *
 * Run once. The result is exported to the sync directory and lives in git from
 * then on; this script is the record of how it was made.
 */

$source = \Drupal::config('views.view.game_all_switches')->getRawData();

$view = $source;
unset($view['uuid'], $view['_core']);
$view['id'] = 'game_slides';
$view['label'] = 'Game Slides';

$default = &$view['display']['default']['display_options'];
$default['title'] = 'Slides';
$default['filters']['type']['value'] = ['slide' => 'slide'];

// A switch reaches its game through the I/O board that drives it. A slide is
// attached to the game directly, so that relationship -- and every reference to
// it -- has to go, or the view joins against a table slides are not in and
// quietly returns nothing.
$default['relationships'] = [];

// Published and unpublished both. On a slide, published is not housekeeping,
// it is the switch that decides whether the machine shows it -- so a screen for
// managing slides that hid the unpublished ones would hide the ones being
// worked on.
unset($default['filters']['status']);

// Columns. The switch table's device fields go; what matters about a slide is
// where it sits in the loop, whether it is published, and what it says.
$fields = $default['fields'];
foreach (['field_number', 'field_debounce', 'field_debounce_mode', 'field_i_o_board', 'field_pin'] as $drop) {
  unset($fields[$drop]);
}

// Borrowed from the rules table, which orders the same way this one does.
$rules = \Drupal::config('views.view.game_rules')->get('display.default.display_options');
$fields['field_weight'] = $rules['fields']['field_weight'];

// The slide's own words, trimmed: enough to tell two slides apart at a glance
// without turning the table into the slides themselves.
$text = $rules['fields']['field_weight'];
$text['id'] = 'field_slide_text';
// The table and the entity field matter as much as the name: a field
// definition that names one field and reads another quietly renders the wrong
// column rather than failing.
$text['table'] = 'node__field_slide_text';
$text['field'] = 'field_slide_text';
$text['entity_type'] = 'node';
$text['entity_field'] = 'field_slide_text';
$text['label'] = 'Text';
$text['type'] = 'text_default';
$text['settings'] = [];
$text['alter']['alter_text'] = FALSE;
$text['alter']['max_length'] = 120;
$text['alter']['trim'] = TRUE;
$text['alter']['ellipsis'] = TRUE;
$fields['field_slide_text'] = $text;

// Weight first, then the title and the words: the order somebody scanning for
// "which slide is out of place" needs them in.
//
// status and edit_node are excluded fields that the title composes into itself
// as tokens -- "Title (edit) [tick]" -- and a Views token is only offered by a
// field that comes *earlier* in this list. Put either of them after the title
// and its token silently renders as nothing, which looks like a broken link
// rather than like a misordered list.
$order = ['views_bulk_operations_bulk_form', 'field_weight', 'status', 'edit_node', 'title', 'field_slide_text'];
$ordered = [];
foreach ($order as $key) {
  if (isset($fields[$key])) {
    $ordered[$key] = $fields[$key];
  }
}
// Anything the switch table had that is not in the list above still belongs to
// a node and is harmless, so it goes on the end rather than being dropped
// silently.
foreach ($fields as $key => $field) {
  if (!isset($ordered[$key])) {
    $ordered[$key] = $field;
  }
}
$default['fields'] = $ordered;

// In the order the slides play, which is the only order that means anything
// here. The switch table has no sort at all.
$default['sorts'] = $rules['sorts'];

// The table's own column setup came from the switches view and names columns
// that no longer exist.
if (isset($default['style']['options']['columns'])) {
  $columns = [];
  foreach (array_keys($default['fields']) as $key) {
    $columns[$key] = $key;
  }
  $default['style']['options']['columns'] = $columns;
  $info = [];
  foreach (array_keys($default['fields']) as $key) {
    $info[$key] = [
      'sortable' => in_array($key, ['field_weight', 'title', 'status'], TRUE),
      'default_sort_order' => 'asc',
      'align' => '',
      'separator' => '',
      'empty_column' => FALSE,
      'responsive' => '',
    ];
  }
  $default['style']['options']['info'] = $info;
  $default['style']['options']['default'] = '-1';
}

// The page display: renamed, and moved to its own path.
$page = $view['display']['all_switches'];
unset($view['display']['all_switches']);
$page['id'] = 'all_slides';
$page['display_options']['path'] = 'node/%node/all-slides';
$view['display']['all_slides'] = $page;

// Let Drupal work the cache metadata out again rather than carrying the
// switch table's field storage tags over.
foreach (array_keys($view['display']) as $key) {
  unset($view['display'][$key]['cache_metadata']);
}

// The bulk actions came from the switch table and are about debounce, which a
// slide does not have. What a slide has is a published flag, and publishing or
// putting away a set of them together is the reason to tick several boxes.
if (isset($default['fields']['views_bulk_operations_bulk_form'])) {
  $default['fields']['views_bulk_operations_bulk_form']['selected_actions'] = [
    // The plugin id, not the action config entity's id: VBO lists these as
    // entity:publish_action:node, and an id it does not know is dropped
    // silently, leaving a bulk form with no actions at all.
    ['action_id' => 'entity:publish_action:node', 'preconfiguration' => ['add_confirmation' => FALSE]],
    ['action_id' => 'entity:unpublish_action:node', 'preconfiguration' => ['add_confirmation' => FALSE]],
  ];
}

// Fields, sorts, filters and the argument all carry the relationship they were
// cloned with.
foreach (['fields', 'sorts', 'filters', 'arguments'] as $section) {
  foreach ($default[$section] ?? [] as $key => $item) {
    if (isset($item['relationship'])) {
      $default[$section][$key]['relationship'] = 'none';
    }
  }
}

$config = \Drupal::configFactory()->getEditable('views.view.game_slides');
$config->setData($view)->save();

// Saved again through the entity API, which recalculates dependencies. Written
// as raw config the view keeps the switch table's list -- field_debounce,
// field_pin, node.type.switch -- and a view that declares a dependency on a
// field it does not use is a view that gets deleted the day somebody removes
// that field.
\Drupal\views\Entity\View::load('game_slides')->save();

print "views.view.game_slides saved\n";
print "  fields: " . implode(', ', array_keys($default['fields'])) . "\n";
print "  path:   " . $page['display_options']['path'] . "\n";

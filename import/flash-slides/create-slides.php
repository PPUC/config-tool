<?php

/**
 * @file
 * Creates the how-to-play slides for Flash.
 *
 * Run once with:
 *   ddev drush php:script import/flash-slides/create-slides.php
 *
 * Matched by title within the game, so running it twice updates the slides
 * rather than doubling them -- but any editing done in the UI is overwritten,
 * which is why this is an authoring script and not an import controller. Once a
 * slide has been edited in the config tool, edit it there.
 *
 * The slides with no image are the rules and the tips; photographs of this
 * machine's own playfield go on those later, with markers pointing at the
 * shots being described.
 */

use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;

const FLASH_GAME_NID = 195;
const IMAGE_SOURCE_DIR = __DIR__ . '/images';

/**
 * Title, text, weight, image, duration.
 *
 * Text is kept to what somebody can read from a few feet away in the seconds
 * the slide is up. The long-form rules these are distilled from are worth
 * reading at home, not in a bar with a ball in the shooter lane.
 */
$slides = [
  [
    'title' => 'Flash',
    'text' => 'Williams, 1978. Designed by Steve Ritchie. 19,505 were built, and this is one of them.',
    'weight' => 100,
  ],

  // The rules, in the order a player meets them.
  [
    'title' => 'Top lanes',
    'text' => 'Making 1-2-3 lights double bonus. Making all four lights triple. There is no lane change on this game: plunge well, or nudge.',
    'weight' => 200,
  ],
  [
    'title' => 'The three-bank',
    'text' => 'Clear the centre targets four times: Thunder, then Lightning, then Tempest, then Super Flash for 50,000.',
    'weight' => 300,
  ],
  [
    'title' => 'The five-bank',
    'text' => 'First clear raises the hole kicker. Second lights extra ball. Third lights the outlane specials.',
    'weight' => 400,
  ],
  [
    'title' => 'The spinner',
    'text' => 'Completing the left bank lights the spinner. On a long ball it out-scores everything else on the playfield.',
    'weight' => 500,
  ],

  // Multiball: the thing this machine could not do when it was built.
  [
    'title' => 'Multiball',
    'text' => 'Something this machine could not do in 1978. Clear both banks of drop targets in the same ball.',
    'weight' => 600,
  ],
  [
    'title' => 'Multiball is ready',
    'text' => 'Both banks down, and the cabinet turns rainbow. Now shoot the eject hole.',
    'weight' => 700,
  ],
  [
    'title' => 'Two balls',
    'text' => 'A second ball is served to the shooter lane. Plunge it, and the one waiting in the hole joins you.',
    'weight' => 800,
  ],
  [
    'title' => 'The ROM never finds out',
    'text' => 'The 1978 program still believes one ball is in play. It is only told the ball drained when both are back in the trough.',
    'weight' => 900,
  ],

  // Tips, from people who play it well.
  [
    'title' => 'Use the third flipper',
    'text' => 'From the upper right flipper you can take all three centre drops at once, and often catch part of the five-bank on the way.',
    'weight' => 1000,
  ],
  [
    'title' => 'The loop repeats',
    'text' => 'The upper loop feeds itself. With the spinner lit it is the best shot in the game.',
    'weight' => 1100,
  ],
  [
    'title' => 'Backhand the drops',
    'text' => 'Let the ball roll out to the tip of the flipper and shoot as it rolls back. The five-bank returns safely that way.',
    'weight' => 1200,
  ],
  [
    'title' => 'Ten thousand, again and again',
    'text' => 'With the first five-bank down, the lit right saucer can be backhanded over and over.',
    'weight' => 1300,
  ],
  [
    'title' => 'Soft plunge',
    'text' => 'A soft plunge gives you a head start on the five-bank. Unless the spinner is already lit, in which case go and hit it.',
    'weight' => 1400,
  ],
  [
    'title' => 'What will drain you',
    'text' => 'The two bullseye standups are drain bait. Leaving the bumpers to the left is far riskier than leaving them to the right.',
    'weight' => 1500,
  ],

  // Trivia. Two firsts for the whole industry, on this machine.
  [
    'title' => 'The first game that hummed',
    'text' => 'Flash was the first machine by any manufacturer with a background sound that played while you played, rising in pitch and speed as you did better.',
    'weight' => 1600,
  ],
  [
    'title' => 'All eyes were on you',
    'text' => '"That sound broadcasted how well the player was doing. If you heard it at high pitch and a fast cycle, all eyes were on you." - Steve Ritchie',
    'weight' => 1700,
    'duration' => 11000,
  ],
  [
    'title' => 'We replaced it',
    'text' => 'Ritchie was right about what it did. He was also right that it is hard to listen to, so this machine plays something newer.',
    'weight' => 1800,
  ],
  [
    'title' => 'And the first to flash',
    'text' => 'Flash was also the first game to use flash lamps: light for its own sake rather than as an indicator. Every machine since has them.',
    'weight' => 1900,
  ],
  [
    'title' => 'Leave the market wanting',
    'text' => '19,505 built, far more than any Williams game before it. Asked why they stopped short of 20,000, the head of sales said: we want to leave the market wanting.',
    'weight' => 2000,
    'duration' => 11000,
  ],
  [
    'title' => 'October 27, 1978',
    'text' => 'Williams System 4, model 486. Design: Steve Ritchie. Art: Constantino and Jeanine Mitchell. Mechanics: John Jung. Sound and software: Randy Pfeiffer. Maximum score: 999,990.',
    'weight' => 2100,
    'duration' => 11000,
  ],

  // The flyers the operators saw in 1978.
  [
    'title' => 'High voltage action',
    'text' => 'How Williams sold it: thunder, lightning, and a sound nobody had heard in an arcade before.',
    'weight' => 2200,
    'image' => 'flyer-high-voltage.jpg',
  ],
  [
    'title' => 'Record breaking earnings',
    'text' => 'The flyer that went to operators. On location, it out-earned everything Williams had built.',
    'weight' => 2300,
    'image' => 'flyer-backglass.jpg',
  ],
  [
    'title' => 'The playfield in 1978',
    'text' => 'Three flippers, two banks of drop targets, a spinner and a kick-out hole. Nothing here has changed.',
    'weight' => 2400,
    'image' => 'flyer-us-playfield.jpg',
  ],
  [
    'title' => 'The Hot One',
    'text' => 'Williams called itself the Hot One that year, and Flash was the reason.',
    'weight' => 2500,
    'image' => 'flyer-cabinet.jpg',
  ],
  [
    'title' => 'Sega brought it to Japan',
    // No Japanese in the text: the font PPUC draws with has no CJK glyphs and
    // renders it as empty boxes. The flyer says it in Japanese anyway.
    'text' => 'Sega Enterprises distributed Williams games in Japan. Their flyer led with a new sales record, from Sega\'s own location test.',
    'weight' => 2600,
    'image' => 'flyer-japan-sega.jpg',
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('node');

/**
 * Saves an image file into the slides directory and returns the file entity.
 */
$load_image = function (string $filename): ?FileInterface {
  $source = IMAGE_SOURCE_DIR . '/' . $filename;
  if (!is_file($source)) {
    echo "  missing image: $source\n";
    return NULL;
  }
  $directory = 'public://slides/' . date('Y-m');
  \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  return \Drupal::service('file.repository')->writeData(
    file_get_contents($source),
    $directory . '/' . $filename,
    \Drupal\Core\File\FileExists::Replace
  );
};

$created = 0;
$updated = 0;

foreach ($slides as $slide) {
  $existing = $storage->loadByProperties([
    'type' => 'slide',
    'title' => $slide['title'],
    'field_game' => FLASH_GAME_NID,
  ]);
  $node = $existing ? reset($existing) : Node::create(['type' => 'slide']);
  $is_new = $node->isNew();

  $node->setTitle($slide['title']);
  $node->set('field_game', FLASH_GAME_NID);
  $node->set('field_weight', $slide['weight']);
  $node->set('field_slide_text', [
    'value' => $slide['text'],
    'format' => 'plain_text',
  ]);
  if (isset($slide['duration'])) {
    $node->set('field_duration', $slide['duration']);
  }
  if (isset($slide['image'])) {
    if ($file = $load_image($slide['image'])) {
      $node->set('field_image', ['target_id' => $file->id()]);
    }
  }
  // Published, so they are exported and shown. Anything still being worked on
  // is unpublished instead of deleted.
  $node->setPublished();
  $node->save();

  $is_new ? $created++ : $updated++;
  printf("%s %4d %s (nid %d)\n", $is_new ? 'created' : 'updated', $slide['weight'], $slide['title'], $node->id());
}

printf("\n%d created, %d updated\n", $created, $updated);

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * The prompt for turning manual pages into the wizard's JSON.
 *
 * The wizard takes JSON rather than images on purpose: the format is the
 * contract, and anything that can produce it will do. Most people have an AI
 * chat to hand, so this is the instruction to paste into one along with the
 * scanned pages.
 *
 * It is built here rather than written out in the form so that the parts that
 * must match the parser - the section names, the solenoid types, the platform's
 * own switch numbers - come from the same constants the parser uses, and a test
 * can check the prompt still describes the format that is actually accepted.
 */
final class ExtractionPrompt {

  /**
   * The pages worth attaching, in the order they are usually printed.
   *
   * The first three carry the numbers and are what the wizard needs. The
   * location pages only add positions, and plenty of manuals have none.
   */
  public const REQUIRED_PAGES = [
    'Switch Matrix',
    'Lamp Matrix',
    'Solenoid/Flashlamp Table',
  ];

  public const OPTIONAL_PAGES = [
    'Switch Locations',
    'Lamp Locations',
    'Solenoid/Flashlamp Locations',
  ];

  /**
   * The prompt, for a given platform.
   */
  public static function text(string $platform = 'WPC'): string {
    $sections = [
      self::intro($platform),
      self::format(),
      self::rules($platform),
      self::example(),
    ];

    return implode("\n\n", $sections) . "\n";
  }

  private static function intro(string $platform): string {
    return sprintf(
      "You are transcribing pages from a pinball machine's operator manual into JSON.\n"
      . "The machine is a %s game and the JSON will be used to build its controller\n"
      . "configuration, so accuracy matters more than completeness.\n"
      . "\n"
      . "I have attached scans of:\n"
      . "  %s\n"
      . "and, if available:\n"
      . "  %s\n"
      . "\n"
      . "The first three carry the numbers and are what you need. The location pages are\n"
      . "playfield diagrams with numbered callouts; they only add positions, and plenty of\n"
      . "manuals do not have them.\n"
      . "\n"
      . "Reply with the JSON document and nothing else - no explanation, no code fence.",
      $platform,
      implode("\n  ", self::REQUIRED_PAGES),
      implode("\n  ", self::OPTIONAL_PAGES)
    );
  }

  private static function format(): string {
    return "FORMAT\n"
      . "\n"
      . "{\n"
      . "  \"game\":     { \"title\": ..., \"platform\": ..., \"rom\": ... },\n"
      . "  \"switches\": [ { \"number\", \"description\", and optionally \"opto\", \"direct\",\n"
      . "                  \"button\", \"location\", \"position\" } ],\n"
      . "  \"coils\":    [ { \"number\", \"description\", \"class\", and optionally \"type\",\n"
      . "                  \"location\", \"fastFlipSwitch\", \"endSwitches\",\n"
      . "                  \"holdWinding\", \"position\" } ],\n"
      . "  \"flippers\": [ { \"name\", \"position\", \"powerCoil\", \"holdCoil\" } ],\n"
      . "  \"flashers\": [ { \"number\", \"description\", and optionally \"location\", \"position\" } ],\n"
      . "  \"lamps\":    [ { \"number\", \"description\", and optionally \"location\", \"position\" } ],\n"
      . "  \"gi\":       [ { \"number\", \"description\", and optionally \"location\", \"position\" } ]\n"
      . "}\n"
      . "\n"
      . "Use no keys other than these. An unknown key is rejected rather than ignored.";
  }

  private static function rules(string $platform): string {
    $directSwitches = PlatformNumbers::directSwitches($platform);
    $directList = [];
    foreach ($directSwitches as $number => $name) {
      $directList[] = sprintf('%d = %s', $number, $name);
    }

    $rules = [
      "The numbers are the manual's own. A switch or lamp in a matrix is the number\n"
      . "printed in its cell, which is column x 10 + row. A coil is its solenoid number.\n"
      . "Do not renumber anything.",

      "Leave out every row the manual marks \"Not Used\", and leave out a switch called\n"
      . "\"Always Closed\" - it exists for the original CPU and is not needed here.",

      "Never invent a value. If a cell is illegible or you are unsure, leave that entry\n"
      . "out completely rather than guessing. Forty correct switches are worth more than\n"
      . "forty-five with two wrong ones, which would be wired to the wrong hardware.",

      "Switch matrix cells marked as optos - usually shaded, with a legend saying so -\n"
      . "get \"opto\": true. Troughs, poppers and ramp-make switches are typically optos.",

      "\"class\" comes from the solenoid table's own type column:\n"
      . "  High Power   -> \"" . DeviceDefaults::CLASS_HIGH_POWER . "\"\n"
      . "  Low Power    -> \"" . DeviceDefaults::CLASS_LOW_POWER . "\"\n"
      . "  Gen. Purpose -> \"" . DeviceDefaults::CLASS_GENERAL_PURPOSE . "\"\n"
      . "It sets the drive power, so do not guess it. Leave the coil out if the column\n"
      . "is unreadable.",

      "Flashers are not coils. Any solenoid row whose type is Flasher goes in\n"
      . "\"flashers\", keyed by its solenoid number. They are driven as LEDs here, not as\n"
      . "PWM outputs.",

      "A motor - a gun, a cannon, a rotating assembly - is a coil with \"type\": \"motor\".\n"
      . "If the switch list has switches at the ends of its travel (\"Gun Position\",\n"
      . "\"Gun Lockup\" and the like), list their numbers in \"endSwitches\".",

      "If two solenoid rows drive one device as a power and a hold winding - the same\n"
      . "coil part number, named \"... High\" and \"... Hold\" - put \"holdWinding\": true on\n"
      . "the hold one. Flippers are the exception: they go in \"flippers\" instead.",

      "Flippers come from the solenoid table's flipper block, which lists them as pairs.\n"
      . "Each entry names its two coil numbers and a position, one of:\n"
      . "  lowerRight, lowerLeft, upperRight, upperLeft\n"
      . "Do not create flipper button or end-of-stroke switches - the tool creates those\n"
      . "itself, with the numbers the ROM expects. Be careful here: the flipper column\n"
      . "printed beside the switch matrix is often a generic template listing flippers the\n"
      . "game does not have. Trust the solenoid table.",

      "Dedicated or direct switches - the coin door and service buttons, usually a\n"
      . "separate column beside the matrix - get \"direct\": true. On " . $platform . " they must\n"
      . "have these numbers:\n"
      . ($directList ? '  ' . implode("\n  ", $directList) : '  (unknown for this platform - leave them out)'),

      "\"location\" is \"cabinet\" or \"backbox\" for anything not on the playfield; omit it\n"
      . "otherwise, since playfield is the default. The start button, buy-in button, tilt\n"
      . "switches and coin door switches are in the cabinet even when they appear in the\n"
      . "matrix. The knocker and backbox illumination are backbox. The solenoid table's\n"
      . "connection columns (PLAYFIELD / BACKBOX / CABINET) tell you which for coils.",

      "Positions, only if the location pages are attached:\n"
      . "  \"position\": { \"x\": 0.42, \"y\": 0.71 }\n"
      . "x runs left to right and y from the flipper end upwards, each as a fraction of\n"
      . "the playfield from 0 to 1. Read them off the callout on the diagram; approximate\n"
      . "is fine, and omit the position for anything you cannot place. Do not guess a\n"
      . "position from a device's name.",
    ];

    // Hanging indent, so a rule that wraps still reads as one rule.
    $numbered = [];
    foreach ($rules as $index => $rule) {
      $lines = explode("\n", $rule);
      $first = array_shift($lines);
      $rest = $lines ? "\n    " . implode("\n    ", $lines) : '';
      $numbered[] = sprintf("%2d. %s%s", $index + 1, $first, $rest);
    }

    return "RULES\n\n" . implode("\n\n", $numbered);
  }

  private static function example(): string {
    return "EXAMPLE OF THE SHAPE\n"
      . "\n"
      . "{\n"
      . "  \"game\": { \"title\": \"Dirty Harry\", \"platform\": \"WPC\", \"rom\": \"dh_lx2\" },\n"
      . "  \"switches\": [\n"
      . "    { \"number\": 11, \"description\": \"Gun Handle Trigger\", \"position\": { \"x\": 0.5, \"y\": 0.3 } },\n"
      . "    { \"number\": 31, \"description\": \"Trough Jam\", \"opto\": true },\n"
      . "    { \"number\": 13, \"description\": \"Start Button\", \"location\": \"cabinet\", \"button\": true },\n"
      . "    { \"number\": 61, \"description\": \"Left Slingshot\" },\n"
      . "    { \"number\": 76, \"description\": \"Gun Position\" },\n"
      . "    { \"number\": 77, \"description\": \"Gun Lockup\" },\n"
      . "    { \"number\": 5,  \"description\": \"Service Credit/Escape\", \"direct\": true }\n"
      . "  ],\n"
      . "  \"coils\": [\n"
      . "    { \"number\": 1,  \"description\": \"Ball Release\", \"class\": \"highPower\" },\n"
      . "    { \"number\": 7,  \"description\": \"Knocker\", \"class\": \"highPower\", \"location\": \"backbox\" },\n"
      . "    { \"number\": 9,  \"description\": \"Left Slingshot\", \"class\": \"lowPower\", \"fastFlipSwitch\": 61 },\n"
      . "    { \"number\": 16, \"description\": \"Trap Door Hold\", \"class\": \"lowPower\", \"holdWinding\": true },\n"
      . "    { \"number\": 20, \"description\": \"Gun Motor\", \"class\": \"lowPower\", \"type\": \"motor\",\n"
      . "      \"endSwitches\": [76, 77] },\n"
      . "    { \"number\": 29, \"description\": \"Lower Right Flipper Power\", \"class\": \"highPower\" },\n"
      . "    { \"number\": 30, \"description\": \"Lower Right Flipper Hold\", \"class\": \"lowPower\" }\n"
      . "  ],\n"
      . "  \"flippers\": [\n"
      . "    { \"name\": \"Lower Right\", \"position\": \"lowerRight\", \"powerCoil\": 29, \"holdCoil\": 30 }\n"
      . "  ],\n"
      . "  \"flashers\": [ { \"number\": 17, \"description\": \"Headquarters\" } ],\n"
      . "  \"lamps\": [\n"
      . "    { \"number\": 11, \"description\": \"Left Rollover\", \"position\": { \"x\": 0.36, \"y\": 0.88 } },\n"
      . "    { \"number\": 88, \"description\": \"Start Button\", \"location\": \"cabinet\" }\n"
      . "  ],\n"
      . "  \"gi\": [ { \"number\": 1, \"description\": \"Right String\" } ]\n"
      . "}";
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Creates the nodes for an allocated device list.
 *
 * Everything interesting was decided before this runs: numbers by the parser,
 * boards and pins by the allocator, power and pulse times by DeviceDefaults.
 * This turns that plan into entities and does no thinking of its own, which is
 * what keeps the thinking testable without a database.
 *
 * Order matters in one place: a PWM device references its fast-flip switch by
 * entity, so switches are created before coils.
 */
final class GameBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates a game and everything under it.
   *
   * @param array $devices
   *   A parsed device list from DeviceDataParser.
   * @param array $plan
   *   An allocation from HardwareAllocator.
   * @param string $sourceJson
   *   The document this was built from, kept on the game so the wizard can be
   *   run again against a corrected version instead of starting over.
   *
   * @return array
   *   ['game' => NodeInterface, 'counts' => [...], 'warnings' => [...]]
   */
  public function build(array $devices, array $plan, string $sourceJson): array {
    $warnings = [];

    $game = $this->createGame($devices['game'], $sourceJson, $warnings);

    $boardsByIndex = [];
    foreach ($plan['boards'] as $board) {
      $boardsByIndex[$board['index']] = $this->createBoard($game, $board, $warnings);
    }

    $switchesByNumber = [];
    foreach ($plan['switches'] as $switch) {
      $switchesByNumber[$switch['number']] = $this->createSwitch($boardsByIndex[$switch['board']], $switch);
    }

    foreach ($plan['coils'] as $coil) {
      $this->createPwmDevice($boardsByIndex[$coil['board']], $coil, $switchesByNumber, $warnings);
    }

    $stripeCount = 0;
    $ledCount = 0;
    foreach ($plan['stripes'] as $stripe) {
      $ledCount += $this->createStripe($boardsByIndex[$stripe['board']], $stripe);
      $stripeCount++;
    }

    return [
      'game' => $game,
      'counts' => [
        'boards' => count($boardsByIndex),
        'switches' => count($switchesByNumber),
        'coils' => count($plan['coils']),
        'stripes' => $stripeCount,
        'leds' => $ledCount,
      ],
      'warnings' => array_merge($plan['notes'], $warnings),
    ];
  }

  private function createGame(array $game, string $sourceJson, array &$warnings): NodeInterface {
    $values = [
      'type' => 'game',
      'title' => $game['title'],
      'field_wizard_source' => ['value' => $sourceJson],
    ];

    $platform = $this->term('platform', $game['platform']);
    if ($platform !== NULL) {
      $values['field_platform'] = ['target_id' => $platform];
    }
    else {
      $warnings[] = sprintf(
        'No platform called "%s" exists, so the game was created without one. '
        . 'Set it before exporting.',
        $game['platform']
      );
    }

    if ($game['rom'] !== '') {
      $values['field_rom'] = ['value' => $game['rom']];
    }

    $node = Node::create($values);
    $node->save();
    return $node;
  }

  private function createBoard(NodeInterface $game, array $board, array &$warnings): NodeInterface {
    $values = [
      'type' => 'i_o_board',
      'title' => $board['description'],
      'field_game' => ['target_id' => $game->id()],
      'field_number' => ['value' => $board['index']],
    ];

    $type = $this->term('i_o_board', $board['type']);
    if ($type !== NULL) {
      $values['field_io_board_type'] = ['target_id' => $type];
    }
    else {
      // Without a type the board has no GPIO mapping, so the export cannot turn
      // its pins into ports. Worth saying loudly rather than producing a game
      // whose YAML has null ports.
      $warnings[] = sprintf(
        'Board %d was created without a type: no "%s" board type exists. '
        . 'Import the default content that defines it, then set the type.',
        $board['index'],
        $board['type']
      );
    }

    $node = Node::create($values);
    $node->save();
    return $node;
  }

  private function createSwitch(NodeInterface $board, array $switch): NodeInterface {
    $values = [
      'type' => 'switch',
      'title' => $switch['description'],
      'field_i_o_board' => ['target_id' => $board->id()],
      'field_number' => ['value' => $switch['number']],
      'field_pin' => ['value' => $switch['pin']],
    ];

    if (!empty($switch['button'])) {
      $values['field_button'] = ['value' => 1];
    }

    // A flipper button is the one switch whose latency is the point of where it
    // was placed, so it gets the debounce mode that matches.
    if (($switch['role'] ?? '') === 'flipperButton') {
      $values['field_button'] = ['value' => 1];
      $mode = $this->term('switch_debounce_mode', 'Fast Flip');
      if ($mode !== NULL) {
        $values['field_debounce_mode'] = ['target_id' => $mode];
      }
    }

    $node = Node::create($values);
    $node->save();
    return $node;
  }

  private function createPwmDevice(NodeInterface $board, array $coil, array $switchesByNumber, array &$warnings): NodeInterface {
    $values = [
      'type' => 'pwm_device',
      'title' => $coil['description'],
      'field_i_o_board' => ['target_id' => $board->id()],
      'field_number' => ['value' => $coil['number']],
      'field_pin' => ['value' => $coil['pin']],
      'field_power' => ['value' => $coil['power']],
      'field_max_pulse_time' => ['value' => $coil['maxPulseTime']],
    ];

    if (!empty($coil['holdWinding'])) {
      $values['field_hold_winding'] = ['value' => 1];
    }

    $type = $this->term('pwm_device', $this->pwmTypeName($coil['type']));
    if ($type !== NULL) {
      $values['field_pwm_type'] = ['target_id' => $type];
    }
    else {
      $warnings[] = sprintf(
        'PWM device %d ("%s") was created without a type: no "%s" term exists.',
        $coil['number'],
        $coil['description'],
        $this->pwmTypeName($coil['type'])
      );
    }

    $fastFlip = $coil['fastFlipSwitch'] ?? NULL;
    if ($fastFlip !== NULL && isset($switchesByNumber[$fastFlip])) {
      $values['field_fast_activation_switch'] = ['target_id' => $switchesByNumber[$fastFlip]->id()];
    }

    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Creates one stripe and its LEDs, returning how many LEDs were made.
   */
  private function createStripe(NodeInterface $board, array $stripe): int {
    $values = [
      'type' => 'addressable_leds',
      'title' => $stripe['label'],
      'field_i_o_board' => ['target_id' => $board->id()],
      'field_pin' => ['value' => $stripe['pin']],
      'field_amount_leds' => ['value' => count($stripe['leds'])],
    ];

    // GRB is what the WS2812 strings in the existing games use. Named here
    // rather than left empty because the field is required for an export.
    $ledType = $this->term('led_type', 'GRB');
    if ($ledType !== NULL) {
      $values['field_led_type'] = ['target_id' => $ledType];
    }

    $node = Node::create($values);
    $node->save();

    $role = $this->term('led_role', $stripe['role']);
    foreach ($stripe['leds'] as $led) {
      $ledValues = [
        'type' => 'addressable_led',
        'title' => $led['description'],
        'field_string' => ['target_id' => $node->id()],
        'field_number' => ['value' => $led['number']],
        'field_string_position' => ['value' => $led['position']],
      ];
      if ($role !== NULL) {
        $ledValues['field_role'] = ['target_id' => $role];
      }
      Node::create($ledValues)->save();
    }

    return count($stripe['leds']);
  }

  /**
   * The pwm_device vocabulary's name for a device type.
   */
  private function pwmTypeName(string $type): string {
    return match ($type) {
      'lamp' => 'Lamp',
      'motor' => 'Motor',
      'shaker' => 'Shaker',
      default => 'Coil',
    };
  }

  /**
   * A taxonomy term id by vocabulary and name, or NULL.
   *
   * NULL rather than creating the term: the vocabularies are default content
   * that the firmware and the exporter both depend on, and inventing a term
   * because one was missing produces a game that looks configured and is not.
   */
  private function term(string $vocabulary, string $name): ?string {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vocabulary,
      'name' => $name,
    ]);
    if (!$terms) {
      return NULL;
    }
    return (string) reset($terms)->id();
  }

}

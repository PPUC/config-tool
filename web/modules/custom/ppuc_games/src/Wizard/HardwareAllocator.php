<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Wizard;

/**
 * Works out which board and pin every device gets.
 *
 * Three things are not negotiable, and everything else is packing:
 *
 * 1. A coil with a fast-flip switch shares a board with that switch. The board
 *    only reacts locally when it owns both; otherwise the activation waits for
 *    the next poll, which is the whole latency this feature exists to avoid.
 *    Flipper buttons are on a playfield board for exactly this reason, even
 *    though they are cabinet hardware.
 * 2. Cabinet devices go on cabinet boards, playfield devices on playfield
 *    boards. Wire runs are physical; a coin door switch on a board under the
 *    playfield means a cable across the machine.
 * 3. One LED stripe per board, on the dedicated LED pin, as every existing
 *    game does.
 *
 * No entities are touched here. It takes a parsed device list and returns a
 * plan, which is what lets the wizard show the allocation before creating
 * anything and what makes this testable without a database.
 */
final class HardwareAllocator {

  public const GROUP_CABINET = 'cabinet';
  public const GROUP_PLAYFIELD = 'playfield';

  /**
   * @var array<int, int>
   *   Connector pin to GPIO for IO_16_8_1.
   */
  private array $ioGpioMapping;

  /**
   * @var array<int, int>
   *   Connector pin to GPIO for Opto_16.
   */
  private array $optoGpioMapping;

  /**
   * Boards being built, in creation order.
   *
   * @var array<int, array>
   */
  private array $boards = [];

  /**
   * @var string[]
   */
  private array $notes = [];

  /**
   * @param array<int, int> $ioGpioMapping
   *   IO_16_8_1's field_gpio_mapping, unserialised.
   * @param array<int, int> $optoGpioMapping
   *   Opto_16's field_gpio_mapping, unserialised.
   */
  public function __construct(array $ioGpioMapping, array $optoGpioMapping) {
    $this->ioGpioMapping = $ioGpioMapping;
    $this->optoGpioMapping = $optoGpioMapping;
  }

  /**
   * Allocates every device in a parsed device list.
   *
   * @return array
   *   ['boards' => [...], 'switches' => [...], 'coils' => [...],
   *    'stripes' => [...], 'notes' => [...]]
   */
  public function allocate(array $devices): array {
    $this->boards = [];
    $this->notes = [];

    $switches = $this->expandSwitches($devices);
    $coils = $this->expandCoils($devices);

    $assignedSwitches = [];
    $assignedCoils = [];

    // 1. Fast-flip groups first. They are the only devices whose board is
    // decided by something other than capacity, so they choose before the
    // packing has a chance to fill the boards they need.
    foreach ($this->fastFlipGroups($switches, $coils) as $group) {
      $board = $this->boardWithRoom(
        $group['location'],
        BoardCapacity::IO_16_8_1,
        count($group['switches']),
        count($group['coils'])
      );
      foreach ($group['switches'] as $switch) {
        $assignedSwitches[$switch['number']] = $this->placeSwitch($board, $switch);
      }
      foreach ($group['coils'] as $coil) {
        $assignedCoils[$coil['number']] = $this->placeCoil($board, $coil);
      }
    }

    // 2. Remaining coils. Before the remaining switches, because outputs are
    // the scarcer resource - 8 per board against 16 inputs.
    foreach ($coils as $coil) {
      if (isset($assignedCoils[$coil['number']])) {
        continue;
      }
      $board = $this->boardWithRoom($this->groupOf($coil['location']), BoardCapacity::IO_16_8_1, 0, 1);
      $assignedCoils[$coil['number']] = $this->placeCoil($board, $coil);
    }

    // 3. Opto switches, on their own board type.
    foreach ($switches as $switch) {
      if (isset($assignedSwitches[$switch['number']]) || !$switch['opto']) {
        continue;
      }
      $board = $this->boardWithRoom($this->groupOf($switch['location']), BoardCapacity::OPTO_16, 1, 0);
      $assignedSwitches[$switch['number']] = $this->placeSwitch($board, $switch);
    }

    // 4. Everything else.
    foreach ($switches as $switch) {
      if (isset($assignedSwitches[$switch['number']])) {
        continue;
      }
      $board = $this->boardWithRoom($this->groupOf($switch['location']), BoardCapacity::IO_16_8_1, 1, 0);
      $assignedSwitches[$switch['number']] = $this->placeSwitch($board, $switch);
    }

    $stripes = $this->allocateStripes($devices);

    $this->nameBoards();

    return [
      'boards' => array_values($this->boards),
      'switches' => array_values($assignedSwitches),
      'coils' => array_values($assignedCoils),
      'stripes' => $stripes,
      'notes' => $this->notes,
    ];
  }

  /**
   * Every switch the wizard will create, including the ones it invents.
   *
   * A flipper contributes two switches that are in no manual table: the button,
   * at the number PinMAME reads, and the EOS, in the custom range because
   * PinMAME does not read it at all.
   */
  private function expandSwitches(array $devices): array {
    $switches = [];
    foreach ($devices['switches'] as $switch) {
      $switches[] = $switch + ['role' => 'switch'];
    }

    $nextCustom = PlatformNumbers::CUSTOM_NUMBER_BASE;
    foreach ($devices['flippers'] as $flipper) {
      // The button belongs to the cabinet but is allocated to a playfield
      // board, so it shares one with the coils it fast-flips.
      $switches[] = [
        'number' => $flipper['buttonSwitch'],
        'description' => $flipper['name'] . ' Flipper Button',
        'opto' => FALSE,
        'direct' => FALSE,
        'button' => TRUE,
        'location' => DeviceDataParser::LOCATION_PLAYFIELD,
        'role' => 'flipperButton',
        'flipper' => $flipper['name'],
      ];

      if ($nextCustom > PlatformNumbers::CUSTOM_NUMBER_LIMIT) {
        $this->notes[] = sprintf(
          'Ran out of custom switch numbers below %d; the EOS switch for "%s" was not created.',
          PlatformNumbers::CUSTOM_NUMBER_LIMIT + 1,
          $flipper['name']
        );
        continue;
      }
      $switches[] = [
        'number' => $nextCustom++,
        'description' => $flipper['name'] . ' Flipper EOS',
        'opto' => FALSE,
        'direct' => FALSE,
        'button' => FALSE,
        'location' => DeviceDataParser::LOCATION_PLAYFIELD,
        'role' => 'flipperEos',
        'flipper' => $flipper['name'],
      ];
    }

    return $switches;
  }

  /**
   * Every coil, with the flipper windings resolved into settings.
   */
  private function expandCoils(array $devices): array {
    $flipperByCoil = [];
    foreach ($devices['flippers'] as $flipper) {
      $flipperByCoil[$flipper['powerCoil']] = ['flipper' => $flipper, 'winding' => 'power'];
      $flipperByCoil[$flipper['holdCoil']] = ['flipper' => $flipper, 'winding' => 'hold'];
    }

    $coils = [];
    foreach ($devices['coils'] as $coil) {
      $winding = $flipperByCoil[$coil['number']] ?? NULL;
      if ($winding === NULL) {
        $coils[] = $coil + [
          'power' => DeviceDefaults::power($coil['class']),
          'maxPulseTime' => DeviceDefaults::MAX_PULSE_TIME_MS,
          'holdWinding' => FALSE,
          'role' => 'coil',
        ];
        continue;
      }

      $flipper = $winding['flipper'];
      $isHold = $winding['winding'] === 'hold';
      $coils[] = array_merge($coil, [
        'power' => $isHold ? DeviceDefaults::FLIPPER_HOLD : DeviceDefaults::FLIPPER_POWER,
        // The hold winding is wound to sit energised, so it gets no bound and
        // says so with holdWinding instead.
        'maxPulseTime' => $isHold ? 0 : DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS,
        'holdWinding' => $isHold,
        'fastFlipSwitch' => $flipper['buttonSwitch'],
        'location' => DeviceDataParser::LOCATION_PLAYFIELD,
        'role' => $isHold ? 'flipperHold' : 'flipperPower',
        'flipper' => $flipper['name'],
      ]);
    }
    return $coils;
  }

  /**
   * Coils that must share a board with a switch, grouped by that switch.
   */
  private function fastFlipGroups(array $switches, array $coils): array {
    $switchByNumber = [];
    foreach ($switches as $switch) {
      $switchByNumber[$switch['number']] = $switch;
    }

    $groups = [];
    foreach ($coils as $coil) {
      $number = $coil['fastFlipSwitch'] ?? NULL;
      if ($number === NULL || !isset($switchByNumber[$number])) {
        continue;
      }
      if (!isset($groups[$number])) {
        $groups[$number] = [
          'switches' => [$switchByNumber[$number]],
          'coils' => [],
          // The switch decides the group's location: it is the thing that must
          // be next to the coil, and a flipper button is deliberately marked
          // playfield for this reason.
          'location' => $this->groupOf($switchByNumber[$number]['location']),
        ];
      }
      $groups[$number]['coils'][] = $coil;
    }

    // A flipper's EOS is not a fast-flip switch, but it belongs to the same
    // finger and there is no reason to put it on another board.
    foreach ($groups as $number => &$group) {
      $flipper = $group['switches'][0]['flipper'] ?? NULL;
      if ($flipper === NULL) {
        continue;
      }
      foreach ($switches as $switch) {
        if (($switch['role'] ?? '') === 'flipperEos' && ($switch['flipper'] ?? NULL) === $flipper) {
          $group['switches'][] = $switch;
        }
      }
    }
    unset($group);

    return array_values($groups);
  }

  /**
   * One LED stripe per role, each on its own board's LED pin.
   */
  private function allocateStripes(array $devices): array {
    $roles = [
      ['role' => 'Lamp', 'label' => 'Lamps', 'leds' => $devices['lamps']],
      ['role' => 'Flasher', 'label' => 'Flashers', 'leds' => $devices['flashers']],
      ['role' => 'GI', 'label' => 'GI', 'leds' => $devices['gi']],
    ];

    $stripes = [];
    foreach ($roles as $role) {
      if (!$role['leds']) {
        continue;
      }
      $board = $this->boardWithFreeLedPin();
      $capacity = $this->capacityOf($board);
      $board['ledUsed'] = TRUE;
      $this->boards[$board['index']] = $board;

      $leds = [];
      $position = 0;
      foreach ($role['leds'] as $led) {
        $leds[] = [
          'number' => $led['number'],
          'description' => $led['description'],
          'role' => $role['role'],
          'position' => $position++,
        ];
      }

      $stripes[] = [
        'label' => $role['label'],
        'board' => $board['index'],
        'pin' => $capacity->ledPin(),
        'role' => $role['role'],
        'leds' => $leds,
      ];
    }

    if ($stripes) {
      $this->notes[] =
        'LED string positions are in matrix order. That is a starting point, not '
        . 'the wiring: reorder them to match how the string actually runs around '
        . 'the playfield.';
    }

    return $stripes;
  }

  private function placeSwitch(array &$board, array $switch): array {
    $pin = array_shift($board['freeInputs']);
    $this->boards[$board['index']] = $board;
    return $switch + ['board' => $board['index'], 'pin' => $pin];
  }

  private function placeCoil(array &$board, array $coil): array {
    $pin = array_shift($board['freeOutputs']);
    $this->boards[$board['index']] = $board;
    return $coil + ['board' => $board['index'], 'pin' => $pin];
  }

  /**
   * An existing board of this group and type with room, or a new one.
   */
  private function &boardWithRoom(string $group, string $type, int $inputs, int $outputs): array {
    foreach ($this->boards as $index => $board) {
      if ($board['group'] !== $group || $board['type'] !== $type) {
        continue;
      }
      if (count($board['freeInputs']) >= $inputs && count($board['freeOutputs']) >= $outputs) {
        return $this->boards[$index];
      }
    }
    $index = $this->addBoard($group, $type);
    return $this->boards[$index];
  }

  /**
   * A board whose LED pin is still free, preferring playfield boards.
   */
  private function boardWithFreeLedPin(): array {
    foreach ([self::GROUP_PLAYFIELD, self::GROUP_CABINET] as $group) {
      foreach ($this->boards as $board) {
        if ($board['group'] === $group && !$board['ledUsed'] && $this->capacityOf($board)->ledPin() !== NULL) {
          return $board;
        }
      }
    }
    return $this->boards[$this->addBoard(self::GROUP_PLAYFIELD, BoardCapacity::IO_16_8_1)];
  }

  private function addBoard(string $group, string $type): int {
    $index = count($this->boards) + 1;
    $capacity = new BoardCapacity($type, $this->mappingFor($type));
    $this->boards[$index] = [
      'index' => $index,
      'group' => $group,
      'type' => $type,
      'freeInputs' => $capacity->inputPins(),
      'freeOutputs' => $capacity->outputPins(),
      'ledUsed' => FALSE,
      'description' => '',
    ];
    return $index;
  }

  private function capacityOf(array $board): BoardCapacity {
    return new BoardCapacity($board['type'], $this->mappingFor($board['type']));
  }

  private function mappingFor(string $type): array {
    return $type === BoardCapacity::OPTO_16 ? $this->optoGpioMapping : $this->ioGpioMapping;
  }

  /**
   * Backbox devices ride on the cabinet board rather than one of their own.
   */
  private function groupOf(string $location): string {
    return $location === DeviceDataParser::LOCATION_PLAYFIELD
      ? self::GROUP_PLAYFIELD
      : self::GROUP_CABINET;
  }

  /**
   * Names boards after their group, numbering within it.
   */
  private function nameBoards(): void {
    $counters = [];
    foreach ($this->boards as $index => $board) {
      $group = $board['group'];
      $counters[$group] = ($counters[$group] ?? 0) + 1;
      $label = $group === self::GROUP_CABINET ? 'Cabinet' : 'Playfield';
      $this->boards[$index]['description'] = sprintf('%s %d (%s)', $label, $counters[$group], $board['type']);
    }
  }

}

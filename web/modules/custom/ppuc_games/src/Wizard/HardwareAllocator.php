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
    //
    // The boards a group needs are created before any of its coils are placed.
    // Creating them on demand instead fills each board to the brim and leaves
    // the last one holding the remainder - one coil, on a board that then looks
    // like it is there for spare IO. The count is the same either way; only the
    // distribution changes.
    // 2. Everything in the cabinet, before any playfield coil. The cabinet's
    // boards - and therefore its spare outputs - have to be known before
    // deciding how many playfield boards to add, and cabinet switches are what
    // create most of those boards.
    foreach ($coils as $coil) {
      if (isset($assignedCoils[$coil['number']]) || $this->groupOf($coil['location']) !== self::GROUP_CABINET) {
        continue;
      }
      $board = $this->boardWithRoom(self::GROUP_CABINET, BoardCapacity::IO_16_8_1, 0, 1);
      $assignedCoils[$coil['number']] = $this->placeCoil($board, $coil);
    }

    foreach ($switches as $switch) {
      if (isset($assignedSwitches[$switch['number']])
          || $switch['opto']
          || $this->groupOf($switch['location']) !== self::GROUP_CABINET) {
        continue;
      }
      $board = $this->boardWithRoom(self::GROUP_CABINET, BoardCapacity::IO_16_8_1, 1, 0);
      $assignedSwitches[$switch['number']] = $this->placeSwitch($board, $switch);
    }

    $this->reserveBoardsForCoils($coils, $assignedCoils);
    foreach ($coils as $coil) {
      if (isset($assignedCoils[$coil['number']])) {
        continue;
      }
      $board = $this->boardForPlayfieldCoil($coil);
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
    $this->reportBoardsCarryingNoDevices($assignedSwitches, $assignedCoils);

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

  /**
   * Says so when a board ended up holding nothing but an LED stripe.
   *
   * Every board has exactly one LED connector, so three stripes need three
   * boards. On a machine with few enough switches and coils to fit on fewer
   * than that, the extra boards exist for their connector alone - which is a
   * board taking up space under the playfield for one wire, and worth saying
   * out loud rather than leaving to be discovered during the build.
   */
  private function reportBoardsCarryingNoDevices(array $switches, array $coils): void {
    $carrying = [];
    foreach (array_merge(array_values($switches), array_values($coils)) as $device) {
      $carrying[$device['board']] = TRUE;
    }

    foreach ($this->boards as $board) {
      if (isset($carrying[$board['index']])) {
        continue;
      }
      $this->notes[] = sprintf(
        'Board %d ("%s") carries no switches or coils - it is there for its LED '
        . 'connector, since each board has only one. Moving those LEDs onto '
        . 'another string would save a board.',
        $board['index'],
        $board['description']
      );
    }
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
   * Creates the playfield boards the remaining coils will need.
   *
   * Only creates what is needed, and counts spare cabinet outputs against the
   * total first: a board under a playfield costs space that is not there, while
   * a coil driven from a spare output in the cabinet costs a wire. See
   * borrowableCabinetOutputs().
   */
  private function reserveBoardsForCoils(array $coils, array $assigned): void {
    $remaining = 0;
    $relocatable = 0;
    foreach ($coils as $coil) {
      if (isset($assigned[$coil['number']]) || $this->groupOf($coil['location']) !== self::GROUP_PLAYFIELD) {
        continue;
      }
      $remaining++;
      if ($this->isRelocatable($coil)) {
        $relocatable++;
      }
    }
    if ($remaining < 1) {
      return;
    }

    $free = 0;
    foreach ($this->boards as $board) {
      if ($board['group'] === self::GROUP_PLAYFIELD && $board['type'] === BoardCapacity::IO_16_8_1) {
        $free += count($board['freeOutputs']);
      }
    }

    $perBoard = count((new BoardCapacity(BoardCapacity::IO_16_8_1, $this->ioGpioMapping))->outputPins());
    if ($perBoard < 1) {
      return;
    }

    $borrowed = min($this->borrowableCabinetOutputs(), $relocatable, max(0, $remaining - $free));
    $additional = (int) ceil(max(0, $remaining - $free - $borrowed) / $perBoard);
    for ($i = 0; $i < $additional; $i++) {
      $this->addBoard(self::GROUP_PLAYFIELD, BoardCapacity::IO_16_8_1);
    }
  }

  /**
   * A board for one playfield coil, borrowing a cabinet output if it must.
   *
   * Playfield boards first. Only when none has an output left does a coil go to
   * a spare output in the cabinet, and only a coil that can stand the wire run.
   */
  private function &boardForPlayfieldCoil(array $coil): array {
    foreach ($this->boards as $index => $board) {
      if ($board['group'] === self::GROUP_PLAYFIELD
          && $board['type'] === BoardCapacity::IO_16_8_1
          && $board['freeOutputs']) {
        return $this->boardWithRoom(self::GROUP_PLAYFIELD, BoardCapacity::IO_16_8_1, 0, 1);
      }
    }

    if ($this->isRelocatable($coil)) {
      foreach ($this->boards as $index => $board) {
        if ($board['group'] === self::GROUP_CABINET
            && $board['type'] === BoardCapacity::IO_16_8_1
            && $board['freeOutputs']) {
          $this->notes[] = sprintf(
            '"%s" (coil %d) is driven from board %d in the cabinet, using an output that '
            . 'was going spare. Run the wires to the playfield rather than adding a board: '
            . 'it has no fast-flip switch, so nothing is waiting on it locally.',
            $coil['description'],
            $coil['number'],
            $board['index']
          );
          return $this->boards[$index];
        }
      }
    }

    return $this->boardWithRoom(self::GROUP_PLAYFIELD, BoardCapacity::IO_16_8_1, 0, 1);
  }

  /**
   * Whether this coil can be driven from the cabinet instead.
   *
   * Only a coil with no fast-flip switch. One that has a fast-flip switch has
   * to be on the board that owns the switch, or the board cannot react to it
   * locally and the whole arrangement is pointless - and that covers every
   * flipper winding, since both are driven by the button.
   */
  private function isRelocatable(array $coil): bool {
    return ($coil['fastFlipSwitch'] ?? NULL) === NULL;
  }

  /**
   * Spare outputs on cabinet boards that already exist.
   *
   * Never creates a cabinet board to hold playfield coils: the point is to use
   * outputs that are already going spare, not to move the problem.
   */
  private function borrowableCabinetOutputs(): int {
    $spare = 0;
    foreach ($this->boards as $board) {
      if ($board['group'] === self::GROUP_CABINET && $board['type'] === BoardCapacity::IO_16_8_1) {
        $spare += count($board['freeOutputs']);
      }
    }
    return $spare;
  }

  /**
   * The emptiest board of this group and type with room, or a new one.
   *
   * Emptiest rather than first-fit, which matters for two reasons. Space under a
   * playfield is tight, so the wizard must never add a board that is not needed
   * - and it does not: a new one appears only when no existing board has room
   * at all, which is the same condition either way, so the board count is the
   * minimum for the device counts.
   *
   * What changes is how full each one is. First-fit filled boards to the brim in
   * order and left the last with whatever was left over - one coil, on a board
   * that then looked like it was there for spare IO. Spreading the load gives
   * every board the same headroom for the device somebody adds later, at no cost
   * in boards.
   */
  private function &boardWithRoom(string $group, string $type, int $inputs, int $outputs): array {
    $best = NULL;
    $bestFree = -1;
    foreach ($this->boards as $index => $board) {
      if ($board['group'] !== $group || $board['type'] !== $type) {
        continue;
      }
      if (count($board['freeInputs']) < $inputs || count($board['freeOutputs']) < $outputs) {
        continue;
      }
      // Rank by whichever resource is being asked for; outputs are the scarcer
      // of the two, so they decide when both are wanted.
      $free = $outputs > 0 ? count($board['freeOutputs']) : count($board['freeInputs']);
      if ($free > $bestFree) {
        $best = $index;
        $bestFree = $free;
      }
    }

    if ($best !== NULL) {
      return $this->boards[$best];
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

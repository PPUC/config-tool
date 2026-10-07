<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\BoardCapacity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The board types beyond IO_16_8_1: their pin maps and what goes on which pin.
 *
 * A pin map is described twice here - default content, which is how it
 * reaches a site, and boards/*.php - and a third time by the firmware, in
 * io-boards/src/PPUCBoardTypes.h. A wrong entry does not fail loudly: the
 * exporter turns the pin into a GPIO and the board drives whatever is there.
 * The GPIO numbers below are the ones traced from each board's KiCad
 * schematic, so every description is held to the hardware rather than to each
 * other.
 */
#[Group('ppuc_games')]
class BoardTypesTest extends TestCase {

  private const DEFAULT_CONTENT = __DIR__ . '/../../../../../../sites/default/files/default_content/taxonomy_term/';
  private const BOARDS = __DIR__ . '/../../../../../../../boards/';

  private const IO_16X8_MATRIX_UUID = '4956761c-7e66-401e-9385-7bef42686b21';
  private const OUT_8X10_UUID = 'eb230001-3f6b-46a9-b6d3-114fa0aa8c55';
  private const OPTO_16_UUID = 'f453b0b1-b024-4ab8-80fd-e6c734c045bf';

  /**
   * Connector pin to GPIO for an IO_16x8_matrix.
   *
   * The 16 inputs are on the same GPIOs as an IO_16_8_1's, but the 8 signal
   * outputs run the other way: Out_1 is GPIO 27 descending to Out_8 on GPIO
   * 19, skipping GPIO 25, the on-board LED. Pin 25 is the WS2812 connector.
   *
   * @return array<int, int>
   */
  private static function io16x8MatrixMapping(): array {
    $mapping = [];
    for ($pin = 1; $pin <= 16; $pin++) {
      $mapping[$pin] = 2 + $pin;
    }
    foreach ([27, 26, 24, 23, 22, 21, 20, 19] as $index => $gpio) {
      $mapping[17 + $index] = $gpio;
    }
    $mapping[25] = 29;
    return $mapping;
  }

  /**
   * Connector pin to GPIO for an Out_8x10.
   *
   * Pins 1-10 are the low-side switches Lo_1..Lo_10 (GPIO 12 descending to
   * 3), pins 11-18 the high-side switches Hi_1..Hi_8 (GPIO 24 descending to
   * 17), and pin 25 the WS2812 connector.
   *
   * @return array<int, int>
   */
  private static function out8x10Mapping(): array {
    $mapping = [];
    for ($pin = 1; $pin <= 10; $pin++) {
      $mapping[$pin] = 13 - $pin;
    }
    for ($pin = 11; $pin <= 18; $pin++) {
      $mapping[$pin] = 35 - $pin;
    }
    $mapping[25] = 29;
    return $mapping;
  }

  /**
   * Connector pin to GPIO for an Opto_16: 16 inputs and the WS2812 connector.
   *
   * @return array<int, int>
   */
  private static function opto16Mapping(): array {
    $mapping = [];
    for ($pin = 1; $pin <= 16; $pin++) {
      $mapping[$pin] = 2 + $pin;
    }
    $mapping[25] = 29;
    return $mapping;
  }

  /**
   * @return array<int, int>
   */
  private function defaultContentMapping(string $uuid, string $name): array {
    $path = self::DEFAULT_CONTENT . $uuid . '.json';
    $this->assertFileExists($path, "the $name default content file has moved or been removed");
    $term = json_decode(file_get_contents($path), TRUE);
    $this->assertSame($name, $term['name'][0]['value']);
    $this->assertSame('i_o_board', $term['vid'][0]['target_id']);
    $this->assertSame($uuid, $term['uuid'][0]['value']);
    return unserialize($term['field_gpio_mapping'][0]['value'], ['allowed_classes' => FALSE]);
  }

  /**
   * The mapping boards/<type>.php would print, without running its var_dump.
   *
   * @return array<int, int>
   */
  private function boardFileMapping(string $type): array {
    $source = file_get_contents(self::BOARDS . $type . '.php');
    $this->assertSame(1, preg_match('/\$mapping\s*=\s*\[(.*?)\];/s', $source, $match), "boards/$type.php has no \$mapping");
    $body = preg_replace('~//.*$~m', '', $match[1]);
    preg_match_all('/(\d+)\s*=>\s*(\d+)/', $body, $pairs, PREG_SET_ORDER);
    $mapping = [];
    foreach ($pairs as $pair) {
      $mapping[(int) $pair[1]] = (int) $pair[2];
    }
    return $mapping;
  }

  /**
   * Out_1 is GPIO 27 descending to Out_8 on GPIO 19, skipping the LED on 25.
   */
  public function testIo16x8MatrixPinMapMatchesTheHardware(): void {
    $mapping = self::io16x8MatrixMapping();

    $this->assertCount(25, $mapping);
    for ($pin = 1; $pin <= 16; $pin++) {
      $this->assertSame(2 + $pin, $mapping[$pin], "input pin $pin is on the wrong GPIO");
    }
    $this->assertSame(
      [17 => 27, 18 => 26, 19 => 24, 20 => 23, 21 => 22, 22 => 21, 23 => 20, 24 => 19],
      array_intersect_key($mapping, array_flip(range(17, 24))),
      'the outputs run downwards on this board, unlike the IO_16_8_1'
    );
    $this->assertSame(29, $mapping[25]);
    $this->assertNotContains(25, $mapping, 'GPIO 25 is the on-board LED');
  }

  /**
   * Lo_1 is GPIO 12 descending to 3, Hi_1 is GPIO 24 descending to 17.
   */
  public function testOut8x10PinMapMatchesTheHardware(): void {
    $mapping = self::out8x10Mapping();

    $this->assertCount(19, $mapping);
    $this->assertSame([12, 11, 10, 9, 8, 7, 6, 5, 4, 3], array_values(array_intersect_key($mapping, array_flip(range(1, 10)))));
    $this->assertSame([24, 23, 22, 21, 20, 19, 18, 17], array_values(array_intersect_key($mapping, array_flip(range(11, 18)))));
    $this->assertSame(29, $mapping[25]);
    // The test points that used to be listed as pins 19-24.
    foreach (range(19, 24) as $pin) {
      $this->assertArrayNotHasKey($pin, $mapping, "pin $pin is a test point, not an output");
    }
    $this->assertSame(count($mapping), count(array_unique($mapping)), 'two pins share a GPIO');
  }

  public function testEveryDescriptionOfABoardAgrees(): void {
    $this->assertSame(
      self::io16x8MatrixMapping(),
      $this->defaultContentMapping(self::IO_16X8_MATRIX_UUID, 'IO_16x8_matrix')
    );
    $this->assertSame(self::io16x8MatrixMapping(), $this->boardFileMapping('IO_16x8_matrix'));

    $this->assertSame(
      self::out8x10Mapping(),
      $this->defaultContentMapping(self::OUT_8X10_UUID, 'Out_8x10')
    );
    $this->assertSame(self::out8x10Mapping(), $this->boardFileMapping('Out_8x10'));

    $this->assertSame(
      self::opto16Mapping(),
      $this->defaultContentMapping(self::OPTO_16_UUID, 'Opto_16')
    );
    $this->assertSame(self::opto16Mapping(), $this->boardFileMapping('Opto_16'));
  }

  /**
   * @return array<string, array{string, string, int, bool, bool}>
   */
  public static function pinRoles(): array {
    return [
      // type, bundle, pin, isLamp, allowed.
      'IO_16_8_1 switch on an input' => [BoardCapacity::IO_16_8_1, 'switch', 16, FALSE, TRUE],
      'IO_16_8_1 switch on an output' => [BoardCapacity::IO_16_8_1, 'switch', 17, FALSE, FALSE],
      'IO_16_8_1 coil on an output' => [BoardCapacity::IO_16_8_1, 'pwm_device', 24, FALSE, TRUE],
      'IO_16_8_1 lamp on an input, a low-power output' => [BoardCapacity::IO_16_8_1, 'pwm_device', 3, TRUE, TRUE],
      'IO_16_8_1 coil on the LED connector' => [BoardCapacity::IO_16_8_1, 'pwm_device', 25, FALSE, FALSE],
      'IO_16_8_1 LED string on the LED connector' => [BoardCapacity::IO_16_8_1, 'addressable_leds', 25, FALSE, TRUE],
      'IO_16_8_1 LED string on an output' => [BoardCapacity::IO_16_8_1, 'addressable_leds', 17, FALSE, FALSE],

      'Opto_16 switch' => [BoardCapacity::OPTO_16, 'switch', 1, FALSE, TRUE],
      'Opto_16 has no outputs' => [BoardCapacity::OPTO_16, 'pwm_device', 1, TRUE, FALSE],
      'Opto_16 LED string' => [BoardCapacity::OPTO_16, 'addressable_leds', 25, FALSE, TRUE],

      'IO_16x8_matrix switch' => [BoardCapacity::IO_16X8_MATRIX, 'switch', 16, FALSE, TRUE],
      'IO_16x8_matrix signal output' => [BoardCapacity::IO_16X8_MATRIX, 'pwm_device', 17, TRUE, TRUE],
      'IO_16x8_matrix inputs are not outputs' => [BoardCapacity::IO_16X8_MATRIX, 'pwm_device', 16, TRUE, FALSE],
      'IO_16x8_matrix switch on an output' => [BoardCapacity::IO_16X8_MATRIX, 'switch', 17, FALSE, FALSE],

      'Out_8x10 lamp on a low-side switch' => [BoardCapacity::OUT_8X10, 'pwm_device', 1, TRUE, TRUE],
      'Out_8x10 lamp on a high-side switch' => [BoardCapacity::OUT_8X10, 'pwm_device', 18, TRUE, TRUE],
      'Out_8x10 coil' => [BoardCapacity::OUT_8X10, 'pwm_device', 1, FALSE, FALSE],
      'Out_8x10 lamp on a former test point' => [BoardCapacity::OUT_8X10, 'pwm_device', 19, TRUE, FALSE],
      'Out_8x10 has no inputs' => [BoardCapacity::OUT_8X10, 'switch', 1, FALSE, FALSE],
      'Out_8x10 LED string' => [BoardCapacity::OUT_8X10, 'addressable_leds', 25, FALSE, TRUE],
    ];
  }

  #[DataProvider('pinRoles')]
  public function testADeviceOnlyGoesOnAPinOfItsKind(string $type, string $bundle, int $pin, bool $isLamp, bool $allowed): void {
    $mappings = [
      BoardCapacity::IO_16_8_1 => $this->boardFileMapping('IO_16_8_1'),
      BoardCapacity::OPTO_16 => self::opto16Mapping(),
      BoardCapacity::IO_16X8_MATRIX => self::io16x8MatrixMapping(),
      BoardCapacity::OUT_8X10 => self::out8x10Mapping(),
    ];

    $refusal = (new BoardCapacity($type, $mappings[$type]))->refusal($bundle, $pin, $isLamp);

    if ($allowed) {
      $this->assertNull($refusal);
    }
    else {
      $this->assertIsString($refusal);
      $this->assertStringContainsString($type, $refusal);
    }
  }

  /**
   * The wizard asks for output pins to put coils on. Out_8x10 must offer none.
   */
  public function testTheWizardCanNeverPutACoilOnALampBoard(): void {
    $out = new BoardCapacity(BoardCapacity::OUT_8X10, self::out8x10Mapping());
    $this->assertSame([], $out->outputPins());
    $this->assertSame([], $out->inputPins());
    $this->assertSame(25, $out->ledPin());

    $matrix = new BoardCapacity(BoardCapacity::IO_16X8_MATRIX, self::io16x8MatrixMapping());
    $this->assertSame(range(1, 16), $matrix->inputPins());
    $this->assertSame(range(17, 24), $matrix->outputPins());
    $this->assertSame(27, $matrix->gpio(17));
    $this->assertSame(19, $matrix->gpio(24));
  }

  public function testOnlyKnownBoardTypesAreNamedInAnExport(): void {
    foreach (['IO_16_8_1', 'IO_16x8_matrix', 'Out_8x10', 'Opto_16'] as $type) {
      $this->assertTrue(BoardCapacity::knows($type), "$type is a ppuc::v2::BoardTypeName()");
    }
    // A board type a site added to the taxonomy by hand. libppuc would refuse
    // to load a game that named it.
    $this->assertFalse(BoardCapacity::knows('My_Custom_Board'));
    $this->assertFalse(BoardCapacity::knows('io_16_8_1'));
  }

  /**
   * IO_16_8_1 keeps its 4 column matrix; the wide one belongs to its own board.
   */
  public function testEachMatrixBelongsToItsBoard(): void {
    $this->assertNull(BoardCapacity::matrixRefusal('IO_16_8_1', 'switch', 4));
    $this->assertNull(BoardCapacity::matrixRefusal('IO_16_8_1', 'switch', 8));
    $this->assertStringContainsString('4 or 8 rows', BoardCapacity::matrixRefusal('IO_16_8_1', 'switch', 16));
    $this->assertSame(32, BoardCapacity::matrixPositions('IO_16_8_1', 'switch', 8));

    $this->assertNull(BoardCapacity::matrixRefusal('IO_16x8_matrix', 'switch', 16));
    $this->assertNull(BoardCapacity::matrixRefusal('IO_16x8_matrix', 'switch', 10));
    $this->assertStringContainsString('1 to 16 rows', BoardCapacity::matrixRefusal('IO_16x8_matrix', 'switch', 17));
    $this->assertSame(128, BoardCapacity::matrixPositions('IO_16x8_matrix', 'switch', 16));
    $this->assertSame(64, BoardCapacity::matrixPositions('IO_16x8_matrix', 'switch', 8));

    $this->assertNull(BoardCapacity::matrixRefusal('Out_8x10', 'lamp', 10));
    $this->assertStringContainsString('1 to 10 rows', BoardCapacity::matrixRefusal('Out_8x10', 'lamp', 11));
    $this->assertStringContainsString('1 to 10 rows', BoardCapacity::matrixRefusal('Out_8x10', 'lamp', 0));
    $this->assertSame(80, BoardCapacity::matrixPositions('Out_8x10', 'lamp', 10));
    $this->assertSame(64, BoardCapacity::matrixPositions('Out_8x10', 'lamp', 8));

    foreach (['Opto_16', 'Out_8x10'] as $type) {
      $this->assertStringContainsString('cannot run a switch matrix', BoardCapacity::matrixRefusal($type, 'switch', 8));
      $this->assertSame(0, BoardCapacity::matrixPositions($type, 'switch', 8));
    }
    foreach (['IO_16_8_1', 'IO_16x8_matrix', 'Opto_16'] as $type) {
      $refusal = BoardCapacity::matrixRefusal($type, 'lamp', 8);
      $this->assertStringContainsString('cannot run a lamp matrix', $refusal);
      $this->assertStringContainsString('Out_8x10', $refusal);
    }
  }

  /**
   * The section libppuc reads: description, board, rows, and lamps by position.
   */
  public function testTheLampMatrixExportIsWhatLibppucValidates(): void {
    $controller = (new \ReflectionClass(\Drupal\ppuc_games\Controller\GamesController::class))
      ->newInstanceWithoutConstructor();
    $method = (new \ReflectionClass($controller))->getMethod('buildLampMatrixYaml');
    $method->setAccessible(TRUE);

    $yaml = $method->invoke($controller, 'Playfield lamps', 4, 8, [
      ['description' => 'Shoot Again', 'number' => 28, 'port' => 9],
      ['description' => 'Left Rollover', 'number' => 11, 'port' => 0],
    ]);

    $this->assertSame(['description', 'board', 'rows', 'lamps'], array_keys($yaml));
    $this->assertSame(4, $yaml['board']);
    $this->assertSame(8, $yaml['rows']);
    $this->assertSame([0, 9], array_column($yaml['lamps'], 'port'), 'lamps are exported in matrix order');
    foreach ($yaml['lamps'] as $lamp) {
      $this->assertSame(['description', 'number', 'port'], array_keys($lamp));
    }
  }

  /**
   * A content type that exists in the module must exist in the config too.
   */
  public function testTheLampMatrixContentTypesAreInTheConfig(): void {
    $sync = __DIR__ . '/../../../../../../sites/default/files/sync/';
    foreach ([
      'node.type.lamp_matrix.yml',
      'node.type.lamp_matrix_lamp.yml',
      'field.storage.node.field_lamp_matrix.yml',
      'field.field.node.lamp_matrix.field_i_o_board.yml',
      'field.field.node.lamp_matrix.field_rows.yml',
      'field.field.node.lamp_matrix_lamp.field_lamp_matrix.yml',
      'field.field.node.lamp_matrix_lamp.field_position.yml',
      'field.field.node.lamp_matrix_lamp.field_number.yml',
    ] as $file) {
      $this->assertFileExists($sync . $file);
    }
  }

}

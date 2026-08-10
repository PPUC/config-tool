<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Opto_16 board type is described twice, and the two must agree.
 *
 * Default content covers a fresh install. An existing site cannot be reached
 * that way: the import runs with --preserve-ids, and the file carries a term id
 * that any site with a hand-created taxonomy term has already used, in which
 * case the importer skips the entity without saying so. The update hook exists
 * for those sites.
 *
 * Two descriptions of one board is a drift risk, and a wrong pin map does not
 * fail loudly - it allocates a switch to a pin that is not there.
 */
#[Group('ppuc_games')]
class Opto16BoardTypeTest extends TestCase {

  private const MODULE = __DIR__ . '/../../../';
  private const DEFAULT_CONTENT = __DIR__ . '/../../../../../../sites/default/files/default_content/taxonomy_term/';

  protected function setUp(): void {
    parent::setUp();
    require_once self::MODULE . 'ppuc_games.install';
  }

  private function defaultContentTerm(): array {
    $path = self::DEFAULT_CONTENT . PPUC_GAMES_OPTO_16_UUID . '.json';
    $this->assertFileExists($path, 'the Opto_16 default content file has moved or been removed');
    return json_decode(file_get_contents($path), TRUE);
  }

  public function testTheDefaultContentAndTheUpdateHookDescribeTheSameBoard(): void {
    $term = $this->defaultContentTerm();

    $fromFile = unserialize($term['field_gpio_mapping'][0]['value'], ['allowed_classes' => FALSE]);
    $fromHook = _ppuc_games_opto_16_gpio_mapping();

    $this->assertSame($fromHook, $fromFile, 'the two descriptions of Opto_16 have drifted apart');
    $this->assertSame('Opto_16', $term['name'][0]['value']);
    $this->assertSame('i_o_board', $term['vid'][0]['target_id']);
    $this->assertSame(PPUC_GAMES_OPTO_16_UUID, $term['uuid'][0]['value'],
      'a different uuid would let a default content import create a second board type');
  }

  /**
   * 16 inputs on the same GPIOs as an IO_16_8_1, plus the LED connector.
   */
  public function testThePinMapMatchesTheHardware(): void {
    $mapping = _ppuc_games_opto_16_gpio_mapping();

    $this->assertCount(17, $mapping);
    for ($pin = 1; $pin <= 16; $pin++) {
      $this->assertArrayHasKey($pin, $mapping);
      $this->assertSame(2 + $pin, $mapping[$pin], "input pin $pin is on the wrong GPIO");
    }
    // Pin 25 is the WS2812 connector, GPIO 29, on every board type.
    $this->assertSame(29, $mapping[25]);
    // No outputs: the board has no PWM drivers.
    foreach (range(17, 24) as $outputPin) {
      $this->assertArrayNotHasKey($outputPin, $mapping, "pin $outputPin would look like a coil output");
    }
  }

  /**
   * A term id in the file is only a hint; a taken one makes the import skip the
   * whole entity. The update hook must not depend on it.
   */
  public function testTheUpdateHookDoesNotDependOnTheTermId(): void {
    $source = file_get_contents(self::MODULE . 'ppuc_games.install');

    $this->assertStringNotContainsString("'tid'", $source);
    $this->assertStringContainsString('uuid', $source);
  }

}

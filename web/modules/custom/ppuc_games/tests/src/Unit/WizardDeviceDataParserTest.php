<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\DeviceDataParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The last point at which a bad transcription can be refused for free.
 *
 * After this, ~150 nodes exist. So these are mostly about rejection: the parser
 * is more useful for what it refuses than for what it accepts, and a message
 * that does not name the offending entry is no use against a 150-line document.
 */
#[CoversClass(DeviceDataParser::class)]
#[Group('ppuc_games')]
class WizardDeviceDataParserTest extends TestCase {

  private DeviceDataParser $parser;

  protected function setUp(): void {
    parent::setUp();
    $this->parser = new DeviceDataParser();
  }

  /**
   * A minimal document that parses, for tests to break in one specific way.
   */
  private static function document(array $overrides = []): array {
    return $overrides + [
      'game' => ['title' => 'Dirty Harry', 'platform' => 'WPC', 'rom' => 'dh_lx2'],
      'switches' => [
        ['number' => 11, 'description' => 'Gun Handle Trigger'],
        ['number' => 61, 'description' => 'Left Sling'],
      ],
      'coils' => [
        ['number' => 1, 'description' => 'Ball Release', 'class' => 'highPower'],
        ['number' => 29, 'description' => 'Lower Right Power', 'class' => 'highPower'],
        ['number' => 30, 'description' => 'Lower Right Hold', 'class' => 'lowPower'],
      ],
      'flippers' => [
        ['name' => 'Lower Right', 'position' => 'lowerRight', 'powerCoil' => 29, 'holdCoil' => 30],
      ],
      'flashers' => [['number' => 17, 'description' => 'Headquarters']],
      'lamps' => [['number' => 11, 'description' => 'Left Rollover']],
      'gi' => [['number' => 1, 'description' => 'Right String']],
    ];
  }

  private function parse(array $document): ?array {
    return $this->parser->parse(json_encode($document));
  }

  private function assertRejectedMentioning(array $document, string $needle): void {
    $this->assertNull($this->parse($document), 'expected the document to be rejected');
    $errors = implode("\n", $this->parser->errors());
    $this->assertStringContainsString($needle, $errors);
  }

  public function testAValidDocumentParses(): void {
    $result = $this->parse(self::document());

    $this->assertNotNull($result, implode("\n", $this->parser->errors()));
    $this->assertSame('Dirty Harry', $result['game']['title']);
    $this->assertCount(2, $result['switches']);
    $this->assertCount(3, $result['coils']);
    $this->assertCount(1, $result['flippers']);
  }

  public function testMalformedJsonIsRejected(): void {
    $this->assertNull($this->parser->parse('{not json'));
    $this->assertNotEmpty($this->parser->errors());
  }

  /**
   * A misspelled key would otherwise be dropped and the field never set.
   */
  public function testAnUnknownKeyIsRejectedRatherThanIgnored(): void {
    $document = self::document();
    $document['coils'][0]['maxPulse'] = 100;

    $this->assertRejectedMentioning($document, 'maxPulse');
  }

  public function testAnUnknownSectionIsRejected(): void {
    $document = self::document(['sounds' => []]);

    $this->assertRejectedMentioning($document, 'sounds');
  }

  /**
   * Two switches with one number is a config that cannot work.
   */
  public function testADuplicateSwitchNumberIsRejected(): void {
    $document = self::document();
    $document['switches'][] = ['number' => 11, 'description' => 'Something Else'];

    $this->assertRejectedMentioning($document, 'reuses switch number 11');
    $this->assertStringContainsString(
      'Gun Handle Trigger',
      implode("\n", $this->parser->errors()),
      'the message must name what already holds the number'
    );
  }

  public function testADuplicateCoilNumberIsRejected(): void {
    $document = self::document();
    $document['coils'][] = ['number' => 1, 'description' => 'Another Coil', 'class' => 'lowPower'];

    $this->assertRejectedMentioning($document, 'reuses coil number 1');
  }

  public function testADuplicateLampNumberIsRejected(): void {
    $document = self::document();
    $document['lamps'][] = ['number' => 11, 'description' => 'Also 11'];

    $this->assertRejectedMentioning($document, 'reuses number 11');
  }

  /**
   * The drive power comes from this, so it is not guessed.
   */
  public function testACoilWithoutAKnownSolenoidTypeIsRejected(): void {
    $document = self::document();
    $document['coils'][0]['class'] = 'mediumPower';

    $this->assertRejectedMentioning($document, 'mediumPower');
  }

  public function testACoilMayNotBeAFlasher(): void {
    // A flasher is an LED in a stripe, never a PWM output.
    $document = self::document();
    $document['coils'][0]['type'] = 'flasher';

    $this->assertRejectedMentioning($document, 'flasher');
  }

  public function testADirectSwitchNumberMustBeOneThePlatformDefines(): void {
    $document = self::document();
    $document['switches'][] = ['number' => 99, 'description' => 'Coin Chute', 'direct' => TRUE];

    $this->assertRejectedMentioning($document, 'not a WPC direct switch number');
  }

  public function testADirectSwitchNumberThePlatformDefinesIsAccepted(): void {
    $document = self::document();
    $document['switches'][] = ['number' => 5, 'description' => 'Service Credit/Escape', 'direct' => TRUE];

    $result = $this->parse($document);
    $this->assertNotNull($result, implode("\n", $this->parser->errors()));
    $this->assertSame(DeviceDataParser::LOCATION_CABINET, end($result['switches'])['location']);
  }

  public function testAFlipperPositionThePlatformDoesNotDefineIsRejected(): void {
    $document = self::document();
    $document['flippers'][0]['position'] = 'middleLeft';

    $this->assertRejectedMentioning($document, 'middleLeft');
  }

  /**
   * The button number is what the ROM reads, so it comes from the platform.
   */
  public function testAFlipperGetsItsPlatformButtonNumber(): void {
    $result = $this->parse(self::document());

    $this->assertSame(112, $result['flippers'][0]['buttonSwitch']);
  }

  public function testAFlipperNamingACoilThatDoesNotExistIsRejected(): void {
    $document = self::document();
    $document['flippers'][0]['holdCoil'] = 99;

    $this->assertRejectedMentioning($document, 'holdCoil 99');
  }

  /**
   * Both windings are separate outputs; one number cannot be both.
   */
  public function testAFlipperUsingOneCoilForBothWindingsIsRejected(): void {
    $document = self::document();
    $document['flippers'][0]['holdCoil'] = 29;

    $this->assertRejectedMentioning($document, 'both windings');
  }

  public function testTwoFlippersInOnePositionAreRejected(): void {
    $document = self::document();
    $document['flippers'][] = [
      'name' => 'Also Lower Right', 'position' => 'lowerRight',
      'powerCoil' => 29, 'holdCoil' => 30,
    ];

    $this->assertRejectedMentioning($document, 'reuses position');
  }

  /**
   * The allocator's whole job is putting these two on one board.
   */
  public function testAFastFlipSwitchThatDoesNotExistIsRejected(): void {
    $document = self::document();
    $document['coils'][0]['fastFlipSwitch'] = 999;

    $this->assertRejectedMentioning($document, 'switch 999 for fast flip');
  }

  public function testAFastFlipSwitchThatExistsIsAccepted(): void {
    $document = self::document();
    $document['coils'][0]['fastFlipSwitch'] = 61;

    $result = $this->parse($document);
    $this->assertNotNull($result, implode("\n", $this->parser->errors()));
    $this->assertSame(61, $result['coils'][0]['fastFlipSwitch']);
  }

  /**
   * A flipper button counts, even though it is not in the switches section.
   */
  public function testAFastFlipSwitchMayBeAFlipperButton(): void {
    $document = self::document();
    $document['coils'][1]['fastFlipSwitch'] = 112;

    $this->assertNotNull($this->parse($document), implode("\n", $this->parser->errors()));
  }

  public function testAHoldWindingMayBeDeclaredOnAnyCoil(): void {
    // Not every power/hold pair is a flipper: a trap door is one coil assembly
    // driven as two outputs.
    $document = self::document();
    $document['coils'][] = [
      'number' => 16, 'description' => 'Trap Door Hold',
      'class' => 'lowPower', 'holdWinding' => TRUE,
    ];

    $result = $this->parse($document);
    $this->assertNotNull($result, implode("\n", $this->parser->errors()));
    $this->assertTrue(end($result['coils'])['holdWinding']);
    $this->assertFalse($result['coils'][0]['holdWinding']);
  }

  public function testAnUnknownLocationIsRejected(): void {
    $document = self::document();
    $document['switches'][0]['location'] = 'apron';

    $this->assertRejectedMentioning($document, 'apron');
  }

  public function testLocationDefaultsToPlayfield(): void {
    $result = $this->parse(self::document());

    $this->assertSame(DeviceDataParser::LOCATION_PLAYFIELD, $result['switches'][0]['location']);
  }

  public function testAPlatformWithNoNumberTableIsRejected(): void {
    $document = self::document();
    $document['game']['platform'] = 'SYS11';

    $this->assertRejectedMentioning($document, 'SYS11');
  }

  public function testAMissingGameSectionIsRejected(): void {
    $document = self::document();
    unset($document['game']);

    $this->assertRejectedMentioning($document, 'game');
  }

  /**
   * Numbers arrive from JSON, and from AI extraction, as strings just as often.
   */
  public function testNumericStringsAreAccepted(): void {
    $document = self::document();
    $document['switches'][0]['number'] = '11';

    $result = $this->parse($document);
    $this->assertNotNull($result, implode("\n", $this->parser->errors()));
    $this->assertSame(11, $result['switches'][0]['number']);
  }

  public function testANonNumericNumberIsRejected(): void {
    $document = self::document();
    $document['switches'][0]['number'] = 'eleven';

    $this->assertRejectedMentioning($document, 'switches[0]');
  }

  public function testAnEntryWithoutADescriptionIsRejected(): void {
    $document = self::document();
    $document['switches'][0]['description'] = '  ';

    $this->assertRejectedMentioning($document, 'no description');
  }

  /**
   * All of them at once: one per round trip through a 150-line document is
   * unusable.
   */
  public function testEveryProblemIsReportedTogether(): void {
    $document = self::document();
    $document['switches'][0]['description'] = '';
    $document['coils'][0]['class'] = 'nonsense';

    $this->assertNull($this->parse($document));
    $this->assertGreaterThanOrEqual(2, count($this->parser->errors()));
  }

}

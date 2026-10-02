<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\DeviceDataParser;
use Drupal\ppuc_games\Wizard\ExtractionPrompt;
use Drupal\ppuc_games\Wizard\PlayfieldDiagram;
use Drupal\ppuc_games\Wizard\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Where the playfield is on a scanned location page.
 *
 * A position is a fraction of the playfield and a scan is a page with the
 * playfield somewhere on it. The corners are the only thing connecting the two,
 * so a set that is incomplete or off the image has to be refused: a marker
 * drawn from it would point confidently at the wrong part.
 */
#[CoversClass(PlayfieldDiagram::class)]
#[CoversClass(DeviceDataParser::class)]
#[Group('ppuc_games')]
class WizardPlayfieldDiagramTest extends TestCase {

  /**
   * An upright scan with a margin all round.
   */
  private const UPRIGHT = [
    'flipperLeft' => ['x' => 0.2, 'y' => 0.9],
    'flipperRight' => ['x' => 0.8, 'y' => 0.9],
    'farRight' => ['x' => 0.8, 'y' => 0.1],
    'farLeft' => ['x' => 0.2, 'y' => 0.1],
  ];

  private function parse(array $diagrams): array {
    $parser = new DeviceDataParser();
    $devices = $parser->parse(json_encode([
      'game' => ['title' => 'Test', 'platform' => 'WPC'],
      'diagrams' => $diagrams,
    ]));
    return [$devices, $parser->errors()];
  }

  public function testADocumentWithoutDiagramsIsStillAccepted(): void {
    $parser = new DeviceDataParser();
    $devices = $parser->parse(json_encode(['game' => ['title' => 'Test', 'platform' => 'WPC']]));

    $this->assertNotNull($devices, implode("\n", $parser->errors()));
    $this->assertSame([], $devices['diagrams']);
  }

  public function testCornersAreReadPerPage(): void {
    [$devices, $errors] = $this->parse(['lamps' => self::UPRIGHT]);

    $this->assertNotNull($devices, implode("\n", $errors));
    $this->assertSame(['lamps'], array_keys($devices['diagrams']));
    $this->assertSame(array_keys(PlayfieldDiagram::CORNERS), array_keys($devices['diagrams']['lamps']));
    $this->assertSame(['x' => 0.2, 'y' => 0.9], $devices['diagrams']['lamps']['flipperLeft']);
  }

  /**
   * Three corners are not an outline.
   */
  public function testAMissingCornerIsRefused(): void {
    $corners = self::UPRIGHT;
    unset($corners['farRight']);
    [$devices, $errors] = $this->parse(['switches' => $corners]);

    $this->assertNull($devices);
    $this->assertStringContainsString('diagrams.switches', $errors[0]);
    $this->assertStringContainsString('farRight', $errors[0]);
  }

  public function testACornerOffTheImageIsRefused(): void {
    $corners = self::UPRIGHT;
    $corners['farLeft']['y'] = 1.4;
    [$devices, $errors] = $this->parse(['coils' => $corners]);

    $this->assertNull($devices);
    $this->assertStringContainsString('fractions of the image', $errors[0]);
  }

  public function testAPageThatIsNotALocationPageIsRefused(): void {
    [$devices, $errors] = $this->parse(['flashers' => self::UPRIGHT]);

    $this->assertNull($devices);
    $this->assertStringContainsString('unknown key "flashers"', $errors[0]);
  }

  /**
   * y runs up the playfield and down the image, so the flipper end is low on
   * an upright scan.
   */
  public function testAPositionLandsBetweenTheCorners(): void {
    $this->assertEqualsWithDelta(
      ['x' => 0.2, 'y' => 0.9],
      PlayfieldDiagram::toImage(self::UPRIGHT, new Position(0.0, 0.0)),
      0.0001
    );
    $this->assertEqualsWithDelta(
      ['x' => 0.5, 'y' => 0.5],
      PlayfieldDiagram::toImage(self::UPRIGHT, new Position(0.5, 0.5)),
      0.0001
    );
    $this->assertEqualsWithDelta(
      ['x' => 0.65, 'y' => 0.1],
      PlayfieldDiagram::toImage(self::UPRIGHT, new Position(0.75, 1.0)),
      0.0001
    );
  }

  /**
   * The reason corners are named by playfield position: a page printed on its
   * side needs nothing else to be said about it.
   */
  public function testASidewaysScanNeedsNoSeparateOrientation(): void {
    // Flipper end at the left of the image, playfield's left edge at the top.
    $sideways = [
      'flipperLeft' => ['x' => 0.1, 'y' => 0.2],
      'flipperRight' => ['x' => 0.1, 'y' => 0.8],
      'farRight' => ['x' => 0.9, 'y' => 0.8],
      'farLeft' => ['x' => 0.9, 'y' => 0.2],
    ];

    $this->assertEqualsWithDelta(
      ['x' => 0.9, 'y' => 0.2],
      PlayfieldDiagram::toImage($sideways, new Position(0.0, 1.0)),
      0.0001
    );
    $this->assertEqualsWithDelta(
      ['x' => 0.3, 'y' => 0.5],
      PlayfieldDiagram::toImage($sideways, new Position(0.5, 0.25)),
      0.0001
    );
  }

  /**
   * The prompt names the pages and the corners; both must be the ones accepted.
   */
  public function testThePromptAsksForTheDiagramsTheParserAccepts(): void {
    $prompt = ExtractionPrompt::text();

    $this->assertSame(
      ExtractionPrompt::OPTIONAL_PAGES,
      array_column(PlayfieldDiagram::KINDS, 'page'),
      'every optional page needs a diagram kind, and the other way round'
    );
    foreach (PlayfieldDiagram::KINDS as $kind => $definition) {
      $this->assertStringContainsString(sprintf('"%s"', $kind), $prompt);
      $this->assertStringContainsString($definition['page'], $prompt);
    }
    foreach (array_keys(PlayfieldDiagram::CORNERS) as $corner) {
      $this->assertStringContainsString(sprintf('"%s"', $corner), $prompt);
    }

    $example = substr($prompt, strpos($prompt, '{', strpos($prompt, 'EXAMPLE OF THE SHAPE')));
    $devices = (new DeviceDataParser())->parse($example);
    $this->assertNotNull($devices);
    $this->assertNotEmpty($devices['diagrams'], 'the example never shows a diagram');
  }

}

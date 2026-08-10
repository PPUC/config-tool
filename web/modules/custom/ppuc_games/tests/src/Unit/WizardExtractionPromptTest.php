<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Wizard\DeviceDataParser;
use Drupal\ppuc_games\Wizard\DeviceDefaults;
use Drupal\ppuc_games\Wizard\ExtractionPrompt;
use Drupal\ppuc_games\Wizard\PlatformNumbers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The prompt has to describe the format that is actually accepted.
 *
 * It is instructions for something else to follow, so nothing catches it being
 * wrong: whoever pastes it gets JSON the wizard rejects, and has no way of
 * knowing whether the prompt or the AI was at fault. The example inside it is
 * therefore run through the real parser, and everything the prompt states as a
 * fact is checked against the constant it came from.
 */
#[CoversClass(ExtractionPrompt::class)]
#[Group('ppuc_games')]
class WizardExtractionPromptTest extends TestCase {

  /**
   * The JSON document the prompt shows as an example.
   */
  private function exampleJson(): string {
    $prompt = ExtractionPrompt::text();
    $marker = strpos($prompt, 'EXAMPLE OF THE SHAPE');
    $this->assertNotFalse($marker, 'the prompt no longer contains an example');

    $start = strpos($prompt, '{', $marker);
    $this->assertNotFalse($start);

    return substr($prompt, $start);
  }

  /**
   * The test that matters: what the prompt asks for must be accepted.
   */
  public function testTheExampleInThePromptParses(): void {
    $parser = new DeviceDataParser();
    $devices = $parser->parse($this->exampleJson());

    $this->assertNotNull($devices, sprintf(
      "the prompt shows an example the wizard rejects:\n%s",
      implode("\n", $parser->errors())
    ));
  }

  /**
   * And it must exercise the things the rules talk about, or it demonstrates
   * nothing.
   */
  public function testTheExampleShowsEveryFeatureTheRulesMention(): void {
    $devices = (new DeviceDataParser())->parse($this->exampleJson());
    $this->assertNotNull($devices);

    $switchFlags = ['opto' => FALSE, 'direct' => FALSE, 'button' => FALSE];
    $switchLocations = [];
    $switchPositions = 0;
    foreach ($devices['switches'] as $switch) {
      foreach (array_keys($switchFlags) as $flag) {
        $switchFlags[$flag] = $switchFlags[$flag] || $switch[$flag];
      }
      $switchLocations[] = $switch['location'];
      $switchPositions += $switch['position'] === NULL ? 0 : 1;
    }
    foreach ($switchFlags as $flag => $shown) {
      $this->assertTrue($shown, "the example never shows a switch with $flag");
    }
    $this->assertContains(DeviceDataParser::LOCATION_CABINET, $switchLocations);
    $this->assertGreaterThan(0, $switchPositions, 'the example never shows a position');

    $classes = array_unique(array_column($devices['coils'], 'class'));
    sort($classes);
    $this->assertSame([DeviceDefaults::CLASS_HIGH_POWER, DeviceDefaults::CLASS_LOW_POWER], $classes);

    $this->assertContains('motor', array_column($devices['coils'], 'type'));
    $this->assertNotEmpty(array_filter($devices['coils'], static fn ($c) => $c['holdWinding']));
    $this->assertNotEmpty(array_filter($devices['coils'], static fn ($c) => $c['endSwitches'] !== []));
    $this->assertNotEmpty(array_filter($devices['coils'], static fn ($c) => $c['fastFlipSwitch'] !== NULL));
    $this->assertNotEmpty(array_filter($devices['coils'],
      static fn ($c) => $c['location'] === DeviceDataParser::LOCATION_BACKBOX));

    $this->assertNotEmpty($devices['flippers']);
    $this->assertNotEmpty($devices['flashers']);
    $this->assertNotEmpty($devices['lamps']);
    $this->assertNotEmpty($devices['gi']);
  }

  /**
   * The numbers it gives for the direct switches are the ROM's, so a wrong one
   * wires the coin door to something nothing reads.
   */
  public function testThePromptGivesThePlatformsOwnDirectSwitchNumbers(): void {
    $prompt = ExtractionPrompt::text('WPC');

    foreach (PlatformNumbers::directSwitches('WPC') as $number => $name) {
      $this->assertStringContainsString(
        sprintf('%d = %s', $number, $name),
        $prompt,
        "the prompt does not give the number for $name"
      );
    }
  }

  /**
   * A platform with no table must not be handed WPC's numbers.
   */
  public function testAnUnknownPlatformIsNotGivenBorrowedNumbers(): void {
    $prompt = ExtractionPrompt::text('SYS11');

    $this->assertStringContainsString('unknown for this platform', $prompt);
    // The worked example is always the WPC one, so look at the rule that gives
    // the numbers rather than the whole prompt.
    $this->assertStringNotContainsString('5 = Service Credit/Escape', $prompt);
    $this->assertStringNotContainsString('1 = Left Coin Chute', $prompt);
  }

  /**
   * Every solenoid type the parser accepts has to be listed, and no others.
   */
  public function testThePromptListsTheSolenoidTypesTheParserAccepts(): void {
    $prompt = ExtractionPrompt::text();

    foreach (DeviceDefaults::COIL_CLASSES as $class) {
      $this->assertStringContainsString('"' . $class . '"', $prompt);
    }
  }

  /**
   * And every flipper position, since an unlisted one is rejected.
   */
  public function testThePromptListsTheFlipperPositions(): void {
    $prompt = ExtractionPrompt::text('WPC');

    foreach (PlatformNumbers::flipperPositions('WPC') as $position) {
      $this->assertStringContainsString($position, $prompt);
    }
  }

  /**
   * The instructions that stop the two mistakes that are expensive to find.
   */
  public function testThePromptSaysNotToGuessAndNotToInventFlipperSwitches(): void {
    $prompt = ExtractionPrompt::text();

    $this->assertStringContainsString('Never invent a value', $prompt);
    $this->assertStringContainsString('leave that entry', $prompt);
    // Flipper buttons and EOS numbers are the tool's to assign; one invented
    // here would collide or be wired to nothing.
    $this->assertStringContainsString('Do not create flipper button', $prompt);
    // The generic flipper column caught out a human transcriber already.
    $this->assertStringContainsString('generic template', $prompt);
  }

  /**
   * It must not ask for keys the parser rejects.
   */
  public function testThePromptOnlyNamesSectionsTheParserKnows(): void {
    $prompt = ExtractionPrompt::text();

    foreach (['game', 'switches', 'coils', 'flippers', 'flashers', 'lamps', 'gi'] as $section) {
      $this->assertStringContainsString('"' . $section . '"', $prompt);
    }
    // A section that does not exist would be rejected wholesale.
    $this->assertStringNotContainsString('"sounds"', $prompt);
    $this->assertStringNotContainsString('"switchMatrix"', $prompt);
  }

  public function testThePromptNamesThePagesToAttach(): void {
    $prompt = ExtractionPrompt::text();

    foreach (array_merge(ExtractionPrompt::REQUIRED_PAGES, ExtractionPrompt::OPTIONAL_PAGES) as $page) {
      $this->assertStringContainsString($page, $prompt);
    }
  }

}

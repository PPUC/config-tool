<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ppuc_games\Hook\GameRoleOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The per-bundle role list, which used to live in configuration and was lost.
 *
 * It was on each field instance as `allowed_values`, which worked but had no
 * config schema, so a re-save dropped it and every bundle fell back to the
 * storage union: switches offering "Knocker", coils offering "Coin chute".
 * Since GamesController::gameRoleOf() reads this field into the exported YAML,
 * that is a wrong role reaching a real machine, not only an untidy form.
 *
 * These tests pin the behaviour the configuration used to carry.
 */
#[Group('ppuc_games')]
class GameRoleOptionsTest extends TestCase {

  /**
   * Every role in the field storage, which is the union of all bundles.
   */
  private const STORAGE_OPTIONS = [
    'start' => 'Start button',
    'coin' => 'Coin chute',
    'serviceCredit' => 'Service credit',
    'trough' => 'Trough / outhole (ball is home)',
    'shooterLane' => 'Shooter lane',
    'tilt' => 'Tilt',
    'slamTilt' => 'Slam tilt',
    'tiltInhibit' => 'Tilt inhibit (host-owned, no wiring)',
    'playfield' => 'Playfield (arms ball save)',
    'troughKick' => 'Trough kick / outhole kicker',
    'knocker' => 'Knocker',
    'gameOn' => 'Game-on relay',
    'gameOver' => 'Game over',
    'ballInPlay' => 'Ball in play',
    'shootAgain' => 'Shoot again',
    'match' => 'Match',
    'ballSave' => 'Ball save',
    'tiltWarning' => 'Tilt warning',
    'playerUp' => 'Player up',
  ];

  private function hook(): GameRoleOptions {
    $hook = new GameRoleOptions();

    // StringTranslationTrait only needs something that returns the string back;
    // these tests assert on keys and on the two labels that differ per bundle.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn($translated_string) => $translated_string->getUntranslatedString()
    );
    $hook->setStringTranslation($translation);

    return $hook;
  }

  private function alter(string $bundle, ?array $options = NULL): array {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getName')->willReturn('field_game_role');

    $entity = new class($bundle) {

      public function __construct(private readonly string $bundle) {}

      public function bundle(): string {
        return $this->bundle;
      }

    };

    $options ??= self::STORAGE_OPTIONS;
    $this->hook()->optionsListAlter($options, [
      'fieldDefinition' => $field,
      'entity' => $entity,
      'widget' => NULL,
    ]);

    return $options;
  }

  public function testASwitchIsOfferedInputRolesOnly(): void {
    $options = $this->alter('switch');

    $this->assertSame([
      'start',
      'coin',
      'serviceCredit',
      'trough',
      'shooterLane',
      'tilt',
      'slamTilt',
      'tiltInhibit',
      'playfield',
    ], array_keys($options));
  }

  public function testASwitchIsNotOfferedOutputRoles(): void {
    $options = $this->alter('switch');

    // The symptom that started this: a switch could be made the knocker.
    $this->assertArrayNotHasKey('knocker', $options);
    $this->assertArrayNotHasKey('gameOn', $options);
    $this->assertArrayNotHasKey('troughKick', $options);
  }

  public function testAMatrixSwitchGetsTheSameRolesAsASwitch(): void {
    $this->assertSame(
      array_keys($this->alter('switch')),
      array_keys($this->alter('switch_matrix_switch')),
      'the matrix is a wiring detail, not a different kind of device'
    );
  }

  public function testAPwmDeviceIsOfferedOutputRolesOnly(): void {
    $options = $this->alter('pwm_device');

    $this->assertSame([
      'troughKick',
      'knocker',
      'gameOn',
      'gameOver',
      'tilt',
      'ballInPlay',
      'shootAgain',
      'match',
      'ballSave',
      'tiltWarning',
      'playerUp',
    ], array_keys($options));
    $this->assertArrayNotHasKey('coin', $options);
    $this->assertArrayNotHasKey('playfield', $options);
  }

  public function testTiltIsLabelledForTheDeviceItIsOn(): void {
    // One key, two devices. The merged storage list cannot hold both labels,
    // which is how the descriptive one was lost when they were collapsed.
    $this->assertSame(
      'Tilt (plumb bob, ball roll, playfield)',
      (string) $this->alter('switch')['tilt']
    );
    $this->assertSame('Tilt', (string) $this->alter('pwm_device')['tilt']);
  }

  public function testTheEmptyOptionSurvives(): void {
    $options = $this->alter('switch', ['_none' => '- None -'] + self::STORAGE_OPTIONS);

    $this->assertSame('_none', array_key_first($options), 'the empty option stays first');
    $this->assertSame('- None -', $options['_none']);
  }

  public function testARoleRemovedFromStorageIsNotReintroduced(): void {
    $storage = self::STORAGE_OPTIONS;
    unset($storage['slamTilt']);

    $this->assertArrayNotHasKey(
      'slamTilt',
      $this->alter('switch', $storage),
      'the field storage decides which roles exist; this hook only narrows'
    );
  }

  public function testOtherBundlesAreLeftAlone(): void {
    $options = $this->alter('game');

    $this->assertSame(self::STORAGE_OPTIONS, $options);
  }

  public function testOtherFieldsAreLeftAlone(): void {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getName')->willReturn('field_engine');

    $options = ['pinmame' => 'PinMAME', 'gamecore' => 'GameCore'];
    $before = $options;

    $this->hook()->optionsListAlter($options, [
      'fieldDefinition' => $field,
      'entity' => NULL,
      'widget' => NULL,
    ]);

    $this->assertSame($before, $options);
  }

  public function testAMissingEntityIsSurvivable(): void {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getName')->willReturn('field_game_role');

    // Some callers alter an options list with no entity in context; narrowing
    // by bundle is impossible then, and offering everything beats fataling.
    $options = self::STORAGE_OPTIONS;
    $this->hook()->optionsListAlter($options, ['fieldDefinition' => $field]);

    $this->assertSame(self::STORAGE_OPTIONS, $options);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Controller\GamesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The two values in ppuc.ini that the machine cannot start without.
 *
 * Both were wrong in a way nothing reported. ppuc looks up the PinMAME ROM by
 * [Game] Rom and the game folder names its altcolor/, pupvideos/ and altsound/
 * subdirectories after the same name - but the ini resolved it through one
 * chain and the folder skeleton through another, and only the skeleton read the
 * filename of the uploaded ROM media. A game with a ROM attached and no machine
 * name got directories called flash_l1 and an ini saying Rom=Flash.
 *
 * [Game] Engine was not written at all. io-boards.yaml carries `engine:
 * gamecore`, but nothing reads that key, so every exported folder started in
 * PinMAME mode however the game was configured. ppuc also spells the ROM-less
 * engine "script" and refuses to start on any other value, so the stored value
 * cannot be passed through unmapped.
 */
#[CoversClass(GamesController::class)]
#[Group('ppuc_games')]
class GamesControllerPpucIniTest extends TestCase {

  private GamesController $controller;

  protected function setUp(): void {
    parent::setUp();

    $this->controller = (new ReflectionClass(GamesController::class))
      ->newInstanceWithoutConstructor();

    // No ppuc_settings node exists for any game yet, which is the state every
    // game is in until someone opens the settings form. The query has to answer
    // for the ini resolver to reach its fallbacks at all.
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $node_storage = $this->createMock('Drupal\Core\Entity\EntityStorageInterface');
    $node_storage->method('getQuery')->willReturn($query);

    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getConfiguration')->willReturn(['source_field' => 'field_media_file_1']);
    $media_type = $this->createMock(MediaTypeInterface::class);
    $media_type->method('getSource')->willReturn($source);
    $media_type_storage = $this->createMock('Drupal\Core\Entity\EntityStorageInterface');
    $media_type_storage->method('load')->willReturn($media_type);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      static fn(string $entity_type_id) => match ($entity_type_id) {
        'media_type' => $media_type_storage,
        default => $node_storage,
      }
    );

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    // The name sanitiser dispatches FileUploadSanitizeNameEvent. With no
    // subscribers registered the name passes through as the controller built
    // it, which is what the ROM name assertions below are about.
    $container->set('event_dispatcher', new EventDispatcher());
    \Drupal::setContainer($container);
  }

  private function call(string $method, ...$args) {
    $reflection = (new ReflectionClass(GamesController::class))->getMethod($method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($this->controller, $args);
  }

  /**
   * A media item whose source file carries the given name.
   */
  private function romMedia(string $filename): MediaInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn($filename);

    $item = $this->createMock(FieldItemListInterface::class);
    $item->method('isEmpty')->willReturn(FALSE);
    // The controller reads `->entity ?? NULL`, and the null-coalescing operator
    // asks __isset() before __get(). A mock answers false to __isset by
    // default, which would hide the file behind a NULL that looks like "no ROM
    // attached" - the very state this test exists to rule out.
    $item->method('__isset')->willReturn(TRUE);
    $item->method('__get')->with('entity')->willReturn($file);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('rom');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')->willReturn($item);

    return $media;
  }

  /**
   * A game node whose fields answer from the given map.
   *
   * 'field_rom' is given as a filename rather than an entity; anything else is
   * a scalar. A field missing from the map behaves as absent.
   */
  private function game(string $title, array $values = []): NodeInterface {
    $rom_media = isset($values['field_rom']) ? [$this->romMedia($values['field_rom'])] : [];

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('game');
    $node->method('getTitle')->willReturn($title);
    $node->method('hasField')->willReturnCallback(
      static fn(string $name): bool => array_key_exists($name, $values)
    );
    $node->method('get')->willReturnCallback(
      function (string $name) use ($values, $rom_media): FieldItemListInterface {
        $item = $this->createMock(FieldItemListInterface::class);

        if ($name === 'field_rom') {
          // referencedEntities() lives on the entity reference list, not on the
          // plain item list every other field here is mocked as.
          $reference = $this->createMock(EntityReferenceFieldItemListInterface::class);
          $reference->method('isEmpty')->willReturn($rom_media === []);
          $reference->method('referencedEntities')->willReturn($rom_media);
          return $reference;
        }

        $value = $values[$name] ?? NULL;
        $item->method('isEmpty')->willReturn($value === NULL);
        $item->method('__get')->with('value')->willReturn($value);
        return $item;
      }
    );

    return $node;
  }

  /**
   * Reads one key out of a generated ini.
   */
  private function iniValue(NodeInterface $game, string $key): string {
    $ini = $this->call('buildPpucIni', $game);
    foreach (explode("\n", $ini) as $line) {
      if (str_starts_with($line, $key . '=')) {
        return substr($line, strlen($key) + 1);
      }
    }
    $this->fail(sprintf('ppuc.ini has no %s key', $key));
  }

  // --- the ROM name -----------------------------------------------------

  public function testTheRomNameComesFromTheUploadedRomFile(): void {
    $game = $this->game('Flash', ['field_rom' => 'flash_l1.zip']);

    // Not "Flash". PinMAME has no such ROM.
    $this->assertSame('flash_l1', $this->iniValue($game, 'Rom'));
  }

  public function testTheIniAndTheFolderSkeletonCannotDisagree(): void {
    // The regression itself: two resolvers, two answers, and altcolor/flash_l1
    // sitting next to an ini pointing at a ROM called Flash.
    $game = $this->game('Flash', ['field_rom' => 'flash_l1.zip']);

    $this->assertSame(
      $this->call('getGameRomName', $game),
      $this->iniValue($game, 'Rom'),
      'the ini ROM name and the game folder name must come from one resolver'
    );
  }

  public function testTheMachineNameWinsOverTheRomFile(): void {
    $game = $this->game('Flash', [
      'field_machine_name' => 'flash_t1',
      'field_rom' => 'flash_l1.zip',
    ]);

    $this->assertSame('flash_t1', $this->iniValue($game, 'Rom'));
  }

  public function testTheTitleIsUsedOnlyWhenNothingElseIsKnown(): void {
    $game = $this->game('Matrix Test');

    // Sanitised, because the same string names directories in the game folder.
    $this->assertSame('Matrix_Test', $this->iniValue($game, 'Rom'));
  }

  public function testTheZipExtensionIsNotPartOfTheRomName(): void {
    $game = $this->game('Time Warp', ['field_rom' => 'tmwrp_l2.zip']);

    $this->assertSame('tmwrp_l2', $this->iniValue($game, 'Rom'));
  }

  // --- the engine -------------------------------------------------------

  public function testAPinMameGameDeclaresThePinMameEngine(): void {
    $game = $this->game('Flash', ['field_engine' => 'pinmame']);

    $this->assertSame('pinmame', $this->iniValue($game, 'Engine'));
  }

  public function testAGameCoreGameDeclaresTheScriptEngine(): void {
    $game = $this->game('Homebrew', ['field_engine' => 'gamecore']);

    // ppuc rejects "gamecore" outright: "Valid values are 'pinmame' and
    // 'script'". Writing the stored value through unmapped would stop the
    // machine from starting at all.
    $this->assertSame('script', $this->iniValue($game, 'Engine'));
  }

  public function testAGameWithNoEngineSetStaysOnPinMame(): void {
    // Every game saved before field_engine existed is in this state.
    $game = $this->game('Time Warp');

    $this->assertSame('pinmame', $this->iniValue($game, 'Engine'));
  }

  // --- the rest of the file --------------------------------------------

  public function testTheKeysPpucReadsAreAllWritten(): void {
    $ini = $this->call('buildPpucIni', $this->game('Flash'));

    // Keys added with the settings bundle; each one was readable by ppuc and
    // absent from the export, so it could not be configured at all.
    foreach ([
      'Engine',
      'NoDisplay',
      'DebugSoundCommands',
      'DebugAudio',
      'DebugSegments',
      'AltSoundMode',
      'SerumResolution',
      'FirmwarePath',
      'AllowFirmwareUpdate',
      'AllowDevFirmwareUpdate',
      'AllowFirmwareDowngrade',
      'AllowUnvalidatedFirmwareUpdate',
    ] as $key) {
      $this->assertMatchesRegularExpression(
        '/^' . preg_quote($key, '/') . '=/m',
        $ini,
        sprintf('ppuc reads %s, so the export has to write it', $key)
      );
    }
  }

  public function testFirmwareFlashingIsOffUnlessAskedFor(): void {
    $ini = $this->call('buildPpucIni', $this->game('Flash'));

    // An exported folder must never flash a board on its own.
    foreach ([
      'AllowFirmwareUpdate',
      'AllowDevFirmwareUpdate',
      'AllowFirmwareDowngrade',
      'AllowUnvalidatedFirmwareUpdate',
    ] as $key) {
      $this->assertSame('false', $this->iniValue($this->game('Flash'), $key));
    }
  }

}

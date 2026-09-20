<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ppuc_games\Controller\GamesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unpacking AltSound and PUP packs into the game folder.
 *
 * Packs are published in two shapes and both are common: some wrap everything
 * in a directory named for the rom, some hold the files at the top level.
 * Which one a pack author chose must not decide whether the game works, and
 * the two destinations want opposite things - AltSound is read straight out of
 * pinmame/altsound/<rom>/, while a PUP pack has to stay a named directory
 * inside pup/pupvideos/ because that name is how PUP finds it.
 *
 * Getting this wrong is quiet: the export succeeds, the folder looks full, and
 * the machine plays without sound or video.
 */
#[CoversClass(GamesController::class)]
#[Group('ppuc_games')]
class GameFolderArchiveTest extends TestCase {

  private GamesController $controller;

  private string $workspace;

  protected function setUp(): void {
    parent::setUp();

    $this->workspace = sys_get_temp_dir() . '/ppuc-archive-test-' . uniqid();
    mkdir($this->workspace, 0777, TRUE);

    $this->controller = (new ReflectionClass(GamesController::class))
      ->newInstanceWithoutConstructor();

    // A file system that does the real thing, since what is being tested is
    // where files land.
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('getTempDirectory')->willReturn($this->workspace);
    $file_system->method('prepareDirectory')->willReturnCallback(
      static function (string &$directory): bool {
        return is_dir($directory) || mkdir($directory, 0777, TRUE);
      }
    );
    $file_system->method('move')->willReturnCallback(
      static function (string $from, string $to): string {
        rename($from, $to);
        return $to;
      }
    );
    $file_system->method('deleteRecursive')->willReturnCallback(
      function (string $path): bool {
        $this->deleteRecursive($path);
        return TRUE;
      }
    );

    $property = (new ReflectionClass(GamesController::class))->getProperty('fileSystem');
    $property->setAccessible(TRUE);
    $property->setValue($this->controller, $file_system);

    // A refused archive is reported rather than thrown, so the paths that
    // refuse one need somewhere to report it.
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    $this->deleteRecursive($this->workspace);
    parent::tearDown();
  }

  private function deleteRecursive(string $path): void {
    if (!is_dir($path)) {
      if (is_file($path)) {
        unlink($path);
      }
      return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
      $this->deleteRecursive($path . '/' . $entry);
    }
    rmdir($path);
  }

  /**
   * Builds a zip from a map of relative path to contents.
   */
  private function zip(string $name, array $entries): string {
    $path = $this->workspace . '/' . $name;
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $contents) {
      $zip->addFromString($entry, $contents);
    }
    $zip->close();

    return $path;
  }

  private function extract(string $archive, string $target, ?string $wrap_into): array {
    $method = (new ReflectionClass(GamesController::class))->getMethod('extractArchive');
    $method->setAccessible(TRUE);
    $method->invoke($this->controller, $archive, $target, $wrap_into);

    return $this->tree($target);
  }

  /**
   * Every file under a directory, relative and sorted.
   */
  private function tree(string $root, string $prefix = ''): array {
    if (!is_dir($root)) {
      return [];
    }

    $found = [];
    foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
      $path = $root . '/' . $entry;
      if (is_dir($path)) {
        $found = array_merge($found, $this->tree($path, $prefix . $entry . '/'));
        continue;
      }
      $found[] = $prefix . $entry;
    }
    sort($found);

    return $found;
  }

  // --- AltSound: the contents belong in the target itself ----------------

  public function testAFlatAltSoundPackLandsInTheRomFolder(): void {
    $archive = $this->zip('flat.zip', ['altsound.csv' => 'a', 'track.wav' => 'b']);

    $this->assertSame(
      ['altsound.csv', 'track.wav'],
      $this->extract($archive, $this->workspace . '/altsound/flash_l1', NULL)
    );
  }

  public function testAWrappedAltSoundPackIsUnwrapped(): void {
    // The wrapping directory would otherwise become
    // pinmame/altsound/flash_l1/flash_l1/, where nothing looks.
    $archive = $this->zip('wrapped.zip', [
      'flash_l1/altsound.csv' => 'a',
      'flash_l1/track.wav' => 'b',
    ]);

    $this->assertSame(
      ['altsound.csv', 'track.wav'],
      $this->extract($archive, $this->workspace . '/altsound/flash_l1', NULL)
    );
  }

  // --- PUP: the contents belong in a named directory ---------------------

  public function testAWrappedPupPackKeepsItsOwnName(): void {
    // PUP finds a pack by its directory name, and the pack author chose it.
    $archive = $this->zip('pup.zip', [
      'flash_l1/screens.pup' => 'a',
      'flash_l1/clip.mp4' => 'b',
    ]);

    $this->assertSame(
      ['flash_l1/clip.mp4', 'flash_l1/screens.pup'],
      $this->extract($archive, $this->workspace . '/pupvideos', 'fallback_name')
    );
  }

  public function testAFlatPupPackIsGivenTheRomName(): void {
    // Nothing named it, so it gets the only name the runtime will look for.
    $archive = $this->zip('pup-flat.zip', ['screens.pup' => 'a', 'clip.mp4' => 'b']);

    $this->assertSame(
      ['flash_l1/clip.mp4', 'flash_l1/screens.pup'],
      $this->extract($archive, $this->workspace . '/pupvideos', 'flash_l1')
    );
  }

  // --- what must not happen ---------------------------------------------

  public function testAnArchiveEscapingItsFolderIsRefused(): void {
    // A relative entry climbing out of the extraction directory would write
    // anywhere the web server can. The whole archive is dropped rather than
    // partly unpacked.
    $archive = $this->zip('evil.zip', [
      'fine.txt' => 'a',
      '../../escaped.txt' => 'b',
    ]);

    $target = $this->workspace . '/altsound/flash_l1';
    $this->assertSame([], $this->extract($archive, $target, NULL));
    $this->assertFileDoesNotExist($this->workspace . '/escaped.txt');
  }

  public function testMacOsMetadataIsNotTreatedAsTheWrapper(): void {
    // Zipping a folder in Finder adds __MACOSX beside it, which would make the
    // archive look like it has two top-level entries and stop it unwrapping.
    $archive = $this->zip('finder.zip', [
      'flash_l1/altsound.csv' => 'a',
      '__MACOSX/._flash_l1' => 'junk',
    ]);

    $this->assertSame(
      ['altsound.csv'],
      $this->extract($archive, $this->workspace . '/altsound/flash_l1', NULL)
    );
  }

  public function testAFileThatIsNotAZipIsSkipped(): void {
    $path = $this->workspace . '/not-a-zip.zip';
    file_put_contents($path, 'this is not a zip');

    $target = $this->workspace . '/altsound/flash_l1';
    $this->assertSame([], $this->extract($path, $target, NULL));
  }

}

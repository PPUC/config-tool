<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Controller\GamesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The comparators that decide device order in every exported game YAML.
 *
 * These look trivial, which is why they are worth pinning: they sort coils,
 * switches and lamps before export, and the firmware addresses devices by the
 * numbers that come out. A comparator that silently reorders - or that stops
 * being a valid comparator - produces a YAML that looks fine and drives the
 * wrong device.
 *
 * The numbers arrive from the database as strings, so the string/int
 * behaviour below is the real behaviour, not a synthetic edge case.
 */
#[CoversClass(GamesController::class)]
#[Group('ppuc_games')]
class GamesControllerSortTest extends TestCase {

  private GamesController $controller;

  protected function setUp(): void {
    parent::setUp();
    // The comparators do not touch $this, so the injected services are not
    // needed. Skipping the constructor keeps this a unit test rather than an
    // exercise in mocking the container.
    $this->controller = (new ReflectionClass(GamesController::class))
      ->newInstanceWithoutConstructor();
  }

  /**
   * An entity whose field_number->value is readable, as the comparator reads it.
   */
  private static function entityWithNumber(string|int|null $number): object {
    return new class($number) {
      public object $field_number;

      public function __construct(string|int|null $number) {
        $this->field_number = new class($number) {
          public function __construct(public string|int|null $value) {}
        };
      }
    };
  }

  private static function entityWithId(string|int $id): object {
    return new class($id) {
      public function __construct(private string|int $id) {}

      public function id(): string|int {
        return $this->id;
      }
    };
  }

  public function testSortsByNumberField(): void {
    $lower = self::entityWithNumber(1);
    $higher = self::entityWithNumber(2);

    $this->assertSame(-1, $this->controller->sortEntitiesByNumberField($lower, $higher));
    $this->assertSame(1, $this->controller->sortEntitiesByNumberField($higher, $lower));
    $this->assertSame(0, $this->controller->sortEntitiesByNumberField($lower, $lower));
  }

  /**
   * Numbers come out of the database as strings. "10" must not sort before "9".
   */
  public function testOrdersNumericStringsNumerically(): void {
    $nine = self::entityWithNumber('9');
    $ten = self::entityWithNumber('10');

    $this->assertSame(-1, $this->controller->sortEntitiesByNumberField($nine, $ten));
    $this->assertSame(1, $this->controller->sortEntitiesByNumberField($ten, $nine));
  }

  /**
   * A comparator has to be consistent, or usort's result depends on input order.
   */
  public function testNumberComparatorIsAntisymmetric(): void {
    $values = ['1', '2', '10', 3, 21, '4'];
    foreach ($values as $a) {
      foreach ($values as $b) {
        $left = $this->controller->sortEntitiesByNumberField(
          self::entityWithNumber($a), self::entityWithNumber($b));
        $right = $this->controller->sortEntitiesByNumberField(
          self::entityWithNumber($b), self::entityWithNumber($a));
        $this->assertSame(-$left, $right, "comparator disagrees with itself for $a vs $b");
      }
    }
  }

  /**
   * The property the export actually depends on: a full sort lands in order.
   */
  public function testSortingAListProducesAscendingNumbers(): void {
    $entities = array_map(
      static fn ($n) => self::entityWithNumber($n),
      ['10', '2', '33', '1', '9', '21']
    );

    usort($entities, [$this->controller, 'sortEntitiesByNumberField']);

    $ordered = array_map(static fn ($e) => (int) $e->field_number->value, $entities);
    $this->assertSame([1, 2, 9, 10, 21, 33], $ordered);
  }

  public function testSortsById(): void {
    $lower = self::entityWithId(4);
    $higher = self::entityWithId(17);

    $this->assertSame(-1, $this->controller->sortEntitiesById($lower, $higher));
    $this->assertSame(1, $this->controller->sortEntitiesById($higher, $lower));
    $this->assertSame(0, $this->controller->sortEntitiesById($lower, $lower));
  }

  public function testSortsArraysByNumberKey(): void {
    $rows = [['number' => '12'], ['number' => '3'], ['number' => '7']];

    usort($rows, [$this->controller, 'sortArrayByNumberValues']);

    $this->assertSame(['3', '7', '12'], array_column($rows, 'number'));
  }

  public function testArrayComparatorReportsEquality(): void {
    $this->assertSame(
      0,
      $this->controller->sortArrayByNumberValues(['number' => 5], ['number' => 5])
    );
  }

}

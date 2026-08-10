<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Controller\GamesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * How optional board flags read out of a node.
 *
 * The reason this is worth a test is backwards compatibility rather than the
 * logic. Every game that already exists was saved before field_slow_switches
 * existed, and its nodes will not carry the field until the config import has
 * run. If reading an absent field did anything other than return FALSE, the
 * export would either fail or start marking boards as slow that are not, which
 * would quietly halve how often a flipper button is looked at.
 */
#[CoversClass(GamesController::class)]
#[Group('ppuc_games')]
class GamesControllerBoardFlagsTest extends TestCase {

  private GamesController $controller;

  protected function setUp(): void {
    parent::setUp();
    // The reader does not touch $this, so the container is not needed.
    $this->controller = (new ReflectionClass(GamesController::class))
      ->newInstanceWithoutConstructor();
  }

  /**
   * Calls the protected reader the exporter uses.
   */
  private function read(NodeInterface $node, string $field): bool {
    $method = (new ReflectionClass(GamesController::class))
      ->getMethod('getBooleanFieldValue');
    $method->setAccessible(TRUE);
    return $method->invoke($this->controller, $node, $field);
  }

  private function node(bool $hasField, bool $isEmpty = FALSE, string|int|bool|null $value = NULL): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn($hasField);

    $item = $this->createMock(FieldItemListInterface::class);
    $item->method('isEmpty')->willReturn($isEmpty);
    // ->value is magic on a field item list, not a real property. Assigning to
    // the mock would hit the stubbed __set and be dropped, and every one of
    // these tests would then pass for the wrong reason.
    $item->method('__get')->with('value')->willReturn($value);
    $node->method('get')->willReturn($item);

    return $node;
  }

  /**
   * The case every pre-existing game is in until config is imported.
   */
  public function testAnAbsentFieldReadsAsFalse(): void {
    $this->assertFalse($this->read($this->node(FALSE), 'field_slow_switches'));
  }

  /**
   * A board saved before the checkbox was added has the field but no value.
   */
  public function testAnEmptyFieldReadsAsFalse(): void {
    $this->assertFalse($this->read($this->node(TRUE, TRUE), 'field_slow_switches'));
  }

  /**
   * Values arrive from the database as strings, including "0".
   */
  public function testUncheckedReadsAsFalse(): void {
    $this->assertFalse($this->read($this->node(TRUE, FALSE, '0'), 'field_slow_switches'));
    $this->assertFalse($this->read($this->node(TRUE, FALSE, 0), 'field_slow_switches'));
  }

  public function testCheckedReadsAsTrue(): void {
    $this->assertTrue($this->read($this->node(TRUE, FALSE, '1'), 'field_slow_switches'));
    $this->assertTrue($this->read($this->node(TRUE, FALSE, 1), 'field_slow_switches'));
    $this->assertTrue($this->read($this->node(TRUE, FALSE, TRUE), 'field_slow_switches'));
  }

  // --- referenced switch numbers ---------------------------------------------
  //
  // eosSwitch is exported from a reference to a switch node. A dangling or
  // absent reference has to come out as "no switch", not as switch 0, which is
  // a switch a game can really have.

  private function readSwitchNumber(NodeInterface $node, string $field): ?int {
    $method = (new ReflectionClass(GamesController::class))
      ->getMethod('getSwitchNumberFieldValue');
    $method->setAccessible(TRUE);
    return $method->invoke($this->controller, $node, $field);
  }

  private function nodeReferencing(bool $hasField, bool $isEmpty, mixed $target): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn($hasField);

    $item = $this->createMock(FieldItemListInterface::class);
    $item->method('isEmpty')->willReturn($isEmpty);
    $item->method('__get')->with('entity')->willReturn($target);
    $node->method('get')->willReturn($item);

    return $node;
  }

  private function switchNumbered(string|int|null $number, bool $hasNumberField = TRUE): NodeInterface {
    $switch = $this->createMock(NodeInterface::class);
    $switch->method('hasField')->willReturn($hasNumberField);

    $item = $this->createMock(FieldItemListInterface::class);
    $item->method('__get')->with('value')->willReturn($number);
    $switch->method('get')->willReturn($item);

    return $switch;
  }

  public function testAnAbsentReferenceFieldIsNoSwitch(): void {
    $this->assertNull($this->readSwitchNumber($this->nodeReferencing(FALSE, FALSE, NULL), 'field_eos_switch'));
  }

  public function testAnEmptyReferenceIsNoSwitch(): void {
    $this->assertNull($this->readSwitchNumber($this->nodeReferencing(TRUE, TRUE, NULL), 'field_eos_switch'));
  }

  /**
   * The referenced node was deleted. Exporting 0 here would name a real switch.
   */
  public function testADanglingReferenceIsNoSwitch(): void {
    $this->assertNull($this->readSwitchNumber($this->nodeReferencing(TRUE, FALSE, NULL), 'field_eos_switch'));
  }

  /**
   * The field exists but was never filled in.
   */
  public function testAReferencedSwitchWithAnEmptyNumberIsNoSwitch(): void {
    $target = $this->switchNumbered(NULL);
    $this->assertNull($this->readSwitchNumber($this->nodeReferencing(TRUE, FALSE, $target), 'field_eos_switch'));
  }

  public function testAReferencedSwitchWithoutANumberIsNoSwitch(): void {
    $target = $this->switchNumbered('7', FALSE);
    $this->assertNull($this->readSwitchNumber($this->nodeReferencing(TRUE, FALSE, $target), 'field_eos_switch'));
  }

  /**
   * Numbers arrive from the database as strings.
   */
  public function testAReferencedSwitchExportsItsNumber(): void {
    $this->assertSame(7, $this->readSwitchNumber(
      $this->nodeReferencing(TRUE, FALSE, $this->switchNumbered('7')), 'field_eos_switch'));
    $this->assertSame(0, $this->readSwitchNumber(
      $this->nodeReferencing(TRUE, FALSE, $this->switchNumbered(0)), 'field_eos_switch'));
  }

}

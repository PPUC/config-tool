<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\ppuc_games\Hook\AllLampsStringPositionSort;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\Plugin\views\relationship\RelationshipPluginBase;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sorting All Lamps by string position sorts by string first.
 *
 * Every string has an LED at position 1, so a sort on the position alone
 * interleaves all strings. The string keys have to come before the position,
 * not after it, and follow the direction the column was clicked in.
 */
#[Group('ppuc_games')]
class AllLampsStringPositionSortTest extends TestCase {

  private const POSITION_SORT = ['field' => 'node__field_string_position_field_string_position_value', 'direction' => 'ASC'];

  private function view(string $id, ?string $active, string $sort = 'asc'): ViewExecutable {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn($id);
    $view->method('getRequest')->willReturn(new Request(['order' => $active, 'sort' => $sort]));

    $style = $this->createMock(Table::class);
    $style->active = $active;
    $view->style_plugin = $style;

    $string = $this->createMock(RelationshipPluginBase::class);
    $string->alias = 'string_node';
    $board = $this->createMock(RelationshipPluginBase::class);
    $board->alias = 'board_node';
    $view->relationship = ['field_string' => $string, 'field_i_o_board' => $board];

    return $view;
  }

  private function query(): Sql {
    $query = $this->createMock(Sql::class);
    $query->orderby = [self::POSITION_SORT];
    $query->method('ensureTable')->willReturnCallback(
      static fn(string $table, string $relationship): string => $relationship . '__' . $table
    );
    $query->method('addOrderBy')->willReturnCallback(
      static function (string $table, string $field, string $order, string $alias) use ($query): void {
        $query->orderby[] = ['field' => "$table.$field AS $alias", 'direction' => $order];
      }
    );
    return $query;
  }

  public function testStringPositionSortsByBoardStringThenPosition(): void {
    $query = $this->query();
    (new AllLampsStringPositionSort())->viewsQueryAlter($this->view('game_all_lamps', 'field_string_position'), $query);

    $this->assertSame([
      ['field' => 'board_node__node__field_number.field_number_value AS ppuc_string_board_number', 'direction' => 'ASC'],
      ['field' => 'string_node.title AS ppuc_string_title', 'direction' => 'ASC'],
      ['field' => 'string_node.nid AS ppuc_string_nid', 'direction' => 'ASC'],
      self::POSITION_SORT,
    ], $query->orderby);
  }

  public function testDescendingSortsTheStringsDescendingToo(): void {
    $query = $this->query();
    (new AllLampsStringPositionSort())->viewsQueryAlter($this->view('game_all_lamps', 'field_string_position', 'desc'), $query);

    $this->assertSame(
      ['DESC', 'DESC', 'DESC'],
      array_column(array_slice($query->orderby, 0, 3), 'direction')
    );
  }

  public function testOtherColumnsAreLeftAlone(): void {
    $query = $this->query();
    (new AllLampsStringPositionSort())->viewsQueryAlter($this->view('game_all_lamps', 'field_number'), $query);

    $this->assertSame([self::POSITION_SORT], $query->orderby);
  }

  public function testOtherViewsAreLeftAlone(): void {
    $query = $this->query();
    (new AllLampsStringPositionSort())->viewsQueryAlter($this->view('game_all_switches', 'field_string_position'), $query);

    $this->assertSame([self::POSITION_SORT], $query->orderby);
  }

}

<?php

namespace Drupal\Tests\sequence\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sequences\Generator;

/**
 * Tests the Sequence ID generation service.
 *
 * @group sequences
 */
class GeneratorTest extends KernelTestBase {

  /**
   * Modules to install.
   *
   * @var string[]
   */
  protected static $modules = ['sequences', 'system'];

  /**
   * The generator service.
   *
   * @var \Drupal\sequences\Generator
   */
  protected Generator $generator;

  /**
   * Setup.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generator = $this->container->get('sequences.generator');
  }

  /**
   * Test ID generation sequence.
   *
   * @throws \Exception
   */
  public function testIncrementingIds(): void {
    $id1 = $this->generator->nextId('invoice');
    $id2 = $this->generator->nextId('invoice');
    $id3 = $this->generator->nextId('invoice');

    $this->assertGreaterThan($id1, $id2);
    $this->assertGreaterThan($id2, $id3);
  }

  /**
   * Test independence of two different sequences.
   *
   * @throws \Exception
   */
  public function testNamedNamedSequencesIndependence(): void {
    $invoiceId = $this->generator->nextId('invoice');
    $orderId = $this->generator->nextId('order');

    $this->assertIsInt($invoiceId);
    $this->assertIsInt($orderId);
    $this->assertEquals($invoiceId, $orderId);
  }

}

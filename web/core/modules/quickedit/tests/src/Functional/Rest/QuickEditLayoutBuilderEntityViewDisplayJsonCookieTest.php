<?php

namespace Drupal\Tests\quickedit\Functional\Rest;

use Drupal\Tests\layout_builder\Functional\Rest\LayoutBuilderEntityViewDisplayJsonCookieTest;

/**
 * @group quickedit
 * @group layout_builder
 * @group rest
 * @group legacy
 */
class QuickEditLayoutBuilderEntityViewDisplayJsonCookieTest extends LayoutBuilderEntityViewDisplayJsonCookieTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['quickedit'];

}

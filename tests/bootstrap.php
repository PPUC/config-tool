<?php

/**
 * @file
 * Bootstrap for the config-tool's own unit tests.
 *
 * Drupal registers module namespaces at runtime from the container, which
 * these tests deliberately do not build - they are plain unit tests over
 * logic that needs no database. Registering the namespaces by hand keeps them
 * fast enough to run on every change.
 *
 * Tests that need entities, fields or config belong in a Kernel test instead,
 * and those bootstrap through web/core/phpunit.xml.dist.
 */

declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';

$loader->addPsr4('Drupal\\ppuc_games\\', __DIR__ . '/../web/modules/custom/ppuc_games/src');

// Core and contrib namespaces referenced from custom module signatures -
// NodeInterface and the field item interfaces, mostly. Registering them is
// enough to type-hint against and to build test doubles for; nothing here
// boots Drupal.
foreach (['/../web/core/modules/*/src', '/../web/modules/contrib/*/src'] as $pattern) {
  foreach (glob(__DIR__ . $pattern, GLOB_ONLYDIR) ?: [] as $src) {
    $module = basename(dirname($src));
    $loader->addPsr4('Drupal\\' . $module . '\\', $src);
  }
}

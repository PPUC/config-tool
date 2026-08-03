# Unit tests

Fast tests over this site's own code — no database, no container build.

## Why PHPUnit is not in `composer.json`

`vendor/` is committed in this repository, which is the right call for
deploying a Drupal site but a poor one for test tooling: adding
`phpunit/phpunit` to `require-dev` would commit roughly two thousand files
that only developers ever run.

So PHPUnit is kept outside the project. Nothing here depends on it being
installed.

## Running them

Download a PHPUnit 11 phar once, anywhere outside the repository:

```
curl -sL -o ~/tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
```

With PHP 8.3+ available locally:

```
php ~/tools/phpunit.phar
```

Without a local PHP — the project needs 8.3, which recent macOS does not
ship:

```
docker run --rm -v "$PWD":/app -v ~/tools:/tools -w /app \
  php:8.3-cli php /tools/phpunit.phar
```

Both pick up `phpunit.xml.dist` in the project root.

## What belongs here

Logic that runs without a database: comparators, value mapping, formatting,
validation rules. `tests/bootstrap.php` registers the custom and contrib
module namespaces by hand instead of booting Drupal, which is what keeps the
suite at a few milliseconds.

Anything that needs entities, fields, config or the container is a **Kernel**
test and belongs under `tests/src/Kernel` in its module, run through
`web/core/phpunit.xml.dist` with `SIMPLETEST_DB` set. Those are slower and
need a database, so keep them out of this suite.

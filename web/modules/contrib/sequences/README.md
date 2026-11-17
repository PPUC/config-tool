# Sequences

The method `Drupal\Core\Database\Connection::nextId()` and the `sequences` table are deprecated in Drupal 10 and are
removed from Drupal 11. See https://www.drupal.org/node/3349345

This module brings back a database driven functionality to get sequences as a service using a simple API.

## Usage

```php
$sequence_name = 'my_sequence';
$next_id = \Drupal::service('sequences.generator')->nextId($sequence_name);
```

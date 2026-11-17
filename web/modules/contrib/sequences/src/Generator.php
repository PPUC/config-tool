<?php

namespace Drupal\sequences;

use Drupal\Core\Database\Connection;

/**
 * Provides unique ID generation using a native auto-increment table.
 */
class Generator {

  public function __construct(protected Connection $database) {
  }

  /**
   * Returns the next ID for a given named sequence.
   *
   * @param string $name
   *   The name of the sequence (e.g., 'invoice').
   *
   * @return int
   *   The next generated ID.
   *
   * @throws \RuntimeException
   *   If the DB driver does not support this method.
   * @throws \Exception
   *   Any other exception.
   */
  public function nextId(string $name): int {
    $driver = $this->database->driver();
    if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'])) {
      throw new \RuntimeException("Database driver '$driver' is not supported by the Sequences module.");
    }

    $table = $this->getSequenceTableName($name);
    $this->ensureSequenceTableExists($table, $name);

    $transaction = $this->database->startTransaction();
    try {
      $this->database
        ->insert($table)
        ->useDefaults(['name'])
        ->execute();

      $last_id = match ($driver) {
        'pgsql' => (int) $this->database
          ->query("SELECT CURRVAL(pg_get_serial_sequence('$table', 'id'))")
          ->fetchField(),
        default => (int) $this->database
          ->query("SELECT LAST_INSERT_ID()")
          ->fetchField(),
      };

      // Delete rows with lower IDs to prevent table growth.
      $this->database->delete($table)
        ->condition('id', $last_id, '<')
        ->execute();

      return $last_id;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Creates a unique and safe table name from the sequence name.
   *
   * @param string $name
   *   The sequence name.
   *
   * @return string
   *   A shortened, valid DB-safe table name.
   */
  protected function getSequenceTableName(string $name): string {
    // Normalize: lowercase, alphanumeric only.
    $sanitized = strtolower(preg_replace('/[^a-z0-9_]+/', '_', $name));
    $prefix = 'sequence_';
    $max_length = 63;

    $base = $prefix . $sanitized;

    // Truncate and append a short hash if too long.
    if (strlen($base) > $max_length) {
      $hash = substr(hash('sha256', $name), 0, 8);
      $base = substr($base, 0, $max_length - 9) . '_' . $hash;
    }

    return $base;
  }

  /**
   * Ensures the table for a named sequence exists.
   *
   * @param string $table
   *   The fully qualified and shortened table name.
   * @param string $name
   *   The sequence name.
   */
  protected function ensureSequenceTableExists(string $table, string $name): void {
    if (!$this->database->schema()->tableExists($table)) {
      $this->database->schema()->createTable($table, [
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
          ],
          'name' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'default' => mb_substr($name, 0, 255),
          ],
        ],
        'primary key' => ['id'],
      ]);
    }
  }

}

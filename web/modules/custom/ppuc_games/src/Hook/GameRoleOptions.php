<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Hook;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Restricts field_game_role to the roles the device class can actually have.
 *
 * A GameCore role says what a device *is* to the game: the start button, the
 * trough, the knocker, the tilt relay. Inputs and outputs take disjoint sets of
 * them - a switch is never a knocker, a coil is never a coin chute - but both
 * live in one field, so the storage holds the union of all 20 values and every
 * bundle would otherwise offer all of them.
 *
 * That matters beyond the form looking untidy. GamesController::gameRoleOf()
 * reads this field straight into the exported YAML, so a role chosen for the
 * wrong class of device reaches io-boards.yaml and names a device that cannot
 * perform it.
 *
 * This used to be done by putting `allowed_values` on each field instance,
 * which worked - FieldConfigBase::getSetting() returns an instance value
 * outright when the key is present - but was never valid configuration. Core
 * says so in options.schema.yml: "This field type has no field instance
 * settings, so no specific config schema type." Nothing validates a key that
 * has no schema, so it survived until something re-saved those three
 * FieldConfig entities and it was silently dropped. A config export then showed
 * three fields quietly losing their option lists.
 *
 * Doing it here instead keeps the storage as the single source of truth for
 * which roles exist, puts the per-bundle view in code where it is tested, and
 * cannot be lost by an export.
 *
 * It also fixes something the storage list cannot express. Two bundles need
 * different labels for the same key: `tilt` is a plumb bob on a switch and a
 * relay on an output. As one merged list those collided, and the collision was
 * resolved by dropping one - which is how "Tilt (plumb bob, ball roll,
 * playfield)" disappeared. Labels are per-bundle here, so both are correct.
 */
final class GameRoleOptions {

  use StringTranslationTrait;

  /**
   * The field this applies to.
   */
  private const FIELD_NAME = 'field_game_role';

  /**
   * Roles an input can have, keyed by bundle.
   *
   * Switches and switch matrix switches take the same set: both are inputs, and
   * the matrix is a wiring detail rather than a different kind of device.
   */
  private const INPUT_BUNDLES = ['switch', 'switch_matrix_switch'];

  /**
   * Returns the roles for a bundle, or NULL to leave the list alone.
   *
   * Labels are duplicated from the field storage rather than derived from it,
   * because two of them deliberately differ per bundle. Keys that are not in
   * storage are ignored by the filter below, so a typo here removes an option
   * rather than inventing one.
   *
   * @param string $bundle
   *   The bundle of the entity being edited.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>|null
   *   Role key to label, or NULL if this bundle is not restricted.
   */
  private function rolesFor(string $bundle): ?array {
    if (in_array($bundle, self::INPUT_BUNDLES, TRUE)) {
      return [
        'start' => $this->t('Start button'),
        'coin' => $this->t('Coin chute'),
        'serviceCredit' => $this->t('Service credit'),
        'trough' => $this->t('Trough / outhole (ball is home)'),
        'shooterLane' => $this->t('Shooter lane'),
        // A switch's tilt is the plumb bob, the ball roll or the playfield
        // switch. An output's tilt is the relay. Same key, different device.
        'tilt' => $this->t('Tilt (plumb bob, ball roll, playfield)'),
        'slamTilt' => $this->t('Slam tilt'),
        'tiltInhibit' => $this->t('Tilt inhibit (host-owned, no wiring)'),
        'playfield' => $this->t('Playfield (arms ball save)'),
      ];
    }

    if ($bundle === 'pwm_device') {
      return [
        'troughKick' => $this->t('Trough kick / outhole kicker'),
        'knocker' => $this->t('Knocker'),
        'gameOn' => $this->t('Game-on relay'),
        'gameOver' => $this->t('Game over'),
        'tilt' => $this->t('Tilt'),
        'ballInPlay' => $this->t('Ball in play'),
        'shootAgain' => $this->t('Shoot again'),
        'match' => $this->t('Match'),
        'ballSave' => $this->t('Ball save'),
        'tiltWarning' => $this->t('Tilt warning'),
        'playerUp' => $this->t('Player up'),
      ];
    }

    return NULL;
  }

  /**
   * Implements hook_options_list_alter().
   *
   * @param array $options
   *   The options, keyed by value. May carry an '_none' entry the widget added.
   * @param array $context
   *   Keys 'fieldDefinition', 'entity' and 'widget'.
   */
  #[Hook('options_list_alter')]
  public function optionsListAlter(array &$options, array $context): void {
    $field = $context['fieldDefinition'] ?? NULL;
    if (!$field instanceof FieldDefinitionInterface || $field->getName() !== self::FIELD_NAME) {
      return;
    }

    $entity = $context['entity'] ?? NULL;
    if ($entity === NULL || !method_exists($entity, 'bundle')) {
      return;
    }

    $roles = $this->rolesFor((string) $entity->bundle());
    if ($roles === NULL) {
      return;
    }

    // Keep only what this bundle may have, and only what the storage still
    // offers: a role removed from the field must disappear from every bundle,
    // not linger here. Relabel what is kept.
    $allowed = [];
    foreach ($roles as $value => $label) {
      if (array_key_exists($value, $options)) {
        $allowed[$value] = $label;
      }
    }

    // The widget's empty option is not a role and must survive, or a device
    // whose role is optional can no longer be set back to none.
    if (array_key_exists('_none', $options)) {
      $allowed = ['_none' => $options['_none']] + $allowed;
    }

    $options = $allowed;
  }

}

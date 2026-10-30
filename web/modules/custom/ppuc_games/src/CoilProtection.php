<?php

declare(strict_types=1);

namespace Drupal\ppuc_games;

use Drupal\ppuc_games\Wizard\DeviceDefaults;

/**
 * Refuses a coil that nothing would stop.
 *
 * libppuc only warns about a coil with no bound, because a game exported before
 * the hold and dual winding flags existed cannot be told apart from a broken
 * one. A coil edited by hand can: this is where the person who can fix it is
 * looking, so it is refused here rather than warned about at the machine.
 *
 * The flipper power winding gets a tighter rule. It is the one coil driven at
 * full power by a button a player can hold for minutes, and the original WPC
 * CPU never let it run longer than 40 ms whatever its end-of-stroke contact
 * did.
 */
final class CoilProtection {

  /**
   * The pwm_device term a coil is, as opposed to a motor, lamp or shaker.
   */
  public const PWM_TYPE_COIL_UUID = 'f72c503f-19af-488e-8eb1-64f234854ea7';

  /**
   * Why a coil cannot be saved like this, or NULL.
   *
   * @param array $coil
   *   'coil' (bool, the type is Coil), 'maxPulseTime', 'holdPower',
   *   'holdPowerActivationTime' (int), 'holdWinding', 'dualWinding',
   *   'fastSwitch' (bool) and 'stopSwitches' (int, how many).
   *
   * @return array|null
   *   [field name, message] for the field to mark, or NULL when it is bounded.
   */
  public static function refusal(array $coil): ?array {
    if (empty($coil['coil'])) {
      return NULL;
    }

    if (!empty($coil['holdWinding']) && $coil['stopSwitches'] > 0) {
      return [
        'field_stop_switches',
        'A hold winding is not stopped by the end-of-stroke switch: it is what keeps the finger up once '
        . 'the switch has closed. Set the stop switch on the power winding instead.',
      ];
    }
    if (!empty($coil['holdWinding']) || !empty($coil['dualWinding'])) {
      return NULL;
    }

    $maxPulse = (int) $coil['maxPulseTime'];
    $limit = DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS;
    if (!empty($coil['fastSwitch']) && $coil['stopSwitches'] > 0) {
      if ($maxPulse < 1 || $maxPulse > $limit) {
        return [
          'field_max_pulse_time',
          sprintf(
            'This is a flipper power winding: a button fires it and its end-of-stroke switch stops it. '
            . 'It needs a maximum pulse time of 1 to %d ms as well, for when that contact fails - '
            . '30 to 40 ms is what WPC used.',
            $limit
          ),
        ];
      }
      return NULL;
    }

    $holds = (int) $coil['holdPower'] > 0 && (int) $coil['holdPowerActivationTime'] > 0;
    if ($maxPulse < 1 && !$holds) {
      return [
        'field_max_pulse_time',
        'Nothing bounds this coil: with no maximum pulse time and no hold power, a coil that is left on '
        . 'burns. Set a maximum pulse time, or a hold power with its activation time. If it is a flipper\'s '
        . 'hold winding or a dual-wound coil, say so with that checkbox.',
      ];
    }
    return NULL;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Changes the max pulse time on selected PWM device nodes.
 */
#[Action(
  id: 'ppuc_games_change_pwm_device_max_pulse_time',
  label: new TranslatableMarkup('Change max pulse time'),
  type: 'node'
)]
final class ChangePwmDeviceMaxPulseTimeAction extends PwmDeviceIntegerActionBase {

  /**
   * {@inheritdoc}
   */
  protected function fieldName(): string {
    return 'field_max_pulse_time';
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldLabel(): string {
    return (string) $this->t('Max Pulse Time');
  }

}

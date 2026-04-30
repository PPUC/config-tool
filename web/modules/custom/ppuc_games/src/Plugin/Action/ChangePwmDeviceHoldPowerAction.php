<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Changes the hold power on selected PWM device nodes.
 */
#[Action(
  id: 'ppuc_games_change_pwm_device_hold_power',
  label: new TranslatableMarkup('Change hold power'),
  type: 'node'
)]
final class ChangePwmDeviceHoldPowerAction extends PwmDeviceIntegerActionBase {

  /**
   * {@inheritdoc}
   */
  protected function fieldName(): string {
    return 'field_hold_power';
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldLabel(): string {
    return (string) $this->t('Hold Power');
  }

  /**
   * {@inheritdoc}
   */
  protected function maxValue(): ?int {
    return 255;
  }

}

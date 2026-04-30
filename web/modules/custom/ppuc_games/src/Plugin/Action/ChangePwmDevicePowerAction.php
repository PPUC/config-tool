<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Changes the power on selected PWM device nodes.
 */
#[Action(
  id: 'ppuc_games_change_pwm_device_power',
  label: new TranslatableMarkup('Change power'),
  type: 'node'
)]
final class ChangePwmDevicePowerAction extends PwmDeviceIntegerActionBase {

  /**
   * {@inheritdoc}
   */
  protected function fieldName(): string {
    return 'field_power';
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldLabel(): string {
    return (string) $this->t('Power');
  }

  /**
   * {@inheritdoc}
   */
  protected function maxValue(): ?int {
    return 255;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultValue(): int {
    return 128;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Base action for changing an integer field on selected PWM device nodes.
 */
abstract class PwmDeviceIntegerActionBase extends ConfigurableActionBase {

  /**
   * Gets the machine name of the field this action updates.
   */
  abstract protected function fieldName(): string;

  /**
   * Gets the form label.
   */
  abstract protected function fieldLabel(): string;

  /**
   * Gets the minimum accepted value.
   */
  protected function minValue(): int {
    return 0;
  }

  /**
   * Gets the maximum accepted value, or NULL for no maximum.
   */
  protected function maxValue(): ?int {
    return NULL;
  }

  /**
   * Gets the default configuration value.
   */
  protected function defaultValue(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'value' => $this->defaultValue(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $element = [
      '#type' => 'number',
      '#title' => $this->fieldLabel(),
      '#default_value' => $this->configuration['value'] ?? $this->defaultValue(),
      '#min' => $this->minValue(),
      '#step' => 1,
      '#required' => TRUE,
    ];

    if ($this->maxValue() !== NULL) {
      $element['#max'] = $this->maxValue();
    }

    $form['value'] = $element;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('value');
    if ($value === NULL || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === FALSE) {
      $form_state->setErrorByName('value', $this->t('@label must be an integer.', ['@label' => $this->fieldLabel()]));
      return;
    }

    $value = (int) $value;
    if ($value < $this->minValue()) {
      $form_state->setErrorByName('value', $this->t('@label must be at least @min.', [
        '@label' => $this->fieldLabel(),
        '@min' => $this->minValue(),
      ]));
    }

    if ($this->maxValue() !== NULL && $value > $this->maxValue()) {
      $form_state->setErrorByName('value', $this->t('@label must be at most @max.', [
        '@label' => $this->fieldLabel(),
        '@max' => $this->maxValue(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['value'] = (int) $form_state->getValue('value');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): TranslatableMarkup {
    if (!$this->isPwmDeviceNode($entity)) {
      return $this->t('Skipped');
    }

    $entity->set($this->fieldName(), (int) $this->configuration['value']);
    $entity->save();

    return $this->t('Updated @label', ['@label' => $this->fieldLabel()]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    if (!$this->isPwmDeviceNode($object)) {
      $access = AccessResult::forbidden();
    }
    else {
      $access = $object->access('update', $account, TRUE)
        ->andIf($object->get($this->fieldName())->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Checks whether an entity is a PWM device node with the configured field.
   */
  private function isPwmDeviceNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'pwm_device'
      && $entity->hasField($this->fieldName());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Changes the debounce time on selected switch nodes.
 */
#[Action(
  id: 'ppuc_games_change_switch_debounce',
  label: new TranslatableMarkup('Change debounce time'),
  type: 'node'
)]
final class ChangeSwitchDebounceAction extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'debounce' => 10,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['debounce'] = [
      '#type' => 'number',
      '#title' => $this->t('Debounce time'),
      '#description' => $this->t('Debounce time in milliseconds between 0 and 255.'),
      '#default_value' => $this->configuration['debounce'] ?? 10,
      '#min' => 0,
      '#max' => 255,
      '#step' => 1,
      '#required' => TRUE,
      '#field_suffix' => $this->t('ms'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('debounce');
    if ($value === NULL || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === FALSE) {
      $form_state->setErrorByName('debounce', $this->t('Debounce time must be an integer.'));
      return;
    }

    $value = (int) $value;
    if ($value < 0 || $value > 255) {
      $form_state->setErrorByName('debounce', $this->t('Debounce time must be between 0 and 255 ms.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['debounce'] = (int) $form_state->getValue('debounce');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): TranslatableMarkup {
    if (!$this->isSwitchNode($entity)) {
      return $this->t('Skipped');
    }

    $entity->set('field_debounce', (int) $this->configuration['debounce']);
    $entity->save();

    return $this->t('Updated debounce time');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    if (!$this->isSwitchNode($object)) {
      $access = AccessResult::forbidden();
    }
    else {
      $access = $object->access('update', $account, TRUE)
        ->andIf($object->get('field_debounce')->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Checks whether an entity is a switch node with the debounce field.
   */
  private function isSwitchNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'switch'
      && $entity->hasField('field_debounce');
  }

}

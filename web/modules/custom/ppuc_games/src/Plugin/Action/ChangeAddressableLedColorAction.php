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
 * Changes the color on selected addressable LED nodes.
 */
#[Action(
  id: 'ppuc_games_change_addressable_led_color',
  label: new TranslatableMarkup('Change color'),
  type: 'node'
)]
final class ChangeAddressableLedColorAction extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'color' => '#ffffff',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['color'] = [
      '#type' => 'color',
      '#title' => $this->t('Color'),
      '#default_value' => $this->configuration['color'] ?? '#ffffff',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $color = $form_state->getValue('color');
    if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
      $form_state->setErrorByName('color', $this->t('Color must be a hex color like #ffffff.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['color'] = strtolower((string) $form_state->getValue('color'));
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): TranslatableMarkup {
    if (!$this->isAddressableLedNode($entity)) {
      return $this->t('Skipped');
    }

    $entity->set('field_color', [
      'color' => (string) $this->configuration['color'],
      'opacity' => NULL,
    ]);
    $entity->save();

    return $this->t('Updated color');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    if (!$this->isAddressableLedNode($object)) {
      $access = AccessResult::forbidden();
    }
    else {
      $access = $object->access('update', $account, TRUE)
        ->andIf($object->get('field_color')->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Checks whether an entity is an addressable LED node with the color field.
   */
  private function isAddressableLedNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'addressable_led'
      && $entity->hasField('field_color');
  }

}

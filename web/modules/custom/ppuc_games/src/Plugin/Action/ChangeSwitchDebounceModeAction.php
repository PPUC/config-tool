<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Changes the debounce mode on selected switch nodes.
 */
#[Action(
  id: 'ppuc_games_change_switch_debounce_mode',
  label: new TranslatableMarkup('Change debounce mode'),
  type: 'node'
)]
final class ChangeSwitchDebounceModeAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ChangeSwitchDebounceModeAction object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'debounce_mode' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['debounce_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Debounce mode'),
      '#options' => $this->getDebounceModeOptions(),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['debounce_mode'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $tid = $form_state->getValue('debounce_mode');
    if (!$tid || !array_key_exists((int) $tid, $this->getDebounceModeOptions())) {
      $form_state->setErrorByName('debounce_mode', $this->t('Select a valid debounce mode.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['debounce_mode'] = (int) $form_state->getValue('debounce_mode');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): TranslatableMarkup {
    if (!$this->isSwitchNode($entity)) {
      return $this->t('Skipped');
    }

    $entity->set('field_debounce_mode', ['target_id' => (int) $this->configuration['debounce_mode']]);
    $entity->save();

    return $this->t('Updated debounce mode');
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
        ->andIf($object->get('field_debounce_mode')->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Builds select options for the switch debounce mode vocabulary.
   */
  private function getDebounceModeOptions(): array {
    $options = [];
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree('switch_debounce_mode');

    foreach ($terms as $term) {
      $options[(int) $term->tid] = $term->name;
    }

    return $options;
  }

  /**
   * Checks whether an entity is a switch node with the debounce mode field.
   */
  private function isSwitchNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'switch'
      && $entity->hasField('field_debounce_mode');
  }

}

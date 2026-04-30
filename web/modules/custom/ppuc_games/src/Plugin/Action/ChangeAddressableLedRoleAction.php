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
 * Changes the role on selected addressable LED nodes.
 */
#[Action(
  id: 'ppuc_games_change_addressable_led_role',
  label: new TranslatableMarkup('Change role'),
  type: 'node'
)]
final class ChangeAddressableLedRoleAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ChangeAddressableLedRoleAction object.
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
      'role' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#options' => $this->getRoleOptions(),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['role'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $tid = $form_state->getValue('role');
    if (!$tid || !array_key_exists((int) $tid, $this->getRoleOptions())) {
      $form_state->setErrorByName('role', $this->t('Select a valid role.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['role'] = (int) $form_state->getValue('role');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): TranslatableMarkup {
    if (!$this->isAddressableLedNode($entity)) {
      return $this->t('Skipped');
    }

    $entity->set('field_role', ['target_id' => (int) $this->configuration['role']]);
    $entity->save();

    return $this->t('Updated role');
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
        ->andIf($object->get('field_role')->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Builds select options for the LED role vocabulary.
   */
  private function getRoleOptions(): array {
    $options = [];
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree('led_role');

    foreach ($terms as $term) {
      $options[(int) $term->tid] = $term->name;
    }

    return $options;
  }

  /**
   * Checks whether an entity is an addressable LED node with the role field.
   */
  private function isAddressableLedNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'addressable_led'
      && $entity->hasField('field_role');
  }

}

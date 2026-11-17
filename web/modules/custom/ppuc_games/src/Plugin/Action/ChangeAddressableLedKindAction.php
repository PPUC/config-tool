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
 * Sets or clears the kind on selected addressable LED nodes.
 *
 * The kind is optional, so the form offers "none" as a real choice: it clears
 * the field instead of being treated as a missing selection.
 */
#[Action(
  id: 'ppuc_games_change_addressable_led_kind',
  label: new TranslatableMarkup('Change kind'),
  type: 'node'
)]
final class ChangeAddressableLedKindAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The select value that clears the field.
   */
  private const NONE = '_none';

  /**
   * Constructs a ChangeAddressableLedKindAction object.
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
      'kind' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $kind = $this->configuration['kind'] ?? NULL;

    $form['kind'] = [
      '#type' => 'select',
      '#title' => $this->t('Kind'),
      '#options' => [self::NONE => $this->t('- None (clear the kind) -')] + $this->getKindOptions(),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $kind === 0 ? self::NONE : $kind,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('kind');
    if ($value === self::NONE) {
      return;
    }
    if (!$value || !array_key_exists((int) $value, $this->getKindOptions())) {
      $form_state->setErrorByName('kind', $this->t('Select a valid kind.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('kind');
    // 0 stands for "clear": no term has tid 0.
    $this->configuration['kind'] = $value === self::NONE ? 0 : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): TranslatableMarkup {
    if (!$this->isAddressableLedNode($entity)) {
      return $this->t('Skipped');
    }

    $kind = (int) $this->configuration['kind'];
    if ($kind === 0) {
      $entity->set('field_led_kind', NULL);
    }
    else {
      $entity->set('field_led_kind', ['target_id' => $kind]);
    }
    $entity->save();

    return $this->t('Updated kind');
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
        ->andIf($object->get('field_led_kind')->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Builds select options for the LED kind vocabulary.
   */
  private function getKindOptions(): array {
    $options = [];
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree('led_kind');

    foreach ($terms as $term) {
      $options[(int) $term->tid] = $term->name;
    }

    return $options;
  }

  /**
   * Checks whether an entity is an addressable LED node with the kind field.
   */
  private function isAddressableLedNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'addressable_led'
      && $entity->hasField('field_led_kind');
  }

}

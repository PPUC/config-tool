<?php

namespace Drupal\sequences\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure sequences settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  use DependencySerializationTrait;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sequences_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['sequences.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('sequences.settings');
    $entity_offsets = $config->get('entity_offsets') ?? [];

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure ID offset values for content entity types. An offset will be added to the auto-generated sequence ID for each entity type. Leave empty for no offset.') . '</p>',
    ];

    $form['entity_offsets'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Entity type offsets'),
      '#tree' => TRUE,
    ];

    // Get all content entity types.
    $entity_types = $this->entity_type_manager->getDefinitions();
    foreach ($entity_types as $entity_type_id => $entity_type) {
      // Only show content entity types.
      if ($entity_type->getGroup() !== 'content') {
        continue;
      }

      $form['entity_offsets'][$entity_type_id] = [
        '#type' => 'number',
        '#title' => $entity_type->getLabel() . ' (' . $entity_type_id . ')',
        '#default_value' => $entity_offsets[$entity_type_id] ?? NULL,
        '#min' => 0,
        '#step' => 1,
        '#description' => $this->t('Offset to add to generated IDs. Leave empty for no offset.'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $entity_offsets = $form_state->getValue('entity_offsets');

    foreach ($entity_offsets as $entity_type_id => $offset) {
      if ($offset !== '' && $offset !== NULL) {
        // Ensure it's a valid integer.
        if (!is_numeric($offset) || $offset < 0 || $offset != (int) $offset) {
          $form_state->setErrorByName(
            "entity_offsets][$entity_type_id",
            $this->t('Offset for @entity_type must be a positive integer or empty.', [
              '@entity_type' => $entity_type_id,
            ])
          );
        }
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity_offsets = $form_state->getValue('entity_offsets');

    // Filter out empty values.
    $entity_offsets = array_filter($entity_offsets, function ($value) {
      return $value !== '' && $value !== NULL;
    });

    // Convert to integers.
    $entity_offsets = array_map('intval', $entity_offsets);

    $this->config('sequences.settings')
      ->set('entity_offsets', $entity_offsets)
      ->save();

    parent::submitForm($form, $form_state);
  }

}

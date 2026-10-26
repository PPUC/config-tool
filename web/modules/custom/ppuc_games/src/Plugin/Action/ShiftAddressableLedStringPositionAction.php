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
 * Moves selected addressable LED nodes along their string by an offset.
 *
 * The LEDs of one string move together or not at all: if any of them would
 * leave the string or land on a position held by an LED that is not selected,
 * none of that string's LEDs are moved.
 */
#[Action(
  id: 'ppuc_games_shift_addressable_led_string_position',
  label: new TranslatableMarkup('Shift string position'),
  type: 'node'
)]
final class ShiftAddressableLedStringPositionAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ShiftAddressableLedStringPositionAction object.
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
      'offset' => 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['offset'] = [
      '#type' => 'number',
      '#title' => $this->t('Offset'),
      '#description' => $this->t('Added to the string position of every selected LED, e.g. 1 or -5.'),
      '#default_value' => $this->configuration['offset'] ?? 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $offset = $form_state->getValue('offset');
    if ($offset === NULL || $offset === '' || filter_var($offset, FILTER_VALIDATE_INT) === FALSE) {
      $form_state->setErrorByName('offset', $this->t('Offset must be an integer.'));
    }
    elseif ((int) $offset === 0) {
      $form_state->setErrorByName('offset', $this->t('Offset must not be 0.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['offset'] = (int) $form_state->getValue('offset');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?NodeInterface $entity = NULL): string {
    $results = $this->executeMultiple([$entity]);
    return $results[0]['message'];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, array{message: string, type?: string}>
   *   One result per entity, as views_bulk_operations expects them.
   */
  public function executeMultiple(array $entities): array {
    $offset = (int) $this->configuration['offset'];
    $results = [];
    $by_string = [];
    foreach (array_values($entities) as $delta => $entity) {
      if (!$this->isAddressableLedNode($entity) || $entity->get('field_string')->isEmpty()) {
        $results[$delta] = ['message' => (string) $this->t('Skipped'), 'type' => 'warning'];
        continue;
      }
      $by_string[(int) $entity->get('field_string')->target_id][$delta] = $entity;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($by_string as $string_id => $leds) {
      $string = $storage->load($string_id);
      $amount = $string?->get('field_amount_leds')->value;
      $taken = $this->positionsOfOthers($string_id, array_map(fn(NodeInterface $led) => (int) $led->id(), $leds));

      $problem = NULL;
      foreach ($leds as $led) {
        $position = (int) $led->get('field_string_position')->value + $offset;
        if ($position < 0 || ($amount !== NULL && $position >= (int) $amount)) {
          $problem = $this->t('Not moved: @string would run past its ends', ['@string' => $string?->label() ?? $string_id]);
          break;
        }
        if (isset($taken[$position])) {
          $problem = $this->t('Not moved: position @position on @string belongs to @led, which is not selected', [
            '@position' => $position,
            '@string' => $string?->label() ?? $string_id,
            '@led' => $taken[$position],
          ]);
          break;
        }
      }

      foreach ($leds as $delta => $led) {
        if ($problem !== NULL) {
          $results[$delta] = ['message' => (string) $problem, 'type' => 'warning'];
          continue;
        }
        $led->set('field_string_position', (int) $led->get('field_string_position')->value + $offset);
        $led->save();
        $results[$delta] = ['message' => (string) $this->t('Moved by @offset', ['@offset' => sprintf('%+d', $offset)])];
      }
    }

    ksort($results);
    return $results;
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
        ->andIf($object->get('field_string_position')->access('edit', $account, TRUE));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * The positions on a string held by LEDs other than the given ones.
   *
   * @return array<int, string>
   *   Position to the LED's name.
   */
  private function positionsOfOthers(int $string_id, array $exclude_ids): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'addressable_led')
      ->condition('field_string.target_id', $string_id)
      ->condition('nid', $exclude_ids, 'NOT IN')
      ->execute();

    $taken = [];
    foreach ($storage->loadMultiple($ids) as $led) {
      $taken[(int) $led->get('field_string_position')->value] = (string) $led->label();
    }
    return $taken;
  }

  /**
   * Checks whether an entity is an addressable LED node with a string position.
   */
  private function isAddressableLedNode(mixed $entity): bool {
    return $entity instanceof NodeInterface
      && $entity->bundle() === 'addressable_led'
      && $entity->hasField('field_string_position');
  }

}

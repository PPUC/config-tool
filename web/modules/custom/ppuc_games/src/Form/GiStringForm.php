<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds a run of GI LEDs to an LED string in one go.
 *
 * General illumination is the one kind of LED that comes in runs: thirty LEDs
 * in a row that all belong to the same GI string and all have the same colour.
 * Adding them one at a time through "Add LED" means filling in the same form
 * thirty times with only the position changing.
 *
 * Two steps, because what the second one asks depends on the string chosen in
 * the first: how long it is, which positions are already taken, and whether it
 * has a white channel.
 */
final class GiStringForm extends FormBase {

  private const STEP_STRING = 'string';
  private const STEP_RANGE = 'range';

  /**
   * Protected and not readonly: see GameWizardForm for why a multi-step form
   * cannot have it any other way.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'ppuc_games_gi_string';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'game') {
      throw new NotFoundHttpException();
    }
    $form_state->set('game', (int) $node->id());

    return ($form_state->get('step') ?? self::STEP_STRING) === self::STEP_RANGE
      ? $this->buildRangeStep($form, $form_state)
      : $this->buildStringStep($form, $form_state, $node);
  }

  private function buildStringStep(array $form, FormStateInterface $form_state, NodeInterface $game): array {
    $options = [];
    foreach ($this->stringsOf($game) as $string) {
      $options[$string->id()] = $this->t('@string (@amount LEDs, board @board)', [
        '@string' => $string->label(),
        '@amount' => (int) $string->get('field_amount_leds')->value,
        '@board' => $string->get('field_i_o_board')->entity?->get('field_number')->value ?? '?',
      ]);
    }

    if (!$options) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t(
          '@game has no LED string yet. Add one with "Add LED String" first; the GI LEDs are added to it.',
          ['@game' => $game->label()]
        ) . '</p>',
      ];
      return $form;
    }

    $form['string'] = [
      '#type' => 'radios',
      '#title' => $this->t('LED string'),
      '#description' => $this->t('The string the GI LEDs are on.'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $form_state->get('string'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
      ],
    ];

    return $form;
  }

  private function buildRangeStep(array $form, FormStateInterface $form_state): array {
    $string = $this->entityTypeManager->getStorage('node')->load($form_state->get('string'));
    $amount = (int) $string->get('field_amount_leds')->value;
    $taken = array_keys($this->takenPositions($string));

    $form['info'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('@string', ['@string' => $string->label()]),
      '#items' => [
        $this->t('@amount LEDs, at positions 0 to @last.', ['@amount' => $amount, '@last' => max(0, $amount - 1)]),
        $taken
          ? $this->t('Already defined: @positions.', ['@positions' => self::ranges($taken)])
          : $this->t('No LED is defined on it yet.'),
      ],
    ];

    // The first free position is where a run is most likely to start.
    $firstFree = 0;
    while (in_array($firstFree, $taken, TRUE)) {
      $firstFree++;
    }

    $form['first'] = [
      '#type' => 'number',
      '#title' => $this->t('First LED'),
      '#description' => $this->t('Its position in the string, starting with 0.'),
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
      '#default_value' => $firstFree,
    ];
    $form['last'] = [
      '#type' => 'number',
      '#title' => $this->t('Last LED'),
      '#description' => $this->t('The position of the last LED of the run, inclusive.'),
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['color'] = [
      '#type' => 'color',
      '#title' => $this->t('Default color'),
      '#description' => $this->t('The color when no effect is running and the GI is turned on by the CPU.'),
      '#default_value' => '#ffffff',
      '#required' => TRUE,
    ];
    if ($this->hasWhiteChannel($string)) {
      $form['white'] = [
        '#type' => 'number',
        '#title' => $this->t('White'),
        '#description' => $this->t('White channel value 0-255, since this is a four-channel string.'),
        '#min' => 0,
        '#max' => 255,
        '#step' => 1,
        '#default_value' => 0,
      ];
    }
    $form['number'] = [
      '#type' => 'number',
      '#title' => $this->t('GI string number'),
      '#description' => $this->t(
        'Which of the game\'s GI strings these LEDs belong to. Platforms like WPC '
        . 'divide the GI into strings, usually 1-8, that are dimmed independently. '
        . 'If the platform does not, leave this at 1.'
      ),
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
      '#default_value' => 1,
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('Each LED is named after this, followed by its position: "GI 12".'),
      '#required' => TRUE,
      '#maxlength' => 200,
      '#default_value' => 'GI',
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Add the GI LEDs'),
      ],
      'back' => [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backToString'],
        '#limit_validation_errors' => [],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->get('step') ?? self::STEP_STRING) !== self::STEP_RANGE) {
      // Radios only offer this game's strings, so there is nothing to check.
      return;
    }

    $first = (int) $form_state->getValue('first');
    $last = (int) $form_state->getValue('last');
    if ($last < $first) {
      $form_state->setErrorByName('last', $this->t('The last LED cannot come before the first.'));
      return;
    }

    // Two LEDs at one position would both be exported, and whichever came
    // second would win without anything saying so.
    $string = $this->entityTypeManager->getStorage('node')->load($form_state->get('string'));
    $clashes = array_intersect_key($this->takenPositions($string), array_flip(range($first, $last)));
    if ($clashes) {
      $names = [];
      foreach (array_slice($clashes, 0, 5, TRUE) as $position => $label) {
        $names[] = sprintf('%d ("%s")', $position, $label);
      }
      $form_state->setErrorByName('first', $this->formatPlural(
        count($clashes),
        'Position @list already has an LED. Choose a range that leaves it out, or delete it first.',
        '@count positions in this range already have an LED: @list@more. Choose a range that leaves them out, or delete them first.',
        ['@list' => implode(', ', $names), '@more' => count($clashes) > 5 ? ', ...' : '']
      ));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->get('step') ?? self::STEP_STRING) !== self::STEP_RANGE) {
      $form_state->set('string', (int) $form_state->getValue('string'));
      $form_state->set('step', self::STEP_RANGE);
      $form_state->setRebuild();
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $string = $storage->load($form_state->get('string'));
    $first = (int) $form_state->getValue('first');
    $last = (int) $form_state->getValue('last');

    $values = [
      'type' => 'addressable_led',
      'field_string' => ['target_id' => $string->id()],
      'field_number' => ['value' => (int) $form_state->getValue('number')],
      // The color field stores six hex digits and no hash.
      'field_color' => ['color' => strtoupper(ltrim((string) $form_state->getValue('color'), '#'))],
    ];
    if ($form_state->hasValue('white')) {
      $values['field_white'] = ['value' => (int) $form_state->getValue('white')];
    }
    $role = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'led_role', 'name' => 'GI']);
    if ($role) {
      $values['field_role'] = ['target_id' => reset($role)->id()];
    }
    else {
      $this->messenger()->addWarning($this->t(
        'No "GI" LED role exists, so the LEDs were created without a role. Import the default content that defines it, then set the role.'
      ));
    }

    $name = trim((string) $form_state->getValue('name'));
    for ($position = $first; $position <= $last; $position++) {
      $storage->create($values + [
        'title' => sprintf('%s %d', $name, $position),
        'field_string_position' => ['value' => $position],
      ])->save();
    }

    $this->messenger()->addStatus($this->formatPlural(
      $last - $first + 1,
      'Added 1 GI LED to @string, at position @first.',
      'Added @count GI LEDs to @string, at positions @first to @last.',
      ['@string' => $string->label(), '@first' => $first, '@last' => $last]
    ));

    // A string shorter than its last LED would have the firmware drive fewer
    // LEDs than were just defined, and the ones past the end would stay dark.
    $amount = (int) $string->get('field_amount_leds')->value;
    if ($last + 1 > $amount) {
      $string->set('field_amount_leds', $last + 1)->save();
      $this->messenger()->addStatus($this->t(
        '@string was set to @old LEDs, which is shorter than that, so its amount of LEDs is now @new.',
        ['@string' => $string->label(), '@old' => $amount, '@new' => $last + 1]
      ));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $form_state->get('game')]);
  }

  /**
   * Returns to choosing the string.
   */
  public function backToString(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', self::STEP_STRING);
    $form_state->setRebuild();
  }

  /**
   * The LED strings of a game, by board and then name.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function stringsOf(NodeInterface $game): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'addressable_leds')
      ->condition('field_i_o_board.entity.field_game.target_id', $game->id())
      ->sort('field_i_o_board.entity.field_number.value')
      ->sort('title')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * The positions on a string that already have an LED.
   *
   * @return array<int, string>
   *   Position to the LED's name, in position order.
   */
  private function takenPositions(NodeInterface $string): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'addressable_led')
      ->condition('field_string.target_id', $string->id())
      ->execute();

    $taken = [];
    foreach ($storage->loadMultiple($ids) as $led) {
      $taken[(int) $led->get('field_string_position')->value] = (string) $led->label();
    }
    ksort($taken);
    return $taken;
  }

  /**
   * Whether the string's LEDs have a fourth, white channel.
   */
  private function hasWhiteChannel(NodeInterface $string): bool {
    return str_contains(strtoupper($string->get('field_led_type')->entity?->getName() ?? ''), 'W');
  }

  /**
   * A list of numbers with its runs collapsed: 0-3, 7, 9-11.
   *
   * @param int[] $numbers
   *   In ascending order.
   */
  private static function ranges(array $numbers): string {
    $ranges = [];
    $start = $previous = NULL;
    foreach ($numbers as $number) {
      if ($previous !== NULL && $number === $previous + 1) {
        $previous = $number;
        continue;
      }
      if ($start !== NULL) {
        $ranges[] = $start === $previous ? (string) $start : $start . '-' . $previous;
      }
      $start = $previous = $number;
    }
    if ($start !== NULL) {
      $ranges[] = $start === $previous ? (string) $start : $start . '-' . $previous;
    }
    return implode(', ', $ranges);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ppuc_games\Wizard\BoardCapacity;
use Drupal\ppuc_games\Wizard\DeviceDataParser;
use Drupal\ppuc_games\Wizard\GameBuilder;
use Drupal\ppuc_games\Wizard\HardwareAllocator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a whole game from a transcription of its manual.
 *
 * Two steps. The first takes the JSON and refuses it if anything is wrong. The
 * second shows what would be created - board by board, so the allocation can be
 * checked before ~150 nodes exist rather than after.
 *
 * The wizard deliberately does not create anything on the first submit. An
 * allocation that puts a flipper button on the wrong board is easy to see and
 * tedious to undo.
 */
final class GameWizardForm extends FormBase {

  private const STEP_INPUT = 'input';
  private const STEP_REVIEW = 'review';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly GameBuilder $gameBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ppuc_games.game_builder'),
    );
  }

  public function getFormId(): string {
    return 'ppuc_games_wizard';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $form_state->get('step') ?? self::STEP_INPUT;

    return $step === self::STEP_REVIEW
      ? $this->buildReviewStep($form, $form_state)
      : $this->buildInputStep($form, $form_state);
  }

  private function buildInputStep(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Creates a game, its I/O boards, switches, coils and LEDs from a JSON '
        . 'description of the three tables in its operator manual: the switch '
        . 'matrix, the lamp matrix and the solenoid/flashlamp table. See the '
        . 'README for the format. Nothing is created until you have seen what '
        . 'the allocation looks like.'
      ) . '</p>',
    ];

    $form['json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Device data (JSON)'),
      '#rows' => 20,
      '#required' => TRUE,
      '#default_value' => $form_state->get('json') ?? '',
      '#description' => $this->t(
        'Numbers come from the manual: matrix switches are column x 10 + row, '
        . 'coils are their solenoid number, lamps their lamp matrix number. '
        . 'Switches outside the matrix are numbered by platform and do not '
        . 'belong in this document.'
      ),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Check and preview'),
      ],
    ];

    return $form;
  }

  private function buildReviewStep(array $form, FormStateInterface $form_state): array {
    $devices = $form_state->get('devices');
    $plan = $form_state->get('plan');

    $form['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('@title (@platform) will be created with:', [
        '@title' => $devices['game']['title'],
        '@platform' => $devices['game']['platform'],
      ]),
      '#items' => [
        $this->formatPlural(count($plan['boards']), '1 I/O board', '@count I/O boards'),
        $this->formatPlural(count($plan['switches']), '1 switch', '@count switches'),
        $this->formatPlural(count($plan['coils']), '1 PWM device', '@count PWM devices'),
        $this->formatPlural(count($plan['stripes']), '1 LED stripe', '@count LED stripes'),
      ],
    ];

    $rows = [];
    foreach ($plan['boards'] as $board) {
      $switches = array_filter($plan['switches'], static fn ($s) => $s['board'] === $board['index']);
      $coils = array_filter($plan['coils'], static fn ($c) => $c['board'] === $board['index']);
      $stripes = array_filter($plan['stripes'], static fn ($s) => $s['board'] === $board['index']);

      $rows[] = [
        $board['index'],
        $board['description'],
        count($switches),
        count($coils),
        $stripes ? reset($stripes)['label'] : $this->t('none'),
      ];
    }

    $form['boards'] = [
      '#type' => 'table',
      '#caption' => $this->t('Board allocation'),
      '#header' => [
        $this->t('Board'),
        $this->t('Description'),
        $this->t('Switches'),
        $this->t('PWM devices'),
        $this->t('LED stripe'),
      ],
      '#rows' => $rows,
    ];

    $flipperRows = [];
    foreach ($devices['flippers'] as $flipper) {
      $boards = [];
      foreach ($plan['switches'] as $switch) {
        if (($switch['flipper'] ?? NULL) === $flipper['name']) {
          $boards[$switch['role']] = $switch['board'];
        }
      }
      foreach ($plan['coils'] as $coil) {
        if (($coil['flipper'] ?? NULL) === $flipper['name']) {
          $boards[$coil['role']] = $coil['board'];
        }
      }
      $flipperRows[] = [
        $flipper['name'],
        $flipper['buttonSwitch'],
        implode(', ', array_unique($boards)),
      ];
    }

    if ($flipperRows) {
      $form['flippers'] = [
        '#type' => 'table',
        '#caption' => $this->t(
          'Flippers. The button, the EOS and both windings must be on one board, '
          . 'or the board cannot react to the button without waiting for the host.'
        ),
        '#header' => [$this->t('Flipper'), $this->t('Button switch'), $this->t('Board')],
        '#rows' => $flipperRows,
      ];
    }

    $notes = $plan['notes'];
    $notes[] = $this->t(
      'The flipper power windings are set to @ms ms. That is a conservative '
      . 'starting value, not something the manual states - check it on a bench '
      . 'before relying on it.',
      ['@ms' => \Drupal\ppuc_games\Wizard\DeviceDefaults::FLIPPER_POWER_MAX_PULSE_TIME_MS]
    );

    $form['notes'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Worth knowing'),
      '#items' => $notes,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Create the game'),
      ],
      'back' => [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backToInput'],
        '#limit_validation_errors' => [],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->get('step') ?? self::STEP_INPUT) !== self::STEP_INPUT) {
      return;
    }

    $json = (string) $form_state->getValue('json');
    $parser = new DeviceDataParser();
    $devices = $parser->parse($json);

    if ($devices === NULL) {
      // Every message names its entry, so all of them at once beats one per
      // round trip through a 150-line document.
      foreach ($parser->errors() as $error) {
        $form_state->setErrorByName('json', $error);
      }
      return;
    }

    $mappings = $this->gpioMappings();
    foreach ([BoardCapacity::IO_16_8_1, BoardCapacity::OPTO_16] as $type) {
      if (empty($mappings[$type])) {
        $form_state->setErrorByName('json', $this->t(
          'No "@type" board type exists, or it has no GPIO mapping. The wizard '
          . 'cannot work out which pins that board has. Import the default '
          . 'content that defines the board types first.',
          ['@type' => $type]
        ));
        return;
      }
    }

    $form_state->set('json', $json);
    $form_state->set('devices', $devices);
    $form_state->set('plan', (new HardwareAllocator(
      $mappings[BoardCapacity::IO_16_8_1],
      $mappings[BoardCapacity::OPTO_16]
    ))->allocate($devices));
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $step = $form_state->get('step') ?? self::STEP_INPUT;

    if ($step === self::STEP_INPUT) {
      $form_state->set('step', self::STEP_REVIEW);
      $form_state->setRebuild();
      return;
    }

    $result = $this->gameBuilder->build(
      $form_state->get('devices'),
      $form_state->get('plan'),
      $form_state->get('json')
    );

    $counts = $result['counts'];
    $this->messenger()->addStatus($this->t(
      'Created @game with @boards boards, @switches switches, @coils PWM devices '
      . 'and @leds LEDs in @stripes stripes.',
      [
        '@game' => $result['game']->label(),
        '@boards' => $counts['boards'],
        '@switches' => $counts['switches'],
        '@coils' => $counts['coils'],
        '@leds' => $counts['leds'],
        '@stripes' => $counts['stripes'],
      ]
    ));

    foreach ($result['warnings'] as $warning) {
      $this->messenger()->addWarning($warning);
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $result['game']->id()]);
  }

  /**
   * Returns to the input step keeping what was typed.
   */
  public function backToInput(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', self::STEP_INPUT);
    $form_state->setRebuild();
  }

  /**
   * Board type name to its connector-pin-to-GPIO mapping.
   *
   * @return array<string, array<int, int>>
   */
  private function gpioMappings(): array {
    $mappings = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'i_o_board']);
    foreach ($terms as $term) {
      if (!$term->hasField('field_gpio_mapping') || $term->get('field_gpio_mapping')->isEmpty()) {
        continue;
      }
      $mapping = unserialize($term->get('field_gpio_mapping')->value, ['allowed_classes' => FALSE]);
      if (is_array($mapping)) {
        $mappings[$term->label()] = $mapping;
      }
    }
    return $mappings;
  }

}

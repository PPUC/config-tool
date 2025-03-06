<?php

namespace Drupal\ppuc_games\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\default_content_deploy\ExporterInterface;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Form\GameImportForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * ConfigDownloadController.
 */
class GamesController extends ControllerBase  {

  public function __construct(protected FileSystemInterface $fileSystem, protected ExporterInterface $exporter) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): GamesController {
    return new static(
      $container->get('file_system'),
      $container->get('default_content_deploy.exporter')
    );
  }

  public function sortEntitiesByNumberField($a, $b): int {
    if ($a->field_number->value == $b->field_number->value) {
      return 0;
    }
    return ($a->field_number->value > $b->field_number->value) ? 1 : -1;
  }

  public function sortEntitiesByMatrixPartAndNumberField($a, $b): int {
    if ($a->field_matrix_part->target_id == $b->field_matrix_part->target_id) {
      return $this->sortEntitiesByNumberField($a, $b);
    }
    return ($a->field_number->value > $b->field_number->value) ? 1 : -1;
  }

  public function sortEntitiesById($a, $b): int {
    if ($a->id() == $b->id()) {
      return 0;
    }
    return ($a->id() > $b->id()) ? 1 : -1;
  }

  public function sortArrayByNumberValues($a, $b): int {
    if ($a['number'] == $b['number']) {
      return 0;
    }
    return ($a['number'] > $b['number']) ? 1 : -1;
  }

  /**
   * @param \Drupal\node\NodeInterface $node
   * @param array $objects
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function buildYaml(NodeInterface $node, array &$objects): array {
    /** @var \Drupal\taxonomy\TermInterface $platform */
    $platform = $node->get('field_platform')->entity;
    $yaml = [
      'ppucVersion' => 1,
      'rom' => 'dummy',
      'serialPort' => $node->field_serial_port->value ?? 'dummy',
      'platform' => $platform->getName(),
      'coinDoorClosedSwitch' => (int)($node->field_coin_door_closed_switch->value ?? 0),
      'gameOnSolenoid' => (int)($node->field_game_on_solenoid->value ?? 0),
      'debug' => false,
      'boards' => [],
      'dipSwitches' => [],
      'switches' => [],
      'ledStripes' => [],
      'pwmOutput' => [],
      'mechs' => [],
    ];

    $storage = $this->entityTypeManager()->getStorage($node->getEntityTypeId());

    $dip_switches = $storage->loadByProperties([
      'field_game' => $node->id(),
      $node->getEntityType()->getKey('bundle') => 'dip_switch',
    ]);
    uasort($dip_switches, [$this, 'sortEntitiesByNumberField']);

    /** @var NodeInterface $dip_switch */
    foreach ($dip_switches as $dip_switch) {
      $objects[] = $dip_switch;
      if (!$dip_switch->isPublished()) {
        continue;
      }
      $yaml['dipSwitches'][] = [
        'description' => trim($dip_switch->label()),
        'number' => (int)($dip_switch->get('field_number')->value),
        'on' => (bool)($dip_switch->get('field_status')->value),
      ];
    }

    $i_o_boards = $storage->loadByProperties([
      'field_game' => $node->id(),
      $node->getEntityType()->getKey('bundle') => 'i_o_board',
    ]);
    uasort($i_o_boards, [$this, 'sortEntitiesByNumberField']);

    /** @var NodeInterface $i_o_board */
    foreach ($i_o_boards as $i_o_board) {
      $objects[] = $i_o_board;
      $i_o_board_number = (int)($i_o_board->get('field_number')->value);
      $i_o_board_type = $i_o_board->get('field_io_board_type')->entity;
      $i_o_board_gpio_mapping = unserialize($i_o_board_type->field_gpio_mapping->value, ['allowed_classes' => FALSE]);
      $poll_events = FALSE;

      // Switches, PWM, LED strings.
      $devices = $storage->loadByProperties([
        'field_i_o_board' => $i_o_board->id(),
      ]);
      /** @var NodeInterface $device */
      foreach ($devices as $device) {
        $objects[] = $device;
        switch ($device->bundle()) {
          case 'switch':
            if ($i_o_board->isPublished() && $device->isPublished()) {
              $yaml['switches'][] = [
                'description' => trim($device->label()),
                'number' => (int) ($device->get('field_number')->value),
                'board' => $i_o_board_number,
                'port' => $i_o_board_gpio_mapping[(int) ($device->get('field_pin')->value)],
              ];

              $poll_events = TRUE;
            }
            break;

          case 'switch_matrix':
            $switch_matrix = [
              'columns' => [],
              'rows' => [],
            ];

            $switch_matrix_parts = $storage->loadByProperties([
              'field_switch_matrix' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'switch_matrix_column_row',
            ]);
            uasort($switch_matrix_parts, [$this, 'sortEntitiesByMatrixPartAndNumberField']);
            /** @var NodeInterface $switch_matrix_part */
            foreach ($switch_matrix_parts as $switch_matrix_part) {
              $objects[] = $switch_matrix_part;
              $part = '';
              switch ($switch_matrix_part->get('field_matrix_part')->entity->uuid()) {
                case '71d21092-dbdc-4741-9894-194b28fd5228':
                  $part = 'columns';

                  break;

                case '1dc42e0d-2332-4623-a2f2-2d23b1fe9e08':
                  $part = 'rows';

                  break;
              }

              if ($switch_matrix_part->isPublished()) {
                $switch_matrix[$part][] = [
                  'description' => trim($switch_matrix_part->label()),
                  'number' => (int) ($switch_matrix_part->get('field_number')->value),
                  'port' => $i_o_board_gpio_mapping[(int) ($switch_matrix_part->get('field_pin')->value)],
                ];
              }
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $yaml['switchMatrix'] = [
                  'description' => trim($device->label()),
                  'board' => $i_o_board_number,
                  'activeLow' => (bool) ($device->get('field_active_low')->value),
                  'pulseTime' => (int) ($device->get('field_pulse_time')->value),
                ] + $switch_matrix;
            }

            break;

          case 'pwm_device':
            $type = '';
            switch ($device->get('field_pwm_type')->entity->uuid()) {
              case '620014f7-3bb6-4413-8d22-284706357dbb':
                $type = 'flasher';

                break;

              case '08c2b5ce-2209-4e13-89be-e95f2d4acdb4':
                $type = 'lamp';

                break;

              default:
                $type = 'coil';

                break;
            }

            $pwm_effects = $storage->loadByProperties([
              'field_pwm_device' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'pwm_effect',
            ]);
            uasort($pwm_effects, [$this, 'sortEntitiesById']);

            $effects = [];
            /** @var NodeInterface $pwm_effect */
            foreach ($pwm_effects as $pwm_effect) {
              $objects[] = $pwm_effect;
              if (!$pwm_effect->isPublished()) {
                continue;
              }
              $trigger = [];
              foreach (explode('/', $pwm_effect->get('field_trigger')->value) as $t) {
                if (preg_match('/([SWLDE])([0-9]+)\s*(ON|OFF)/', $t, $matches)) {
                  $trigger[] = [
                    'source' => $matches[1],
                    'number' => (int)($matches[2]),
                    'value' => ($matches[3] === 'OFF') ? 0 : 1,
                  ];
                }
              }

              $effects[] = [
                'description' => trim($pwm_effect->label()),
                'duration' => (int)($pwm_effect->get('field_duration')->value),
                'effect' => (int)($pwm_effect->get('field_pwm_effect')->value),
                'frequency' => (int)($pwm_effect->get('field_frequency')->value),
                'maxIntensity' => (int)($pwm_effect->get('field_max_intensity')->value),
                'minIntensity' => (int)($pwm_effect->get('field_min_intensity')->value),
                'mode' => (int)($pwm_effect->get('field_mode')->value),
                'priority' =>  (int)($pwm_effect->get('field_priority')->value),
                'repeat' => (int)($pwm_effect->get('field_repeat')->value),
                'trigger' => $trigger,
              ];
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $yaml['pwmOutput'][] = [
                'description' => trim($device->label()),
                'type' => $type,
                'number' => (int) ($device->get('field_number')->value),
                'board' => $i_o_board_number,
                'port' => $i_o_board_gpio_mapping[(int) ($device->get('field_pin')->value)],
                'power' => (int) ($device->get('field_power')->value),
                'holdPower' => (int) ($device->get('field_hold_power')->value),
                'holdPowerActivationTime' => (int) ($device->get('field_hold_power_activation_time')->value),
                'minPulseTime' => (int) ($device->get('field_min_pulse_time')->value),
                'maxPulseTime' => (int) ($device->get('field_max_pulse_time')->value),
                'fastFlipSwitch' => (int) ($device->get('field_fast_activation_switch')->entity->field_number->value ?? 0),
                'effects' => $effects,
              ];
            }
            break;

          case 'addressable_leds':
            $leds = [
              'lamps' => [],
              'flashers' => [],
              'gi' => [],
            ];

            $addressable_leds = $storage->loadByProperties([
              'field_string' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'addressable_led',
            ]);
            uasort($addressable_leds, [$this, 'sortEntitiesByNumberField']);
            /** @var NodeInterface $addressable_led */
            foreach ($addressable_leds as $addressable_led) {
              $objects[] = $addressable_led;
              if (!$addressable_led->isPublished()) {
                continue;
              }
              $role = '';
              switch ($addressable_led->get('field_role')->entity->uuid()) {
                case '380dd744-eef0-4bb8-9b62-d6d4ac2af2c6':
                  $role = 'lamps';

                  break;

                case '5545fe5f-e4a0-489d-b200-4416807f17c9':
                  $role = 'flashers';

                  break;

                case 'abf972eb-9d90-4c98-9d84-926854d07f73':
                  $role = 'gi';

                  break;
              }

              $leds[$role][] = [
                'description' => trim($addressable_led->label()),
                'number' => (int)($addressable_led->get('field_number')->value),
                'ledNumber' => (int)($addressable_led->get('field_string_position')->value),
                'color' => $addressable_led->get('field_color')->color,
              ];
            }

            $led_effects = $storage->loadByProperties([
              'field_string' => $device->id(),
              $node->getEntityType()->getKey('bundle') => 'led_effect',
            ]);
            uasort($led_effects, [$this, 'sortEntitiesById']);

            $effects = [];
            /** @var NodeInterface $led_effect */
            foreach ($led_effects as $led_effect) {
              $objects[] = $led_effect;
              if (!$led_effect->isPublished()) {
                continue;
              }
              $trigger = [];
              foreach (explode('/', $led_effect->get('field_trigger')->value) as $t) {
                if (preg_match('/([SWLDE])([0-9]+)\s*(ON|OFF)/', $t, $matches)) {
                  $trigger[] = [
                    'source' => $matches[1],
                    'number' => (int)($matches[2]),
                    'value' => ($matches[3] === 'OFF') ? 0 : 1,
                  ];
                }
              }

              $effects[] = [
                'description' => trim($led_effect->label()),
                'color' => (int)($led_effect->get('field_color')->value),
                'duration' => (int)($led_effect->get('field_duration')->value),
                'effect' => (int)($led_effect->get('field_effect')->value),
                'reverse' => (int)($led_effect->get('field_reverse')->value),
                'segment' => (int)($led_effect->get('field_segment')->value),
                'speed' => (int)($led_effect->get('field_speed')->value),
                'mode' => (int)($led_effect->get('field_mode')->value),
                'priority' =>  (int)($led_effect->get('field_priority')->value),
                'repeat' => (int)($led_effect->get('field_repeat')->value),
                'trigger' => $trigger,
              ];
            }

            if ($i_o_board->isPublished() && $device->isPublished()) {
              $segments = [];
              /** @var \Drupal\range\Plugin\Field\FieldType\RangeIntegerItem $segment */
              foreach ($device->get('field_segments') as $number => $segment) {
                $segments[] = [
                  'number' => (int) $number,
                  'from' => (int) ($segment->get('from')->getValue()),
                  'to' => (int) ($segment->get('to')->getValue()),
                ];
              }

              $yaml['ledStripes'][] = [
                  'description' => trim($device->label()),
                  'board' => $i_o_board_number,
                  'port' => $i_o_board_gpio_mapping[(int) ($device->get('field_pin')->value)],
                  'ledType' => $device->get('field_led_type')->entity->getName(),
                  'brightness' => (int) ($device->get('field_brightness')->value),
                  'amount' => (int) ($device->get('field_amount_leds')->value),
                  'lightUp' => (int) ($device->get('field_light_up')->value),
                  'afterGlow' => (int) ($device->get('field_after_glow')->value),
                  'segments' => $segments,
                ] + $leds + ['effects' => $effects];
            }

            break;
        }
      }

      if ($i_o_board->isPublished()) {
        usort($yaml['switches'], [$this, 'sortArrayByNumberValues']);
        usort($yaml['pwmOutput'], [$this, 'sortArrayByNumberValues']);

        $yaml['boards'][] = [
          'description' => trim($i_o_board->label()),
          'number' => $i_o_board_number,
          'pollEvents' => $poll_events,
        ];
      }
    }

    return $yaml;
  }

  /**
   * @param \Drupal\node\NodeInterface $node
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response object.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function streamPinMameYaml(NodeInterface $node): Response {
    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '.yml', 'yml');
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_filename = $event->getFilename();

    $objects = [];

    return new Response(
      Yaml::encode($this->buildYaml($node, $objects)),
      200,
      [
        'Content-Type' => 'application/yaml',
        'Content-Disposition' => 'attachment; filename=' . $sanitized_filename,
      ]
    );
  }

  /**
   * Streams a tar.gz archive containing a complete game configuration.
   */
  public function streamGameZip(NodeInterface $node): Response {
    $this->exporter->setFolder($this->fileSystem->getTempDirectory() . '/dcd/content');
    $this->exporter->setSkipEntityTypeIds(['user', 'taxonomy_term']);
    $this->exporter->setForceUpdate(TRUE);
    $this->exporter->exportEntity($node, TRUE);
    $this->exporter->setForceUpdate(FALSE);

    $objects = [];
    $this->buildYaml($node, $objects);
    foreach ($objects as $object) {
      $this->exporter->exportEntity($object);
    }

    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '.tar.gz', '.tar.gz');
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_filename = $event->getFilename();

    // Redirect for download archive file.
    return $this->redirect('default_content_deploy.export.download', ['file_name' => $sanitized_filename]);
  }

  public function importGameZip(): array {
    return \Drupal::formBuilder()->getForm(GameImportForm::class);
  }

  public function title(NodeInterface $node): ?string {
    return $node->getTitle();
  }

}

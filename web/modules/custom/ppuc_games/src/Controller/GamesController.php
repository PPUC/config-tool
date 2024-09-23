<?php

namespace Drupal\ppuc_games\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Form\GameImportForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * ConfigDownloadController.
 */
class GamesController extends ControllerBase  {

  /**
   * The File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * DownloadController constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The File system.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system')
    );
  }

  public function sortEntitiesByNumberField($a, $b) {
    if ($a->field_number->value == $b->field_number->value) {
      return 0;
    }
    return ($a->field_number->value > $b->field_number->value) ? 1 : -1;
  }

  public function sortEntitiesByMatrixPartAndNumberField($a, $b) {
    if ($a->field_matrix_part->target_id == $b->field_matrix_part->target_id) {
      return $this->sortEntitiesByNumberField($a, $b);
    }
    return ($a->field_number->value > $b->field_number->value) ? 1 : -1;
  }

  public function sortArrayByNumberValues($a, $b){
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
   */
  protected function buildYaml(NodeInterface $node, array &$objects): array {
    /** @var \Drupal\taxonomy\TermInterface $platform */
    $platform = $node->field_platform->entity;
    $yaml = [
      'ppucVersion' => 1,
      'rom' => 'dummy',
      'serialPort' => $node->field_serial_port->value ?? 'dummy',
      'platform' => $platform->getName(),
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
      'status' => TRUE,
      $node->getEntityType()->getKey('bundle') => 'dip_switch',
    ]);
    uasort($dip_switches, [$this, 'sortEntitiesByNumberField']);

    foreach ($dip_switches as $dip_switch) {
      $objects[] = $dip_switch;
      $yaml['dipSwitches'][] = [
        'description' => trim($dip_switch->label()),
        'number' => (int)($dip_switch->field_number->value),
        'on' => (bool)($dip_switch->field_status->value),
      ];
    }

    $i_o_boards = $storage->loadByProperties([
      'field_game' => $node->id(),
      'status' => TRUE,
      $node->getEntityType()->getKey('bundle') => 'i_o_board',
    ]);
    uasort($i_o_boards, [$this, 'sortEntitiesByNumberField']);

    foreach ($i_o_boards as $i_o_board) {
      $objects[] = $i_o_board;
      $i_o_board_number = (int)($i_o_board->field_number->value);
      $i_o_board_type = $i_o_board->field_io_board_type->entity;
      $i_o_board_gpio_mapping = unserialize($i_o_board_type->field_gpio_mapping->value, ['allowed_classes' => FALSE]);
      $poll_events = FALSE;

      // Switches, PWM, LED strings.
      $devices = $storage->loadByProperties([
        'field_i_o_board' => $i_o_board->id(),
        'status' => TRUE,
      ]);
      foreach ($devices as $device) {
        $objects[] = $device;
        switch ($device->bundle()) {
          case 'switch':
            $yaml['switches'][] = [
              'description' => trim($device->label()),
              'number' => (int)($device->field_number->value),
              'board' => $i_o_board_number,
              'port' => $i_o_board_gpio_mapping[(int)($device->field_pin->value)],
            ];

            $poll_events = TRUE;

            break;

          case 'switch_matrix':
            $switch_matrix = [
              'columns' => [],
              'rows' => [],
            ];

            $switch_matrix_parts = $storage->loadByProperties([
              'field_switch_matrix' => $device->id(),
              'status' => TRUE,
              $node->getEntityType()->getKey('bundle') => 'switch_matrix_column_row',
            ]);
            uasort($switch_matrix_parts, [$this, 'sortEntitiesByMatrixPartAndNumberField']);

            foreach ($switch_matrix_parts as $switch_matrix_part) {
              $objects[] = $switch_matrix_part;
              $part = '';
              switch ($switch_matrix_part->field_matrix_part->entity->uuid()) {
                case '71d21092-dbdc-4741-9894-194b28fd5228':
                  $part = 'columns';

                  break;

                case '1dc42e0d-2332-4623-a2f2-2d23b1fe9e08':
                  $part = 'rows';

                  break;
              }

              $switch_matrix[$part][] = [
                'description' => trim($switch_matrix_part->label()),
                'number' => (int)($switch_matrix_part->field_number->value),
                'port' => $i_o_board_gpio_mapping[(int)($switch_matrix_part->field_pin->value)],
              ];
            }

            $yaml['switchMatrix'] = [
                'description' => trim($device->label()),
                'board' => $i_o_board_number,
                'activeLow' => (bool)($device->field_active_low->value),
                'pulseTime' => (int)($device->field_pulse_time->value),
              ] + $switch_matrix;

            break;

          case 'pwm_device':
            $type = '';
            switch ($device->field_pwm_type->entity->uuid()) {
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

            $yaml['pwmOutput'][] = [
              'description' => trim($device->label()),
              'type' => $type,
              'number' => (int)($device->field_number->value),
              'board' => $i_o_board_number,
              'port' => $i_o_board_gpio_mapping[(int)($device->field_pin->value)],
              'power' => (int)($device->field_power->value),
              'holdPower' => (int)($device->field_hold_power->value),
              'holdPowerActivationTime' => (int)($device->field_hold_power_activation_time->value),
              'minPulseTime' => (int)($device->field_min_pulse_time->value),
              'maxPulseTime' => (int)($device->field_max_pulse_time->value),
              'fastFlipSwitch' => (int)($device->field_fast_activation_switch->entity->field_number->value ?? 0),
            ];

            break;

          case 'addressable_leds':
            $leds = [
              'lamps' => [],
              'flashers' => [],
              'gi' => [],
            ];

            $addressable_leds = $storage->loadByProperties([
              'field_string' => $device->id(),
              'status' => TRUE,
              $node->getEntityType()->getKey('bundle') => 'addressable_led',
            ]);
            uasort($addressable_leds, [$this, 'sortEntitiesByNumberField']);

            foreach ($addressable_leds as $addressable_led) {
              $objects[] = $addressable_led;
              $role = '';
              switch ($addressable_led->field_role->entity->uuid()) {
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
                'number' => (int)($addressable_led->field_number->value),
                'ledNumber' => (int)($addressable_led->field_string_position->value),
                'color' => $addressable_led->field_color->color,
              ];
            }

            $yaml['ledStripes'][] = [
                'description' => trim($device->label()),
                'board' => $i_o_board_number,
                'port' => $i_o_board_gpio_mapping[(int)($device->field_pin->value)],
                'ledType' => $device->field_led_type->entity->getName(),
                'amount' => (int)($device->field_amount_leds->value),
                'lightUp' => (int)($device->field_light_up->value),
                'afterGlow' => (int)($device->field_after_glow->value),
              ] + $leds;

            break;
        }
      }

      usort($yaml['switches'], [$this, 'sortArrayByNumberValues']);
      usort($yaml['pwmOutput'], [$this, 'sortArrayByNumberValues']);

      $yaml['boards'][] = [
        'description' => trim($i_o_board->label()),
        'number' => $i_o_board_number,
        'pollEvents' => $poll_events,
      ];
    }

    return $yaml;
  }

  /**
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response object.
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
    /** @var \Drupal\default_content_deploy\Exporter $exporter */
    $exporter = \Drupal::service('default_content_deploy.exporter');
    $exporter->setFolder($this->fileSystem->getTempDirectory() . '/dcd/content');
    $exporter->setSkipEntityTypeIds(['user', 'taxonomy_term']);
    $exporter->setForceUpdate(TRUE);
    $exporter->exportEntity($node, TRUE);
    $exporter->setForceUpdate(FALSE);

    $objects = [];
    $this->buildYaml($node, $objects);
    foreach ($objects as $object) {
      $exporter->exportEntity($object);
    }

    $event = new FileUploadSanitizeNameEvent(str_replace(' ', '_', $node->getTitle()) . '_' . $node->uuid() . '.tar.gz', '.tar.gz');
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_filename = $event->getFilename();

    // Redirect for download archive file.
    return $this->redirect('default_content_deploy.export.download', ['file_name' => $sanitized_filename]);
  }

  public function importGameZip() {
    return \Drupal::formBuilder()->getForm(GameImportForm::class);
  }

  public function title(NodeInterface $node) {
    return $node->getTitle();
  }

}

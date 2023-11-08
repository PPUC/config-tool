<?php

namespace Drupal\ppuc_games\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\default_content\ExporterInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\ppuc_games\Form\GameImportForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\ZipStream;

/**
 * ConfigDownloadController.
 */
class GamesController extends ControllerBase {

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
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response object.
   */
  public function streamPinMameYaml(NodeInterface $node): Response {
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
      $i_o_board_number = (int)($i_o_board->field_number->value);
      $i_o_board_type = $i_o_board->field_io_board_type->entity;
      $i_o_board_gpio_mapping =  unserialize($i_o_board_type->field_gpio_mapping->value, ['allowed_classes' => FALSE]);
      $poll_events = FALSE;

      // Switches, PWM, LED strings.
      $devices = $storage->loadByProperties([
        'field_i_o_board' => $i_o_board->id(),
        'status' => TRUE,
      ]);
      foreach ($devices as $device) {
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
                'brightness' => (int)($addressable_led->field_brightness->value),
                'color' => $addressable_led->field_color->color,
              ];
            }

            $yaml['ledStripes'][] = [
              'description' => trim($device->label()),
              'board' => $i_o_board_number,
              'port' => $i_o_board_gpio_mapping[(int)($device->field_pin->value)],
              'ledType' => $device->field_led_type->entity->getName(),
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

    return new Response(
      Yaml::encode($yaml),
      200,
      [
        'Content-Type' => 'application/yaml',
        'Content-Disposition' => 'attachment; filename=game.yml',
      ]
    );
  }

  /**
   * Streams a zip archive containing a complete Solr configuration.
   */
  public function streamGameZip(NodeInterface $node): Response {

    try {
      @ob_clean();
      // If you are using nginx as a webserver, it will try to buffer the
      // response. We have to disable this with a custom header.
      // @see https://github.com/maennchen/ZipStream-PHP/wiki/nginx
      header('X-Accel-Buffering: no');
      $zip = new ZipStream(
        enableZip64: false,
        outputName: $node->uuid() . '.zip'
      );

      /** @var ExporterInterface $exporter */
      $exporter = \Drupal::service('default_content.exporter');
      /** @var EntityRepositoryInterface $repository */
      $repository = \Drupal::service('entity.repository');
      $nodePath = 'content/node/';

      $gameExport = $exporter->exportContentWithReferences('node', $node->id());
      foreach ($gameExport as $entityType => $serializedEntities) {
        if ($entityType !== 'user' && $entityType !== 'taxonomy_term') {
          foreach ($serializedEntities as $uuid => $serializedEntity) {
            $zip->addFile('content/' . $entityType . '/' . $uuid . '.yml', $serializedEntity);
            // For files, copy the file into the same folder.
            $entity = $repository->loadEntityByUuid($entityType, $uuid);
            if ($entity instanceof FileInterface) {
              $zip->addFileFromPath('content/' . $entityType . '/' . $entity->getFilename(), $entity->getFileUri());
            }
          }
        }
      }

      $storage = $this->entityTypeManager()->getStorage($node->getEntityTypeId());
      $i_o_boards_and_dip_switches = $storage->loadByProperties([
        'field_game' => $node->id(),
      ]);

      foreach ($i_o_boards_and_dip_switches as $i_o_board_or_dip_switch) {
        $zip->addFile($nodePath . $i_o_board_or_dip_switch->uuid() . '.yml', $exporter->exportContent('node', $i_o_board_or_dip_switch->id()));

        // Switches, PWM, LED strings.
        $devices = $storage->loadByProperties([
          'field_i_o_board' => $i_o_board_or_dip_switch->id(),
        ]);
        foreach ($devices as $device) {
          $zip->addFile($nodePath . $device->uuid() . '.yml', $exporter->exportContent('node', $device->id()));

          $leds = $storage->loadByProperties([
            'field_string' => $device->id(),
          ]);

          foreach ($leds as $led) {
            $zip->addFile($nodePath . $led->uuid() . '.yml', $exporter->exportContent('node', $led->id()));
          }
        }
      }

      $zip->finish();
      @ob_end_flush();
      exit();
    }
    catch (\Exception $e) {
      watchdog_exception('ppuc', $e);
      $this->messenger()->addError($this->t('An error occurred during the creation of the game zip. Look at the logs for details.'));
    }

    return new RedirectResponse($node->toUrl('canonical')->toString());
  }

  public function importGameZip() {
    return \Drupal::formBuilder()->getForm(GameImportForm::class);
  }

  public function title(NodeInterface $node) {
    return $node->getTitle();
  }
}

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
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * ConfigDownloadController.
 */
class GamesController extends ControllerBase {

  /**
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response object.
   */
  public function streamPinMameYaml(NodeInterface $node): Response {
    $yaml = [
      'ppucVersion' => 1,
      'rom' => 'dummy',
      'serialPort' => 'dummy',
      'display' => 'dummy',
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

    foreach ($dip_switches as $dip_switch) {
      $yaml['dipSwitches'][] = [
        'description' => $dip_switch->label(),
        'number' => (int)($dip_switch->field_number->value),
        'on' => (bool)($dip_switch->field_status->value),
      ];
    }

    $i_o_boards = $storage->loadByProperties([
      'field_game' => $node->id(),
      'status' => TRUE,
      $node->getEntityType()->getKey('bundle') => 'i_o_board',
    ]);

    foreach ($i_o_boards as $i_o_board) {
      $i_o_board_number = (int)($i_o_board->field_number->value);
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
              'description' => 'dummy',
              'number' => (int)($device->field_number->value),
              'board' => $i_o_board_number,
              'port' => (int)($device->field_pin->value),
            ];

            $poll_events = TRUE;

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
              'description' => $device->label(),
              'type' => $type,
              'number' => (int)($device->field_number->value),
              'board' => $i_o_board_number,
              'port' => (int)($device->field_pin->value),
              'power' => (int)($device->field_power->value),
              'holdPower' => (int)($device->field_hold_power->value),
              'holdPowerActivationTime' => (int)($device->field_hold_power_activation_time->value),
              'minPulseTime' => (int)($device->field_min_pulse_time->value),
              'maxPulseTime' => (int)($device->field_max_pulse_time->value),
              'fastFlipSwitch' => (int)($device->field_fast_activation_switch->entity->field_number->value ?? -1),
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
                'description' => $addressable_led->label(),
                'number' => (int)($addressable_led->field_number->value),
                'ledNumber' => (int)($addressable_led->field_string_position->value),
                'brightness' => (int)($addressable_led->field_brightness->value),
                'color' => $addressable_led->field_color->color,
              ];
            }

            $yaml['ledStripes'][] = [
              'description' => $device->label(),
              'board' => $i_o_board_number,
              'port' => (int)($device->field_pin->value),
              'ledType' => $device->field_led_type->entity->getName(),
              'lightUp' => (int)($device->field_light_up->value),
              'afterGlow' => (int)($device->field_after_glow->value),
            ] + $leds;

            break;
        }
      }

      $yaml['boards'][] = [
        'description' => $i_o_board->label(),
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
    $archive_options = new Archive();
    $archive_options->setSendHttpHeaders(TRUE);
    $archive_options->setEnableZip64(FALSE);

    try {
      @ob_clean();
      // If you are using nginx as a webserver, it will try to buffer the
      // response. We have to disable this with a custom header.
      // @see https://github.com/maennchen/ZipStream-PHP/wiki/nginx
      header('X-Accel-Buffering: no');
      $zip = new ZipStream($node->uuid() . '.zip', $archive_options);

      /** @var ExporterInterface $exporter */
      $exporter = \Drupal::service('default_content.exporter');
      /** @var EntityRepositoryInterface $repository */
      $repository = \Drupal::service('entity.repository');
      $nodePath = 'content/node/';

      $gameExport = $exporter->exportContentWithReferences('node', $node->id());
      foreach ($gameExport as $entityType => $serializedEntities) {
        if ($entityType !== 'user') {
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

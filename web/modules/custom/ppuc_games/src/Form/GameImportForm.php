<?php

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Archiver\Zip;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\default_content\ImporterInterface;

class GameImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ppuc_games_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = array(
      '#attributes' => array('enctype' => 'multipart/form-data'),
    );

    $validators = array(
      'file_validate_extensions' => array('zip'),
    );
    $form['upload_zip'] = array(
      '#type' => 'managed_file',
      '#name' => 'upload_zip',
      '#title' => t('Game'),
      '#size' => 40,
      '#description' => t('A game as zip file.'),
      '#upload_validators' => $validators,
      '#upload_location' => 'public://ppuc_games_import/',
    );

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('upload_zip') === NULL) {
      $form_state->setErrorByName('upload_zip', $this->t('No game zip.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file = \Drupal::entityTypeManager()->getStorage('file')
      ->load($form_state->getValue('upload_zip')[0]);

    /** @var FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    $extractDir = $fileSystem->getTempDirectory() . '/' . basename($file->getFilename());

    $fileSystem->deleteRecursive($extractDir);
    $fileSystem->mkdir($extractDir);

    $zip = new Zip($fileSystem->realpath($file->getFileUri()));
    $zip->extract($extractDir);

    /** @var ImporterInterface $importer */
    $importer = \Drupal::service('default_content.importer');

    $entities = $importer->importContentFromFolder($extractDir . '/content');

    $fileSystem->deleteRecursive($extractDir);

    foreach ($entities as $entity) {
      if ($entity->bundle() === 'game') {
        $form_state->setRedirectUrl($entity->toUrl());
      }
    }
  }
}

<?php

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\default_content_deploy\Form\ImportForm;

class GameImportForm extends ImportForm {

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
    $form = parent::buildForm($form, $form_state);

    $form['file']['#required'] = TRUE;
    $form['file']['#description'] = $this->t('Upload a game tar.gz file exported from a PPUC config-tool instance.');
    $form['file']['#title'] = $this->t('Game Archive');

    $form['folder'] = [
      '#type' => 'value',
      '#default_value' => '',
    ];

    $form['force_override']['#description'] = $this->t('If the game already exists, it gets updated and the details get merged. Using this option, it gets overridden.');

    $form['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import game'),
    ];

    return $form;
  }
}

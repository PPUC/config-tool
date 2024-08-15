<?php

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeForm;

/**
 * PPUC form handler for the node edit forms.
 *
 * @internal
 */
class PpucNodeForm extends NodeForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $element = parent::actions($form, $form_state);

    unset($element['preview']);

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Button-level validation handlers are highly discouraged for entity forms,
   * as they will prevent entity validation from running. If the entity is going
   * to be saved during the form submission, this method should be manually
   * invoked from the button-level validation handler, otherwise an exception
   * will be thrown.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);

    if ($entity->hasField('field_pin') && $entity->hasField('field_i_o_board')) {
      $i_o_board = $entity->field_i_o_board->entity;
      $i_o_board_type = $i_o_board->field_io_board_type->entity;
      $i_o_board_gpio_mapping = unserialize($i_o_board_type->field_gpio_mapping->value, ['allowed_classes' => FALSE]);
      if (!in_array((int) ($entity->field_pin->value), $i_o_board_gpio_mapping, TRUE)) {
        $form_state->setErrorByName('field_pin[0][value]', $this->t('The selected board has no port %pin.', ['%pin' => $entity->field_pin->value]));
      }
    }

    return $entity;
  }

}

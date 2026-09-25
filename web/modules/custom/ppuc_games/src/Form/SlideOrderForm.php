<?php

namespace Drupal\ppuc_games\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drag the slides of a game into the order they play.
 *
 * The order is `field_weight`, which is also the number the exported filename
 * starts with, so dragging a row here renames a file in the game folder. That
 * is why this writes the field rather than keeping an order of its own the way
 * a generic dragging module would: there is already one answer to "which slide
 * comes first", and a second one would eventually disagree with it.
 *
 * @internal
 */
class SlideOrderForm extends FormBase {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'ppuc_games_slide_order';
  }

  /**
   * Every slide of the game, published or not, in the order they play.
   */
  protected function slides(NodeInterface $game): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'slide')
      ->condition('field_game.target_id', $game->id())
      ->sort('field_weight.value', 'ASC')
      ->sort('title', 'ASC')
      ->execute();

    return $ids ? $this->entityTypeManager->getStorage('node')->loadMultiple($ids) : [];
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'game') {
      throw new \InvalidArgumentException('Slide order needs a game.');
    }
    $form_state->set('game', $node);
    $slides = $this->slides($node);

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Drag the rows into the order the slides should play. Unpublished slides keep their place in the list but are not shown on the machine.') . '</p>',
    ];

    $form['slides'] = [
      '#type' => 'table',
      '#header' => [$this->t('Slide'), $this->t('Published'), $this->t('Weight')],
      '#empty' => $this->t('This game has no slides yet.'),
      '#tabledrag' => [[
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'slide-weight',
      ]],
    ];

    $delta = max(count($slides), 1);
    foreach ($slides as $slide) {
      $id = $slide->id();
      $form['slides'][$id]['#attributes']['class'][] = 'draggable';
      $form['slides'][$id]['#weight'] = (int) $this->weightOf($slide);

      $form['slides'][$id]['title'] = [
        '#type' => 'link',
        '#title' => $slide->getTitle(),
        '#url' => $slide->toUrl('edit-form'),
      ];
      $form['slides'][$id]['status'] = [
        '#markup' => $slide->isPublished() ? $this->t('Published') : $this->t('Not published'),
      ];
      // Hidden by tabledrag, and the fallback when JavaScript is not there.
      $form['slides'][$id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', ['@title' => $slide->getTitle()]),
        '#title_display' => 'invisible',
        '#default_value' => (int) $this->weightOf($slide),
        '#delta' => $delta,
        '#attributes' => ['class' => ['slide-weight']],
      ];
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save order'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.game_slides.all_slides', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  protected function weightOf(NodeInterface $slide): int {
    return $slide->hasField('field_weight') && !$slide->get('field_weight')->isEmpty()
      ? (int) $slide->get('field_weight')->value
      : 0;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $game = $form_state->get('game');
    $rows = $form_state->getValue('slides') ?: [];

    // Sorted by the weights the drag produced, then renumbered in hundreds.
    // Renumbering rather than saving the drag's own -10..10 keeps the gaps the
    // convention relies on: a slide can be slipped between two others by
    // typing a number, and the exported filenames stay four digits wide.
    uasort($rows, static fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $storage = $this->entityTypeManager->getStorage('node');
    $weight = 100;
    $moved = 0;
    foreach (array_keys($rows) as $id) {
      $slide = $storage->load($id);
      if (!$slide instanceof NodeInterface || $slide->bundle() !== 'slide') {
        continue;
      }
      if ($this->weightOf($slide) !== $weight) {
        $slide->set('field_weight', $weight);
        $slide->save();
        $moved++;
      }
      $weight += 100;
    }

    $this->messenger()->addStatus($moved
      ? $this->t('Reordered @count slides.', ['@count' => $moved])
      : $this->t('The order was already as shown.'));
    $form_state->setRedirect('view.game_slides.all_slides', ['node' => $game->id()]);
  }

}

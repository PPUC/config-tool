<?php

namespace Drupal\Tests\default_content\Kernel;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests export functionality.
 *
 * @coversDefaultClass \Drupal\default_content\Normalizer\ContentEntityNormalizer
 * @group default_content
 */
class MenuLinkContentNormalizerTest extends KernelTestBase {

  use EntityReferenceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'default_content',
    'link',
    'menu_link_content',
    'node',
  ];

  /**
   * The tested default content exporter.
   *
   * @var \Drupal\default_content\Exporter
   */
  protected $exporter;

  /**
   * A node to reference in menu links.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $referencedNode;

  /**
   * A test menu link.
   *
   * @var \Drupal\menu_link_content\MenuLinkContentInterface
   */
  protected $link;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');

    // Create a node type with a paragraphs field.
    NodeType::create([
      'type' => 'page',
      'name' => 'page',
    ])->save();

    // Create a node to reference in menu links.
    $this->referencedNode = Node::create([
      'type' => 'page',
      'title' => 'Referenced node',
    ]);
    $this->referencedNode->save();

    // Create a test menu link that references the test node.
    $this->link = MenuLinkContent::create([
      'title' => 'Parent menu link',
      'link' => 'entity:node/' . $this->referencedNode->id(),
    ]);
    $this->link->save();
  }

  /**
   * Tests menu_link_content entities.
   */
  public function testMenuLinks() {
    /** @var \Drupal\menu_link_content\MenuLinkContentInterface $child_link */
    $child_link = MenuLinkContent::create([
      'title' => 'Child menu link',
      'parent' => 'menu_link_content:' . $this->link->uuid(),
      'link' => [
        'uri' => 'https://www.example.org',
        'options' => [
          'attributes' => [
            'target' => '_blank',
          ],
        ],
      ],
    ]);
    $child_link->save();

    /** @var \Drupal\default_content\Normalizer\ContentEntityNormalizerInterface $normalizer */
    $normalizer = \Drupal::service('default_content.content_entity_normalizer');

    $normalized = $normalizer->normalize($this->link);

    $expected = [
      '_meta' => [
        'version' => '1.0',
        'entity_type' => 'menu_link_content',
        'uuid' => $this->link->uuid(),
        'bundle' => 'menu_link_content',
        'default_langcode' => 'en',
        'depends' => [
          $this->referencedNode->uuid() => 'node',
        ],
      ],
      'default' => [
        'enabled' => [
          0 => [
            'value' => TRUE,
          ],
        ],
        'title' => [
          0 => [
            'value' => 'Parent menu link',
          ],
        ],
        'menu_name' => [
          0 => [
            'value' => 'tools',
          ],
        ],
        'link' => [
          0 => [
            'target_uuid' => $this->referencedNode->uuid(),
            'title' => '',
            'options' => [],
          ],
        ],
        'external' => [
          0 => [
            'value' => FALSE,
          ],
        ],
        'rediscover' => [
          0 => [
            'value' => FALSE,
          ],
        ],
        'weight' => [
          0 => [
            'value' => 0,
          ],
        ],
        'expanded' => [
          0 => [
            'value' => FALSE,
          ],
        ],
        'revision_translation_affected' => [
          0 => [
            'value' => TRUE,
          ],
        ],
      ],
    ];

    $this->assertEquals($expected, $normalized);

    $normalized_child = $normalizer->normalize($child_link);

    $expected_child = [
      '_meta' => [
        'version' => '1.0',
        'entity_type' => 'menu_link_content',
        'uuid' => $child_link->uuid(),
        'bundle' => 'menu_link_content',
        'default_langcode' => 'en',
        'depends' => [
          $this->link->uuid() => 'menu_link_content',
        ],
      ],
      'default' => [
        'enabled' => [
          0 => [
            'value' => TRUE,
          ],
        ],
        'title' => [
          0 => [
            'value' => 'Child menu link',
          ],
        ],
        'menu_name' => [
          0 => [
            'value' => 'tools',
          ],
        ],
        'link' => [
          0 => [
            'uri' => 'https://www.example.org',
            'title' => '',
            'options' => [
              'attributes' => [
                'target' => '_blank',
              ],
            ],
          ],
        ],
        'external' => [
          0 => [
            'value' => FALSE,
          ],
        ],
        'rediscover' => [
          0 => [
            'value' => FALSE,
          ],
        ],
        'weight' => [
          0 => [
            'value' => 0,
          ],
        ],
        'expanded' => [
          0 => [
            'value' => FALSE,
          ],
        ],
        'parent' => [
          0 => [
            'value' => $child_link->getParentId(),
          ],
        ],
        'revision_translation_affected' => [
          0 => [
            'value' => TRUE,
          ],
        ],
      ],
    ];
    $this->assertEquals($expected_child, $normalized_child);

    // Delete the link and referenced node and recreate them.
    $normalized_node = $normalizer->normalize($this->referencedNode);
    $child_link->delete();
    $this->link->delete();
    $this->referencedNode->delete();

    $recreated_node = $normalizer->denormalize($normalized_node);
    $recreated_node->save();
    $this->assertNotEquals($this->referencedNode->id(), $recreated_node->id());

    $recreated_link = $normalizer->denormalize($normalized);
    $this->assertEquals('entity:node/' . $recreated_node->id(), $recreated_link->get('link')->uri);

    // Since the original link has been deleted, this should be a new link.
    $this->assertTrue($recreated_link->isNew());
  }

  /**
   * Tests that we can control whether existing menu links are updated or not.
   *
   * @param bool $update_existing
   *   Whether to update existing menu links.
   *
   * @dataProvider updateExistingMenuLinkProvider
   */
  public function testUpdatingExistingMenuLink($update_existing): void {
    // Change the existing menu link to reference a different node.
    $different_node = Node::create([
      'type' => 'page',
      'title' => 'Different node',
    ]);
    $different_node->save();

    $this->link->set('link', 'entity:node/' . $different_node->id());

    /** @var \Drupal\default_content\Normalizer\ContentEntityNormalizerInterface $normalizer */
    $normalizer = \Drupal::service('default_content.content_entity_normalizer');
    $normalized_link = $normalizer->normalize($this->link);
    $recreated_link = $normalizer->denormalize($normalized_link, $update_existing);

    // Regardless whether or not we are updating existing menu links, the link
    // is not new since it already exists in the database.
    $this->assertFalse($recreated_link->isNew());

    // The node reference should only change if we allow updating existing menu
    // links.
    $expected_reference = $update_existing ? 'entity:node/' . $different_node->id() : 'entity:node/' . $this->referencedNode->id();
    $this->assertEquals($expected_reference, $recreated_link->get('link')->uri);
  }

  /**
   * Provides test data for ::testUpdatingExistingMenuLink().
   *
   * @return array
   *   An array of test data for testing both states of the '$update_existing'
   *   parameter.
   */
  public function updateExistingMenuLinkProvider() {
    return [[TRUE], [FALSE]];
  }

}

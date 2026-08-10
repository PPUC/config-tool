<?php

declare(strict_types=1);

namespace Drupal\Tests\ppuc_games\Unit;

use Drupal\Component\DependencyInjection\ReverseContainer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ppuc_games\Form\GameWizardForm;
use Drupal\ppuc_games\Wizard\GameBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * The wizard's services have to survive the form cache.
 *
 * A multi-step form is serialized between requests, and
 * DependencySerializationTrait is what puts injected services back: __sleep()
 * records each by service id, __wakeup() assigns it again. Both run in
 * FormBase's scope, which has consequences that are easy to get wrong and
 * impossible to notice without running the form:
 *
 *  - a *private* property is invisible to get_object_vars() there, so it is
 *    never recorded and never restored;
 *  - a *readonly* property cannot be assigned from that scope at all.
 *
 * Either way the property comes back uninitialized and the next method to touch
 * it dies with "must not be accessed before initialization" - which is exactly
 * what happened the first time this form was used.
 */
#[CoversClass(GameWizardForm::class)]
#[Group('ppuc_games')]
class GameWizardFormSerializationTest extends TestCase {

  /**
   * A real container holding the two services, as Drupal's does.
   *
   * Not a double: ReverseContainer is final and reads the container's own
   * service map to work out an object's id, which is the machinery under test
   * here. Faking it would test the fake.
   */
  private function containerWithServices(
    EntityTypeManagerInterface $entityTypeManager,
    GameBuilder $gameBuilder,
  ): Container {
    $container = new Container();
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('ppuc_games.game_builder', $gameBuilder);
    $container->set(ReverseContainer::class, new ReverseContainer($container));

    return $container;
  }

  protected function tearDown(): void {
    // Leave no container behind for other tests.
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testTheFormSurvivesTheFormCache(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    // Real, not a double: GameBuilder is final, and it needs nothing but the
    // entity type manager to exist.
    $gameBuilder = new GameBuilder($entityTypeManager);
    \Drupal::setContainer($this->containerWithServices($entityTypeManager, $gameBuilder));

    $form = new GameWizardForm($entityTypeManager, $gameBuilder);
    $restored = unserialize(serialize($form));

    $this->assertInstanceOf(GameWizardForm::class, $restored);
    // Reading them back is the whole point: an uninitialized typed property
    // throws on access rather than returning NULL.
    $this->assertSame($entityTypeManager, $this->serviceOf($restored, 'entityTypeManager'));
    $this->assertSame($gameBuilder, $this->serviceOf($restored, 'gameBuilder'));
  }

  /**
   * Every injected service must be reachable from FormBase's scope.
   *
   * The round trip above only covers the two services the form has today. This
   * covers the next one somebody adds.
   */
  public function testEveryInjectedServiceIsProtectedAndNotReadonly(): void {
    $reflection = new \ReflectionClass(GameWizardForm::class);

    foreach ($reflection->getProperties() as $property) {
      if ($property->isStatic() || $property->getDeclaringClass()->getName() !== GameWizardForm::class) {
        continue;
      }
      $this->assertFalse($property->isPrivate(), sprintf(
        '%s is private, so DependencySerializationTrait cannot see it in FormBase\'s scope '
        . 'and it will not survive the form cache', $property->getName()
      ));
      $this->assertFalse($property->isReadOnly(), sprintf(
        '%s is readonly, so DependencySerializationTrait cannot assign it from FormBase\'s '
        . 'scope after unserialize', $property->getName()
      ));
    }
  }

  private function serviceOf(object $form, string $name): object {
    $property = new \ReflectionProperty(GameWizardForm::class, $name);
    $this->assertTrue($property->isInitialized($form), sprintf(
      '%s came back uninitialized: the form cannot be used after a rebuild', $name
    ));
    return $property->getValue($form);
  }

}

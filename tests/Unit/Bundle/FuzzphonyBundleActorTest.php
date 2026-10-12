<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\EventListener\SearchExceptionListener;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Bundle\Security\NullActorResolver;
use Fuzzphony\Bundle\Security\PropertyPathActorResolver;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** The fuzzphony.actor node, the actor resolver and the search gate services, and the 403 listener. */
final class FuzzphonyBundleActorTest extends TestCase
{
    public function testWithoutConfigurationNobodyIsTheActorAndTheGateIsWired(): void
    {
        $container = $this->buildContainer();

        self::assertSame(NullActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver')->getClass());
        self::assertTrue($container->getDefinition('fuzzphony.actor_resolver')->isPublic());
        self::assertTrue($container->hasAlias(ActorResolver::class));
        self::assertTrue($container->getAlias(ActorResolver::class)->isPublic());
        self::assertSame(SearchGate::class, $container->getDefinition('fuzzphony.search_gate')->getClass());
        self::assertEquals([new Reference('fuzzphony.metrics'), new Reference('logger')], $container->getDefinition('fuzzphony.search_gate')->getArguments());
        self::assertTrue($container->hasAlias(SearchGate::class));
        self::assertEquals(
            [new Reference('fuzzphony.engine'), new Reference('fuzzphony.registry'), new Reference('fuzzphony.search_gate')],
            $container->getDefinition('fuzzphony')->getArguments(),
        );
        $listener = $container->getDefinition('fuzzphony.exception_listener');
        self::assertSame(SearchExceptionListener::class, $listener->getClass());
        self::assertSame([['event' => 'kernel.exception', 'priority' => 64]], $listener->getTag('kernel.event_listener'));
    }

    public function testYourOwnResolverIsAliased(): void
    {
        $container = $this->buildContainer(['actor' => ['resolver' => 'app.my_resolver']]);

        self::assertFalse($container->hasDefinition('fuzzphony.actor_resolver'));
        self::assertSame('app.my_resolver', (string) $container->getAlias('fuzzphony.actor_resolver'));
        self::assertTrue($container->getAlias('fuzzphony.actor_resolver')->isPublic());
    }

    public function testPropertyPathsBuildThePropertyPathResolver(): void
    {
        $container = $this->buildContainer(['actor' => ['tenant' => 'account.id', 'user' => 'userIdentifier']]);

        $definition = $container->getDefinition('fuzzphony.actor_resolver');
        self::assertSame(PropertyPathActorResolver::class, $definition->getClass());
        self::assertEquals(
            [new Reference('security.token_storage'), new Reference('property_accessor'), 'account.id', 'userIdentifier'],
            $definition->getArguments(),
        );
        self::assertTrue($definition->isPublic());
    }

    public function testAPathThatIsNotGivenIsPassedAsNull(): void
    {
        $container = $this->buildContainer(['actor' => ['tenant' => 'account.id']]);

        self::assertSame([2 => 'account.id', 3 => null], array_slice($container->getDefinition('fuzzphony.actor_resolver')->getArguments(), 2, null, true));
    }

    public function testAResolverAndPropertyPathsCannotBeCombined(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/fuzzphony\.actor\.resolver cannot be combined with fuzzphony\.actor\.tenant/');

        $this->buildContainer(['actor' => ['resolver' => 'x', 'tenant' => 'a.b']]);
    }

    public function testAResolverAndAUserPathCannotBeCombinedEither(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->buildContainer(['actor' => ['resolver' => 'x', 'user' => 'userIdentifier']]);
    }

    public function testThePropertyPathsNeedBothPackages(): void
    {
        FuzzphonyBundle::assertActorPackages(true, true);

        foreach ([[false, true], [true, false], [false, false]] as [$security, $propertyAccess]) {
            try {
                FuzzphonyBundle::assertActorPackages($security, $propertyAccess);
                self::fail('A missing package must fail the container build.');
            } catch (InvalidConfiguration $e) {
                self::assertStringContainsString('symfony/security-core', $e->getMessage());
                self::assertStringContainsString('symfony/property-access', $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 3));
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->register('doctrine.dbal.default_connection', DbalConnection::class)->setSynthetic(true)->setPublic(true);
        $container->register('logger', \Psr\Log\LoggerInterface::class)->setSynthetic(true)->setPublic(true);

        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([$config], $container);

        return $container;
    }
}

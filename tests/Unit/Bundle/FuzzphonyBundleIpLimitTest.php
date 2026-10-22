<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Bundle\Security\AuthorizationGuard;
use Fuzzphony\Bundle\Security\NullActorResolver;
use Fuzzphony\Bundle\Security\PropertyPathActorResolver;
use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Bundle\Security\RequestIpActorResolver;
use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Search\ActorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

/** fuzzphony.rate_limit.per: ip: the config tree, the limiter the bundle prepends, and how the guard and the resolver are wired. */
final class FuzzphonyBundleIpLimitTest extends TestCase
{
    private const array DEFAULT_ENTRY = ['rate_limiter' => ['fuzzphony_ip' => ['policy' => 'sliding_window', 'limit' => 600, 'interval' => '1 minute']]];

    public function testPerIpWithoutALimiterPrependsTheDefaultEntryAndUsesIt(): void
    {
        $container = $this->prepended(['rate_limit' => ['per' => 'ip']], [['secret' => 'x']]);

        self::assertSame([self::DEFAULT_ENTRY, ['secret' => 'x']], $container->getExtensionConfig('framework'), 'prepended: the application keeps the last word');

        $container = $this->loaded(['rate_limit' => ['per' => 'ip']]);
        $guard = $container->getDefinition('fuzzphony.guard.rate_limit');
        self::assertSame(RateLimitGuard::class, $guard->getClass());
        self::assertEquals([new Reference('limiter.fuzzphony_ip'), 'ip', '%kernel.secret%', new Reference('fuzzphony.metrics'), new Reference('logger')], $guard->getArguments());
        self::assertEquals([new Reference('fuzzphony.guard.rate_limit')], $container->getDefinition('fuzzphony.search_gate')->getArgument(4));
    }

    public function testAnApplicationDefinedEntryOfThatNameIsNotOverridden(): void
    {
        $own = ['rate_limiter' => ['fuzzphony_ip' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 hour']]];

        $container = $this->prepended(['rate_limit' => ['per' => 'ip']], [$own]);

        self::assertSame([$own], $container->getExtensionConfig('framework'), 'nothing prepended');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function entryShapes(): iterable
    {
        $entry = ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 hour'];
        yield 'keyed by name directly' => [['fuzzphony_ip' => $entry]];
        yield 'under `limiters`' => [['limiters' => ['fuzzphony_ip' => $entry]]];
        yield 'under `limiter`' => [['limiter' => ['fuzzphony_ip' => $entry]]];
    }

    /** @param array<string, mixed> $rateLimiter */
    #[DataProvider('entryShapes')]
    public function testAnEntryOfThatNameIsRecognisedInEveryShape(array $rateLimiter): void
    {
        $own = ['rate_limiter' => $rateLimiter];

        $container = $this->prepended(['rate_limit' => ['per' => 'ip']], [$own]);

        self::assertSame([$own], $container->getExtensionConfig('framework'), 'nothing prepended');
    }

    public function testEntriesOfOtherNamesInEveryShapeDoNotStopThePrepend(): void
    {
        foreach ([['other' => ['limit' => 1]], ['limiters' => ['other' => ['limit' => 1]]], ['limiter' => ['other' => ['limit' => 1]]], ['limiters' => 'x']] as $rateLimiter) {
            $container = $this->prepended(['rate_limit' => ['per' => 'ip']], [['rate_limiter' => $rateLimiter]]);

            self::assertSame(self::DEFAULT_ENTRY, $container->getExtensionConfig('framework')[0]);
        }
    }

    public function testANamedLimiterOfTheApplicationIsUsedAndNothingIsPrepended(): void
    {
        $framework = [['rate_limiter' => ['public_search' => ['policy' => 'fixed_window', 'limit' => 30, 'interval' => '1 minute']]]];

        $container = $this->prepended(['rate_limit' => ['per' => 'ip', 'limiter' => 'public_search']], $framework);

        self::assertSame($framework, $container->getExtensionConfig('framework'));
        $guard = $this->loaded(['rate_limit' => ['per' => 'ip', 'limiter' => 'public_search']])->getDefinition('fuzzphony.guard.rate_limit');
        self::assertEquals([new Reference('limiter.public_search'), 'ip', '%kernel.secret%', new Reference('fuzzphony.metrics'), new Reference('logger')], $guard->getArguments());
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function settingsThatNeedNoDefaultEntry(): iterable
    {
        yield 'per tenant' => [[['rate_limit' => ['per' => 'tenant']]]];
        yield 'per user with a limiter' => [[['rate_limit' => ['per' => 'user', 'limiter' => 'search']]]];
        yield 'nothing configured' => [[[]]];
        yield 'per ip, then a limiter in a later file' => [[['rate_limit' => ['per' => 'ip']], ['rate_limit' => ['limiter' => 'search']]]];
        yield 'per ip in one file, tenant in a later one' => [[['rate_limit' => ['per' => 'ip']], ['rate_limit' => ['per' => 'tenant']]]];
    }

    /** @param list<array<string, mixed>> $files */
    #[DataProvider('settingsThatNeedNoDefaultEntry')]
    public function testOtherSettingsPrependNothing(array $files): void
    {
        $container = $this->prepended(null, [['secret' => 'x']], $files);

        self::assertSame([['secret' => 'x']], $container->getExtensionConfig('framework'));
    }

    public function testTheLastFileDecidesPerAndLimiter(): void
    {
        $container = $this->prepended(null, [], [['rate_limit' => ['per' => 'tenant']], ['rate_limit' => ['per' => 'ip']]]);
        self::assertSame([self::DEFAULT_ENTRY], $container->getExtensionConfig('framework'));

        $container = $this->prepended(null, [], [['rate_limit' => ['per' => 'ip', 'limiter' => 'search']], ['rate_limit' => ['limiter' => null]]]);
        self::assertSame([self::DEFAULT_ENTRY], $container->getExtensionConfig('framework'), 'the limiter was reset to null: the default is needed');
    }

    public function testWithoutFrameworkBundleNothingIsPrepended(): void
    {
        $container = $this->prepended(['rate_limit' => ['per' => 'ip']], null);

        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    public function testPerIpWrapsEveryKindOfResolverWithTheRequestAddress(): void
    {
        $default = $this->loaded(['rate_limit' => ['per' => 'ip']]);
        self::assertSame(RequestIpActorResolver::class, $default->getDefinition('fuzzphony.actor_resolver')->getClass());
        self::assertTrue($default->getDefinition('fuzzphony.actor_resolver')->isPublic());
        self::assertEquals([new Reference('fuzzphony.actor_resolver.inner'), new Reference('request_stack')], $default->getDefinition('fuzzphony.actor_resolver')->getArguments());
        self::assertSame(NullActorResolver::class, $default->getDefinition('fuzzphony.actor_resolver.inner')->getClass());
        self::assertSame('fuzzphony.actor_resolver', (string) $default->getAlias(ActorResolver::class));

        $paths = $this->loaded(['rate_limit' => ['per' => 'ip'], 'actor' => ['tenant' => 'account.id', 'user' => 'userIdentifier']]);
        self::assertSame(PropertyPathActorResolver::class, $paths->getDefinition('fuzzphony.actor_resolver.inner')->getClass());

        $own = $this->loaded(['rate_limit' => ['per' => 'ip'], 'actor' => ['resolver' => 'app.my_resolver']]);
        self::assertSame('app.my_resolver', (string) $own->getAlias('fuzzphony.actor_resolver.inner'));
    }

    public function testPerIpDoesNotTurnTheSearchLogIpOn(): void
    {
        $container = $this->loaded(['rate_limit' => ['per' => 'ip'], 'search_log' => ['enabled' => true]]);

        self::assertSame(IpMode::Off, $container->getDefinition('fuzzphony.search_gate')->getArgument(5), 'the gate stores no address');
        self::assertSame(IpMode::Off, $container->getDefinition('fuzzphony.search_gate.operator')->getArgument(5));
        self::assertFalse($container->getDefinition('fuzzphony.engine')->getArgument(6), 'and the doctor does not ask for the actor_ip column');
    }

    public function testTheOperatorGateRunsNoGuardEvenForPerIp(): void
    {
        $container = $this->loaded(['rate_limit' => ['per' => 'ip']]);

        self::assertSame([], $container->getDefinition('fuzzphony.search_gate.operator')->getArgument(4), 'the console has no client');
    }

    public function testPerTenantAndPerUserKeepTheResolverUnwrapped(): void
    {
        foreach (['tenant', 'user'] as $per) {
            $container = $this->loaded(['rate_limit' => ['per' => $per, 'limiter' => 'search']]);

            self::assertSame(NullActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver')->getClass());
            self::assertFalse($container->has('fuzzphony.actor_resolver.inner'));
            self::assertSame($per, $container->getDefinition('fuzzphony.guard.rate_limit')->getArgument(1));
        }
    }

    public function testPerTenantWithoutALimiterStaysUnlimited(): void
    {
        $container = $this->loaded(['rate_limit' => ['per' => 'tenant']]);

        self::assertFalse($container->hasDefinition('fuzzphony.guard.rate_limit'));
        self::assertSame([], $container->getDefinition('fuzzphony.search_gate')->getArgument(4));
    }

    public function testPerIpAloneBuildsNoAuthorizationGuard(): void
    {
        $container = $this->loaded(['rate_limit' => ['per' => 'ip']]);

        self::assertFalse($container->hasDefinition('fuzzphony.guard.authorization'), 'only per: ip: no visitor is refused for lack of a token');
    }

    public function testPerIpWithAuthorizationRunsAuthorizationFirst(): void
    {
        $container = $this->loaded(['rate_limit' => ['per' => 'ip'], 'authorization' => ['enabled' => true]]);

        self::assertEquals([new Reference('fuzzphony.guard.authorization'), new Reference('fuzzphony.guard.rate_limit')], $container->getDefinition('fuzzphony.search_gate')->getArgument(4));
        self::assertSame(AuthorizationGuard::class, $container->getDefinition('fuzzphony.guard.authorization')->getClass());
    }

    /**
     * @param array<string, mixed>|null       $fuzzphony one fuzzphony configuration (null: use $files)
     * @param list<array<string, mixed>>|null $framework the framework configurations of the application (null: no FrameworkBundle)
     * @param list<array<string, mixed>>      $files     several fuzzphony configurations, in the order they were loaded
     */
    private function prepended(?array $fuzzphony, ?array $framework, array $files = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['kernel.environment' => 'test', 'kernel.debug' => false, 'kernel.project_dir' => dirname(__DIR__, 3), 'kernel.cache_dir' => sys_get_temp_dir(), 'kernel.build_dir' => sys_get_temp_dir()] as $name => $value) {
            $container->setParameter($name, $value);
        }
        if ($framework !== null) {
            $container->registerExtension(new class extends Extension {
                public function load(array $configs, ContainerBuilder $container): void {}

                public function getAlias(): string
                {
                    return 'framework';
                }
            });
        }
        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container->registerExtension($extension);
        foreach ($fuzzphony !== null ? [$fuzzphony] : $files as $file) {
            $container->loadFromExtension('fuzzphony', $file);
        }
        foreach ($framework ?? [] as $config) {
            $container->loadFromExtension('framework', $config);
        }
        $extension->prepend($container);

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function loaded(array $config): ContainerBuilder
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

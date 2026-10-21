<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Bundle\Security\AuthorizationGuard;
use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** The fuzzphony.rate_limit and fuzzphony.authorization nodes and the guards handed to the search gate. */
final class FuzzphonyBundleGuardsTest extends TestCase
{
    public function testWithoutGuardConfigurationTheGateHasNoGuards(): void
    {
        $arguments = $this->gateArguments($this->buildContainer());

        self::assertCount(6, $arguments);
        self::assertSame(IpMode::Off, $arguments[5], 'the client IP is off by default');
        self::assertEquals(new Reference('fuzzphony.metrics'), $arguments[0]);
        self::assertEquals(new Reference('logger'), $arguments[1]);
        self::assertInstanceOf(Definition::class, $arguments[2], 'no search log: the gate gets a log that records nothing');
        self::assertSame(NullSearchLog::class, $arguments[2]->getClass());
        self::assertNull($arguments[3]);
        self::assertSame([], $arguments[4]);
    }

    public function testALimiterNameBuildsTheRateLimitGuard(): void
    {
        $container = $this->buildContainer(['rate_limit' => ['limiter' => 'search']]);

        $guards = $this->gateArguments($container)[4];
        self::assertEquals([new Reference('fuzzphony.guard.rate_limit')], $guards);
        $guard = $container->getDefinition('fuzzphony.guard.rate_limit');
        self::assertSame(RateLimitGuard::class, $guard->getClass());
        self::assertEquals([new Reference('limiter.search'), 'tenant'], $guard->getArguments());
    }

    public function testThePerSettingIsPassedOn(): void
    {
        $container = $this->buildContainer(['rate_limit' => ['limiter' => 'search', 'per' => 'user']]);

        self::assertSame('user', $container->getDefinition('fuzzphony.guard.rate_limit')->getArgument(1));
    }

    public function testEnablingAuthorizationBuildsTheAuthorizationGuard(): void
    {
        $container = $this->buildContainer(['authorization' => ['enabled' => true]]);

        self::assertEquals([new Reference('fuzzphony.guard.authorization')], $this->gateArguments($container)[4]);
        $guard = $container->getDefinition('fuzzphony.guard.authorization');
        self::assertSame(AuthorizationGuard::class, $guard->getClass());
        self::assertEquals([new Reference('security.authorization_checker'), new Reference('security.token_storage'), 'FUZZPHONY_SEARCH'], $guard->getArguments());
    }

    public function testTheIndexAttributeIsPassedOn(): void
    {
        $container = $this->buildContainer(['authorization' => ['enabled' => true, 'index_attribute' => 'ROLE_USER']]);

        self::assertSame('ROLE_USER', $container->getDefinition('fuzzphony.guard.authorization')->getArgument(2));
    }

    public function testAuthorizationComesBeforeTheRateLimit(): void
    {
        $container = $this->buildContainer(['rate_limit' => ['limiter' => 'search'], 'authorization' => ['enabled' => true]]);

        self::assertEquals(
            [new Reference('fuzzphony.guard.authorization'), new Reference('fuzzphony.guard.rate_limit')],
            $this->gateArguments($container)[4],
            'a denied search must not spend a token',
        );
    }

    public function testTheGuardsFollowTheSearchLogArguments(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true], 'authorization' => ['enabled' => true]]);

        $arguments = $this->gateArguments($container);
        self::assertEquals(new Reference('fuzzphony.search_log'), $arguments[2]);
        self::assertEquals([new Reference('fuzzphony.guard.authorization')], $arguments[4]);
    }

    public function testTheSearchCommandGetsAFuzzphonyWhoseGateRunsNoGuard(): void
    {
        $container = $this->buildContainer(['rate_limit' => ['limiter' => 'search'], 'authorization' => ['enabled' => true], 'search_log' => ['enabled' => true, 'redact' => ['email']]]);

        $command = $container->getDefinition(SearchCommand::class);
        self::assertEquals([new Reference('fuzzphony.operator')], $command->getArguments());
        $operator = $container->getDefinition('fuzzphony.operator');
        self::assertSame(Fuzzphony::class, $operator->getClass());
        self::assertEquals(
            [new Reference('fuzzphony.engine'), new Reference('fuzzphony.registry'), new Reference('fuzzphony.search_gate.operator')],
            $operator->getArguments(),
        );
        $operatorGate = $container->getDefinition('fuzzphony.search_gate.operator');
        self::assertSame(SearchGate::class, $operatorGate->getClass());
        self::assertSame([['channel' => 'fuzzphony']], $operatorGate->getTag('monolog.logger'));
        $mainGate = $this->gateArguments($container);
        self::assertEquals([new Reference('fuzzphony.guard.authorization'), new Reference('fuzzphony.guard.rate_limit')], $mainGate[4], 'the main gate runs both guards');
        self::assertEquals(
            [...array_slice($mainGate, 0, 4), [], IpMode::Off],
            $operatorGate->getArguments(),
            'the operator gate counts and logs like the main one (metrics, logger, search log, redactor) but has no guard',
        );
        self::assertEquals([new Reference('fuzzphony.engine'), new Reference('fuzzphony.registry'), new Reference('fuzzphony.search_gate')], $container->getDefinition('fuzzphony')->getArguments());
    }

    public function testAnEmptyLimiterNameIsNoGuard(): void
    {
        $container = $this->buildContainer(['rate_limit' => ['limiter' => '', 'per' => 'user']]);

        self::assertSame([], $this->gateArguments($container)[4]);
        self::assertFalse($container->hasDefinition('fuzzphony.guard.rate_limit'));
    }

    public function testDisabledAuthorizationIsNoGuard(): void
    {
        $container = $this->buildContainer(['authorization' => ['enabled' => false, 'index_attribute' => 'ROLE_USER']]);

        self::assertSame([], $this->gateArguments($container)[4]);
        self::assertFalse($container->hasDefinition('fuzzphony.guard.authorization'));
    }

    public function testPerMustBeTenantUserOrIp(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->buildContainer(['rate_limit' => ['limiter' => 'search', 'per' => 'account']]);
    }

    public function testTheRateLimitNeedsItsPackage(): void
    {
        FuzzphonyBundle::assertRateLimiterPackage(true);

        try {
            FuzzphonyBundle::assertRateLimiterPackage(false);
            self::fail('A missing package must fail the container build.');
        } catch (InvalidConfiguration $e) {
            self::assertStringContainsString('symfony/rate-limiter', $e->getMessage());
            self::assertStringContainsString('fuzzphony.rate_limit', $e->getMessage());
        }
    }

    public function testAuthorizationNeedsItsPackage(): void
    {
        FuzzphonyBundle::assertAuthorizationPackage(true);

        try {
            FuzzphonyBundle::assertAuthorizationPackage(false);
            self::fail('A missing package must fail the container build.');
        } catch (InvalidConfiguration $e) {
            self::assertStringContainsString('symfony/security-core', $e->getMessage());
            self::assertStringContainsString('fuzzphony.authorization', $e->getMessage());
        }
    }

    /** @return array<int|string, mixed> */
    private function gateArguments(ContainerBuilder $container): array
    {
        return $container->getDefinition('fuzzphony.search_gate')->getArguments();
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

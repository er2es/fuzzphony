<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\Analytics\BufferedSearchLog;
use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Bundle\Command\SearchLogPurgeCommand;
use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Bundle\EventListener\SearchLogFlushListener;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Bundle\Security\NullActorResolver;
use Fuzzphony\Bundle\Security\PropertyPathActorResolver;
use Fuzzphony\Bundle\Security\RequestIpActorResolver;
use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Search\ActorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** The fuzzphony.search_log node: the buffer, its flush, the two commands and the engine and gate wiring. */
final class FuzzphonyBundleSearchLogTest extends TestCase
{
    private const array BASE_COMMANDS = [SchemaCommand::class, DoctorCommand::class, ReindexCommand::class, WorkerCommand::class, SearchCommand::class, WizardCommand::class];

    public function testByDefaultNothingIsLoggedAndTheGateKnowsOnlyMetricsAndLogger(): void
    {
        $container = $this->buildContainer();

        self::assertFalse($container->hasDefinition('fuzzphony.search_log'));
        self::assertFalse($container->hasDefinition('fuzzphony.search_log_flush_listener'));
        self::assertFalse($container->hasDefinition('fuzzphony.query_redactor'));
        self::assertEquals([new Reference('fuzzphony.metrics'), new Reference('logger'), new Definition(NullSearchLog::class), null, [], IpMode::Off], $container->getDefinition('fuzzphony.search_gate')->getArguments());
        self::assertFalse($container->getDefinition('fuzzphony.engine')->getArgument(4));
        self::assertSame(90, $container->getDefinition('fuzzphony.engine')->getArgument(5));
        self::assertSame(self::BASE_COMMANDS, array_keys($container->findTaggedServiceIds('console.command')), 'the log commands exist only when the log is on');
    }

    public function testEnablingTheLogRegistersTheBufferTheListenerAndTheCommands(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'retention_days' => 30]]);

        $log = $container->getDefinition('fuzzphony.search_log');
        self::assertSame(BufferedSearchLog::class, $log->getClass());
        self::assertEquals([new ServiceClosureArgument(new Reference('fuzzphony.engine')), new Reference('fuzzphony.metrics'), new Reference('logger')], $log->getArguments(), 'the engine is a service closure: built on the first write only');
        self::assertSame([['method' => 'reset']], $log->getTag('kernel.reset'), 'a worker flushes between messages');

        $listener = $container->getDefinition('fuzzphony.search_log_flush_listener');
        self::assertSame(SearchLogFlushListener::class, $listener->getClass());
        self::assertEquals([new Reference('fuzzphony.search_log')], $listener->getArguments());
        self::assertSame([['event' => 'kernel.terminate'], ['event' => 'console.terminate']], $listener->getTag('kernel.event_listener'));

        self::assertEquals([new Reference('fuzzphony')], $container->getDefinition(AnalyticsCommand::class)->getArguments());
        self::assertEquals([new Reference('fuzzphony'), 30], $container->getDefinition(SearchLogPurgeCommand::class)->getArguments());
        self::assertSame(
            [...self::BASE_COMMANDS, AnalyticsCommand::class, SearchLogPurgeCommand::class],
            array_keys($container->findTaggedServiceIds('console.command')),
        );
    }

    public function testARequestThatLoggedNothingNeverBuildsTheEngine(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true]]);

        $container->set('logger', new NullLogger());
        $container->set('fuzzphony.metrics', new NullMetricsCollector());
        $listener = $container->get('fuzzphony.search_log_flush_listener');
        self::assertInstanceOf(SearchLogFlushListener::class, $listener);
        $listener(new \stdClass());

        self::assertTrue($container->initialized('fuzzphony.search_log_flush_listener'));
        self::assertFalse($container->initialized('fuzzphony.engine'), 'the flush of an empty buffer builds neither the engine nor its connection');
    }

    public function testTheEngineKnowsTheLogIsOnAndTheRetention(): void
    {
        $engine = $this->buildContainer(['search_log' => ['enabled' => true, 'retention_days' => 7]])->getDefinition('fuzzphony.engine');

        self::assertTrue($engine->getArgument(4));
        self::assertSame(7, $engine->getArgument(5));
        self::assertEquals(new Reference('fuzzphony.metrics'), $engine->getArgument(3));
    }

    public function testTheGateReceivesTheLogAndNoRedactorWhenNothingIsRedacted(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true]]);

        self::assertEquals(
            [new Reference('fuzzphony.metrics'), new Reference('logger'), new Reference('fuzzphony.search_log'), null, [], IpMode::Off],
            $container->getDefinition('fuzzphony.search_gate')->getArguments(),
        );
    }

    public function testRedactBuildsAQueryRedactorForTheGate(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'redact' => ['email', '/\d{9}/']]]);

        $redactor = $container->getDefinition('fuzzphony.query_redactor');
        self::assertSame(PatternQueryRedactor::class, $redactor->getClass());
        self::assertSame([PatternQueryRedactor::class, 'fromSettings'], $redactor->getFactory());
        self::assertSame([['email', '/\d{9}/']], $redactor->getArguments());
        self::assertEquals(new Reference('fuzzphony.query_redactor'), $container->getDefinition('fuzzphony.search_gate')->getArgument(3));
    }

    public function testTheRedactorServiceBuildsFromTheSettings(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'redact' => ['email']]]);

        $redactor = $container->get('fuzzphony.query_redactor');

        self::assertInstanceOf(PatternQueryRedactor::class, $redactor);
        self::assertSame('mail [redacted] now', $redactor->redact('mail ada@example.com now'));
    }

    public function testAnInvalidRegularExpressionFailsTheContainerBuild(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('search_log.redact');

        $this->buildContainer(['search_log' => ['enabled' => true, 'redact' => ['/(unclosed']]]);
    }

    public function testAnInvalidRegularExpressionFailsEvenWhileTheLogIsOff(): void
    {
        $this->expectException(InvalidConfiguration::class);

        $this->buildContainer(['search_log' => ['redact' => ['/(unclosed']]]);
    }

    public function testTheClientIpIsOffByDefaultAndTheResolverIsNotWrapped(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true]]);

        self::assertSame(IpMode::Off, $container->getDefinition('fuzzphony.search_gate')->getArgument(5));
        self::assertSame(NullActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver')->getClass());
        self::assertFalse($container->has('fuzzphony.actor_resolver.inner'));
    }

    /** @return iterable<string, array{string, IpMode}> */
    public static function ipModes(): iterable
    {
        yield 'masked' => ['masked', IpMode::Masked];
        yield 'full' => ['full', IpMode::Full];
        yield 'off' => ['off', IpMode::Off];
    }

    #[DataProvider('ipModes')]
    public function testTheModeGoesToBothGates(string $setting, IpMode $mode): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'ip' => $setting]]);

        self::assertSame($mode, $container->getDefinition('fuzzphony.search_gate')->getArgument(5));
        self::assertSame($mode, $container->getDefinition('fuzzphony.search_gate.operator')->getArgument(5));
    }

    public function testWithTheIpOnTheResolverIsWrappedAndStillPublic(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'ip' => 'masked']]);

        $resolver = $container->getDefinition('fuzzphony.actor_resolver');
        self::assertSame(RequestIpActorResolver::class, $resolver->getClass());
        self::assertTrue($resolver->isPublic());
        self::assertEquals([new Reference('fuzzphony.actor_resolver.inner'), new Reference('request_stack')], $resolver->getArguments());
        self::assertSame(NullActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver.inner')->getClass());
        self::assertSame('fuzzphony.actor_resolver', (string) $container->getAlias(ActorResolver::class));
    }

    public function testWithTheIpOnAPropertyPathResolverIsWrapped(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'ip' => 'full'], 'actor' => ['tenant' => 'account.id', 'user' => 'userIdentifier']]);

        self::assertSame(RequestIpActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver')->getClass());
        self::assertSame(PropertyPathActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver.inner')->getClass());
    }

    public function testWithTheIpOnYourOwnResolverIsWrapped(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'ip' => 'masked'], 'actor' => ['resolver' => 'app.my_resolver']]);

        self::assertSame(RequestIpActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver')->getClass());
        self::assertSame('app.my_resolver', (string) $container->getAlias('fuzzphony.actor_resolver.inner'));
    }

    public function testTheIpSettingDoesNothingWhileTheLogIsOff(): void
    {
        $container = $this->buildContainer(['search_log' => ['ip' => 'full']]);

        self::assertSame(IpMode::Off, $container->getDefinition('fuzzphony.search_gate')->getArgument(5));
        self::assertSame(NullActorResolver::class, $container->getDefinition('fuzzphony.actor_resolver')->getClass(), 'nothing stores an address, so nothing reads it');
    }

    public function testTheIpModeMustBeOffMaskedOrFull(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/search_log\.ip/');

        $this->buildContainer(['search_log' => ['enabled' => true, 'ip' => 'partial']]);
    }

    public function testRetentionMustBeAtLeastOneDay(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/retention_days/');

        $this->buildContainer(['search_log' => ['retention_days' => 0]]);
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

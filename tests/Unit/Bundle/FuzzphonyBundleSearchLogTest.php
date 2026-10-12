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
use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
        self::assertEquals([new Reference('fuzzphony.metrics'), new Reference('logger')], $container->getDefinition('fuzzphony.search_gate')->getArguments());
        self::assertFalse($container->getDefinition('fuzzphony.engine')->getArgument(4));
        self::assertSame(90, $container->getDefinition('fuzzphony.engine')->getArgument(5));
        self::assertSame(self::BASE_COMMANDS, array_keys($container->findTaggedServiceIds('console.command')), 'the log commands exist only when the log is on');
    }

    public function testEnablingTheLogRegistersTheBufferTheListenerAndTheCommands(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'retention_days' => 30]]);

        $log = $container->getDefinition('fuzzphony.search_log');
        self::assertSame(BufferedSearchLog::class, $log->getClass());
        self::assertEquals([new Reference('fuzzphony.engine'), new Reference('fuzzphony.metrics'), new Reference('logger')], $log->getArguments());

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
            [new Reference('fuzzphony.metrics'), new Reference('logger'), new Reference('fuzzphony.search_log'), null],
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

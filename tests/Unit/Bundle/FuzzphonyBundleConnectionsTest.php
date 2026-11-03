<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Bundle\Command\SearchLogPurgeCommand;
use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Engine\Postgres\Inspection\PrivilegeCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** fuzzphony.connections: the worker and the admin may use another database user; without it nothing changes. */
final class FuzzphonyBundleConnectionsTest extends TestCase
{
    public function testWithoutConnectionsEveryCommandUsesTheOneConnection(): void
    {
        $container = $this->buildContainer([]);

        foreach (['fuzzphony.connection.worker', 'fuzzphony.connection.admin', 'fuzzphony.worker', 'fuzzphony.admin', 'fuzzphony.engine.worker', 'fuzzphony.engine.admin'] as $id) {
            self::assertFalse($container->hasDefinition($id), $id);
        }
        self::assertEquals(new Reference('fuzzphony'), $container->getDefinition(WorkerCommand::class)->getArgument(0));
        self::assertEquals(new Reference('fuzzphony'), $container->getDefinition(SchemaCommand::class)->getArgument(0));
        self::assertEquals(new Reference('fuzzphony.connection'), $container->getDefinition(SchemaCommand::class)->getArgument(1));
    }

    public function testTheSameNameAsTheConnectionIsNotSeparate(): void
    {
        $container = $this->buildContainer(['connections' => ['worker' => 'default', 'admin' => 'default']]);

        self::assertFalse($container->hasDefinition('fuzzphony.worker'));
        self::assertFalse($container->hasDefinition('fuzzphony.admin'));
    }

    public function testASeparateWorkerAndAdminGetTheirOwnConnectionEngineAndLibrary(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true], 'connections' => ['worker' => 'worker', 'admin' => 'admin']]);

        foreach (['worker', 'admin'] as $role) {
            self::assertEquals([new Reference(sprintf('doctrine.dbal.%s_connection', $role))], $container->getDefinition('fuzzphony.connection.' . $role)->getArguments());
            $engine = $container->getDefinition('fuzzphony.engine.' . $role);
            self::assertEquals(new Reference('fuzzphony.connection.' . $role), $engine->getArgument(0));
            self::assertSame($container->getDefinition('fuzzphony.engine')->getArgument(4), $engine->getArgument(4), 'the same search log switch');
            self::assertEquals(new Reference('fuzzphony.engine.' . $role), $container->getDefinition('fuzzphony.' . $role)->getArgument(0));
        }
        self::assertEquals(new Reference('fuzzphony.worker'), $container->getDefinition(WorkerCommand::class)->getArgument(0));
        self::assertEquals(new Reference('fuzzphony.worker'), $container->getDefinition('fuzzphony.messenger.refresh_handler')->getArgument(0));
        self::assertEquals(new Reference('fuzzphony.admin'), $container->getDefinition(SchemaCommand::class)->getArgument(0));
        self::assertEquals(new Reference('fuzzphony.connection.admin'), $container->getDefinition(SchemaCommand::class)->getArgument(1));
        foreach ([DoctorCommand::class, ReindexCommand::class, AnalyticsCommand::class, SearchLogPurgeCommand::class] as $command) {
            self::assertEquals(new Reference('fuzzphony.admin'), $container->getDefinition($command)->getArgument(0), $command);
        }
        $log = $container->getDefinition('fuzzphony.search_log')->getArgument(0);
        self::assertInstanceOf(ServiceClosureArgument::class, $log);
        self::assertEquals([new Reference('fuzzphony.engine')], $log->getValues(), 'the search log is written on the application connection');
        self::assertEquals(new Reference('fuzzphony.connection'), $container->getDefinition('fuzzphony.introspector')->getArgument(0));
    }

    public function testOnlyTheWorkerCanBeSeparate(): void
    {
        $container = $this->buildContainer(['connections' => ['worker' => 'worker']]);

        self::assertEquals(new Reference('fuzzphony.worker'), $container->getDefinition(WorkerCommand::class)->getArgument(0));
        self::assertEquals(new Reference('fuzzphony'), $container->getDefinition(SchemaCommand::class)->getArgument(0));
        self::assertFalse($container->hasDefinition('fuzzphony.admin'));
    }

    public function testTheDoctorChecksTheRolesInUse(): void
    {
        $shared = $this->doctorCheck($this->buildContainer([]));
        $separate = $this->doctorCheck($this->buildContainer(['connections' => ['worker' => 'worker', 'admin' => 'admin']]));

        self::assertSame(PrivilegeCheck::class, $shared->getClass());
        self::assertEquals(new Reference('fuzzphony.connection'), $shared->getArgument(0));
        self::assertEquals(['search' => new Reference('fuzzphony.connection'), 'worker' => new Reference('fuzzphony.connection')], $shared->getArgument(2), 'an admin that is the application user is not asked for DDL');
        self::assertEquals(new Reference('fuzzphony.connection.admin'), $separate->getArgument(0));
        self::assertEquals(['search' => new Reference('fuzzphony.connection'), 'worker' => new Reference('fuzzphony.connection.worker'), 'admin' => new Reference('fuzzphony.connection.admin')], $separate->getArgument(2));
    }

    private function doctorCheck(ContainerBuilder $container): Definition
    {
        $check = $container->getDefinition(DoctorCommand::class)->getArgument(4);
        self::assertInstanceOf(Definition::class, $check);

        return $check;
    }

    public function testAnUnknownConnectionNameListsTheConfiguredOnes(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('fuzzphony.connections.worker names the DBAL connection "wroker", which is not configured. Configured connections: default, worker.');
        $this->buildContainer(['connections' => ['worker' => 'wroker']], ['default', 'worker']);
    }

    public function testAKnownNameAndAnUnlistedConfigurationAreAccepted(): void
    {
        self::assertTrue($this->buildContainer(['connections' => ['admin' => 'admin']], ['default', 'admin'])->hasDefinition('fuzzphony.admin'));
        self::assertTrue($this->buildContainer(['connections' => ['admin' => 'admin']])->hasDefinition('fuzzphony.admin'), 'without a list of connections nothing is checked');
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string>            $known  what prependExtension() recorded of DoctrineBundle's configuration
     */
    private function buildContainer(array $config, array $known = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['kernel.environment' => 'test', 'kernel.debug' => false, 'kernel.project_dir' => dirname(__DIR__, 3), 'kernel.cache_dir' => sys_get_temp_dir(), 'kernel.build_dir' => sys_get_temp_dir()] as $name => $value) {
            $container->setParameter($name, $value);
        }
        if ($known !== []) {
            $container->setParameter('fuzzphony.dbal_connections', $known);
        }
        foreach (['default', 'worker', 'admin'] as $name) {
            $container->register(sprintf('doctrine.dbal.%s_connection', $name), DbalConnection::class)->setSynthetic(true)->setPublic(true);
        }
        $container->register('logger', \Psr\Log\LoggerInterface::class)->setSynthetic(true)->setPublic(true);
        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([$config], $container);

        return $container;
    }
}

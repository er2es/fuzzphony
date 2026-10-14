<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Corners of FuzzphonyBundle::loadExtension() and of its configuration tree that the other bundle tests do not pin. */
final class FuzzphonyBundleMutationsTest extends TestCase
{
    public function testPerTenantIsAcceptedExplicitly(): void
    {
        $container = $this->buildContainer(['rate_limit' => ['limiter' => 'search', 'per' => 'tenant']]);

        self::assertSame('tenant', $container->getDefinition('fuzzphony.guard.rate_limit')->getArgument(1));
    }

    public function testOneDayOfRetentionIsAccepted(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true, 'retention_days' => 1]]);

        self::assertSame(1, $container->getDefinition('fuzzphony.engine')->getArgument(5));
    }

    public function testTheSearchLogServiceLogsToTheFuzzphonyChannel(): void
    {
        $container = $this->buildContainer(['search_log' => ['enabled' => true]]);

        self::assertSame([['channel' => 'fuzzphony']], $container->getDefinition('fuzzphony.search_log')->getTag('monolog.logger'));
    }

    public function testTheWorkerSleepsOneSecondByDefault(): void
    {
        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        $config = (new Processor())->processConfiguration($configuration, [[]]);

        self::assertIsArray($config['worker']);
        self::assertSame(1.0, $config['worker']['idle_sleep']);
        self::assertSame(500, $config['worker']['batch_size']);
    }

    public function testAnEmptyRawConfigurationLogsNothingAndKeepsNinetyDaysOfRetention(): void
    {
        // loadExtension() receives the processed tree; called directly it must still fall back to its own defaults
        $container = $this->loadRaw([]);

        $engine = $container->getDefinition('fuzzphony.engine');
        self::assertFalse($engine->getArgument(4), 'the search log is off unless it is asked for');
        self::assertSame(90, $engine->getArgument(5));
        self::assertFalse($container->hasDefinition('fuzzphony.search_log'));
    }

    public function testAnAbsentRetentionFallsBackToNinetyDays(): void
    {
        $container = $this->loadRaw(['search_log' => ['enabled' => true]]);

        self::assertSame(90, $container->getDefinition('fuzzphony.engine')->getArgument(5));
        $purge = $container->getDefinition(\Fuzzphony\Bundle\Command\SearchLogPurgeCommand::class);
        self::assertSame(90, $purge->getArgument(1));
    }

    public function testTheLogFlagGivenToTheEngineIsABoolean(): void
    {
        $container = $this->loadRaw(['search_log' => ['enabled' => 1]]);

        self::assertSame(true, $container->getDefinition('fuzzphony.engine')->getArgument(4));
    }

    public function testAuthorizationIsOffWhenTheKeyIsAbsent(): void
    {
        $container = $this->loadRaw([]);

        self::assertFalse($container->hasDefinition('fuzzphony.guard.authorization'));
        self::assertSame([], $container->getDefinition('fuzzphony.search_gate')->getArgument(4));
    }

    public function testRedactEntriesAreMadeStringsBeforeTheyAreValidated(): void
    {
        // a null entry becomes "", which is no regular expression: the build fails with the configuration error
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('search_log.redact');

        $this->buildContainer(['search_log' => ['enabled' => true, 'redact' => [null]]]);
    }

    public function testTheRedactorSettingsAreHandedOverAsAList(): void
    {
        $container = $this->loadRaw(['search_log' => ['enabled' => true, 'redact' => [5 => 'email', 9 => '/\d{9}/']]]);

        self::assertSame([['email', '/\d{9}/']], $container->getDefinition('fuzzphony.query_redactor')->getArguments());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function missingPackages(): iterable
    {
        yield 'actor paths need the security token storage' => ['actor', 'Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface', 'actor'];
        yield 'actor paths need the property accessor' => ['actor', 'Symfony\Component\PropertyAccess\PropertyAccessorInterface', 'actor'];
        yield 'authorization needs the authorization checker' => ['authorization', 'Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface', 'fuzzphony.authorization'];
        yield 'rate limit needs the rate limiter' => ['rate_limit', 'Symfony\Component\RateLimiter\RateLimiterFactory', 'fuzzphony.rate_limit'];
    }

    #[DataProvider('missingPackages')]
    public function testAMissingPackageFailsTheContainerBuild(string $scenario, string $hiddenClass, string $messagePart): void
    {
        $output = $this->buildInChildProcess($scenario, $hiddenClass);

        self::assertStringStartsWith(InvalidConfiguration::class . '|', $output);
        self::assertStringContainsString($messagePart, $output);
    }

    public function testTheChildProcessBuildsWhenNothingIsMissing(): void
    {
        foreach (['actor', 'authorization', 'rate_limit'] as $scenario) {
            self::assertSame('none|', $this->buildInChildProcess($scenario, 'Not\\A\\Class'), $scenario);
        }
    }

    private function buildInChildProcess(string $scenario, string $hiddenClass): string
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/hidden-package-build.php', $scenario, $hiddenClass],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        self::assertNotFalse($stdout);
        self::assertSame('', $stderr);

        return $stdout;
    }

    /** @param array<array-key, mixed> $config */
    private function loadRaw(array $config): ContainerBuilder
    {
        $container = $this->container();
        $instanceof = [];
        $configurator = new ContainerConfigurator($container, new PhpFileLoader($container, new FileLocator()), $instanceof, __FILE__, __FILE__);

        (new FuzzphonyBundle())->loadExtension($config, $configurator, $container);

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = $this->container();
        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([$config], $container);

        return $container;
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 3));
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->register('doctrine.dbal.default_connection', DbalConnection::class)->setSynthetic(true)->setPublic(true);
        $container->register('logger', \Psr\Log\LoggerInterface::class)->setSynthetic(true)->setPublic(true);

        return $container;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\BundleSettings;
use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** The corners of the bundle's wiring that decide which connection, which schemas and which indexes the security rules see. */
final class FuzzphonyBundleWiringEdgesTest extends TestCase
{
    public function testSystemSearchesAreAllowedUnlessSwitchedOff(): void
    {
        self::assertTrue(BundleSettings::fromConfig([], 'limiter')->allowSystem);
        self::assertTrue(BundleSettings::fromConfig(['authorization' => []], 'limiter')->allowSystem);
        self::assertTrue(BundleSettings::fromConfig(['authorization' => ['allow_system' => true]], 'limiter')->allowSystem);
        self::assertFalse(BundleSettings::fromConfig(['authorization' => ['allow_system' => false]], 'limiter')->allowSystem);
        $settings = (new \ReflectionClass(BundleSettings::class))->getConstructor();
        self::assertNotNull($settings);
        $default = null;
        foreach ($settings->getParameters() as $parameter) {
            if ($parameter->getName() === 'allowSystem') {
                $default = $parameter->getDefaultValue();
            }
        }
        self::assertTrue($default, 'the constructor default is the documented one');
    }

    public function testTheNamesOfTheDbalConnectionsAreStringsOnceAndInOrder(): void
    {
        $names = new \ReflectionMethod(FuzzphonyBundle::class, 'dbalConnectionNames');

        self::assertSame(['a', '5', 'z', 'b'], $names->invoke(null, [
            ['dbal' => ['connections' => ['a' => [], 5 => []], 'default_connection' => 'z']],
            'not an array',
            ['dbal' => null],
            ['dbal' => ['connections' => ['b' => [], 'a' => []]]],
        ]));
        self::assertSame(['default', 'main'], $names->invoke(null, [['dbal' => ['default_connection' => 'main']]]), 'no connections key: the implicit one and the default named');
        self::assertSame([], $names->invoke(null, []));
    }

    public function testAKnownConnectionCheckIsPublicAndNamesTheRole(): void
    {
        $settings = BundleSettings::fromConfig(['connections' => ['worker' => 'w', 'admin' => 'a']], 'limiter');

        FuzzphonyBundle::assertKnownConnections($settings, []);
        FuzzphonyBundle::assertKnownConnections($settings, ['default', 'w', 'a']);
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('fuzzphony.connections.admin names the DBAL connection "a", which is not configured. Configured connections: default, w.');
        FuzzphonyBundle::assertKnownConnections($settings, ['default', 'w']);
    }

    public function testAGrantedFieldNeedsAuthorizationButAFieldWithOtherOptionsDoesNot(): void
    {
        FuzzphonyBundle::assertGrantedNeedsAuthorization(['docs' => ['fields' => ['name' => ['weight' => 'A'], 'note' => 'B', 'x' => null]]], false);
        FuzzphonyBundle::assertGrantedNeedsAuthorization(['docs' => ['fields' => ['note' => ['granted' => 'ROLE_X']]]], true);
        FuzzphonyBundle::assertGrantedNeedsAuthorization(['docs' => 'not a map'], false);

        try {
            FuzzphonyBundle::assertGrantedNeedsAuthorization(['docs' => ['fields' => ['name' => ['weight' => 'A'], 'note' => ['granted' => 'ROLE_X']]]], false);
            self::fail('a granted field without authorization was accepted');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString(RegistryFactory::GRANTED_WITHOUT_AUTHORIZATION, $e->getMessage());
            self::assertStringContainsString('"docs"', $e->getMessage());
        }
    }

    public function testTheContainerRefusesAGrantedFieldWithoutAuthorizationAtLoad(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->build(['authorization' => ['enabled' => false], 'indexes' => ['docs' => ['source' => ['table' => 'docs'], 'fields' => ['name' => ['weight' => 'A', 'granted' => 'ROLE_X']]]]]);
    }

    public function testTheSeparateAdminIsWiredWhateverTheWorkerIs(): void
    {
        $container = $this->build(['connections' => ['worker' => 'default', 'admin' => 'admin']]);

        self::assertFalse($container->hasDefinition('fuzzphony.connection.worker'));
        self::assertTrue($container->hasDefinition('fuzzphony.connection.admin'), 'the admin follows although the worker uses the application connection');
    }

    public function testTheGrantsOfTheCommandsKnowBothSchemas(): void
    {
        $container = $this->build(['extension_schema' => 'ext', 'schema' => 'fz']);

        $schemaGrants = $container->getDefinition(SchemaCommand::class)->getArgument(2);
        self::assertInstanceOf(Definition::class, $schemaGrants);
        $names = $schemaGrants->getArgument(0);
        self::assertInstanceOf(Definition::class, $names);
        self::assertSame(['ext', 'fz'], $names->getArguments());

        $check = $container->getDefinition(DoctorCommand::class)->getArgument(4);
        self::assertInstanceOf(Definition::class, $check);
        $doctorGrants = $check->getArgument(1);
        self::assertInstanceOf(Definition::class, $doctorGrants);
        $doctorNames = $doctorGrants->getArgument(0);
        self::assertInstanceOf(Definition::class, $doctorNames);
        self::assertSame(['ext', 'fz'], $doctorNames->getArguments());
    }

    public function testTheRegistryGetsNoProjectDirWhenTheContainerHasNone(): void
    {
        $container = $this->build([], withProjectDir: false);

        $registry = $container->getDefinition('fuzzphony.registry');
        self::assertNull($registry->getArgument(4) ?? null, 'no project directory is passed on');
    }

    /** @param array<array-key, mixed> $config */
    private function build(array $config, bool $withProjectDir = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $parameters = ['kernel.environment' => 'test', 'kernel.debug' => false, 'kernel.cache_dir' => sys_get_temp_dir(), 'kernel.build_dir' => sys_get_temp_dir()];
        if ($withProjectDir) {
            $parameters['kernel.project_dir'] = dirname(__DIR__, 3);
        }
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
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

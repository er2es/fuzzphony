<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class RegistryFactoryMutationsTest extends TestCase
{
    public function testTheDiscoveredIndexesAreInTheRegistryAndYamlOverridesThem(): void
    {
        $discovery = new DoctrineIndexDiscovery(DoctrineTestCase::entityManager());

        $discovered = RegistryFactory::create([], $discovery);
        $overridden = RegistryFactory::create(['articles' => ['sync' => 'manual']], $discovery);

        self::assertTrue($discovered->has('articles'), 'the #[Searchable] entity is found');
        self::assertSame('orm', $discovered->get('articles')->sync->value);
        self::assertSame('manual', $overridden->get('articles')->sync->value, 'YAML overrides a discovered index');
        self::assertFalse(RegistryFactory::create([])->has('articles'), 'without discovery there is nothing to find');
    }

    public function testACachedDiscoveryIsNotRunAgainAndTheYamlIsStillAppliedFresh(): void
    {
        $cache = new ArrayAdapter();
        $first = RegistryFactory::create([], new DoctrineIndexDiscovery(DoctrineTestCase::entityManager()), $cache, cacheInCli: true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getMetadataFactory');
        $second = RegistryFactory::create(['articles' => ['sync' => 'manual']], new DoctrineIndexDiscovery($entityManager), $cache, cacheInCli: true);

        self::assertSame('orm', $first->get('articles')->sync->value);
        self::assertSame('manual', $second->get('articles')->sync->value, 'the YAML is applied on top of the cached definitions on every request');
    }

    public function testInTheCommandLineTheCacheIsNeitherReadNorWrittenByDefault(): void
    {
        self::assertSame('cli', \PHP_SAPI, 'the suite runs in the command line');
        $cache = new ArrayAdapter();
        // a warm pool, as a web request left it: a command must not take its (possibly stale) definitions
        RegistryFactory::create([], new DoctrineIndexDiscovery(DoctrineTestCase::entityManager()), $cache, cacheInCli: true);
        $stale = $cache->getItem('fuzzphony_discovered_indexes')->get();
        self::assertNotSame([], $stale);
        $cache->save($cache->getItem('fuzzphony_discovered_indexes')->set([])); // the pool says: no indexes

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('getMetadataFactory');
        $first = RegistryFactory::create([], new DoctrineIndexDiscovery($entityManager), $cache);
        $second = RegistryFactory::create([], new DoctrineIndexDiscovery($entityManager), $cache);

        self::assertFalse($first->has('articles'), 'the warm pool was not read: what the metadata says now is what a command sees');
        self::assertFalse($second->has('articles'));
        self::assertSame([], $cache->getItem('fuzzphony_discovered_indexes')->get(), 'and nothing was written to it');
    }

    public function testInTheCommandLineAnEmptyPoolStaysEmptyByDefault(): void
    {
        $cache = new ArrayAdapter();

        $registry = RegistryFactory::create([], new DoctrineIndexDiscovery(DoctrineTestCase::entityManager()), $cache);

        self::assertTrue($registry->has('articles'));
        self::assertFalse($cache->hasItem('fuzzphony_discovered_indexes'), 'no pool item is written');
    }

    public function testTheCachedDefinitionsSurviveAFilesystemRoundTripUnchanged(): void
    {
        $directory = sys_get_temp_dir() . '/fuzzphony_discovery_' . bin2hex(random_bytes(4));
        try {
            $fresh = RegistryFactory::create([], new DoctrineIndexDiscovery(DoctrineTestCase::entityManager()));
            RegistryFactory::create([], new DoctrineIndexDiscovery(DoctrineTestCase::entityManager()), new FilesystemAdapter('fz', 0, $directory), cacheInCli: true);

            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->expects(self::never())->method('getMetadataFactory');
            $cached = RegistryFactory::create([], new DoctrineIndexDiscovery($entityManager), new FilesystemAdapter('fz', 0, $directory), cacheInCli: true);

            self::assertEquals($fresh->get('articles'), $cached->get('articles'));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }

    public function testAnUnusableCacheEntryIsRediscoveredAndReplaced(): void
    {
        foreach (['not an array', ['articles' => 'x'], [new \stdClass()]] as $garbage) {
            $cache = new ArrayAdapter();
            $cache->save($cache->getItem('fuzzphony_discovered_indexes')->set($garbage));

            $registry = RegistryFactory::create([], new DoctrineIndexDiscovery(DoctrineTestCase::entityManager()), $cache, cacheInCli: true);

            self::assertTrue($registry->has('articles'));
            $stored = $cache->getItem('fuzzphony_discovered_indexes')->get();
            self::assertIsArray($stored);
            self::assertContainsOnlyInstancesOf(IndexDefinition::class, $stored);
            self::assertNotSame([], $stored);
        }
    }

    public function testWithoutAPoolEveryCallDiscoversAgain(): void
    {
        $discovery = new DoctrineIndexDiscovery(DoctrineTestCase::entityManager());

        $first = RegistryFactory::create([], $discovery);
        $second = RegistryFactory::create([], $discovery);

        self::assertEquals($first->get('articles'), $second->get('articles'));
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The discovery cache through a real, compiled Symfony kernel with a real cache.system: the wiring in
 * FuzzphonyBundle (service('cache.system'), the kernel.debug switch) is only proven end to end here.
 */
final class RegistryCacheKernelTest extends TestCase
{
    private const string KEY = 'fuzzphony_discovered_indexes';

    private string $dir;

    protected function setUp(): void
    {
        PostgresTestCase::dsn(); // skips without FUZZPHONY_TEST_DSN
        $this->dir = sys_get_temp_dir() . '/fuzzphony-registry-cache-' . bin2hex(random_bytes(6));
        RegistryCacheKernel::$metadataEnumerations = 0;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testProductionDiscoversOnceAndAFreshKernelOnTheSameCacheDirReadsItFromCacheSystem(): void
    {
        [$first, $firstPool] = $this->boot(false);
        self::assertSame(1, RegistryCacheKernel::$metadataEnumerations, 'the first use discovers the entities');
        self::assertTrue($firstPool->getItem(self::KEY)->isHit(), 'and writes the discovered list to cache.system');
        self::assertTrue($first->has('articles'));

        $compiled = $this->compiledContainers();
        self::assertNotSame([], $compiled);

        RegistryCacheKernel::$metadataEnumerations = 0;
        [$second] = $this->boot(false);

        self::assertSame($compiled, $this->compiledContainers(), 'the second kernel reuses the compiled container of the first');
        self::assertSame(0, RegistryCacheKernel::$metadataEnumerations, 'the second kernel never asks the metadata factory');
        self::assertEquals($first->get('articles'), $second->get('articles'));
        self::assertInstanceOf(IndexDefinition::class, $second->get('articles'));
    }

    public function testDebugNeverCaches(): void
    {
        [$first, $firstPool] = $this->boot(true);
        [$second, $secondPool] = $this->boot(true);

        self::assertSame(2, RegistryCacheKernel::$metadataEnumerations, 'every registry build asks the metadata factory');
        self::assertFalse($firstPool->hasItem(self::KEY));
        self::assertFalse($secondPool->hasItem(self::KEY));
        self::assertEquals($first->get('articles'), $second->get('articles'));
    }

    public function testTheCommandLineNeverUsesTheCacheUnlessTheSeamIsSet(): void
    {
        [$first, $firstPool] = $this->boot(false, cacheInCli: false);
        [$second, $secondPool] = $this->boot(false, cacheInCli: false);

        self::assertSame(2, RegistryCacheKernel::$metadataEnumerations, 'a command asks the metadata factory on every build, production or not');
        self::assertFalse($firstPool->hasItem(self::KEY));
        self::assertFalse($secondPool->hasItem(self::KEY), 'and writes nothing to cache.system');
        self::assertEquals($first->get('articles'), $second->get('articles'));
    }

    public function testClearingTheKernelCacheDirDropsTheDiscoveredList(): void
    {
        $this->boot(false);
        RegistryCacheKernel::$metadataEnumerations = 0;
        $this->boot(false);
        self::assertSame(0, RegistryCacheKernel::$metadataEnumerations, 'precondition: warm cache');

        (new Filesystem())->remove($this->dir . '/cache'); // what cache:clear does
        [$registry, $pool] = $this->boot(false);

        self::assertSame(1, RegistryCacheKernel::$metadataEnumerations, 'after cache:clear the entities are discovered again');
        self::assertTrue($pool->getItem(self::KEY)->isHit(), 'and the list is cached anew');
        self::assertTrue($registry->has('articles'));
    }

    /** @return array{IndexRegistry, CacheItemPoolInterface} a kernel booted like a new process: new Kernel object, new container instance. */
    private function boot(bool $debug, bool $cacheInCli = true): array
    {
        // PhpFilesAdapter memoises the values it read in a process-wide static; a new process starts without it
        (new \ReflectionProperty(PhpFilesAdapter::class, 'valuesCache'))->setValue(null, []);
        $kernel = new RegistryCacheKernel($this->dir, $debug, $cacheInCli);
        $kernel->boot();
        $container = $kernel->getContainer();
        $registry = $container->get('test.registry');
        $pool = $container->get('test.cache');
        self::assertInstanceOf(IndexRegistry::class, $registry);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $kernel->shutdown();

        return [$registry, $pool];
    }

    /** @return list<string> */
    private function compiledContainers(): array
    {
        $found = glob($this->dir . '/cache/*Container*.php');
        $files = $found === false ? [] : $found;
        $files = array_map(static fn(string $f): string => $f . '@' . filemtime($f), $files);
        sort($files);

        return $files;
    }
}

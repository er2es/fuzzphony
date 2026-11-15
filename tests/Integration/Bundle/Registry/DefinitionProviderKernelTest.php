<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\CountingDefinitionProvider;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use Fuzzphony\Tests\Support\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/** A service implementing DefinitionProvider is autoconfigured into the registry: asked on every registry build, in production too, never by cache:warmup. */
final class DefinitionProviderKernelTest extends TestCase
{
    use RestoresPhpHandlers;

    private string $dir;

    protected function setUp(): void
    {
        PostgresTestCase::dsn();
        $this->dir = sys_get_temp_dir() . '/fuzzphony-provider-' . bin2hex(random_bytes(6));
        CountingDefinitionProvider::$calls = 0;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTheTaggedProviderJoinsTheRegistryOnEveryBuildAndNotInTheWarmer(): void
    {
        (new \ReflectionProperty(PhpFilesAdapter::class, 'valuesCache'))->setValue(null, []);
        foreach ([1, 2] as $build) {
            $kernel = new RegistryCacheKernel($this->dir, false, true, [], [CountingDefinitionProvider::class]);
            $kernel->boot();
            try {
                $warmer = $kernel->getContainer()->get('test.warmer');
                self::assertInstanceOf(CacheWarmerInterface::class, $warmer);
                $warmer->warmUp($this->dir . '/cache');
                self::assertSame($build - 1, CountingDefinitionProvider::$calls, 'cache:warmup does not ask the provider');

                $registry = $kernel->getContainer()->get('test.registry');
                self::assertInstanceOf(IndexRegistry::class, $registry);
                self::assertSame(['articles', 'provided_docs'], array_keys($registry->all()));
                self::assertSame('provider ' . CountingDefinitionProvider::class, $registry->origin('provided_docs'));
                self::assertSame($build, CountingDefinitionProvider::$calls, 'once per registry build, also with a warm production cache');
            } finally {
                $kernel->shutdown();
            }
        }
    }
}

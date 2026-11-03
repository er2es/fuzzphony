<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Doctrine\ArticleAdminIndex;
use Fuzzphony\Tests\Fixtures\Doctrine\ArticlesDuplicateIndex;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use Fuzzphony\Tests\Support\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Filesystem\Filesystem;

/** A class with #[AsIndexDefinition], registered as a service, is found through autoconfiguration, next to the attribute index of the same entity; cached in production. */
final class DefinitionClassKernelTest extends TestCase
{
    use RestoresPhpHandlers;

    private const string KEY = 'fuzzphony_discovered_definition_classes';

    private string $dir;

    protected function setUp(): void
    {
        PostgresTestCase::dsn();
        $this->dir = sys_get_temp_dir() . '/fuzzphony-defclass-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTheTaggedServiceJoinsTheAttributeIndexOfTheSameEntity(): void
    {
        [$registry] = $this->boot(true, [ArticleAdminIndex::class]);

        self::assertSame(['articles', 'articles_admin'], array_keys($registry->all()));
        self::assertSame('attribute ' . Article::class, $registry->origin('articles'));
        self::assertSame('definition class ' . ArticleAdminIndex::class, $registry->origin('articles_admin'));
        self::assertSame('articles', $registry->get(Article::class)->name, 'the entity class still answers with the attribute index');
        self::assertSame(Article::class, $registry->get('articles_admin')->entityClass);
    }

    public function testProductionCachesTheDefinitionAndAFreshKernelReadsAnEqualOne(): void
    {
        [$first, $pool] = $this->boot(false, [ArticleAdminIndex::class]);
        self::assertTrue($pool->getItem(self::KEY)->isHit(), 'the definitions of the classes are written to cache.system');

        [$second] = $this->boot(false, [ArticleAdminIndex::class]);

        self::assertEquals($first->get('articles_admin'), $second->get('articles_admin'));
        $cached = $pool->getItem(self::KEY)->get();
        self::assertIsArray($cached);
        self::assertEquals($first->get('articles_admin'), $cached[ArticleAdminIndex::class]);
    }

    public function testClearingTheCacheDirBuildsTheDefinitionAgain(): void
    {
        $this->boot(false, [ArticleAdminIndex::class]);
        (new Filesystem())->remove($this->dir . '/cache'); // what cache:clear does
        [$registry, $pool] = $this->boot(false, [ArticleAdminIndex::class]);

        self::assertTrue($registry->has('articles_admin'));
        self::assertTrue($pool->getItem(self::KEY)->isHit());
    }

    public function testNoDefinitionClassLeavesTheCacheAlone(): void
    {
        [$registry, $pool] = $this->boot(false, []);

        self::assertSame(['articles'], array_keys($registry->all()));
        self::assertFalse($pool->hasItem(self::KEY));
    }

    public function testTheSameNameAsTheAttributeIndexIsRefusedNamingBothSources(): void
    {
        try {
            $this->boot(true, [ArticlesDuplicateIndex::class]);
            self::fail('a duplicate name must be refused');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('defined twice: by attribute ' . Article::class . ' and by definition class ' . ArticlesDuplicateIndex::class, $e->getMessage());
        }
    }

    /**
     * @param list<class-string> $classes
     *
     * @return array{IndexRegistry, CacheItemPoolInterface}
     */
    private function boot(bool $debug, array $classes): array
    {
        (new \ReflectionProperty(PhpFilesAdapter::class, 'valuesCache'))->setValue(null, []);
        $kernel = new RegistryCacheKernel($this->dir, $debug, true, [], $classes);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            $registry = $container->get('test.registry');
            $pool = $container->get('test.cache');
            self::assertInstanceOf(IndexRegistry::class, $registry);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        } finally {
            $kernel->shutdown();
        }

        return [$registry, $pool];
    }
}

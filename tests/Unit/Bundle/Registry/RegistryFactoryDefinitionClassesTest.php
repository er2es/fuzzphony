<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Registry;

use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Doctrine\ArticleAdminIndex;
use Fuzzphony\Tests\Fixtures\LegacyOrdersIndex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** The definition classes in the registry: origin, conflicts, YAML override and the cache. */
final class RegistryFactoryDefinitionClassesTest extends TestCase
{
    public function testDefinitionClassesJoinTheRegistryWithTheirOrigin(): void
    {
        $registry = RegistryFactory::create([], definitionClasses: [new ArticleAdminIndex(), new LegacyOrdersIndex()]);

        self::assertSame(['articles_admin', 'legacy_orders'], array_keys($registry->all()));
        self::assertSame('definition class ' . ArticleAdminIndex::class, $registry->origin('articles_admin'));
        self::assertSame(Article::class, $registry->get(Article::class)->entityClass);
        self::assertSame('articles_admin', $registry->get(Article::class)->name);
    }

    public function testTheSameNameFromTwoDefinitionClassesIsRefusedNamingBoth(): void
    {
        $twin = new #[\Fuzzphony\Core\Attribute\AsIndexDefinition('articles_admin')] class {
            public function build(\Fuzzphony\Core\Definition\IndexBuilder $b): void
            {
                $b->fromTable('other')->field('name', 'A');
            }
        };

        try {
            RegistryFactory::create([], definitionClasses: [new ArticleAdminIndex(), $twin]);
            self::fail('a duplicate name must be refused');
        } catch (InvalidDefinition $e) {
            self::assertSame('articles_admin', $e->index);
            self::assertStringContainsString('defined twice: by definition class ' . ArticleAdminIndex::class . ' and by definition class class@anonymous', $e->getMessage());
        }
    }

    public function testAYamlOverrideOfADefinitionClassIndexFollowsTheAttributeRule(): void
    {
        $registry = RegistryFactory::create(
            ['articles_admin' => ['thresholds' => ['relevance' => ['min_score' => 0.25]]], 'extra' => ['source' => ['table' => 'x'], 'fields' => ['name' => 'A']]],
            definitionClasses: [new ArticleAdminIndex()],
        );

        self::assertSame(['title', 'body'], $registry->get('articles_admin')->fieldNames(), 'the override keeps the fields of the class');
        self::assertSame('definition class ' . ArticleAdminIndex::class . ' + YAML', $registry->origin('articles_admin'));
        self::assertSame('YAML', $registry->origin('extra'));
    }

    public function testTheDefinitionsAreCachedAndAWarmCacheInstantiatesNothing(): void
    {
        $cache = new ArrayAdapter();
        $fresh = RegistryFactory::create([], null, $cache, true, definitionClasses: [new ArticleAdminIndex()]);

        $neverIterated = new class implements \IteratorAggregate, \Countable {
            public function getIterator(): \Traversable
            {
                throw new \LogicException('a warm cache must not instantiate the services');
            }

            public function count(): int
            {
                return 1;
            }
        };
        $cached = RegistryFactory::create([], null, $cache, true, definitionClasses: $neverIterated);

        self::assertEquals($fresh->get('articles_admin'), $cached->get('articles_admin'));
        self::assertSame('definition class ' . ArticleAdminIndex::class, $cached->origin('articles_admin'));
    }

    public function testNoDefinitionClassNoCacheItem(): void
    {
        $cache = new ArrayAdapter();
        $empty = new class implements \IteratorAggregate, \Countable {
            public function getIterator(): \EmptyIterator
            {
                return new \EmptyIterator();
            }

            public function count(): int
            {
                return 0;
            }
        };
        RegistryFactory::create([], null, $cache, true, definitionClasses: $empty);

        self::assertCount(0, $cache->getValues(), 'a countable zero never touches the cache');
    }

    public function testACorruptCachedEntryIsRebuilt(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('fuzzphony_discovered_definition_classes');
        $cache->save($item->set(['not-a-definition']));

        $registry = RegistryFactory::create([], null, $cache, true, definitionClasses: [new ArticleAdminIndex()]);

        self::assertTrue($registry->has('articles_admin'));
    }

    public function testBuildingTwiceIsDeterministic(): void
    {
        $first = RegistryFactory::create([], definitionClasses: [new ArticleAdminIndex()]);
        $second = RegistryFactory::create([], definitionClasses: [new ArticleAdminIndex()]);

        self::assertEquals($first->get('articles_admin'), $second->get('articles_admin'));
    }

    public function testAPoolThatCannotBeWrittenNeverBreaksDiscovery(): void
    {
        $throwing = new class extends ArrayAdapter {
            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                throw new \RuntimeException('read-only');
            }
        };
        $refusing = new class extends ArrayAdapter {
            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return false;
            }
        };

        foreach ([$throwing, $refusing] as $pool) {
            $registry = RegistryFactory::create([], cache: $pool, cacheInCli: true, definitionClasses: [new ArticleAdminIndex()]);
            self::assertSame(['articles_admin'], array_keys($registry->all()));
        }
    }

    public function testTheWarmerFillsTheCacheAndIsANoOpWithoutOne(): void
    {
        $cache = new ArrayAdapter();
        $warmer = new \Fuzzphony\Bundle\Registry\RegistryCacheWarmer([], null, $cache, null, true, [new ArticleAdminIndex()]);

        self::assertTrue($warmer->isOptional());
        self::assertSame([], $warmer->warmUp('/tmp'));
        self::assertTrue($cache->getItem('fuzzphony_discovered_definition_classes')->isHit());
        self::assertSame([], (new \Fuzzphony\Bundle\Registry\RegistryCacheWarmer([], null, null, null, true, []))->warmUp('/tmp'));
    }
}

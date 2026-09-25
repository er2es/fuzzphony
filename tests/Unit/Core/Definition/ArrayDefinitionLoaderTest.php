<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\TestCase;

final class ArrayDefinitionLoaderTest extends TestCase
{
    public function testLoadsAYamlShapedIndex(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', [
            'source' => ['query' => 'SELECT a.id, a.title, a.body, a.published_at FROM article a WHERE a.deleted_at IS NULL;'],
            'fields' => ['title' => ['weight' => 'A', 'fuzzy' => true], 'body' => 'C'],
            'filters' => ['published_at' => 'datetime'],
            'watch' => ['article' => 'SELECT :id'],
            'language' => 'german',
            'recency' => 'published_at',
            'profiles' => ['fresh' => ['recency' => 0.5]],
            'thresholds' => ['min_score' => 0.05, 'fuzzy_mode' => 'always', 'relax_when_empty' => false],
        ]);

        self::assertStringEndsNotWith(';', (string) $definition->source->query);
        self::assertSame(Weight::C, $definition->field('body')?->weight);
        self::assertSame('fuzzphony_german', $definition->text->configName());
        self::assertArrayHasKey('default', $definition->profiles);
        self::assertSame(0.5, $definition->profile('fresh')->recency);
        self::assertSame(FuzzyMode::Always, $definition->thresholds->fuzzyMode);
        self::assertFalse($definition->thresholds->relaxWhenEmpty);
    }

    public function testYamlOverridesAttributeIndexes(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);
        $merged = (new ArrayDefinitionLoader())->override($base, [
            'sync' => 'queue',
            'thresholds' => ['min_score' => 0.1],
            'profiles' => ['popular' => ['boost' => 0.2]],
        ]);

        self::assertSame(SyncMode::Queue, $merged->sync);
        self::assertSame(0.1, $merged->thresholds->minScore);
        self::assertSame(0.2, $merged->profile('popular')->boost);
        self::assertSame($base->fields, $merged->fields, 'fields stay defined by attributes');
    }

    public function testUnknownKeysAreRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/Unknown option\(s\): feilds/');
        (new ArrayDefinitionLoader())->load('x', ['feilds' => []]);
    }

    public function testTenantKeySetsTheTenantScope(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', [
            'source' => ['query' => 'SELECT a.id, a.title, a.account_id FROM article a'],
            'fields' => ['title' => 'A'],
            'filters' => ['account_id' => 'int'],
            'watch' => ['article' => 'SELECT :id'],
            'tenant' => 'account_id',
        ]);

        self::assertSame('account_id', $definition->tenant);
    }

    public function testYamlCanOverrideTheTenantScope(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class); // no #[Searchable(tenant: ...)]
        $merged = (new ArrayDefinitionLoader())->override($base, ['tenant' => 'price']);

        self::assertSame('price', $merged->tenant);
    }

    public function testWatchColumnsCanBeDeclaredInYaml(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => ['name']]],
        ]);

        self::assertSame(['name'], $definition->watches[0]->columns);
    }

    public function testWatchColumnsAsAnEmptyListMeansNoFiltering(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => []]],
        ]);

        self::assertNull($definition->watches[0]->columns);
    }

    public function testAbsentWatchColumnsMeansNoFiltering(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id']],
        ]);

        self::assertNull($definition->watches[0]->columns);
    }

    public function testAScalarWatchColumnsValueIsRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"columns" must be a list of strings/');
        (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => 'name']], // typo for columns: [name]
        ]);
    }

    public function testAWatchColumnsListContainingANonStringIsRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"columns" must be a list of strings/');
        (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => ['name', 42]]],
        ]);
    }

    public function testClassKeySetsTheEntityClass(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['table' => 'product'],
            'fields' => ['name' => 'A'],
            'class' => Product::class,
        ]);

        self::assertSame(Product::class, $definition->entityClass);
    }

    public function testIdTypeAndTriggerLevelKeysAreAppliedOnLoad(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['table' => 'product', 'id' => 'uuid'],
            'fields' => ['name' => 'A'],
            'id_type' => 'uuid',
            'trigger_level' => 'row',
        ]);

        self::assertSame(\Fuzzphony\Core\Definition\IdType::Uuid, $definition->idType);
        self::assertSame(\Fuzzphony\Core\Definition\TriggerLevel::Row, $definition->triggerLevel);
    }

    public function testANonArrayOptionalSectionIsIgnored(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['table' => 'product'],
            'fields' => ['name' => 'A'],
            'filters' => 'not-a-map', // ignored: map() falls back to []
        ]);

        self::assertSame([], $definition->filters);
    }

    public function testYamlOverrideCanChangeTriggerLevelLanguageBoostRecencyAndWatches(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);
        $merged = (new ArrayDefinitionLoader())->override($base, [
            'trigger_level' => 'row',
            'language' => 'german',
            'unaccent' => false,
            'boost' => 'price',
            'recency' => 'price',
            'watch' => ['brand' => ['ids' => 'SELECT id FROM product WHERE brand_id = :id']],
        ]);

        self::assertSame(\Fuzzphony\Core\Definition\TriggerLevel::Row, $merged->triggerLevel);
        self::assertSame('german', $merged->text->language);
        self::assertFalse($merged->text->unaccent);
        self::assertSame('price', $merged->boostColumn);
        self::assertSame('price', $merged->recencyColumn);
        self::assertContains('brand', array_map(static fn(Watch $w): string => $w->table, $merged->watches));
    }
}

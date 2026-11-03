<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexDefinitionClass;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Tests\Fixtures\Product;
use Fuzzphony\Tests\Fixtures\SiteMultiSourceIndex;
use PHPUnit\Framework\TestCase;

final class MultiSourceDefinitionTest extends TestCase
{
    private static function byBuilder(): IndexDefinition
    {
        return IndexDefinition::builder('site')
            ->source('product', SiteMultiSourceIndex::PRODUCT_SQL, entity: Product::class, watch: [new Watch('ms_product'), new Watch('ms_tag', 'SELECT id FROM ms_product WHERE tag_id = :id', 'id', ['label'])])
            ->sourceFromTable('article', 'ms_article', idType: 'uuid')
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'C')
            ->filter('account', 'int')
            ->tenant('account')
            ->build();
    }

    /** @return array<string, mixed> */
    private static function yaml(): array
    {
        return [
            'sources' => [
                'product' => [
                    'query' => SiteMultiSourceIndex::PRODUCT_SQL,
                    'entity' => Product::class,
                    'watch' => ['ms_product' => 'SELECT :id', 'ms_tag' => ['ids' => 'SELECT id FROM ms_product WHERE tag_id = :id', 'columns' => ['label']]],
                ],
                'article' => ['table' => 'ms_article', 'id_type' => 'uuid'],
            ],
            'fields' => ['title' => ['weight' => 'A', 'fuzzy' => true], 'body' => 'C'],
            'filters' => ['account' => 'int'],
            'tenant' => 'account',
        ];
    }

    /** @param array<string, mixed> $config */
    private static function fingerprintOf(array $config): string
    {
        return Fingerprint::definition((new ArrayDefinitionLoader())->load('site', $config));
    }

    /** @param array<string, mixed> $patch */
    private static function with(array $patch): string
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(self::yaml(), $patch);

        return self::fingerprintOf($merged);
    }

    public function testTheBuilderTheYamlAndADefinitionClassGiveTheSameIndex(): void
    {
        $builder = self::byBuilder();
        $yaml = (new ArrayDefinitionLoader())->load('site', self::yaml());
        $class = IndexDefinitionClass::fromClass(SiteMultiSourceIndex::class);

        self::assertSame(Fingerprint::definition($builder), Fingerprint::definition($yaml));
        self::assertSame(Fingerprint::definition($builder), Fingerprint::definition($class));
        self::assertSame(Fingerprint::documents($builder), Fingerprint::documents($class));
        self::assertEquals($builder, $class);
        self::assertEquals($builder, $yaml);
        self::assertSame(['product', 'article'], array_map(static fn($s): string => $s->name, $builder->sources));
        self::assertSame(IdType::String, $builder->idType, 'the stored key is text');
        self::assertTrue($builder->isMultiSource());
    }

    public function testTheFingerprintFollowsEverySourceElement(): void
    {
        $base = Fingerprint::definition(self::byBuilder());

        self::assertNotSame($base, self::with(['sources' => ['product' => ['query' => SiteMultiSourceIndex::PRODUCT_SQL . ' WHERE account > 0']]]), 'the SQL');
        self::assertNotSame($base, self::with(['sources' => ['product' => ['watch' => ['ms_tag' => ['columns' => ['label', 'id']]]]]]), 'a watch');
        self::assertNotSame($base, self::with(['sources' => ['article' => ['id_type' => 'string']]]), 'the id type');
        self::assertNotSame($base, self::with(['sources' => ['product' => ['entity' => null]]]), 'the entity');
        $reversed = self::yaml();
        $reversed['sources'] = ['article' => ['table' => 'ms_article', 'id_type' => 'uuid'], 'product' => ['query' => SiteMultiSourceIndex::PRODUCT_SQL, 'entity' => Product::class, 'watch' => ['ms_product' => 'SELECT :id', 'ms_tag' => ['ids' => 'SELECT id FROM ms_product WHERE tag_id = :id', 'columns' => ['label']]]]];
        self::assertNotSame($base, self::fingerprintOf($reversed), 'the order');
    }

    public function testASourceNameIsAnIdentifierAndUniqueInsideTheIndex(): void
    {
        try {
            IndexDefinition::builder('site')
                ->source('Pro:duct', 'SELECT 1 AS id', watch: [new Watch('t')])
                ->source('product', 'SELECT 1 AS id', watch: [new Watch('t')])
                ->source('product', 'SELECT 1 AS id', watch: [new Watch('t')])
                ->field('title', 'A')
                ->build();
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('Source name "Pro:duct" must match', $e->getMessage());
            self::assertStringContainsString('Source "product" is defined twice', $e->getMessage());
        }
    }

    public function testASourceAndSourcesAreExclusive(): void
    {
        try {
            IndexDefinition::builder('site')->fromTable('product')->source('a', 'SELECT 1 AS id', watch: [new Watch('t')])->field('title', 'A')->build();
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('either one source (fromTable / fromQuery) or several', $e->getMessage());
        }
        try {
            (new ArrayDefinitionLoader())->load('site', ['source' => ['table' => 'x'], 'sources' => ['a' => ['table' => 'y']], 'fields' => ['title' => 'A']]);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('"sources" and "source" are exclusive', $e->getMessage());
        }
    }

    public function testWatchesEntityAndIdTypeBelongToTheSources(): void
    {
        foreach (['watch' => ['x' => 'SELECT :id'], 'id_type' => 'uuid', 'class' => Product::class] as $key => $value) {
            try {
                (new ArrayDefinitionLoader())->load('site', ['sources' => ['a' => ['table' => 'y']], 'fields' => ['title' => 'A'], $key => $value]);
                self::fail('expected InvalidDefinition for ' . $key);
            } catch (InvalidDefinition $e) {
                self::assertStringContainsString(sprintf('"sources" and "%s" are exclusive', $key), $e->getMessage());
            }
        }
        try {
            IndexDefinition::builder('site')->source('a', 'SELECT 1 AS id', watch: [new Watch('t')])->watch('t')->entity(Product::class)->idType('uuid')->field('title', 'A')->build();
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('declares its watches inside each source', $e->getMessage());
            self::assertStringContainsString('the entity class inside each source', $e->getMessage());
            self::assertStringContainsString('the id type inside each source', $e->getMessage());
        }
    }

    public function testAQuerySourceNeedsItsOwnWatchesForSyncThatUsesTriggers(): void
    {
        try {
            IndexDefinition::builder('site')->source('legacy', 'SELECT 1 AS id')->field('title', 'A')->build();
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('Source "legacy": Sync mode "queue" needs to know which tables to watch', $e->getMessage());
        }
    }

    public function testYamlSourcesAreCheckedAsAWhole(): void
    {
        try {
            (new ArrayDefinitionLoader())->load('site', ['sources' => ['a' => ['table' => 'y', 'query' => 'SELECT 1', 'entiti' => 'x'], 'b' => ['entity' => 'No\\Such\\Class'], 'c' => 'table'], 'fields' => ['title' => 'A']]);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('Source "a": unknown option(s): entiti', $e->getMessage());
            self::assertStringContainsString('Source "a" takes one of "table", "query" or "query_file"', $e->getMessage());
            self::assertStringContainsString('Source "b": "entity" must be the name of an existing class', $e->getMessage());
            self::assertStringContainsString('Source "c" must be a map', $e->getMessage());
        }
    }

    public function testTheRegistryFindsTheIndexOfASourcesEntity(): void
    {
        $registry = new IndexRegistry([self::byBuilder()]);

        self::assertSame('site', $registry->get(Product::class)->name);
        self::assertSame('product', $registry->get('site')->sourceForEntity(Product::class)?->name);
    }

    public function testAKeyIsSplitIntoTheKindAndTheTypedId(): void
    {
        $site = self::byBuilder();
        $single = IndexDefinition::builder('products')->fromTable('p')->field('title', 'A')->build();

        self::assertSame(['article', '0b1d6f0e-0000-4000-8000-000000000001'], $site->parseKey('article:0b1d6f0e-0000-4000-8000-000000000001'));
        self::assertSame(['product', 42], $site->parseKey('product:42'));
        self::assertSame(['products', 7], $single->parseKey('7'));
    }
}

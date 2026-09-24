<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Conformance;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Capability;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every engine must share. A new engine (MySQL, MariaDB, ...) extends this class,
 * provides a connection + engine, and must pass these tests for the capabilities it claims.
 */
abstract class EngineConformanceTestCase extends TestCase
{
    protected Connection $connection;
    protected Engine $engine;
    protected Fuzzphony $fuzzphony;

    abstract protected function createConnection(): Connection;

    abstract protected function createEngine(Connection $connection): Engine;

    /** Creates fz_brand + fz_product with the shared fixture rows. */
    abstract protected function createFixtureTables(Connection $connection): void;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->engine = $this->createEngine($this->connection);
        $this->createFixtureTables($this->connection);

        $index = Indexes::products('manual');
        $this->fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('products');
    }

    /** @return list<int|string> */
    protected function ids(SearchResult $result): array
    {
        return $result->ids();
    }

    public function testFindsByWord(): void
    {
        self::assertContains(1, $this->ids($this->fuzzphony->in('products')->query('mouse')->get()));
    }

    public function testStemming(): void
    {
        $this->requireCapability(Capability::Stemming);
        self::assertContains(1, $this->ids($this->fuzzphony->in('products')->query('mice OR mouses')->get()));
    }

    public function testAccentFolding(): void
    {
        $this->requireCapability(Capability::AccentFolding);
        self::assertSame([5], $this->ids($this->fuzzphony->in('products')->query('creme brulee')->get()));
    }

    public function testTypoTolerance(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        $result = $this->fuzzphony->in('products')->query('headphnoes')->get();

        self::assertTrue($result->usedFuzzy);
        self::assertSame(3, $result->hits[0]->id ?? null);
    }

    public function testExclusion(): void
    {
        // typo-tolerant fallback kicks in here (few exact hits), and must honour the exclusion too
        $ids = $this->ids($this->fuzzphony->in('products')->query('wireless -headphones')->get());

        self::assertContains(1, $ids);
        self::assertNotContains(3, $ids);
    }

    public function testPhrase(): void
    {
        $this->requireCapability(Capability::Phrase);
        self::assertSame([3], $this->ids($this->fuzzphony->in('products')->query('"noise cancelling"')->thresholds(['fuzzy_mode' => 'never'])->get()));
    }

    public function testFieldScope(): void
    {
        $this->requireCapability(Capability::FieldScopedQueries);
        self::assertEqualsCanonicalizing([2], $this->ids($this->fuzzphony->in('products')->query('brand:razer')->thresholds(['fuzzy_mode' => 'never'])->get()));
    }

    public function testFiltersNarrowResults(): void
    {
        $ids = $this->ids($this->fuzzphony->in('products')->query('mouse')->whereBetween('price', 3_000, 5_000)->where('in_stock', true)->get());

        self::assertSame([1], $ids);
    }

    public function testExactTitleMatchRanksFirst(): void
    {
        self::assertSame(4, $this->fuzzphony->in('products')->query('mouse pad')->get()->hits[0]->id ?? null);
    }

    public function testMinScoreOnlyCountsRelevance(): void
    {
        $result = $this->fuzzphony->in('products')->query('mouse')->profile('popular')->thresholds(['min_score' => 10])->get();

        self::assertSame([], $result->hits, 'boosts must not rescue irrelevant hits');
    }

    public function testPerQueryRankingOverridesChangeTheOrder(): void
    {
        $plain = $this->fuzzphony->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->ranking(['exact_bonus' => 0, 'prefix_bonus' => 0]);

        self::assertNotSame(2, $plain->get()->hits[0]->id ?? null);
        self::assertSame(2, $plain->ranking(['boost' => 1.0])->get()->hits[0]->id ?? null, 'the most popular mouse wins with a strong boost');
    }

    public function testInvalidRankingOverridesFailFast(): void
    {
        $this->expectException(\Fuzzphony\Core\Exception\InvalidDefinition::class);
        $this->fuzzphony->in('products')->ranking(['text' => 0, 'fuzzy' => 0]);
    }

    public function testTotalsSurviveEmptyPages(): void
    {
        $result = $this->fuzzphony->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->page(50, 2)->get();

        self::assertSame([], $result->hits);
        self::assertGreaterThan(0, $result->total);
    }

    public function testOnlyExclusionsReturnNothingWithAWarning(): void
    {
        $result = $this->fuzzphony->in('products')->query('-mouse')->get();

        self::assertSame([], $result->hits);
        self::assertNotEmpty($result->warnings);
    }

    public function testBrowseWithoutText(): void
    {
        $result = $this->fuzzphony->in('products')->where('in_stock', false)->get();

        self::assertSame([3], $result->ids());
    }

    public function testRefreshReflectsChangesAndDeletes(): void
    {
        $this->connection->execute("UPDATE fz_product SET name = 'Vertical ergonomic mouse' WHERE id = 2");
        $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
        $this->fuzzphony->refresh('products', [2, 4]);

        self::assertContains(2, $this->ids($this->fuzzphony->in('products')->query('ergonomic')->get()));
        self::assertNotContains(4, $this->ids($this->fuzzphony->in('products')->query('pad')->thresholds(['fuzzy_mode' => 'never'])->get()));
    }

    protected function requireCapability(Capability $capability): void
    {
        if (!$this->engine->capabilities()->supports($capability)) {
            self::markTestSkipped(sprintf('%s does not support %s.', $this->engine->name(), $capability->value));
        }
    }

    /**
     * The shared dataset, as plain rows (engines create the tables in their own dialect).
     *
     * @return array{brands: list<array{int, string}>, products: list<array{int, string, string, int, int, bool, float, string}>}
     */
    public static function fixtureRows(): array
    {
        return [
            'brands' => [[1, 'Logitech'], [2, 'Razer'], [3, 'Sony']],
            'products' => [
                [1, 'Wireless mouse', 'Silent wireless mouse for the office', 1, 3_990, true, 5.0, '-2 days'],
                [2, 'Gaming mouse RGB', 'Fast wired gaming mouse with cable', 2, 19_990, true, 9.0, '-200 days'],
                [3, 'Wireless headphones', 'Noise cancelling headphones, long battery life', 3, 49_990, false, 7.0, '-10 days'],
                [4, 'Mouse pad', 'Large mouse pad', 1, 2_990, true, 1.0, 'now'],
                [5, 'Crème brûlée torch', 'Kitchen torch for caramelising', 3, 9_990, true, 0.0, 'now'],
            ],
        ];
    }
}

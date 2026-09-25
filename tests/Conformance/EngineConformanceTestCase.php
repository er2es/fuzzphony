<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Conformance;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Capability;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Sync\Reindexer;
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

    public function testATypoOnOneWordStillRequiresTheOtherWord(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        // "wireles" alone is close enough to product 1 ("Wireless mouse"), but product 1 has no headphones
        $result = $this->fuzzphony->in('products')->query('wireles headphones')->get();

        self::assertTrue($result->usedFuzzy);
        self::assertSame([3], $this->ids($result));
    }

    public function testTypoTolerantOrGroup(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        $result = $this->fuzzphony->in('products')->query('headphnoes | torhc')->get();

        self::assertTrue($result->usedFuzzy);
        self::assertEqualsCanonicalizing([3, 5], $this->ids($result));
    }

    public function testTypoTolerantMatchingHonoursANegationNestedInAGroup(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        // product 1 is a wireless mouse, but a "silent" one
        $result = $this->fuzzphony->in('products')->query('wireles (headphones | mouse -silent)')->get();

        self::assertTrue($result->usedFuzzy);
        self::assertSame([3], $this->ids($result));
    }

    public function testTypoTolerantPhrase(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        $this->requireCapability(Capability::Phrase);
        $result = $this->fuzzphony->in('products')->query('"wireles headphones"')->get();

        self::assertTrue($result->usedFuzzy);
        // a phrase is one needle: the wireless headphones first, then the wireless mouse that shares
        // enough trigrams with it, and nothing else
        self::assertSame([3, 1], $this->ids($result));
        self::assertGreaterThan($result->hits[1]->breakdown->fuzzySimilarity, $result->hits[0]->breakdown->fuzzySimilarity);

        // the phrase is one unit among others: the wireless mouse lacks "sony", so it is out
        self::assertSame([3], $this->ids($this->fuzzphony->in('products')->query('"wireles headphones" sony')->get()));
    }

    public function testAnOrBranchScoresOnlyWhenItMatches(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        // 3 matches the AND group in full (1.0). 1, 2 and 4 match through the typo "mose" alone;
        // 1 also has "wireless" (half of the group), which must not count: its branch does not match.
        $result = $this->fuzzphony->in('products')->query('mose | (wireless headphones)')
            ->thresholds(['fuzzy_mode' => 'always'])->ranking(['exact_bonus' => 0, 'prefix_bonus' => 0])->get();
        $fuzzy = [];
        foreach ($result->hits as $hit) {
            $fuzzy[$hit->id] = $hit->breakdown->fuzzySimilarity;
        }

        self::assertEqualsCanonicalizing([1, 2, 3, 4], array_keys($fuzzy));
        self::assertEqualsWithDelta(1.0, $fuzzy[3], 1e-9);
        self::assertEqualsWithDelta($fuzzy[2], $fuzzy[1], 1e-9, 'a half-matching AND branch must not lift a row matched through another branch');
        self::assertLessThan(1.0, $fuzzy[1]);
    }

    public function testTypoTolerantScoreFollowsTheQueryStructure(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        // both need "mouse"; 1 also matches "wireles" (closer than "logitek"), 4 only "logitek";
        // 2 (no wireless / logitech) and 3 (no mouse) must not appear at all
        $result = $this->fuzzphony->in('products')->query('(wireles | logitek) mouse')->ranking(['exact_bonus' => 0, 'prefix_bonus' => 0])->get();

        self::assertSame([1, 4], $this->ids($result));
    }

    public function testStopWordsDoNotBlockTypoTolerance(): void
    {
        $this->requireCapability(Capability::Fuzzy);
        $this->requireCapability(Capability::Stemming);
        $result = $this->fuzzphony->in('products')->query('headphnoes for')->get();
        $alone = $this->fuzzphony->in('products')->query('headphnoes')->get();

        self::assertSame([3], $this->ids($result));
        // the stop word is ignored, not scored as a word that failed to match
        self::assertSame([3], $this->ids($alone));
        self::assertEqualsWithDelta($alone->hits[0]->breakdown->fuzzySimilarity, $result->hits[0]->breakdown->fuzzySimilarity, 1e-9);
    }

    public function testAWordThatMatchesNothingIsIgnoredWhenTheQueryFindsNothing(): void
    {
        // "offfice" is a typo of a word that only occurs in a description, which typo tolerance does not reach
        $result = $this->fuzzphony->in('products')->query('wireless mouse offfice')->get();

        self::assertSame([1], $this->ids($result));
        self::assertSame('(wireless AND mouse)', $result->interpretedAs);
        self::assertContains('No results for all words; ignored words that match nothing: "offfice".', $result->warnings);
    }

    public function testWordsThatAllExistButNeverTogetherAreNotRelaxed(): void
    {
        $result = $this->fuzzphony->in('products')->query('mouse torch')->get();

        self::assertSame([], $this->ids($result));
        self::assertSame(0, $result->total);
        self::assertSame([], $result->warnings);
        self::assertSame('(mouse AND torch)', $result->interpretedAs);
    }

    public function testAQueryWithHitsIsNotRelaxed(): void
    {
        $result = $this->fuzzphony->in('products')->query('wireless mouse')->get();

        self::assertSame([1], $this->ids($result));
        self::assertSame([], $result->warnings);
    }

    public function testASingleWordOrOnlyUnmatchedWordsAreNotRelaxed(): void
    {
        foreach (['offfice', 'offfice zzqqx'] as $query) {
            $result = $this->fuzzphony->in('products')->query($query)->get();

            self::assertSame([], $this->ids($result), $query);
            self::assertSame([], $result->warnings, $query);
        }
    }

    public function testRelaxationCanBeTurnedOff(): void
    {
        $result = $this->fuzzphony->in('products')->query('wireless mouse offfice')->thresholds(['relax_when_empty' => false])->get();

        self::assertSame([], $this->ids($result));
        self::assertSame([], $result->warnings);
        self::assertSame('(wireless AND mouse AND offfice)', $result->interpretedAs);
    }

    public function testRelaxationDoesNotDependOnTypoTolerance(): void
    {
        $result = $this->fuzzphony->in('products')->query('wireless mouse offfice')->thresholds(['fuzzy_mode' => 'never'])->get();

        self::assertSame([1], $this->ids($result));
        self::assertFalse($result->usedFuzzy);
        self::assertContains('No results for all words; ignored words that match nothing: "offfice".', $result->warnings);
    }

    public function testRelaxationOnlyCountsDocumentsThatPassTheFilters(): void
    {
        // only product 3 (wireless headphones) is out of stock: "mouse" matches nothing there
        $result = $this->fuzzphony->in('products')->query('wireless mouse')->where('in_stock', false)->get();

        self::assertSame([3], $this->ids($result));
        self::assertContains('No results for all words; ignored words that match nothing: "mouse".', $result->warnings);
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

    public function testPruneOrphansRemovesOnlyDocumentsTheSourceNoLongerHas(): void
    {
        $index = $this->fuzzphony->registry()->get('products');
        $this->connection->execute('DELETE FROM fz_product WHERE id IN (2, 4)'); // manual sync: still indexed
        self::assertContains(2, $this->ids($this->fuzzphony->in('products')->query('gaming')->get()));

        self::assertSame(2, $this->engine->pruneOrphans($index, 2), 'batches smaller than the index still see every document');
        self::assertSame(0, $this->engine->pruneOrphans($index));

        $browse = $this->fuzzphony->in('products')->get();
        self::assertEqualsCanonicalizing([1, 3, 5], $this->ids($browse), 'valid documents are kept');
    }

    public function testFullReindexPrunesOrphansButAResumedOneDoesNot(): void
    {
        $index = $this->fuzzphony->registry()->get('products');
        $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
        $pruned = [];
        $onPruned = static function (int $removed) use (&$pruned): void {
            $pruned[] = $removed;
        };

        (new Reindexer($this->engine))->run($index, 5_000, 1, null, $onPruned);
        self::assertSame([], $pruned, 'a resumed run covers only part of the source');
        self::assertContains(4, $this->ids($this->fuzzphony->in('products')->get()));

        (new Reindexer($this->engine))->run($index, 5_000, null, null, $onPruned);
        self::assertSame([1], $pruned);
        self::assertNotContains(4, $this->ids($this->fuzzphony->in('products')->get()));
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

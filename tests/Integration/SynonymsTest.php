<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

/** Synonyms are expanded on the query: groups, one-way rules, phrases, stems, exclusion and the bonus. */
final class SynonymsTest extends TestCase
{
    private RecordingMetricsCollector $metrics;

    /** @param list<mixed>|null $entries */
    private function fuzzphony(?array $entries = null): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Acme'], [2, 'Globex']],
            'products' => [
                [1, 'Smart television 55 inch', 'Ultra HD screen', 1, 49_990, true, 5.0, 'now'],
                [2, 'Wall bracket', 'Fits every TV on the wall', 1, 2_990, true, 3.0, 'now'],
                [3, 'Wireless mouse', 'Silent mouse', 2, 3_990, true, 2.0, 'now'],
                [4, 'Solid state drive 1TB', 'Fast storage', 2, 19_990, true, 4.0, 'now'],
                [5, 'Notebook sleeve', 'Protects a notebook', 1, 1_990, true, 1.0, 'now'],
                [6, 'Laptop stand', 'Aluminium stand for a laptop', 1, 5_990, true, 1.0, 'now'],
                [7, 'SSD enclosure', 'Case for an ssd', 2, 6_990, true, 1.0, 'now'],
                [8, 'TV', 'The television', 1, 9_990, true, 1.0, 'now'],
            ],
        ]);
        $this->metrics = new RecordingMetricsCollector();
        $index = Indexes::products('manual');
        if ($entries !== []) {
            $index = $index->withSynonyms(Synonyms::fromEntries($entries ?? [['tv', 'television'], ['ssd', 'solid state drive'], 'laptop => notebook']));
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection, metrics: $this->metrics), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    private function search(): \Fuzzphony\Core\Search\SearchBuilder
    {
        return $this->fuzzphony()->in('products')->thresholds(['fuzzy_mode' => 'never']);
    }

    /** @return list<int|string> */
    private static function ids(SearchResult $result): array
    {
        $ids = $result->ids();
        sort($ids);

        return $ids;
    }

    private function roundTrips(): int
    {
        return count(array_filter($this->metrics->calls, static fn(array $c): bool => $c[1] === 'fuzzphony.synonyms.duration_ms'));
    }

    public function testAGroupFindsItsMembersBothWays(): void
    {
        $search = $this->search();

        self::assertSame([1, 2, 8], self::ids($search->query('tv')->get()), 'the television and the TVs');
        self::assertSame([1, 2, 8], self::ids($search->query('television')->get()));
        // without the synonyms the same search only knows the word itself
        self::assertSame([2, 8], self::ids($this->fuzzphony([])->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('tv')->get()));
    }

    public function testAWordIsComparedByItsStem(): void
    {
        // "televisions" and "television" are one stem, so the plural finds the group of "television"
        self::assertSame([1, 2, 8], self::ids($this->search()->query('Televisions')->get()));
        self::assertSame([1, 2, 8], self::ids($this->search()->query('TELEVISION')->get()));
    }

    public function testExcludingAWordExcludesItsSynonymsToo(): void
    {
        $search = $this->search();

        self::assertSame([], self::ids($search->query('screen -tv')->get()), 'the television has the screen, and "television" is excluded');
        self::assertSame([1], self::ids($this->fuzzphony([])->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('screen -tv')->get()));
    }

    public function testAOneWayRuleWorksOneWayOnly(): void
    {
        $search = $this->search();

        self::assertSame([5, 6], self::ids($search->query('laptop')->get()), 'a laptop is also a notebook');
        self::assertSame([5], self::ids($search->query('notebook')->get()), 'a notebook is not a laptop');
    }

    public function testAPhraseMemberMatchesAQuotedPhraseOnly(): void
    {
        $search = $this->search();

        self::assertSame([4, 7], self::ids($search->query('ssd')->get()));
        self::assertSame([4, 7], self::ids($search->query('"solid state drive"')->get()));
        self::assertSame([4], self::ids($search->query('solid state drive')->get()), 'three separate words: no synonym');
    }

    public function testTheExpansionIsShownHighlightedAndTheTypedWordKeepsTheExactBonus(): void
    {
        $result = $this->search()->query('tv')->highlight('name')->get();

        self::assertSame('(tv OR television)', $result->interpretedAs);
        self::assertSame(8, $result->hits[0]->id, 'the product named TV first');
        self::assertGreaterThan(0.0, $result->hits[0]->breakdown->exactBonus, 'the bonus compares with "tv", not with "tv television"');
        $byId = [];
        foreach ($result->hits as $hit) {
            $byId[$hit->id] = $hit;
        }
        self::assertSame('Smart <mark>television</mark> 55 inch', $byId[1]->highlights['name']);
    }

    public function testRelaxationDoesNotNameAnAlternativeTheUserDidNotType(): void
    {
        $fuzzphony = $this->fuzzphony(['unicorn => pegasus']);
        $result = $fuzzphony->in('products')->query('mouse unicorn')->thresholds(['fuzzy_mode' => 'never'])->get();

        self::assertSame([3], self::ids($result));
        self::assertSame(['No results for all words; ignored words that match nothing: "unicorn".'], $result->warnings);
    }

    public function testPostgreSqlIsAskedForStemsOnlyWhenThereIsSomethingToExpand(): void
    {
        $with = $this->fuzzphony();
        $with->in('products')->query('tv')->get();
        self::assertSame(1, $this->roundTrips());

        $with->in('products')->query('tv*')->get();
        $with->in('products')->query('')->get();
        self::assertSame(1, $this->roundTrips(), 'a prefix and a browse have no word that could expand');

        $without = $this->fuzzphony([]);
        $without->in('products')->query('tv')->get();
        self::assertSame(0, $this->roundTrips(), 'an index without synonyms never asks');
    }

    public function testAMemberWithQuotesIsJustText(): void
    {
        $fuzzphony = $this->fuzzphony([["o'brien", 'obrien']]);

        self::assertSame([], self::ids($fuzzphony->in('products')->query('obrien')->get()));
        self::assertSame([], self::ids($fuzzphony->in('products')->query("o'brien")->get()));
    }

    public function testEveryWordOfTheQueryIsExpanded(): void
    {
        $search = $this->search();

        self::assertSame([1, 2, 4, 7, 8], self::ids($search->query('tv | ssd')->get()));
        self::assertSame([], self::ids($search->query('tv ssd')->get()), 'both groups must match in one product');
        self::assertSame([1, 2, 4, 7, 8], self::ids($search->query('tv | "solid state drives"')->get()), 'a plural is stemmed too, whichever word of the query it is');
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
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
    private PostgresEngine $engine;

    /** @param array<array-key, mixed>|null $entries */
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
                [9, "O'Brien jacket", 'Waxed jacket', 2, 12_990, true, 1.0, 'now'],
                [10, 'Wi-Fi router', 'Dual band', 2, 8_990, true, 1.0, 'now'],
                [11, 'C++ book', 'The language', 1, 4_990, true, 1.0, 'now'],
                [12, 'C# book', 'Another language', 1, 4_990, true, 1.0, 'now'],
                [13, 'Cpp primer', 'Learn to program', 1, 3_990, true, 1.0, 'now'],
                [14, 'Old televsion set', 'Needs repair', 1, 990, true, 1.0, 'now'],
            ],
        ]);
        $this->metrics = new RecordingMetricsCollector();
        // the suggestion lookup is another statement of explain(): these tests read the search's own
        $index = Indexes::products('manual')->withThresholds(new Thresholds(didYouMean: false));
        if ($entries !== []) {
            $index = $index->withSynonyms(Synonyms::fromEntries($entries ?? [['tv', 'television'], ['ssd', 'solid state drive'], 'laptop => notebook']));
        }
        $this->engine = new PostgresEngine($connection, metrics: $this->metrics);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
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
        $with->in('products')->query('tv')->get();
        self::assertSame(1, $this->roundTrips(), 'the stems are kept');

        $without = $this->fuzzphony([]);
        $without->in('products')->query('tv')->get();
        self::assertSame(0, $this->roundTrips(), 'an index without synonyms never asks');
    }

    public function testAnApostropheInAMemberIsMatchedAsText(): void
    {
        $fuzzphony = $this->fuzzphony([["o'brien", 'obrien']]);

        self::assertSame([9], self::ids($fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('obrien')->get()));
    }

    public function testHyphenatedAndSymbolWordsAreNotConfusedWithTheirParts(): void
    {
        $shown = fn(array $entries, string $query): string => (string) $this->fuzzphony($entries)->in('products')->thresholds(['fuzzy_mode' => 'never'])->query($query)->get()->interpretedAs;

        // "wi-fi" is one word: "fi" is another and must not be expanded to it, nor the reverse
        self::assertSame('(wi-fi OR wireless)', $shown([['wi-fi', 'wireless']], 'wi-fi'));
        self::assertSame('(wireless OR wi-fi)', $shown([['wi-fi', 'wireless']], 'wireless'));
        self::assertSame('fi', $shown([['wi-fi', 'wireless']], 'fi'));
        // PostgreSQL reduces both "c#" and "c++" to "c": they are still different words
        self::assertSame('(c++ OR cpp)', $shown([['c++', 'cpp']], 'c++'));
        self::assertSame('c#', $shown([['c++', 'cpp']], 'c#'));
        self::assertSame('c', $shown([['c++', 'cpp']], 'c'));
    }

    public function testAWordThatMatchesNothingIsReportedOnceWhileItsSynonymsFindDocuments(): void
    {
        $fuzzphony = $this->fuzzphony([['telly', 'television']]);
        $search = $fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'never']);

        $result = $search->query('telly xyzzy')->get();
        self::assertSame([1, 8], self::ids($result));
        self::assertSame(['No results for all words; ignored words that match nothing: "xyzzy".'], $result->warnings);
        self::assertSame('(telly OR television)', $result->interpretedAs, 'the expansion stays: it is one word');

        // a word with synonyms is one unit: it is not probed again for every alternative
        self::assertSame(['full-text', 'relaxation probe', 'relaxed: full-text'], array_column($search->query('telly xyzzy')->explain()->statements, 'label'));
        self::assertSame(['full-text'], array_column($search->query('telly')->explain()->statements, 'label'), 'a single word is never probed');
        self::assertSame(['full-text'], array_column($this->fuzzphony([['zzzz', 'yyyy']])->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('zzzz')->explain()->statements, 'label'));
    }

    public function testTypoToleranceAppliesToTheAlternatives(): void
    {
        $fuzzphony = $this->fuzzphony([['tv', 'television']]);
        $search = $fuzzphony->in('products');

        self::assertSame([1, 2, 8], self::ids($search->query('tv')->thresholds(['fuzzy_mode' => 'never'])->get()));
        // the product that says "televsion" (a typo of the alternative) is found by the typo-tolerant search for "tv"
        self::assertSame([1, 2, 8, 14], self::ids($search->query('tv')->thresholds(['fuzzy_mode' => 'always'])->get()));
        // a typo of the word the user typed is not a synonym of anything; the description is not a typo-tolerant field, so not product 8
        self::assertSame([1, 14], self::ids($search->query('televison')->thresholds(['fuzzy_mode' => 'always'])->get()));
    }

    public function testAPluralAbbreviationHasToBeListed(): void
    {
        // English leaves "tvs" as it is (no vowel to cut an "s" after), so it is not "tv" unless listed
        self::assertSame([], self::ids($this->search()->query('tvs')->get()));
        self::assertSame([1, 2, 8], self::ids($this->fuzzphony([['tv', 'tvs', 'television']])->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('tvs')->get()));
    }

    public function testAFieldScopedWordExpandsInsideItsField(): void
    {
        self::assertSame([1, 8], self::ids($this->search()->query('name:tv')->get()), 'the TV in a description is not in the name');
    }

    public function testAHugeSynonymGroupIsBoundedAndTheQueryStillRuns(): void
    {
        $members = array_map(static fn(int $i): string => 'word' . $i, range(0, 29));
        $fuzzphony = $this->fuzzphony([$members]);

        $result = $fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'always'])->query(implode(' ', array_slice($members, 0, 12)))->get();

        self::assertSame([], $result->ids());
        self::assertContains('Synonyms were expanded for part of the query only (too many alternatives).', $result->warnings);
    }

    public function testTheStemsAreFetchedOnceAndOnlyNewWordsAskAgain(): void
    {
        $fuzzphony = $this->fuzzphony();
        $search = $fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'never']);

        $search->query('tv')->get();
        self::assertSame(1, $this->roundTrips(), 'the synonyms and the word, together');
        $search->query('television -tv')->get();
        self::assertSame(1, $this->roundTrips(), 'both words are members: known already');
        $search->query('mouse')->get();
        self::assertSame(2, $this->roundTrips(), '"mouse" is a word this engine has not seen yet');
        $search->query('mouse tv')->get();
        self::assertSame(2, $this->roundTrips());
    }
    public function testEveryWordOfTheQueryIsExpanded(): void
    {
        $search = $this->search();

        self::assertSame([1, 2, 4, 7, 8], self::ids($search->query('tv | ssd')->get()));
        self::assertSame([], self::ids($search->query('tv ssd')->get()), 'both groups must match in one product');
        self::assertSame([1, 2, 4, 7, 8], self::ids($search->query('tv | "solid state drives"')->get()), 'a plural is stemmed too, whichever word of the query it is');
    }

    public function testTheStemCacheIsBoundedAndStartsOverWhenFull(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('tv')->get();
        $property = new \ReflectionProperty(PostgresEngine::class, 'stems');
        /** @var array<string, array<string, string>> $cached */
        $cached = $property->getValue($this->engine);
        $config = (string) array_key_first($cached);
        $property->setValue($this->engine, [$config => array_fill_keys(array_map(static fn(int $i): string => 'filler' . $i, range(1, 5_001)), 'x')]);

        $result = $fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'never'])->query('tv')->get();

        self::assertSame([1, 2, 8], self::ids($result));
        /** @var array<string, array<string, string>> $after */
        $after = $property->getValue($this->engine);
        self::assertLessThan(100, count($after[$config]), 'the filler is gone, the stems of this query are back');
    }

    public function testTheBudgetIsFourTimesMaxTerms(): void
    {
        $fuzzphony = $this->fuzzphony([['w0', 'w1', 'w2', 'w3', 'w4', 'w5']]);
        $warning = 'Synonyms were expanded for part of the query only (too many alternatives).';
        $run = fn(string $query, int $maxTerms): array => $fuzzphony->in('products')->thresholds(['fuzzy_mode' => 'never', 'max_terms' => $maxTerms])->query($query)->get()->warnings;

        // each word has five alternatives: with max_terms 2 the budget is 8, so one word fits and a second does not
        self::assertNotContains($warning, $run('w0', 2));
        self::assertContains($warning, $run('w0 w1', 2));
        // with max_terms 3 the budget is 12: two words fit, three do not
        self::assertNotContains($warning, $run('w0 w1', 3));
        self::assertContains($warning, $run('w0 w1 w2', 3));
    }
}

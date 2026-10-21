<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * A query made only of stop words ("the who"): the text search configuration drops them from the query and from the
 * documents, so the strict branch has nothing to match, but the typo-tolerant branch works on the normalised text and
 * keeps them as ordinary words. Every other query keeps dropping its stop words.
 */
final class StopWordQueriesTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_stop_doc, fuzzphony_stop_docs, fuzzphony_stop_docs__vocab, fuzzphony_stop_plain, fuzzphony_stop_plain__vocab, fuzzphony_stop_simple, fuzzphony_stop_simple__vocab, fuzzphony_stop_tenant, fuzzphony_stop_tenant__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_stop_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL, owner bigint NOT NULL)');
        $rows = [
            [1, 'The Who', 'rock band', '', 'music', 1],
            [2, 'Who', 'a doctor', '', 'tv', 1],
            [3, 'Rock opera', 'the who live', '', 'music', 2],
            [4, 'To be or not to be', 'hamlet', '', 'books', 1],
            [5, 'Beatles', 'the band', 'the who', 'music', 1],
            [6, 'Zebra', 'only here', 'the who', 'books', 1],
            [7, 'Mouse', 'wireless', '', 'tv', 1],
            [8, 'Mower', 'for the garden', '', 'tv', 2],
        ];
        foreach ($rows as [$id, $title, $body, $secret, $cat, $owner]) {
            $this->connection->execute('INSERT INTO fz_stop_doc VALUES (:id, :title, :body, :secret, :cat, :owner)', ['id' => $id, 'title' => $title, 'body' => $body, 'secret' => $secret, 'cat' => $cat, 'owner' => $owner]);
        }
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            $this->index('stop_docs', fuzzy: true),
            // the same documents with no typo-tolerant field: stop words can only be dropped
            $this->index('stop_plain', fuzzy: false),
            // "simple" has no stop words: the strict branch finds them, as before
            $this->index('stop_simple', fuzzy: true, language: 'simple'),
            $this->index('stop_tenant', fuzzy: true, tenant: true),
        ]));
        $this->fuzzphony->schema()->apply($this->connection);
        foreach (['stop_docs', 'stop_plain', 'stop_simple', 'stop_tenant'] as $name) {
            $this->fuzzphony->reindex($name);
        }
    }

    private function index(string $name, bool $fuzzy, string $language = 'english', bool $tenant = false): IndexDefinition
    {
        $builder = IndexDefinition::builder($name)
            ->fromQuery('SELECT id, title, body, secret, cat, owner FROM fz_stop_doc')
            ->field('title', 'A', fuzzy: $fuzzy)
            ->field('body', 'B', fuzzy: $fuzzy)
            ->field('secret', 'C', fuzzy: $fuzzy)
            ->filter('cat', 'string')
            ->language($language)
            ->sync('manual');
        if ($tenant) {
            $builder->filter('owner', 'int')->tenant('owner');
        }

        return $builder->build()->withSynonyms(Synonyms::fromEntries([['band', 'group']]));
    }

    private function search(string $text, string $mode = 'fallback', string $index = 'stop_docs'): SearchBuilder
    {
        return $this->fuzzphony->in($index)->query($text)->thresholds(['fuzzy_mode' => $mode]);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int|string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @return iterable<string, array{string}> */
    public static function fuzzyModes(): iterable
    {
        yield 'fallback' => ['fallback'];
        yield 'always' => ['always'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fuzzyModes')]
    public function testAQueryOfStopWordsFindsThemInTheTypoTolerantBranch(string $mode): void
    {
        $result = $this->search('the who', $mode)->get();

        self::assertSame([1, 3, 5, 6], self::sorted($result->ids()), 'in the title, the body or the secret field: every field of an unrestricted viewer');
        self::assertSame(1, $result->ids()[0], 'the title that is exactly the words ranks first');
        self::assertSame(4, $result->total);
        self::assertTrue($result->usedFuzzy);
        self::assertSame('(the AND who)', $result->interpretedAs);
        self::assertSame([], $result->warnings, 'no warning is shown about stop words today, and none for this');
        self::assertNull($result->didYouMean);

        $who = $this->search('who', $mode)->get()->ids();
        self::assertSame(2, $who[0], 'the title that is exactly the word first');
        self::assertEqualsCanonicalizing([1, 2, 3, 5, 6], array_slice($who, 0, 5));
        self::assertSame([4], $this->search('"to be or not to be"', $mode)->get()->ids(), 'a quoted phrase is one word of 14 letters');
        self::assertSame([1, 2], self::sorted($this->search('title:who', $mode)->get()->ids()), 'a word scoped to a field is checked against that field only');
    }

    public function testAtMostThreeStopWordsAreSearched(): void
    {
        $this->connection->execute("INSERT INTO fz_stop_doc VALUES (30, 'The Who and for', '', '', 'music', 1)");
        $this->fuzzphony->reindex('stop_docs');

        self::assertSame([30], $this->search('the who and')->get()->ids());
        self::assertSame([], $this->search('the who and for')->get()->ids(), 'four stop words: the cost adds up, so the query is left as it always was');
        self::assertSame([], $this->search('the who and for', 'always')->restrictedToFields('title')->get()->ids());
    }

    public function testShortStopWordsStillMustMatchExactly(): void
    {
        // "to", "be" ... are shorter than fuzzy_min_length (3): such a word matches exactly, which a stop word never does
        self::assertSame([], $this->search('to be or not to be')->get()->ids());
        self::assertSame([], $this->search('to the')->get()->ids());
        self::assertSame([], $this->search('to the', 'always')->get()->ids());
        $result = $this->search('to be')->thresholds(['fuzzy_min_length' => 2])->get();
        self::assertSame([4], $result->ids(), 'with a lower minimum they are long enough');
    }

    public function testWithoutTypoToleranceTheStopWordsAreDroppedAsBefore(): void
    {
        foreach (['fallback', 'always', 'never'] as $mode) {
            self::assertSame([], $this->search('the who', $mode, 'stop_plain')->get()->ids(), 'no typo-tolerant field: ' . $mode);
        }
        self::assertSame([], $this->search('the who', 'never')->get()->ids(), 'fuzzy_mode never');
        self::assertSame([], $this->search('who', 'never')->get()->ids());
        self::assertSame([], $this->search('the who')->thresholds(['fuzzy_mode' => 'always'])->ranking(['fuzzy' => 0.0])->get()->ids(), 'a typo-tolerant weight of 0');
        $simple = $this->search('the who', 'never', 'stop_simple')->get();
        self::assertSame([1, 3, 5, 6], self::sorted($simple->ids()), 'the simple configuration has no stop words: the strict branch finds them');
        self::assertFalse($simple->usedFuzzy);
    }

    public function testAQueryWithARealWordIgnoresItsStopWordsAsBefore(): void
    {
        // the stop words stay dropped (the point of dropping them): the same hits as without them
        foreach (['fallback', 'always', 'never'] as $mode) {
            self::assertSame(self::sorted($this->search('rock', $mode)->get()->ids()), self::sorted($this->search('the who rock', $mode)->get()->ids()), $mode);
            self::assertSame(self::sorted($this->search('mouse', $mode)->get()->ids()), self::sorted($this->search('a mouse for the', $mode)->get()->ids()), $mode);
            self::assertSame(self::sorted($this->search('hamlet', $mode)->get()->ids()), self::sorted($this->search('"to be or not to be" hamlet', $mode)->get()->ids()), $mode);
        }
        // and a real word that is not found stays not found: it is not rescued by the stop words
        self::assertSame([], $this->search('the who zzzzzz', 'never')->get()->ids());
        self::assertSame([], $this->search('the who -rock', 'never', 'stop_plain')->thresholds(['relax_when_empty' => false])->where('cat', 'nothing')->get()->ids());
    }

    public function testAnExcludedRealWordLeavesTheQueryAsItWas(): void
    {
        // the strict branch is then a pure exclusion ("everything but rock", known and unchanged); the typo-tolerant branch
        // does not run in fallback mode with that many hits, and the stop words stay dropped
        $result = $this->search('the who -band')->get();
        self::assertFalse($result->usedFuzzy);
        self::assertSame([2, 3, 4, 6, 7, 8], self::sorted($result->ids()));
    }

    public function testAnExcludedStopWordIsStillIgnored(): void
    {
        self::assertSame([1, 3, 5, 6, 8], self::sorted($this->search('the -who')->get()->ids()), '"the" is searched, "-who" is dropped as a stop word always was');
    }

    public function testTheFiltersAndTheTenantStillApply(): void
    {
        self::assertSame([1, 3, 5], self::sorted($this->search('the who')->where('cat', 'music')->get()->ids()));
        self::assertSame([6], $this->search('the who')->where('cat', 'books')->get()->ids());
        self::assertSame([], $this->search('the who')->where('cat', 'nothing')->get()->ids());
        self::assertSame([1, 5, 6], self::sorted($this->search('the who', 'fallback', 'stop_tenant')->forTenant(1)->get()->ids()), 'documents of tenant 1');
        self::assertSame([3], $this->search('the who', 'fallback', 'stop_tenant')->forTenant(2)->get()->ids(), 'documents of tenant 2');
        self::assertSame([], $this->search('the who', 'always', 'stop_tenant')->forTenant(3)->get()->ids());
    }

    public function testFacetsAndExactCountsFollowTheSearch(): void
    {
        $result = $this->search('the who')->facets('cat')->exactCounts()->get();

        self::assertSame(4, $result->total);
        $facet = [];
        foreach ($result->facets['cat'] as $value) {
            $facet[$value->value] = $value->count;
        }
        self::assertSame(['music' => 3, 'books' => 1], $facet);
    }

    public function testARestrictedViewerNeverMatchesThroughAHiddenField(): void
    {
        // 5 and 6 have "the who" in the hidden secret field only
        self::assertSame([1, 3], self::sorted($this->search('the who')->restrictedToFields('title', 'body')->get()->ids()));
        self::assertSame([1], $this->search('the who')->restrictedToFields('title')->get()->ids());
        self::assertSame([3], $this->search('the who')->restrictedToFields('body')->get()->ids());
        self::assertSame([5, 6], self::sorted($this->search('the who')->restrictedToFields('secret')->get()->ids()));
        self::assertSame([], $this->search('the who')->restrictedToFields()->get()->ids(), 'nothing visible');
        self::assertSame([1], $this->search('the who', 'always')->restrictedToFields('title')->get()->ids());
        self::assertSame([], $this->search('title:who', 'fallback')->restrictedToFields('body')->get()->ids(), 'a word scoped to a hidden field');
        self::assertSame([1, 2], self::sorted($this->search('title:who')->restrictedToFields('title', 'body')->get()->ids()));
        // a restricted viewer finds the same documents in the same order as an unrestricted one searching only that field
        self::assertSame($this->search('title:the title:who')->get()->ids(), $this->search('the who')->restrictedToFields('title')->get()->ids());
    }

    public function testTheCandidateLimitStillBoundsTheSearch(): void
    {
        for ($i = 100; $i < 130; ++$i) {
            $this->connection->execute('INSERT INTO fz_stop_doc VALUES (:id, :title, :body, :secret, :cat, 1)', ['id' => $i, 'title' => 'The ' . $i, 'body' => '', 'secret' => '', 'cat' => 'bulk']);
        }
        $this->fuzzphony->reindex('stop_docs');

        $result = $this->search('the')->thresholds(['candidate_limit' => 10])->limit(50)->get();

        self::assertSame(10, $result->total, 'at most the candidate limit');
        self::assertTrue($result->totalIsLowerBound);
        self::assertCount(10, $result->hits);
        $exact = $this->search('the')->thresholds(['candidate_limit' => 10])->exactCounts()->get();
        self::assertFalse($exact->totalIsLowerBound);
        self::assertGreaterThanOrEqual(30, $exact->total);
    }

    public function testTheEmptyResultRelaxationStaysTheOrdinaryOne(): void
    {
        // a real word that matches nothing is dropped, as before; the stop word is searched
        $result = $this->search('mouse zzzzzz')->get();
        self::assertSame([7], $result->ids());
        self::assertSame(['No results for all words; ignored words that match nothing: "zzzzzz".'], $result->warnings);
        // every word a stop word and no document has both: the one that matches nothing is relaxed away, as for any word
        $this->connection->execute('DELETE FROM fz_stop_doc');
        $this->connection->execute("INSERT INTO fz_stop_doc VALUES (1, 'Black cat', 'the cat', '', 'x', 1), (2, 'Dog', 'for dogs', '', 'x', 1)");
        $this->fuzzphony->reindex('stop_docs');
        $both = $this->search('the for')->get();
        self::assertSame([], $both->ids(), 'one has "the", the other "for": no document has both');
        self::assertSame([], $both->warnings);
        $off = $this->search('the for')->thresholds(['relax_when_empty' => false])->get();
        self::assertSame([], $off->ids());
    }

    public function testTheStatementsOfAStopWordQueryAreExplained(): void
    {
        $labels = array_map(static fn(array $s): string => $s['label'], $this->search('the who')->explain()->statements);

        self::assertContains('full-text', $labels);
        self::assertContains('fallback: full-text + fuzzy', $labels);
        $always = array_map(static fn(array $s): string => $s['label'], $this->search('the who', 'always')->explain()->statements);
        self::assertContains('full-text + fuzzy', $always);
        self::assertNotContains('fallback: full-text + fuzzy', $always);
    }
}

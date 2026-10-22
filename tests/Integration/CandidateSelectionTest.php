<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A word with more matches than the candidate limit: the full-text branch selects the best candidates by the page's own sort
 * key (text rank, exact and prefix bonuses, boost, recency, then id), not the first ones the index returns.
 *
 * The oracle of every test is the same search with the largest candidate limit (10 000), which a corpus of a few hundred
 * documents never reaches: it ranks every match, as the page orders them.
 */
final class CandidateSelectionTest extends TestCase
{
    private const int LIMIT = 50;

    private Connection $connection;
    private Fuzzphony $fuzzphony;
    private IndexDefinition $index;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_cand, fuzzphony_cand, fuzzphony_cand__vocab, fuzzphony_other, fuzzphony_other__vocab, fz_other CASCADE');
        $this->connection->execute('CREATE TABLE fz_cand (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, cat text NOT NULL, in_stock boolean NOT NULL, pop real NOT NULL)');
        $this->connection->execute('CREATE TABLE fz_other (id bigint PRIMARY KEY, title text NOT NULL)');
        $this->index = IndexDefinition::builder('cand')
            ->fromQuery('SELECT id, title, body, cat, in_stock, pop FROM fz_cand')
            ->field('title', 'A', fuzzy: true)->field('body', 'B')
            ->filter('cat', 'string')->filter('in_stock', 'bool')
            ->boostBy('pop')
            ->profile('popular', new RankingProfile(boost: 0.5))
            ->language('english')->sync('manual')->build();
        $other = IndexDefinition::builder('other')->fromQuery('SELECT id, title FROM fz_other')->field('title', 'A', fuzzy: true)->language('english')->sync('manual')->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->index, $other]));
        $this->fuzzphony->schema()->apply($this->connection);
    }

    /** 400 fillers that hold `common` once, then (inserted last, so the index returns them last) the planted best documents. */
    private function loadCorpus(): void
    {
        $this->connection->execute("INSERT INTO fz_cand SELECT i, 'common filler n' || i, 'about things ' || i, CASE WHEN i % 2 = 0 THEN 'a' ELSE 'b' END, i % 3 <> 0, 0 FROM generate_series(1, 400) AS i");
        $this->connection->execute("INSERT INTO fz_cand VALUES
            (900001, 'common', 'plain', 'a', true, 0),
            (900002, 'common common common', 'plain', 'a', true, 0),
            (900003, 'common stuff', 'plain', 'a', true, 1.0),
            (900004, 'common thing', 'common common common', 'b', false, 0),
            (900005, 'common', 'plain', 'b', false, 0)");
        $this->connection->execute("INSERT INTO fz_cand SELECT 800000 + i, 'rareword item ' || i, 'plain', 'a', true, 0 FROM generate_series(1, 5) AS i");
        $this->fuzzphony->reindex('cand');
    }

    /** @param (callable(SearchBuilder): SearchBuilder)|null $configure */
    private function search(string $text, int $limit = 20, int $offset = 0, int $candidates = self::LIMIT, ?callable $configure = null): SearchBuilder
    {
        $builder = $this->fuzzphony->in('cand')->query($text)->thresholds(['candidate_limit' => $candidates, 'fuzzy_mode' => 'never'])->limit($limit, $offset);

        return $configure === null ? $builder : $configure($builder);
    }

    /**
     * @return list<array{int|string, float}> id and score of every hit of the page
     */
    private static function rows(SearchBuilder $builder): array
    {
        return array_map(static fn($hit): array => [$hit->id, round($hit->score, 9)], $builder->get()->hits);
    }

    /** What the oracle (every match ranked) and the capped search return for the same page. */
    private function assertLikeTheOracle(string $text, int $limit = 20, int $offset = 0, ?callable $configure = null): void
    {
        $oracle = $this->search($text, $limit, $offset, Thresholds::MAX_CANDIDATE_LIMIT, $configure);
        $capped = $this->search($text, $limit, $offset, self::LIMIT, $configure);
        self::assertFalse($oracle->get()->totalIsLowerBound, 'the oracle ranks every match');
        self::assertSame(self::rows($oracle), self::rows($capped), sprintf('"%s" page at offset %d: the capped search returns the best matches', $text, $offset));
    }

    public function testThePlantedBestDocumentsOfACommonWordAreOnTheFirstPage(): void
    {
        $this->loadCorpus();

        $result = $this->search('common')->get();

        self::assertTrue($result->totalIsLowerBound);
        self::assertSame(self::LIMIT, $result->total);
        // the exact title, the word repeated and the exact title again come before any of the fillers the index returns first
        $top = array_slice($result->ids(), 0, 3);
        sort($top);
        self::assertSame([900001, 900002, 900005], $top);
        self::assertContains(900004, $result->ids(), 'the word repeated in the body');
        $this->assertLikeTheOracle('common');
        $this->assertLikeTheOracle('common', 50, 0);
    }

    public function testTheBoostOfAPlantedDocumentCountsInTheSelection(): void
    {
        $this->loadCorpus();
        $boosted = static fn(SearchBuilder $b): SearchBuilder => $b->profile('popular');

        $result = $this->search('common', 20, 0, self::LIMIT, $boosted)->get();

        self::assertContains(900003, $result->ids(), 'popularity is part of the key: the popular document is among the best');
        self::assertSame(900003, $result->hits[0]->id);
        $this->assertLikeTheOracle('common', 20, 0, $boosted);
    }

    public function testARareWordKeepsTheFastStatementAndNeverRunsTheOrderedSelection(): void
    {
        $this->loadCorpus();

        $explained = $this->search('rareword')->explain(true);

        self::assertFalse($explained->result->totalIsLowerBound);
        self::assertSame(5, $explained->result->total);
        $statement = $explained->statements[0]['sql'];
        // the fast selection is the statement of the limit, unchanged but for its name
        self::assertMatchesRegularExpression('/fts_fast AS \(\n    SELECT s\.id, ts_rank_cd\(\'\{0\.1,0\.2,0\.4,1\}\'::real\[\], s\.tsv, q\.tsq, 32\)::double precision AS r_text\n    FROM .* AS s CROSS JOIN q\n    WHERE s\.tsv @@ q\.tsq AND TRUE\n    LIMIT ' . self::LIMIT . '\n\)/', $statement);
        self::assertStringContainsString('(SELECT count(*) FROM fts_fast) < ' . self::LIMIT, $statement);
        $plan = implode("\n", $explained->plan);
        self::assertStringContainsString('never executed', $plan, 'the ordered selection is not run');
        self::assertMatchesRegularExpression('/One-Time Filter: .*>= ' . self::LIMIT . '/', $plan);
        $this->assertLikeTheOracle('rareword', 5);
    }

    public function testAFindingWithFewerMatchesThanTheLimitIsTheFastSelectionExactly(): void
    {
        $this->loadCorpus();

        // fewer matches than the limit: the ordered select never runs, so the page is the fast one
        foreach (['stuff', 'rareword item', 'common stuff', '"common stuff"', 'common -filler', 'common +stuff'] as $text) {
            $explained = $this->search($text)->explain(true);
            self::assertFalse($explained->result->totalIsLowerBound, $text);
            self::assertStringContainsString('never executed', implode("\n", $explained->plan), $text);
            $this->assertLikeTheOracle($text);
        }
    }

    public function testMinScoreKeepsTheDocumentsUnderItOutOfTheSlots(): void
    {
        $this->loadCorpus();
        $scores = $this->fuzzphony->in('cand')->query('common')->thresholds(['candidate_limit' => 10000, 'fuzzy_mode' => 'never'])->limit(500)->get();
        $byId = [];
        foreach ($scores->hits as $hit) {
            $byId[$hit->id] = $hit->breakdown->relevance;
        }
        // between the relevance of a filler (one `common`) and of the documents with the word repeated
        $min = ($byId[400] + $byId[900002]) / 2;
        self::assertGreaterThan($byId[400], $min);
        $with = static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['min_score' => $min, 'candidate_limit' => self::LIMIT, 'fuzzy_mode' => 'never']);
        $oracle = static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['min_score' => $min, 'candidate_limit' => 10000, 'fuzzy_mode' => 'never']);

        $found = $with($this->fuzzphony->in('cand')->query('common')->limit(20))->get();
        $expected = $oracle($this->fuzzphony->in('cand')->query('common')->limit(20))->get();

        self::assertSame($expected->ids(), $found->ids());
        self::assertContains(900002, $found->ids(), 'the fillers under min_score did not take the slots');
        self::assertSame($expected->total, $found->total);
        self::assertTrue($found->totalIsLowerBound, 'the fast selection hit the limit: still flagged, although the documents above min_score are all there');
    }

    public function testFiltersApplyToTheOrderedSelection(): void
    {
        $this->loadCorpus();

        $inStock = static fn(SearchBuilder $b): SearchBuilder => $b->where('in_stock', true);
        $result = $this->search('common', 20, 0, self::LIMIT, $inStock)->get();
        self::assertNotContains(900004, $result->ids());
        self::assertNotContains(900005, $result->ids());
        self::assertContains(900001, array_slice($result->ids(), 0, 3), 'the best documents that pass the filter');
        $this->assertLikeTheOracle('common', 20, 0, $inStock);

        $category = static fn(SearchBuilder $b): SearchBuilder => $b->where('cat', 'b')->where('in_stock', false);
        $this->assertLikeTheOracle('common', 20, 0, $category);
        self::assertContains(900005, $this->search('common', 20, 0, self::LIMIT, $category)->get()->ids());
    }

    public function testPagesPastTheFirstCandidatesAreExactAndHasMoreEndsWithTheLastCandidatePage(): void
    {
        $this->loadCorpus();

        // every page of 20 is the oracle's page, the ones past the candidate limit (50) too
        foreach ([0, 20, 40, 60, 100, 200] as $offset) {
            $this->assertLikeTheOracle('common', 20, $offset);
        }
        $first = $this->search('common')->get();
        self::assertTrue($first->hasMore());
        self::assertSame(50, $first->total);
        $second = $this->search('common', 20, 20)->get();
        self::assertTrue($second->hasMore());
        // offset 40 + 20 = 60 > 50: the candidates grow to 60, the page is whole and it is the last of them
        $third = $this->search('common', 20, 40)->get();
        self::assertSame(60, $third->total);
        self::assertTrue($third->totalIsLowerBound);
        self::assertCount(20, $third->hits);
        self::assertFalse($third->hasMore(), 'false at the last page of the candidates, although the total is a lower bound');
        // fewer matches than the candidates: the end of the list
        $last = $this->search('common', 20, 400)->get();
        self::assertFalse($last->totalIsLowerBound);
        self::assertFalse($last->hasMore());
    }

    public function testAFederatedSearchSelectsTheBestOfEachIndexAndEndsWithTheLastCandidatePage(): void
    {
        $this->loadCorpus();
        $this->connection->execute("INSERT INTO fz_other SELECT i, 'common other n' || i FROM generate_series(1, 120) AS i");
        $this->connection->execute("INSERT INTO fz_other VALUES (9000, 'common')");
        $this->fuzzphony->reindex('other');
        $federated = fn(int $limit, int $offset) => $this->fuzzphony->federated()
            ->index('cand', configure: static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['candidate_limit' => self::LIMIT, 'fuzzy_mode' => 'never']))
            ->index('other', configure: static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['candidate_limit' => self::LIMIT, 'fuzzy_mode' => 'never']))
            ->query('common')->limit($limit, $offset)->get();

        $result = $federated(20, 0);

        $ids = array_map(static fn($h): string => $h->index . ':' . $h->hit->id, $result->hits);
        self::assertContains('other:9000', $ids, 'the best of the second index is found although 120 of its documents match first');
        self::assertContains('cand:900002', $ids);
        self::assertTrue($result->totalIsLowerBound);
        self::assertTrue($result->hasMore());

        // one capped index alone: the last page of its candidates ends the list
        $single = fn(int $limit, int $offset) => $this->fuzzphony->federated()
            ->index('cand', configure: static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['candidate_limit' => self::LIMIT, 'fuzzy_mode' => 'never']))
            ->query('common')->limit($limit, $offset)->get();
        self::assertTrue($single(20, 20)->hasMore());
        $end = $single(20, 40);
        self::assertSame(60, $end->total);
        self::assertTrue($end->totalIsLowerBound);
        self::assertFalse($end->hasMore(), 'false at the last candidate page of a federated search');
    }

    public function testTiesAreBrokenByIdLikeThePage(): void
    {
        // equal documents, inserted with the highest id first: the first rows the index returns have the highest ids
        $this->connection->execute("INSERT INTO fz_cand SELECT i, 'tie word', 'plain', 'a', true, 0 FROM generate_series(300, 1, -1) AS i");
        $this->fuzzphony->reindex('cand');

        $capped = $this->search('tie')->get();

        self::assertSame(range(1, 20), $capped->ids(), 'the lowest ids, as the page orders equal scores');
        $this->assertLikeTheOracle('tie');
        $this->assertLikeTheOracle('tie', 20, 40);
    }

    public function testTheWindowNeverExceedsTheHardCap(): void
    {
        $this->connection->execute("INSERT INTO fz_cand SELECT i, 'bulk word', 'plain', 'a', true, 0 FROM generate_series(1, 10500) AS i");
        $this->connection->execute("INSERT INTO fz_cand VALUES (900001, 'bulk', 'plain', 'a', true, 0)");
        $this->fuzzphony->reindex('cand');

        $first = $this->search('bulk', 10, 0, 10)->explain();
        self::assertSame(900001, $first->result->hits[0]->id, 'the best of 10 500 matches comes first although it is the last the index returns');
        $deep = $this->search('bulk', 20, 9990, 10)->get();
        self::assertSame(10_000, $deep->total, 'offset + limit = 10 010 is cut to the hard cap');
        self::assertTrue($deep->totalIsLowerBound);
        self::assertCount(10, $deep->hits);
        self::assertFalse($deep->hasMore());
        $past = $this->search('bulk', 20, 10_000, 10)->get();
        self::assertSame([], $past->ids(), 'past the hard cap a page is empty');
        self::assertFalse($past->hasMore());
        self::assertStringContainsString('LIMIT 10000', $this->search('bulk', 20, 9990, 10)->explain()->statements[0]['sql']);
    }

    public function testOnlyTheFirstMatchesOfTheScanBudgetAreRanked(): void
    {
        $this->loadCorpus();
        $profile = new RankingProfile();
        $run = function (int $budget) use ($profile): array {
            $statement = (new SearchSqlBuilder($this->index, new Names(), [], null, false, max(1, $budget)))
                ->ranked("'common'", 'common', null, [], $profile, new Thresholds(candidateLimit: 10, fuzzyMode: FuzzyMode::Never), 5, 0);

            return $this->connection->fetchAll($statement['sql'], $statement['params']);
        };

        $all = array_map(static fn(array $r): int => Coerce::int($r['id']), $run(100_000));
        self::assertContains(900001, $all, 'the planted documents are ranked when the budget covers every match');
        self::assertContains(900002, $all);

        $small = $run(30);
        $ids = array_map(static fn(array $r): int => Coerce::int($r['id']), $small);
        self::assertCount(5, $ids);
        foreach ($ids as $id) {
            self::assertLessThanOrEqual(400, $id, 'the planted documents come after the first 30 matches: they are not ranked');
        }
        self::assertSame(10, Coerce::int($small[0]['total']), 'the candidates are the best of the budget, N of them');
    }
}

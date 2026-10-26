<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The candidates of the typo-tolerant branch for a word more common than the candidate limit. An unrestricted viewer (and a tenant)
 * gets the first matches the trigram index returns, which are the first in the PHYSICAL order of the sidecar table: cheap, and a
 * lower bound (a closer spelling further down the table may not be among them), but a full-text match is never ranked below them for
 * that reason (it keeps its typo score). A restricted viewer gets the best candidates by the page's sort key, whatever a hidden field
 * does to the order of the table (the hidden-field tests of the other viewers are in CandidateSelectionRestrictedTest). Planted
 * documents prove the quality; tenants prove that nothing but their own documents decides.
 */
final class TypoBranchCandidatesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_tb_doc, fuzzphony_tb_docs, fuzzphony_tb_docs__vocab, fuzzphony_tb_docs__next, fuzzphony_tb_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_tb_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL, tenant_id int NOT NULL, boost double precision NOT NULL DEFAULT 0)');
    }

    private function index(bool $tenant = false): Fuzzphony
    {
        $builder = IndexDefinition::builder('tb_docs')->fromQuery('SELECT id, title, body, secret, cat, tenant_id, boost FROM fz_tb_doc')
            ->field('title', 'A', fuzzy: true)->field('body', 'B', fuzzy: true)->field('secret', 'C', fuzzy: true)
            ->filter('cat', 'string')->filter('tenant_id', 'int')->boostBy('boost')->language('english')->sync('manual');
        if ($tenant) {
            $builder = $builder->tenant('tenant_id');
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->build()]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('tb_docs');

        return $fuzzphony;
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'always' => ['always'];
        yield 'fallback' => ['fallback'];
    }

    /**
     * 3 000 titles with `gadgetron` (similarity 0.7 to the typed `gadgetrun`) and, inserted last, the closest spelling
     * `gadgetrunn` (0.9): an unrestricted viewer's candidates are the first matches in the table, so the closest spelling is not
     * promised to be among them (a lower bound), but the answer is a full page of matches of the word, the same on every run.
     */
    #[DataProvider('modes')]
    public function testAnUnrestrictedViewersCandidatesAreTheFirstMatchesOfAnyCommonTypoWord(string $mode): void
    {
        $this->connection->execute("INSERT INTO fz_tb_doc SELECT i, 'gadgetron widget n' || i, 'plain text ' || i, 'x', CASE WHEN i % 2 = 0 THEN 'a' ELSE 'b' END, 1 FROM generate_series(1, 3000) AS i");
        $this->connection->execute("INSERT INTO fz_tb_doc VALUES (90000, 'gadgetrunn widget', 'plain', 'x', 'a', 1), (90001, 'gadgetrunn', 'plain', 'x', 'b', 1)");
        $fuzzphony = $this->index();

        $first = null;
        foreach ([100, 500] as $limit) {
            $result = $fuzzphony->in('tb_docs')->query('gadgetrun')->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => $limit])->limit(20)->facets('cat')->get();
            self::assertTrue($result->totalIsLowerBound, 'the typo word matches more documents than the candidate limit');
            self::assertCount(20, $result->hits);
            self::assertSame(20, count(array_unique($result->ids())));
            $again = $fuzzphony->in('tb_docs')->query('gadgetrun')->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => $limit])->limit(20)->facets('cat')->get();
            self::assertSame($result->ids(), $again->ids(), 'the same candidates on every run');
            $first ??= $result->ids();
        }
    }

    /**
     * Three documents of `mousse cake` at the very end of 3 000 titles with `mouse`: the strict branch finds the three (fewer than
     * `fallback_below`), so the typo branch runs too and its first candidates are the `mouse` titles of the table's start, which never
     * reach the late exact matches. The exact matches keep their typo score and rank first all the same, not below every look-alike.
     */
    #[DataProvider('modes')]
    public function testAFullTextMatchOutsideTheFirstTypoCandidatesKeepsItsTypoScore(string $mode): void
    {
        $this->connection->execute("INSERT INTO fz_tb_doc SELECT i, 'mouse n' || i, 'plain text ' || i, 'x', 'a', 1 FROM generate_series(1, 3000) AS i");
        $this->connection->execute("INSERT INTO fz_tb_doc VALUES (90000, 'mousse cake', 'plain', 'x', 'a', 1), (90001, 'mousse cake', 'plain', 'x', 'a', 1), (90002, 'mousse cake', 'plain', 'x', 'a', 1)");
        $fuzzphony = $this->index();

        $result = $fuzzphony->in('tb_docs')->query('mousse')->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 100])->limit(10)->get();

        self::assertTrue($result->usedFuzzy, 'the typo branch ran');
        self::assertSame([90000, 90001, 90002], array_slice($result->ids(), 0, 3), 'the exact matches come first');
        self::assertGreaterThan($result->hits[3]->score, $result->hits[2]->score);
        self::assertGreaterThan(0.0, $result->hits[0]->breakdown->fuzzySimilarity, 'with the typo score the branch gives them');
    }

    /** The same for a restricted viewer, who sees the title only: the hidden fields must not matter either. */
    #[DataProvider('modes')]
    public function testTheClosestSpellingIsACandidateForARestrictedViewer(string $mode): void
    {
        $this->connection->execute("INSERT INTO fz_tb_doc SELECT i, 'gadgetron widget n' || i, 'gadgetrun ' || i, 'gadgetrun', 'a', 1 FROM generate_series(1, 3000) AS i");
        $this->connection->execute("INSERT INTO fz_tb_doc VALUES (90000, 'gadgetrunn widget', 'plain', 'x', 'a', 1)");
        $fuzzphony = $this->index();

        $result = $fuzzphony->in('tb_docs')->query('gadgetrun')->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 100])->restrictedToFields('title')->limit(20)->get();
        self::assertTrue($result->totalIsLowerBound);
        self::assertSame(90000, $result->hits[0]->id, 'the closest spelling in a field the viewer sees comes first');
    }

    /**
     * The boost, the exact-title and prefix bonuses and the recency are part of the page's sort key: among documents as close
     * as each other, a restricted viewer's typo branch keeps the best by that key (here the boost), not the first in the table.
     */
    public function testAmongEquallyCloseSpellingsTheBestByTheSortKeyIsACandidateForARestrictedViewer(): void
    {
        $this->connection->execute("INSERT INTO fz_tb_doc SELECT i, 'gadgetron n' || i, 'plain', 'x', 'a', 1, 0 FROM generate_series(1, 3000) AS i");
        $this->connection->execute("INSERT INTO fz_tb_doc VALUES (90000, 'gadgetron', 'plain', 'x', 'a', 1, 5)");
        $fuzzphony = $this->index();

        $result = $fuzzphony->in('tb_docs')->query('gadgetrun')->thresholds(['fuzzy_mode' => 'always', 'candidate_limit' => 100])->ranking(['boost' => 0.5])->restrictedToFields('title')->limit(5)->get();
        self::assertSame(90000, $result->hits[0]->id);
    }

    /**
     * @return iterable<string, array{string, string}> the query and the mode
     */
    public static function tenantCases(): iterable
    {
        foreach (['gadgetrun', 'gadgetrun widget'] as $text) {
            foreach (['always', 'fallback'] as $mode) {
                yield $text . ' | ' . $mode => [$text, $mode];
            }
        }
    }

    /**
     * A tenant's typo candidates and facets never depend on the other tenant's documents, not even on their size (which moves
     * the rows around the heap pages): tenant 1 has 300 matching documents among 2 700 rows of tenant 2, whose secret takes a
     * different size per variant. (Tenant 2 itself is not compared: the size of its own rows decides which of its matches come first,
     * as it does for anyone whose documents are all visible.)
     */
    #[DataProvider('tenantCases')]
    public function testATenantsTypoSelectionDoesNotDependOnAnotherTenant(string $text, string $mode): void
    {
        $outcomes = [];
        $variants = [
            'empty' => "''",
            'a phrase' => "'gadgetron widget words'",
            'sizes' => 'repeat(\'filler words \', i % 37)',
            'the word' => "CASE WHEN i % 3 = 0 THEN repeat('gadgetron ', 50) || i ELSE 'zzz' END",
        ];
        foreach ($variants as $variant => $secret) {
            $this->connection->execute('DROP TABLE IF EXISTS fz_tb_doc, fuzzphony_tb_docs, fuzzphony_tb_docs__vocab, fuzzphony_tb_docs__next, fuzzphony_tb_docs__changes CASCADE');
            $this->connection->execute('CREATE TABLE fz_tb_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL, tenant_id int NOT NULL, boost double precision NOT NULL DEFAULT 0)');
            // the secret of tenant 1 (every tenth row) is the same in every variant
            $this->connection->execute(sprintf("INSERT INTO fz_tb_doc SELECT i, 'gadgetron widget n' || i, 'plain', CASE WHEN i %% 10 = 0 THEN 'x' ELSE %s END, CASE WHEN i %% 2 = 0 THEN 'a' ELSE 'b' END, CASE WHEN i %% 10 = 0 THEN 1 ELSE 2 END FROM generate_series(1, 3000) AS i", $secret));
            $this->connection->execute("INSERT INTO fz_tb_doc VALUES (90000, 'gadgetrunn widget', 'plain', 'x', 'a', 1)");
            $fuzzphony = $this->index(true);
            foreach ([1] as $tenant) {
                $explained = $fuzzphony->in('tb_docs')->forTenant($tenant)->query($text)->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 100])->facets('cat')->limit(20)->explain();
                $result = $explained->result;
                self::assertTrue($result->totalIsLowerBound, 'the tenant matches more documents than the candidate limit');
                $outcomes[$tenant][$variant] = [
                    array_map(static fn($hit): array => [$hit->id, round($hit->score, 9)], $result->hits),
                    $result->total,
                    $result->hasMore(),
                    array_map(static fn($facet): array => array_map(static fn($v): array => [$v->value, $v->count], $facet), $result->facets),
                ];
            }
        }
        foreach ($outcomes as $tenant => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'], $outcome, sprintf('tenant %d: the other tenant\'s documents changed the outcome (%s)', $tenant, $variant));
            }
        }
    }
}

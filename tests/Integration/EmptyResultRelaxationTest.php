<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bridge\Doctrine\DbalConnection;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use PHPUnit\Framework\TestCase;

/** PostgreSQL side of the empty-result relaxation: the statements it runs, and tenant isolation. */
final class EmptyResultRelaxationTest extends TestCase
{
    private Connection $connection;

    private function fuzzphony(bool $tenant = false, int $filler = 0, ?Connection $connection = null): Fuzzphony
    {
        $connection = $this->connection = $connection ?? PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        if ($filler > 0) {
            // enough rows for the planner to prefer the indexes over a sequential scan
            $connection->execute("INSERT INTO fz_product SELECT 100 + n, 'Filler item ' || n, 'Lorem ipsum dolor ' || (n % 97), 1, 1000 + n, true, 0, now() FROM generate_series(1, :n) AS n", ['n' => $filler]);
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');
        if ($filler > 0) {
            $connection->execute('ANALYZE "fuzzphony_products"');
        }

        return $fuzzphony;
    }

    public function testExplainShowsTheProbeAndTheRelaxedSearch(): void
    {
        $explanation = $this->fuzzphony()->in('products')->query('wireless mouse offfice')->explain(analyze: true);

        self::assertSame(
            ['full-text', 'fallback: full-text + fuzzy', 'relaxation probe', 'relaxed: full-text', 'relaxed: fallback: full-text + fuzzy'],
            array_column($explanation->statements, 'label'),
        );
        self::assertSame('(wireless AND mouse)', $explanation->interpretedAs);
        self::assertSame([1], $explanation->result->ids());
        self::assertNotEmpty(array_filter($explanation->plan, static fn(string $l): bool => str_contains($l, 'actual time')));

        $probe = $explanation->statements[2];
        self::assertStringContainsString('m0 AS MATERIALIZED (SELECT 1 FROM "fuzzphony_products" AS s CROSS JOIN q WHERE (s.tsv @@ q.ft0 OR q.fn1 <% s.fz)', $probe['sql']);
        self::assertStringNotContainsString('offfice', $probe['sql'], 'user text is bound, never inlined');
        self::assertContains('offfice', $probe['params']);
    }

    public function testTheProbeRunsOnlyWhenItCanChangeSomething(): void
    {
        $search = $this->fuzzphony()->in('products');
        $labels = static fn(string $q, bool $relax = true, string $fuzzyMode = 'fallback'): array => array_column(
            $search->query($q)->thresholds(['relax_when_empty' => $relax, 'fuzzy_mode' => $fuzzyMode])->explain()->statements,
            'label',
        );

        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], $labels('offfice'), 'one word: nothing to keep');
        self::assertSame(['full-text'], $labels('the ab'), 'a stop word is not a word to keep or to report');
        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], $labels('wireless mouse offfice', relax: false));
        self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'relaxation probe'], $labels('mouse torch'), 'every word matches something: no second search');
        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], $labels('wireless mouse'), 'hits: no probe');
        self::assertSame(['full-text', 'relaxation probe', 'relaxed: full-text'], $labels('wireless mouse offfice', fuzzyMode: 'never'));
    }

    public function testTheProbeReadsTheTextIndexesInsteadOfScanningForAFirstMatch(): void
    {
        $search = $this->fuzzphony(filler: 150_000)->in('products');
        $probe = $search->query('mouse torch')->explain()->statements[2];
        self::assertSame('relaxation probe', $probe['label']);

        $plan = implode("
", array_map(
            static fn(array $row): string => Coerce::str(reset($row)),
            $this->connection->fetchAll('EXPLAIN ' . $probe['sql'], $probe['params']),
        ));

        self::assertStringContainsString('Bitmap Index Scan on fuzzphony_products_tsv', $plan);
        self::assertStringContainsString('Bitmap Index Scan on fuzzphony_products_fz', $plan);
        self::assertStringNotContainsString('Seq Scan on fuzzphony_products', $plan);
        self::assertStringNotContainsString('Index Scan using', $plan);
    }

    public function testExplainShowsThePlanOfTheSearchNotOfTheProbe(): void
    {
        $explanation = $this->fuzzphony()->in('products')->query('mouse torch')->explain();

        self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'relaxation probe'], array_column($explanation->statements, 'label'));
        $plan = implode("
", $explanation->plan);
        self::assertStringContainsString('CTE scored', $plan);
        self::assertStringNotContainsString('CTE m0', $plan);
    }

    public function testTheProbeLeavesNoPlannerSettingsInTheCallersTransaction(): void
    {
        $search = $this->fuzzphony()->in('products');

        $settings = $this->connection->transactional(function (Connection $c) use ($search): array {
            $result = $search->query('wireless mouse offfice')->get();
            self::assertContains('No results for all words; ignored words that match nothing: "offfice".', $result->warnings);
            $probed = $search->query('mouse torch')->get();
            self::assertSame([], $probed->ids());

            return [
                'enable_seqscan' => $c->fetchValue('SHOW enable_seqscan'),
                'enable_indexscan' => $c->fetchValue('SHOW enable_indexscan'),
                'enable_bitmapscan' => $c->fetchValue('SHOW enable_bitmapscan'),
            ];
        });

        self::assertSame(['enable_seqscan' => 'on', 'enable_indexscan' => 'on', 'enable_bitmapscan' => 'on'], $settings);
    }

    public function testSearchingInsideADbalTransactionLeavesNoSettingsBehind(): void
    {
        $dbal = DoctrineTestCase::dbalConnection();
        $connection = new DbalConnection($dbal);
        $search = $this->fuzzphony(connection: $connection)->in('products');

        $dbal->beginTransaction();
        try {
            $relaxed = $search->query('wireless mouse offfice')->get();
            $fuzzy = $search->query('wireles mice')->get();
            $settings = [
                'enable_seqscan' => $dbal->fetchOne('SHOW enable_seqscan'),
                'enable_indexscan' => $dbal->fetchOne('SHOW enable_indexscan'),
                'threshold' => $dbal->fetchOne("SELECT current_setting('pg_trgm.word_similarity_threshold')"),
            ];
        } finally {
            $dbal->rollBack();
        }

        self::assertSame([1], $relaxed->ids());
        self::assertTrue($fuzzy->usedFuzzy);
        self::assertSame(['enable_seqscan' => 'on', 'enable_indexscan' => 'on', 'threshold' => '0.6'], $settings);
    }

    public function testTheSimilarityThresholdIsRestoredInTheCallersTransaction(): void
    {
        $search = $this->fuzzphony()->in('products');
        $this->connection->fetchValue("SELECT set_config('pg_trgm.word_similarity_threshold', '0.55', false)");
        $shown = static fn(Connection $c): mixed => $c->fetchValue("SELECT current_setting('pg_trgm.word_similarity_threshold')");

        $inside = $this->connection->transactional(function (Connection $c) use ($search, $shown): array {
            $result = $search->query('wireles mice')->get();
            self::assertContains(1, $result->ids());
            self::assertTrue($result->usedFuzzy);
            $afterSearch = $shown($c);
            $explained = $search->query('wireles mice')->explain();

            return ['after search' => $afterSearch, 'after explain' => $shown($c), 'explained' => $explained->result->usedFuzzy];
        });

        self::assertSame(['after search' => '0.55', 'after explain' => '0.55', 'explained' => true], $inside);
        self::assertSame('0.55', $shown($this->connection));
    }

    public function testTheProbeReusesTheStopWordLookupOfTheSearch(): void
    {
        $this->fuzzphony();
        $log = new \ArrayObject();
        $recording = new class ($this->connection, $log) implements Connection {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly Connection $inner, private readonly \ArrayObject $log) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->log[] = $sql;

                return $this->inner->fetchAll($sql, $params);
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->log[] = $sql;

                return $this->inner->fetchValue($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->log[] = $sql;

                return $this->inner->execute($sql, $params);
            }

            public function transactional(callable $callback): mixed
            {
                return $this->inner->transactional(fn(): mixed => $callback($this));
            }
        };
        $search = (new Fuzzphony(new PostgresEngine($recording), new IndexRegistry([Indexes::products('manual')])))->in('products');

        $result = $search->query('wireless mouse offfice')->get();

        self::assertSame([1], $result->ids());
        $lookups = array_filter($log->getArrayCopy(), static fn(string $sql): bool => str_contains($sql, 'numnode('));
        // one for the fuzzy fallback of the search, one for the fallback of the relaxed search; the probe reuses the first
        self::assertCount(2, $lookups);
    }

    public function testARelaxedSearchThatFindsNothingKeepsTheOriginalAnswer(): void
    {
        $search = $this->fuzzphony()->in('products');

        // wireless mouse exists, torch exists, never together; zzqqx exists nowhere
        $result = $search->query('wireless mouse torch zzqqx')->get();
        $explanation = $search->query('wireless mouse torch zzqqx')->explain();

        self::assertSame([], $result->ids());
        self::assertSame(0, $result->total);
        self::assertSame('(wireless AND mouse AND torch AND zzqqx)', $result->interpretedAs, 'the query as typed, not the relaxed one');
        self::assertSame([], $result->warnings, 'nothing was found, so nothing is reported as ignored');
        self::assertSame(
            ['full-text', 'fallback: full-text + fuzzy', 'relaxation probe', 'relaxed: full-text', 'relaxed: fallback: full-text + fuzzy'],
            array_column($explanation->statements, 'label'),
        );
        self::assertStringContainsString('CTE scored', implode("
", $explanation->plan));
    }

    public function testRelaxationNeverTurnsAnOrBranchIntoAnExclusionOnly(): void
    {
        $search = $this->fuzzphony()->in('products');

        // (zzqqx AND NOT mouse) OR (wireless AND yyqqx): dropping the unknown words would leave
        // "everything except mouse" as a branch
        $result = $search->query('zzqqx -mouse | wireless yyqqx')->get();
        $explanation = $search->query('zzqqx -mouse | wireless yyqqx')->explain();

        self::assertSame([], $result->ids());
        self::assertSame(0, $result->total);
        self::assertSame('((zzqqx AND NOT mouse) OR (wireless AND yyqqx))', $result->interpretedAs);
        self::assertSame([], $result->warnings);
        self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'relaxation probe'], array_column($explanation->statements, 'label'));
    }

    public function testAStopWordIsNeitherKeptNorReportedAsUnmatched(): void
    {
        $result = $this->fuzzphony()->in('products')->query('wireless mouse for offfice')->get();

        self::assertSame([1], $result->ids());
        self::assertContains('No results for all words; ignored words that match nothing: "offfice".', $result->warnings);
    }

    public function testAWordThatOnlyExistsForAnotherTenantIsReportedLikeAWordThatExistsNowhere(): void
    {
        $search = $this->fuzzphony(tenant: true)->in('products');

        // Sony (brand 3) has products 3 and 5; "gaming" only exists in Razer's product 2
        $other = $search->forTenant(3)->query('wireless gaming')->get();
        $nowhere = $search->forTenant(3)->query('wireless zzqqx')->get();

        self::assertSame([3], $other->ids());
        self::assertSame('wireless', $other->interpretedAs);
        self::assertContains('No results for all words; ignored words that match nothing: "gaming".', $other->warnings);
        self::assertSame(self::observable($nowhere), self::observable($other), 'nothing tells the two apart');

        // and the other way round: Razer has no wireless product
        $razer = $search->forTenant(2)->query('wireless gaming')->get();
        self::assertSame([2], $razer->ids());
        self::assertContains('No results for all words; ignored words that match nothing: "wireless".', $razer->warnings);
    }

    /** @return array<string, mixed> */
    private static function observable(SearchResult $result): array
    {
        return [
            'ids' => $result->ids(),
            'total' => $result->total,
            'usedFuzzy' => $result->usedFuzzy,
            'interpretedAs' => $result->interpretedAs,
            'warnings' => str_replace(['gaming', 'zzqqx'], 'WORD', $result->warnings),
        ];
    }
}

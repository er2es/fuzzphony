<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** PostgreSQL-specific behaviour: database-side sync, schema evolution, the doctor, highlighting. */
final class PostgresEngineTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
    }

    private function fuzzphony(string $sync): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([Indexes::products($sync)]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testQueueModeFollowsChangesIncludingJoinedTables(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $index = $fuzzphony->registry()->get('products');

        $this->connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1"); // fans out to products 1 and 4
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
        $this->connection->execute('DELETE FROM fz_product WHERE id = 2');

        self::assertSame(4, $this->engine->queueSize($index)); // 1, 4, 6, 2 (deduplicated)
        self::assertSame(4, (new Worker($this->engine))->runOnce([$index]));
        self::assertSame(0, $this->engine->queueSize($index));

        self::assertEqualsCanonicalizing([1, 4, 6], $fuzzphony->in('products')->query('brand:"logitech g"')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
        self::assertNotContains(2, $fuzzphony->in('products')->query('gaming')->get()->ids());
    }

    public function testStatementLevelTriggersHandleBulkChangesSetBased(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $index = $fuzzphony->registry()->get('products');
        $this->connection->execute(
            "INSERT INTO fz_product SELECT i, 'Bulk item ' || i, 'generated', 1 + i % 3, 100, true, 0, now() FROM generate_series(100, 1099) AS i",
        );
        self::assertSame(1000, $this->engine->queueSize($index), 'one statement, 1000 queued ids');

        $this->connection->execute("UPDATE fz_product SET description = 'changed' WHERE id >= 100"); // same ids: deduplicated
        self::assertSame(1000, $this->engine->queueSize($index));

        (new Worker($this->engine))->runOnce([$index], 250);
        self::assertSame(1000, $fuzzphony->in('products')->query('bulk')->thresholds(['fuzzy_mode' => 'never'])->get()->total);

        $this->connection->execute('DELETE FROM fz_product WHERE id >= 100');
        (new Worker($this->engine))->runOnce([$index]);
        self::assertSame(0, $fuzzphony->in('products')->query('bulk')->thresholds(['fuzzy_mode' => 'never'])->get()->total);
    }

    public function testSwitchingTriggerLevelLeavesNoDuplicates(): void
    {
        $this->fuzzphony('queue');
        $row = Indexes::products('queue')->with(triggerLevel: TriggerLevel::Row);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$row]));
        $fuzzphony->schema()->apply($this->connection);

        $names = array_column($this->connection->fetchAll("SELECT tgname FROM pg_trigger WHERE tgrelid = 'fz_brand'::regclass AND NOT tgisinternal ORDER BY tgname"), 'tgname');
        // the row-level trigger, plus the TRUNCATE trigger (statement-level at both levels, so it is kept)
        self::assertSame(['fuzzphony_sync_products__fz_brand', 'fuzzphony_sync_products__fz_brand_trn'], $names);
        self::assertTrue($fuzzphony->inspect('products')->isHealthy());
    }

    public function testTriggerModeIsSynchronous(): void
    {
        $fuzzphony = $this->fuzzphony('trigger');

        $this->connection->execute("UPDATE fz_product SET name = 'Ergonomic vertical mouse' WHERE id = 2");

        self::assertSame([2], $fuzzphony->in('products')->query('ergonomic')->get()->ids());
    }

    public function testHighlightsAreHtmlSafe(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute("UPDATE fz_product SET name = 'Mouse <b onmouseover=x> & more' WHERE id = 1");
        $fuzzphony->refresh('products', [1]);

        $hit = $fuzzphony->in('products')->query('mouse')->highlight('name')->where('price', '<', 4_000)->get()->hits[0];

        self::assertStringContainsString('<mark>Mouse</mark>', $hit->highlights['name']);
        self::assertStringNotContainsString('<b', $hit->highlights['name']);
    }

    public function testDoctorIsHappyAfterInstall(): void
    {
        $report = $this->fuzzphony('queue')->inspect('products', new InspectOptions(deep: true));

        self::assertSame(CheckStatus::Ok, $report->status(), implode("\n", array_map(static fn(Check $c): string => $c->name . ': ' . $c->message, $report->problems())));
    }

    public function testDoctorFindsDriftMissingIndexesAndDisabledTriggers(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $this->connection->execute('DROP INDEX fuzzphony_products_fz');
        $this->connection->execute('ALTER TABLE fuzzphony_products DROP COLUMN f_price');
        $this->connection->execute('ALTER TABLE fz_brand DISABLE TRIGGER fuzzphony_sync_products__fz_brand_upd');

        $problems = [];
        foreach ($fuzzphony->inspect('products')->problems() as $check) {
            $problems[$check->name] = $check;
        }

        self::assertStringContainsString('f_price', $problems['Sidecar columns']->message);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY', (string) $problems['Index fuzzphony_products_fz']->fix);
        self::assertStringContainsString('ENABLE TRIGGER', (string) $problems['Sync trigger on fz_brand']->fix);

        $fuzzphony->schema()->apply($this->connection); // idempotent repair (except the trigger state, which is deliberate)
        $this->connection->execute('ALTER TABLE fz_brand ENABLE TRIGGER fuzzphony_sync_products__fz_brand_upd');
        $fuzzphony->reindex('products');
        self::assertTrue($fuzzphony->inspect('products')->isHealthy());
    }

    public function testDoctorReportsAMissingTruncateTrigger(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        // what an index whose schema was applied before the TRUNCATE trigger existed looks like
        $this->connection->execute('DROP TRIGGER fuzzphony_sync_products__fz_brand_trn ON fz_brand');

        $problems = array_values(array_filter($fuzzphony->inspect('products')->problems(), static fn(Check $c): bool => $c->name === 'Sync trigger on fz_brand'));

        self::assertCount(1, $problems);
        self::assertSame(CheckStatus::Error, $problems[0]->status);
        self::assertSame('missing fuzzphony_sync_products__fz_brand_trn: a TRUNCATE of this table leaves stale documents in the index', $problems[0]->message);
        self::assertStringContainsString('fuzzphony:schema --apply', (string) $problems[0]->fix);

        $fuzzphony->schema()->apply($this->connection);
        self::assertSame(CheckStatus::Ok, $fuzzphony->inspect('products')->status());
    }

    public function testDoctorRecognizesAMissingTruncateTriggerWhenTheNameIsHashed(): void
    {
        // a long index name makes Identifier::limit() hash the trigger names, so they no longer end in "_trn"
        $index = IndexDefinition::builder(str_repeat('long_index_name_', 3))
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id')
            ->field('name', 'A')
            ->sync('queue')
            ->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);
        $generator = new PostgresSchemaGenerator();
        $names = array_keys($generator->triggerDefinitions($index, $index->effectiveWatches()[1]));
        $truncate = end($names);
        self::assertIsString($truncate);
        self::assertFalse(str_ends_with($truncate, '_trn'), 'the fixture must exercise a hashed name');
        $this->connection->execute(sprintf('DROP TRIGGER %s ON fz_brand', Sql::ident($truncate)));

        $problems = array_values(array_filter($fuzzphony->inspect($index->name)->problems(), static fn(Check $c): bool => $c->name === 'Sync trigger on fz_brand'));

        self::assertCount(1, $problems);
        self::assertSame(sprintf('missing %s: a TRUNCATE of this table leaves stale documents in the index', $truncate), $problems[0]->message);
    }

    public function testDoctorCountsOrphanedDocumentsOnlyWhenDeep(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('DELETE FROM fz_product WHERE id IN (2, 4)'); // no sync: both stay indexed
        $orphans = static fn(InspectOptions $options): Check => array_values(array_filter(
            $fuzzphony->inspect('products', $options)->checks,
            static fn(Check $c): bool => $c->name === 'Orphaned documents',
        ))[0];

        $quick = $orphans(new InspectOptions());
        self::assertSame(CheckStatus::Skipped, $quick->status);
        self::assertStringContainsString('--deep', $quick->message);

        $deep = $orphans(new InspectOptions(deep: true));
        self::assertSame(CheckStatus::Warning, $deep->status);
        self::assertStringStartsWith('2 indexed document(s)', $deep->message);
        self::assertSame('bin/console fuzzphony:reindex products', $deep->fix);

        $fuzzphony->reindex('products');
        self::assertSame(CheckStatus::Ok, $orphans(new InspectOptions(deep: true))->status);
    }

    public function testDoctorReportsABrokenSourceMapping(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('ALTER TABLE fz_product RENAME COLUMN price TO price_huf');

        $messages = array_map(static fn(Check $c): string => $c->message, $fuzzphony->inspect('products')->problems());

        self::assertNotEmpty(array_filter($messages, static fn(string $m): bool => str_contains($m, 'cannot be queried')));
    }

    public function testDoctorReportsColumnAwareFiltering(): void
    {
        // Create a custom index with explicit columns on a watch to test column-aware filtering
        $index = Indexes::products('queue')
            ->with(watches: [
                new \Fuzzphony\Core\Definition\Watch('fz_product', columns: ['name', 'price']),
                new \Fuzzphony\Core\Definition\Watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', 'id', ['name']),
            ]);

        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        $messages = array_column($fuzzphony->inspect('products')->checks, 'message', 'name');

        self::assertArrayHasKey('Column-aware filtering', $messages);
    }

    /**
     * A typo'd explicit column name (e.g. "nmae" instead of "name") passes
     * DefinitionValidator (identifier syntax only) and `--apply` (PL/pgSQL resolves column
     * names lazily), then breaks every UPDATE on the watched table at runtime. The doctor
     * must report this as an error, not as "active".
     */
    public function testDoctorReportsAnErrorForAWatchColumnThatDoesNotExist(): void
    {
        $index = Indexes::products('queue')
            ->with(watches: [
                new \Fuzzphony\Core\Definition\Watch('fz_product'),
                new \Fuzzphony\Core\Definition\Watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', 'id', ['nmae']),
            ]);

        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        $checks = array_values(array_filter(
            $fuzzphony->inspect('products')->checks,
            static fn(Check $c): bool => $c->name === 'Column-aware filtering',
        ));

        self::assertNotEmpty($checks, 'expected a "Column-aware filtering" check to be reported');
        foreach ($checks as $check) {
            self::assertSame(CheckStatus::Error, $check->status, $check->message);
        }
    }

    /**
     * relevantColumns() only affects trigger-installing sync modes; a "Column-aware
     * filtering" line for an orm/manual-sync index would be noise (no trigger ever consults
     * it), so the doctor must not emit one.
     */
    public function testDoctorDoesNotReportColumnAwareFilteringForNonTriggerSyncModes(): void
    {
        $index = \Fuzzphony\Core\Definition\IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->sync(\Fuzzphony\Core\Definition\SyncMode::Manual)
            ->build();

        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products_direct');

        $messages = array_column($fuzzphony->inspect('products_direct')->checks, 'message', 'name');

        self::assertArrayNotHasKey('Column-aware filtering', $messages);
    }

    public function testExplainReturnsSqlAndPlan(): void
    {
        $explanation = $this->fuzzphony('manual')->in('products')->query('wireless mouse')->explain(analyze: true);

        self::assertSame('(wireless AND mouse)', $explanation->interpretedAs);
        self::assertNotEmpty($explanation->statements);
        self::assertNotEmpty(array_filter($explanation->plan, static fn(string $l): bool => str_contains($l, 'actual time')));
    }

    public function testFuzzyModesStillDecideWhenTheTypoTolerantBranchRuns(): void
    {
        $search = $this->fuzzphony('manual')->in('products');

        $never = $search->query('headphnoes')->thresholds(['fuzzy_mode' => 'never'])->explain();
        self::assertSame(['full-text'], array_column($never->statements, 'label'));
        self::assertSame([], $never->result->ids());

        $enough = $search->query('mouse')->thresholds(['fallback_below' => 1])->explain();
        self::assertSame(['full-text'], array_column($enough->statements, 'label'), 'enough strict hits: no fallback');

        $fallback = $search->query('headphnoes')->thresholds(['fallback_below' => 1])->explain();
        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], array_column($fallback->statements, 'label'));
        self::assertSame([3], $fallback->result->ids());

        $always = $search->query('wireless mouse')->thresholds(['fuzzy_mode' => 'always'])->explain();
        self::assertSame(['full-text + fuzzy'], array_column($always->statements, 'label'));
        self::assertSame([1], $always->result->ids(), 'every word must match, also in always mode');
        self::assertEqualsWithDelta(1.0, $always->result->hits[0]->breakdown->fuzzySimilarity, 1e-9, 'both words match exactly');
    }

    public function testNoFuzzyStatementWhenOnlyStopWordsCouldMatchFuzzily(): void
    {
        $explanation = $this->fuzzphony('manual')->in('products')->query('the ab')->explain();

        self::assertSame(['full-text'], array_column($explanation->statements, 'label'));
    }

    public function testFuzzyStatementsCarryNoWholeQueryTrigramCheck(): void
    {
        $explanation = $this->fuzzphony('manual')->in('products')->query('wireles -cable')->explain();
        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], array_column($explanation->statements, 'label'));
        $sql = $explanation->statements[1]['sql'];

        self::assertStringNotContainsString('q.norm <% s.fz', $sql);
        self::assertStringNotContainsString('excl', $sql);
        self::assertStringContainsString('NOT (s.tsv @@ q.ft', $sql);
    }

    public function testErrorsCarryAHint(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('DROP TABLE fuzzphony_products');

        $this->expectException(EngineFailure::class);
        $this->expectExceptionMessageMatches('/Hint: Run "bin\/console fuzzphony:doctor"/');
        $fuzzphony->in('products')->query('mouse')->get();
    }

    public function testSchemaCanBeDroppedWithoutTouchingTheSource(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $this->engine->dropSchema($fuzzphony->registry()->get('products'))->apply($this->connection);

        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"));
        self::assertSame(5, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fz_product')));
        $this->connection->execute("UPDATE fz_brand SET name = 'x' WHERE id = 1"); // no trigger left behind
        $this->addToAssertionCount(1);
    }

    public function testNameIdentifiesTheEngine(): void
    {
        self::assertSame('postgresql', $this->engine->name());
    }

    public function testSchemaGeneratorIsSharedAcrossCalls(): void
    {
        self::assertSame($this->engine->schemaGenerator(), $this->engine->schemaGenerator());
    }

    public function testRefreshWithNoIdsIsANoOp(): void
    {
        self::assertSame(0, $this->engine->refresh(Indexes::products(), []));
    }

    public function testPruneOrphansRejectsANonPositiveBatchSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be >= 1.');

        $this->engine->pruneOrphans(Indexes::products(), 0);
    }

    /**
     * guard() must rethrow a FuzzphonyException (here: InvalidQuery from a broken Connection)
     * unchanged, not wrap it in an EngineFailure like an ordinary \Throwable.
     */
    public function testGuardRethrowsAFuzzphonyExceptionUnwrapped(): void
    {
        $failing = new class ($this->connection) implements Connection {
            public function __construct(private readonly Connection $inner) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                if (str_contains($sql, 'numnode(')) {
                    throw new InvalidQuery('synthetic failure for the guard test');
                }

                return $this->inner->fetchAll($sql, $params);
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return $this->inner->fetchValue($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                return $this->inner->execute($sql, $params);
            }

            public function transactional(callable $callback): mixed
            {
                return $this->inner->transactional(fn(Connection $c): mixed => $callback($this));
            }
        };
        $fuzzphony = new Fuzzphony(new PostgresEngine($failing), new IndexRegistry([Indexes::products('manual')]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('synthetic failure for the guard test'); // unwrapped: not "Fuzzphony search failed: ..."

        $fuzzphony->in('products')->query('headphnoes')->thresholds(['fallback_below' => 1])->get();
    }

    public function testDoctorReportsAMissingRequiredExtension(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('DROP EXTENSION pg_trgm CASCADE');
        try {
            $problems = array_column($fuzzphony->inspect('products')->problems(), null, 'name');

            self::assertSame(CheckStatus::Error, $problems['Extension pg_trgm']->status);
            self::assertSame('not installed (a superuser or the database owner must create it once)', $problems['Extension pg_trgm']->message);
            self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS pg_trgm', (string) $problems['Extension pg_trgm']->fix);
        } finally {
            $this->connection->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    public function testDoctorSkipsAnUninstalledExtensionThatIsNotNeeded(): void
    {
        $index = IndexDefinition::builder('products_direct')->fromTable('fz_product')->field('name', 'A')->filter('price', 'int')->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products_direct');
        $this->connection->execute('DROP EXTENSION pg_trgm CASCADE');
        try {
            $checks = array_column($fuzzphony->inspect('products_direct')->checks, null, 'name');

            self::assertSame(CheckStatus::Skipped, $checks['Extension pg_trgm']->status);
            self::assertSame('not needed by this index', $checks['Extension pg_trgm']->message);
        } finally {
            $this->connection->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    /**
     * When sourceColumns() is run inside the caller's own (ambient) transaction and the probe
     * view fails to create, the ambient transaction is left aborted; the finally block's own
     * DROP VIEW then also fails, and that second failure must be swallowed, not thrown in place
     * of the original. The aborted transaction still poisons whatever inspect() tries next
     * (this is what running the doctor inside someone else's transaction costs), so the overall
     * call still fails, but with PostgreSQL's own "transaction is aborted" error, not a
     * confusing "view does not exist" from the cleanup itself.
     */
    public function testASourceQueryErrorInsideTheCallersTransactionLeavesItAborted(): void
    {
        $index = IndexDefinition::builder('products_direct')
            ->fromQuery('SELECT id, name, no_such_column FROM fz_product')
            ->field('name', 'A')
            ->sync('manual')
            ->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/current transaction is aborted/');
        $this->connection->transactional(fn(): mixed => $fuzzphony->inspect('products_direct'));
    }

    public function testDoctorReportsAUuidIdTypeMismatch(): void
    {
        $index = IndexDefinition::builder('products_direct')->fromTable('fz_product')->idType('uuid')->field('name', 'A')->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        $problems = array_column($fuzzphony->inspect('products_direct')->problems(), 'message', 'name');

        self::assertSame('"id" is bigint, but the index expects id type "uuid".', $problems['Id column']);
    }

    public function testDoctorReportsAStringIdTypeMismatch(): void
    {
        $index = IndexDefinition::builder('products_direct')->fromTable('fz_product')->idType('string')->field('name', 'A')->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        $problems = array_column($fuzzphony->inspect('products_direct')->problems(), 'message', 'name');

        self::assertSame('"id" is bigint, but the index expects id type "string".', $problems['Id column']);
    }

    public function testDoctorReportsAnIdColumnThatDoesNotExistInTheSource(): void
    {
        $index = IndexDefinition::builder('products_direct')->fromQuery('SELECT name FROM fz_product', 'id')->field('name', 'A')->sync('manual')->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        $problems = array_column($fuzzphony->inspect('products_direct')->problems(), 'message', 'name');

        self::assertSame('Column "id" not found in the source. Available: name.', $problems['Id column']);
    }

    public function testDoctorReportsBrokenFieldFilterBoostAndRecencyColumnMappings(): void
    {
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('title', 'A', column: 'no_such_column')
            ->filter('missing_filter', 'int', 'also_missing')
            ->boostBy('no_such_boost')
            ->recencyBy('no_such_recency')
            ->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        $problems = array_column($fuzzphony->inspect('products_direct')->problems(), 'message', 'name');

        self::assertStringContainsString('Missing in source: title -> "no_such_column"', $problems['Field columns']);
        self::assertSame('Column "also_missing" not found in source.', $problems['Filter missing_filter']);
        self::assertSame('Column "no_such_boost" not found in source.', $problems['Boost column']);
        self::assertSame('Column "no_such_recency" not found in source.', $problems['Recency column']);
    }

    public function testDoctorWarnsAboutExtraSidecarColumns(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('ALTER TABLE fuzzphony_products ADD COLUMN extra_junk text');

        $extra = array_values(array_filter(
            $fuzzphony->inspect('products')->problems(),
            static fn(Check $c): bool => $c->name === 'Sidecar columns' && str_contains($c->message, 'no longer in the definition'),
        ));

        self::assertCount(1, $extra);
        self::assertSame(CheckStatus::Warning, $extra[0]->status);
        self::assertStringContainsString('extra_junk', $extra[0]->message);
        self::assertStringContainsString('ALTER TABLE "fuzzphony_products" DROP COLUMN "extra_junk";', (string) $extra[0]->fix);
    }

    public function testDoctorWarnsAboutLeftoverTriggersFromAPreviousSyncLevel(): void
    {
        $this->fuzzphony('queue'); // installs the default statement-level triggers
        $rowIndex = Indexes::products('queue')->with(triggerLevel: TriggerLevel::Row);
        $rowFuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$rowIndex]));

        $leftover = array_values(array_filter(
            $rowFuzzphony->inspect('products')->problems(),
            static fn(Check $c): bool => $c->name === 'Sync trigger on fz_brand' && str_contains($c->message, 'Leftover trigger'),
        ));

        self::assertCount(1, $leftover);
        self::assertSame(CheckStatus::Warning, $leftover[0]->status);
        self::assertStringContainsString('do not match "queue" sync / row level and cause double work', $leftover[0]->message);
        self::assertStringContainsString('fuzzphony:schema --apply', (string) $leftover[0]->fix);
    }

    public function testDoctorWarnsAboutVeryTolerantFuzzySimilarityAndAHighCandidateLimit(): void
    {
        $index = Indexes::products('manual')->with(thresholds: new Thresholds(fuzzySimilarity: 0.1, candidateLimit: 6_000));
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        $problems = array_column($fuzzphony->inspect('products')->problems(), 'message', 'name');

        self::assertStringContainsString('fuzzy_similarity 0.10 is very tolerant', $problems['Typo tolerance'] ?? '');
        self::assertSame('candidate_limit 6000 may make frequent words slow to rank.', $problems['Candidate limit'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\Inspection\PostgresInspector;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The doctor's checks of a PostgreSQL index: what each one says for a concrete database state. */
final class PostgresInspectorChecksTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fz_insp_item, fz_insp_cov, fz_insp_text, fz_insp_nokey CASCADE');
        foreach (['insp_item', 'insp_cov', 'insp_text', 'insp_nokey'] as $name) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS "fuzzphony_%1$s", "fuzzphony_%1$s__vocab", "fuzzphony_%1$s__next", "fuzzphony_%1$s__changes" CASCADE', $name));
        }
    }

    private function fuzzphony(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    /** @return array<string, Check> by name; the first one when a name repeats */
    private static function byName(Fuzzphony $fuzzphony, string $index, InspectOptions $options = new InspectOptions()): array
    {
        $byName = [];
        foreach ($fuzzphony->inspect($index, $options)->checks as $check) {
            $byName[$check->name] ??= $check;
        }

        return $byName;
    }

    /** @return list<Check> */
    private static function named(Fuzzphony $fuzzphony, string $index, string $name, InspectOptions $options = new InspectOptions()): array
    {
        return array_values(array_filter($fuzzphony->inspect($index, $options)->checks, static fn(Check $c): bool => $c->name === $name));
    }

    private function inspector(Connection $connection): PostgresInspector
    {
        return new PostgresInspector($connection, new PostgresSchemaGenerator());
    }

    public function testAHealthyIndexIsReportedCheckByCheck(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('queue'));
        $version = $this->connection->fetchValue('SHOW server_version');
        self::assertIsString($version);
        $number = explode(' ', $version)[0];

        $actual = [];
        foreach ($fuzzphony->inspect('products')->checks as $check) {
            $actual[] = [$check->name, $check->status, $check->message, $check->fix];
        }

        self::assertSame(
            [
                ['PostgreSQL version', CheckStatus::Ok, $number, null],
                ['Extension unaccent', CheckStatus::Ok, 'installed', null],
                ['Extension pg_trgm', CheckStatus::Ok, 'installed', null],
                ['Text search configuration', CheckStatus::Ok, '"public"."fuzzphony_english"', null],
                ['Normaliser function', CheckStatus::Ok, '"public"."fuzzphony_norm"(text)', null],
                ['Source', CheckStatus::Ok, 'custom query', null],
                ['Id column', CheckStatus::Ok, 'id (bigint)', null],
                ['Field columns', CheckStatus::Ok, '3 field(s) mapped', null],
                ['Filter price', CheckStatus::Ok, 'price (integer)', null],
                ['Filter in_stock', CheckStatus::Ok, 'in_stock (boolean)', null],
                ['Filter published_at', CheckStatus::Ok, 'published_at (timestamp with time zone)', null],
                ['Filter brand_id', CheckStatus::Ok, 'brand_id (bigint)', null],
                ['Boost column', CheckStatus::Ok, 'popularity (real)', null],
                ['Recency column', CheckStatus::Ok, 'published_at (timestamp with time zone)', null],
                ['Sidecar columns', CheckStatus::Ok, '16 column(s) match the definition', null],
                ['Index fuzzphony_products_tsv', CheckStatus::Ok, 'valid', null],
                ['Index fuzzphony_products_fz', CheckStatus::Ok, 'valid', null],
                ['Index fuzzphony_products_f_price', CheckStatus::Ok, 'valid', null],
                ['Index fuzzphony_products_f_in_stock', CheckStatus::Ok, 'valid', null],
                ['Index fuzzphony_products_f_published_at', CheckStatus::Ok, 'valid', null],
                ['Index fuzzphony_products_f_brand_id', CheckStatus::Ok, 'valid', null],
                ['Refresh function', CheckStatus::Ok, '"public"."fuzzphony_refresh_products"(bigint[])', null],
                ['Rebuild refresh function', CheckStatus::Ok, '"public"."fuzzphony_refresh_products__next"(bigint[])', null],
                ['Rebuild change log function', CheckStatus::Ok, '"public"."fuzzphony_track_products"()', null],
                ['Sync trigger on fz_product', CheckStatus::Ok, '4 trigger(s), queue mode, statement level', null],
                ['Sync trigger on fz_brand', CheckStatus::Ok, '4 trigger(s), queue mode, statement level', null],
                ['Sync queue', CheckStatus::Ok, '0 item(s) waiting', null],
                ['Coverage', CheckStatus::Skipped, 'Estimates are not available for query sources; run with --deep for exact counts.', null],
                ['Orphaned documents', CheckStatus::Skipped, 'Run with --deep to count indexed documents that are no longer in the source.', null],
                ['Vocabulary', CheckStatus::Ok, '12 words', null],
                ['Configuration', CheckStatus::Ok, 'fuzzy=fallback, similarity=by word length, min_score=0.00, candidates=2000', null],
            ],
            array_slice($actual, 0, 31),
        );
        $deep = self::byName($fuzzphony, 'products', new InspectOptions(deep: true));
        self::assertSame('5 of 5 documents indexed (100.0%, exact)', $deep['Coverage']->message);
        self::assertSame(CheckStatus::Ok, $deep['Orphaned documents']->status);
        self::assertSame('none', $deep['Orphaned documents']->message);
    }

    /** @return iterable<string, array{int, ?string, ?string}> */
    public static function serverVersions(): iterable
    {
        yield '15.0 is the oldest supported' => [150000, 'PostgreSQL version: 15.0', null];
        yield '16.7' => [160007, 'PostgreSQL version: 16.7', null];
        yield '15.9990 keeps the remainder of the major version' => [159990, 'PostgreSQL version: 15.9990', null];
        yield '14.0 is too old' => [140000, null, 'PostgreSQL 15 or newer is required, found 14.'];
        yield '14.9990 is still 14' => [149990, null, 'PostgreSQL 15 or newer is required, found 14.'];
        yield '9.6 is too old' => [90600, null, 'PostgreSQL 15 or newer is required, found 9.'];
    }

    #[DataProvider('serverVersions')]
    public function testTheServerVersionIsReported(int $versionNum, ?string $ok, ?string $error): void
    {
        $connection = new InterceptingConnection(
            $this->connection,
            fetchValue: static fn(string $sql): ?array => str_contains($sql, 'server_version_num') ? [$versionNum] : null,
        );
        $this->fuzzphony(Indexes::products('manual'));

        $first = $this->inspector($connection)->inspect(Indexes::products('manual'), new InspectOptions())->checks[0];

        if ($ok !== null) {
            self::assertSame(CheckStatus::Ok, $first->status);
            self::assertSame($ok, $first->name . ': ' . $first->message);
        } else {
            self::assertSame(CheckStatus::Error, $first->status);
            self::assertSame('PostgreSQL version', $first->name);
            self::assertSame($error, $first->message);
        }
    }

    public function testAMissingExtensionIsAnErrorOnlyWhenTheIndexNeedsIt(): void
    {
        $this->fuzzphony(Indexes::products('manual'));
        $nothingInstalled = new InterceptingConnection(
            $this->connection,
            fetchAll: static fn(string $sql): ?array => str_contains($sql, 'FROM pg_extension') ? [] : null,
        );

        $withFuzzy = array_column($this->inspector($nothingInstalled)->inspect(Indexes::products('manual'), new InspectOptions())->checks, null, 'name');
        self::assertEquals(
            Check::error('Extension unaccent', 'not installed (a superuser or the database owner must create it once)', 'CREATE EXTENSION IF NOT EXISTS unaccent;'),
            $withFuzzy['Extension unaccent'],
        );
        self::assertEquals(
            Check::error('Extension pg_trgm', 'not installed (a superuser or the database owner must create it once)', 'CREATE EXTENSION IF NOT EXISTS pg_trgm;'),
            $withFuzzy['Extension pg_trgm'],
        );

        $noFuzzy = IndexDefinition::builder('products')->fromQuery('SELECT p.id, p.name FROM fz_product p')->field('name', 'A')->sync('manual')->build();
        $checks = array_column($this->inspector($nothingInstalled)->inspect($noFuzzy, new InspectOptions())->checks, null, 'name');
        self::assertSame(CheckStatus::Error, $checks['Extension unaccent']->status, 'unaccent is always needed');
        self::assertEquals(Check::skipped('Extension pg_trgm', 'not needed by this index'), $checks['Extension pg_trgm']);
    }

    public function testTheSourceIsProbedThroughATemporaryViewThatIsDropped(): void
    {
        $this->fuzzphony(Indexes::products('manual'));
        $connection = new InterceptingConnection($this->connection);

        $this->inspector($connection)->inspect(Indexes::products('manual'), new InspectOptions());

        $created = $connection->statements('CREATE TEMPORARY VIEW');
        self::assertCount(1, $created);
        self::assertSame(1, preg_match('/^CREATE TEMPORARY VIEW "(fuzzphony_probe_[0-9a-f]{8})" AS /', $created[0], $view));
        $name = $view[1] ?? '';
        self::assertSame(['DROP VIEW IF EXISTS "' . $name . '"'], $connection->statements('DROP VIEW'));
        self::assertSame([['view' => 'pg_temp.' . $name]], $connection->params('SELECT attname, format_type'));
        self::assertSame(0, $this->connection->fetchValue('SELECT count(*) FROM pg_class WHERE relname LIKE \'fuzzphony_probe_%\''), 'the session keeps no probe view');
    }

    public function testASourceThatCannotBeQueriedIsReportedWithItsFirstLineOnly(): void
    {
        $this->fuzzphony(Indexes::products('manual'));
        $failing = new InterceptingConnection(
            $this->connection,
            execute: static function (string $sql): void {
                if (str_starts_with($sql, 'CREATE TEMPORARY VIEW')) {
                    throw new \RuntimeException("  relation is gone  \nLINE 1: SELECT ...\n        ^");
                }
            },
        );

        $checks = $this->inspector($failing)->inspect(Indexes::products('manual'), new InspectOptions(deep: true))->checks;

        $source = array_values(array_filter($checks, static fn(Check $c): bool => $c->name === 'Source'));
        self::assertEquals([Check::error('Source', 'The source cannot be queried: relation is gone', 'Run the source query manually and fix it.')], $source);
        self::assertSame([], array_filter($checks, static fn(Check $c): bool => in_array($c->name, ['Id column', 'Coverage', 'Orphaned documents'], true)), 'nothing is compared with a source that cannot be read');
        self::assertCount(1, $failing->statements('DROP VIEW'), 'the view is dropped whatever happened');
    }

    public function testABrokenSourceNeverReachesTheCountsOfTheDeepChecks(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('manual'));
        $this->connection->execute('ALTER TABLE fz_product RENAME COLUMN price TO price_huf');

        $checks = self::byName($fuzzphony, 'products', new InspectOptions(deep: true));

        self::assertSame(CheckStatus::Error, $checks['Source']->status);
        self::assertMatchesRegularExpression('/^The source cannot be queried: SQLSTATE\[\w+\]: [^\n]+$/', $checks['Source']->message);
        self::assertSame('Run the source query manually and fix it.', $checks['Source']->fix);
        self::assertArrayNotHasKey('Coverage', $checks);
        self::assertArrayNotHasKey('Orphaned documents', $checks);
    }

    public function testAMissingSidecarIsReportedWithoutCountingItsDocuments(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('manual'));
        $this->connection->execute('DROP TABLE fuzzphony_products CASCADE');

        $checks = self::byName($fuzzphony, 'products', new InspectOptions(deep: true));

        self::assertSame(CheckStatus::Error, $checks['Sidecar table']->status);
        self::assertSame('Table "public"."fuzzphony_products" does not exist.', $checks['Sidecar table']->message);
        self::assertSame('Ok', $checks['Source']->status->name);
        self::assertArrayNotHasKey('Coverage', $checks);
        self::assertArrayNotHasKey('Orphaned documents', $checks);
        self::assertArrayNotHasKey('Vocabulary', $checks);
    }

    private function tableIndex(string $table, string $idType = 'int', ?string $recency = null): IndexDefinition
    {
        $builder = IndexDefinition::builder(substr($table, 3))
            ->fromTable($table)
            ->idType($idType)
            ->field('name', 'A')
            ->filter('price', 'int')
            ->sync('manual');
        if ($recency !== null) {
            $builder->recencyBy($recency);
        }

        return $builder->build();
    }

    public function testATableSourceIsDescribedAndItsKeyChecked(): void
    {
        $this->connection->execute('CREATE TABLE fz_insp_item (id smallint PRIMARY KEY, name text NOT NULL, price integer NOT NULL, seen timestamptz NOT NULL DEFAULT now())');
        $this->connection->execute("INSERT INTO fz_insp_item (id, name, price) VALUES (1, 'a', 10), (2, 'b', 20), (3, 'c', 30)");
        $index = $this->tableIndex('fz_insp_item', recency: 'seen');
        $fuzzphony = $this->fuzzphony($index);
        $this->connection->execute('ANALYZE fz_insp_item');
        $this->connection->execute('ANALYZE "fuzzphony_insp_item"');

        $checks = self::byName($fuzzphony, 'insp_item');

        self::assertEquals(Check::ok('Source', 'table "fz_insp_item"'), $checks['Source']);
        self::assertEquals(Check::ok('Source key', '"id" is a primary/unique key'), $checks['Source key']);
        self::assertEquals(Check::ok('Id column', 'id (smallint)'), $checks['Id column'], 'a smallint id is an int id');
        self::assertArrayNotHasKey('Boost column', $checks);
        self::assertEquals(Check::ok('Recency column', 'seen (timestamp with time zone)'), $checks['Recency column'], 'a missing boost column does not hide the recency one');
        self::assertEquals(Check::ok('Coverage', '3 of 3 documents indexed (100.0%, estimated)'), $checks['Coverage']);
    }

    public function testATextIdIsAStringId(): void
    {
        $this->connection->execute('CREATE TABLE fz_insp_text (id text PRIMARY KEY, name text NOT NULL, price integer NOT NULL)');
        $this->connection->execute("INSERT INTO fz_insp_text VALUES ('x', 'a', 10)");

        $checks = self::byName($this->fuzzphony($this->tableIndex('fz_insp_text', idType: 'string')), 'insp_text');

        self::assertEquals(Check::ok('Id column', 'id (text)'), $checks['Id column']);
    }

    public function testAnUnkeyedTableSourceWarnsWithTheIndexToCreate(): void
    {
        $this->connection->execute('CREATE TABLE fz_insp_nokey (id integer NOT NULL, name text NOT NULL, price integer NOT NULL)');
        $this->connection->execute("INSERT INTO fz_insp_nokey VALUES (1, 'a', 10)");

        $checks = self::byName($this->fuzzphony($this->tableIndex('fz_insp_nokey')), 'insp_nokey');

        self::assertEquals(
            Check::warning(
                'Source key',
                '"id" is not a primary or unique key: refreshes will scan the table and duplicate ids are ambiguous.',
                'CREATE UNIQUE INDEX CONCURRENTLY ON "fz_insp_nokey" ("id");',
            ),
            $checks['Source key'],
        );
    }

    public function testAMissingTableSourceNamesTheTable(): void
    {
        $this->connection->execute('CREATE TABLE fz_insp_item (id integer PRIMARY KEY, name text NOT NULL, price integer NOT NULL)');
        $fuzzphony = $this->fuzzphony($this->tableIndex('fz_insp_item'));
        $this->connection->execute('DROP TABLE fz_insp_item');

        $source = self::named($fuzzphony, 'insp_item', 'Source')[0];

        self::assertSame(CheckStatus::Error, $source->status);
        self::assertStringStartsWith('The source cannot be queried: SQLSTATE[', $source->message);
        self::assertStringNotContainsString("\n", $source->message);
        self::assertSame('Check that table "fz_insp_item" exists and is readable.', $source->fix);
    }

    public function testCoverageToleratesFivePercentEitherWay(): void
    {
        $this->connection->execute('CREATE TABLE fz_insp_cov (id integer PRIMARY KEY, name text NOT NULL, price integer NOT NULL)');
        $this->connection->execute("INSERT INTO fz_insp_cov SELECT i, 'n' || i, i FROM generate_series(1, 21) AS i");
        $fuzzphony = $this->fuzzphony($this->tableIndex('fz_insp_cov'));
        $deep = new InspectOptions(deep: true);
        $coverage = static fn(): Check => self::named($fuzzphony, 'insp_cov', 'Coverage', $deep)[0];

        $this->connection->execute('DELETE FROM fz_insp_cov WHERE id = 21');
        self::assertEquals(Check::ok('Coverage', '21 of 20 documents indexed (105.0%, exact)'), $coverage(), '1.05 is still fine');

        $this->connection->execute('DELETE FROM fz_insp_cov WHERE id = 20');
        self::assertEquals(
            Check::warning('Coverage', '21 of 19 documents indexed (110.5%, exact).', 'bin/console fuzzphony:reindex insp_cov'),
            $coverage(),
        );

        $fuzzphony->reindex('insp_cov');
        $this->connection->execute("INSERT INTO fz_insp_cov VALUES (20, 'n20', 20)");
        self::assertEquals(Check::ok('Coverage', '19 of 20 documents indexed (95.0%, exact)'), $coverage(), '0.95 is still fine');

        $this->connection->execute("INSERT INTO fz_insp_cov VALUES (21, 'n21', 21)");
        self::assertEquals(
            Check::warning('Coverage', '19 of 21 documents indexed (90.5%, exact).', 'bin/console fuzzphony:reindex insp_cov'),
            $coverage(),
        );
    }

    public function testAnInvalidIndexIsAnErrorWithTheStatementsThatRepairIt(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('manual'));
        // what an interrupted CREATE INDEX CONCURRENTLY leaves: the index exists, flagged invalid
        $this->connection->execute('DROP INDEX fuzzphony_products_f_price');
        $this->connection->execute('UPDATE fuzzphony_products SET f_price = 1');
        try {
            $this->connection->execute('CREATE UNIQUE INDEX CONCURRENTLY fuzzphony_products_f_price ON fuzzphony_products (f_price)');
            self::fail('the unique build must fail on the duplicate values');
        } catch (\PDOException) {
        }

        $check = self::named($fuzzphony, 'products', 'Index fuzzphony_products_f_price')[0];

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('INVALID (an interrupted concurrent build); it is ignored by the planner', $check->message);
        self::assertStringStartsWith('DROP INDEX CONCURRENTLY "fuzzphony_products_f_price"; CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_products_f_price" ON ', (string) $check->fix);
    }

    public function testAMissingIndexIsReportedByName(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('manual'));
        $this->connection->execute('DROP INDEX fuzzphony_products_fz');

        $check = self::named($fuzzphony, 'products', 'Index fuzzphony_products_fz')[0];

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('missing', $check->message);
    }

    public function testAMissingTriggerOtherThanTheTruncateOneMeansChangesAreNotIndexed(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('queue'));
        $this->connection->execute('DROP TRIGGER fuzzphony_sync_products__fz_brand_upd ON fz_brand');
        $this->connection->execute('DROP TRIGGER fuzzphony_sync_products__fz_brand_trn ON fz_brand');

        $check = self::named($fuzzphony, 'products', 'Sync trigger on fz_brand')[0];

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('missing fuzzphony_sync_products__fz_brand_upd, fuzzphony_sync_products__fz_brand_trn: changes to this table are not indexed', $check->message);
    }

    public function testTheSyncQueueWarnsAboutAnOldestItemAndNeverAboutAnEmptyQueue(): void
    {
        $fuzzphony = $this->fuzzphony(Indexes::products('queue'));
        $options = new InspectOptions(maxQueueAgeSeconds: -1);
        self::assertEquals(Check::ok('Sync queue', '0 item(s) waiting'), self::named($fuzzphony, 'products', 'Sync queue', $options)[0], 'an empty queue has no age');

        $queue = static fn(int $age): InterceptingConnection => new InterceptingConnection(
            PostgresTestCase::connect(),
            fetchAll: static fn(string $sql): ?array => str_contains($sql, 'AS age') ? [['n' => 1, 'rebuild' => false, 'age' => $age]] : null,
        );
        $check = fn(int $age): Check => array_values(array_filter(
            $this->inspector($queue($age))->inspect(Indexes::products('queue'), new InspectOptions(maxQueueAgeSeconds: 60))->checks,
            static fn(Check $c): bool => $c->name === 'Sync queue',
        ))[0];

        self::assertEquals(Check::ok('Sync queue', '1 item(s) waiting'), $check(60), 'exactly the limit is not over it');
        $late = $check(61);
        self::assertSame(CheckStatus::Warning, $late->status);
        self::assertSame('1 item(s) waiting, oldest 61s: is the worker running?', $late->message);
        self::assertSame('bin/console fuzzphony:worker   (or from cron: bin/console fuzzphony:worker --once)', $late->fix);
    }

    public function testACandidateLimitAboveFiveThousandWarns(): void
    {
        $index = static fn(int $limit): IndexDefinition => Indexes::products('manual')->withThresholds(new Thresholds(candidateLimit: $limit));
        $this->fuzzphony($index(5_000));

        $inspect = fn(int $limit): array => self::byName(new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index($limit)])), 'products');

        $atLimit = $inspect(5_000);
        self::assertArrayNotHasKey('Candidate limit', $atLimit);
        self::assertSame('fuzzy=fallback, similarity=by word length, min_score=0.00, candidates=5000', $atLimit['Configuration']->message);
        $over = $inspect(5_001);
        self::assertEquals(Check::warning('Candidate limit', 'candidate_limit 5001 may make frequent words slow to rank.'), $over['Candidate limit']);
        self::assertArrayNotHasKey('Configuration', $over);
    }

    /**
     * @param list<string> $brandColumns
     * @param list<string> $productColumns
     */
    private static function watched(array $brandColumns, array $productColumns = ['name', 'price']): IndexDefinition
    {
        return Indexes::products('queue')->withWatches([
            new Watch('fz_product', columns: $productColumns),
            new Watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', 'id', $brandColumns),
        ]);
    }

    public function testColumnAwareFilteringIsReportedForEveryWatchAndAnUnknownColumnDoesNotStopTheNext(): void
    {
        $fuzzphony = $this->fuzzphony(self::watched(['name']));

        $good = self::named($fuzzphony, 'products', 'Column-aware filtering');
        self::assertCount(2, $good);
        self::assertSame([CheckStatus::Ok, CheckStatus::Ok], array_map(static fn(Check $c): CheckStatus => $c->status, $good));
        self::assertStringStartsWith('active for fz_product (', $good[0]->message);
        self::assertStringStartsWith('active for fz_brand (', $good[1]->message);

        $typo = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([self::watched(['nmae'])]));
        $checks = self::named($typo, 'products', 'Column-aware filtering');
        self::assertCount(2, $checks);
        self::assertEquals(Check::ok('Column-aware filtering', $good[0]->message), $checks[0]);
        self::assertEquals(Check::error('Column-aware filtering', 'Watch on "fz_brand" names unknown column(s): nmae.'), $checks[1]);

        $first = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([self::watched(['name'], ['nmae'])]));
        $checks = self::named($first, 'products', 'Column-aware filtering');
        self::assertCount(2, $checks, 'the watch after the faulty one is still checked');
        self::assertEquals(Check::error('Column-aware filtering', 'Watch on "fz_product" names unknown column(s): nmae.'), $checks[0]);
        self::assertSame(CheckStatus::Ok, $checks[1]->status);
    }

    public function testAUuidIdIsReportedAsSuch(): void
    {
        $this->connection->execute('CREATE TABLE fz_insp_text (id uuid PRIMARY KEY, name text NOT NULL, price integer NOT NULL)');
        $this->connection->execute("INSERT INTO fz_insp_text VALUES ('3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'a', 10)");

        $checks = self::byName($this->fuzzphony($this->tableIndex('fz_insp_text', idType: 'uuid')), 'insp_text');

        self::assertEquals(Check::ok('Id column', 'id (uuid)'), $checks['Id column']);
    }

    public function testAQueueBacklogOverTheLimitWarnsAndOneAtTheLimitDoesNot(): void
    {
        $this->fuzzphony(Indexes::products('queue'));
        $check = function (int $size): Check {
            $connection = new InterceptingConnection(
                PostgresTestCase::connect(),
                fetchAll: static fn(string $sql): ?array => str_contains($sql, 'AS age') ? [['n' => $size, 'rebuild' => false, 'age' => 0]] : null,
            );

            return array_values(array_filter(
                $this->inspector($connection)->inspect(Indexes::products('queue'), new InspectOptions(maxQueueBacklog: 3))->checks,
                static fn(Check $c): bool => $c->name === 'Sync queue',
            ))[0];
        };

        self::assertEquals(Check::ok('Sync queue', '3 item(s) waiting'), $check(3));
        self::assertSame(CheckStatus::Warning, $check(4)->status);
        self::assertSame('4 item(s) waiting, oldest 0s: is the worker running?', $check(4)->message);
    }

    public function testTypoToleranceThatCannotWorkIsAWarning(): void
    {
        $noFuzzy = static fn(string $mode): IndexDefinition => IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name FROM fz_product p')->field('name', 'A')->sync('manual')
            ->thresholds(new Thresholds(fuzzyMode: \Fuzzphony\Core\Ranking\FuzzyMode::from($mode)))->build();
        $configuration = fn(IndexDefinition $index): array => array_column($this->inspector($this->connection)->inspect($index, new InspectOptions())->checks, null, 'name');

        $always = $configuration($noFuzzy('always'));
        self::assertEquals(
            Check::warning('Typo tolerance', 'fuzzy_mode is enabled but no field is marked fuzzy, so it has no effect.', 'Mark the most important field fuzzy: #[SearchField("A", fuzzy: true)]'),
            $always['Typo tolerance'],
        );
        self::assertArrayNotHasKey('Configuration', $always);
        self::assertArrayNotHasKey('Typo tolerance', $configuration($noFuzzy('never')), 'switched off: nothing to warn about');
        self::assertArrayNotHasKey('Typo tolerance', $configuration(Indexes::products('manual')), 'a fuzzy field makes it work');
    }

    public function testADoctorWithoutTheSearchLogIsUnchanged(): void
    {
        $this->fuzzphony(Indexes::products('queue'));
        $connection = new InterceptingConnection($this->connection);
        $default = $this->inspector($connection)->inspect(Indexes::products('queue'), new InspectOptions())->checks;
        $explicit = (new PostgresInspector($this->connection, new PostgresSchemaGenerator(), false, 90))->inspect(Indexes::products('queue'), new InspectOptions())->checks;
        $viaEngine = (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('queue')])))->inspect('products')->checks;

        self::assertSame([], array_values(array_filter($default, static fn(Check $c): bool => $c->name === 'Search log')));
        self::assertEquals($default, $explicit);
        self::assertEquals($default, $viaEngine);
        self::assertSame([], $connection->statements('SELECT count(*) AS total'), 'the search log table is not even looked at');
        foreach ($connection->params('SELECT to_regclass') as $params) {
            self::assertStringNotContainsString('search_log', (string) json_encode($params));
        }
    }

    public function testTheSearchLogIsCheckedOnlyWhenTheEngineKeepsOne(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_search_log CASCADE');
        $index = Indexes::products('manual');
        $withLog = new Fuzzphony(new PostgresEngine($this->connection, searchLog: true), new IndexRegistry([$index]));
        $withLog->schema()->apply($this->connection);
        $withLog->reindex('products');

        $empty = self::named($withLog, 'products', 'Search log');
        self::assertEquals([Check::ok('Search log', '0 rows')], $empty);

        $this->connection->execute("INSERT INTO fuzzphony_search_log (at, index_name, query, query_norm, hits, took_ms, used_fuzzy, status) VALUES (now(), 'products', 'a', 'a', 1, 1, false, 'ok'), (now() - interval '10 days', 'products', 'b', 'b', 0, 1, false, 'ok')");
        self::assertEquals([Check::ok('Search log', '2 rows')], self::named($withLog, 'products', 'Search log'));
    }

    public function testRowsOlderThanTheRetentionAreAWarning(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_search_log CASCADE');
        $index = Indexes::products('manual');
        $engine = new PostgresEngine($this->connection, searchLog: true, searchLogRetentionDays: 30);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
        $this->connection->execute("INSERT INTO fuzzphony_search_log (at, index_name, query, query_norm, hits, took_ms, used_fuzzy, status) VALUES (now(), 'products', 'a', 'a', 1, 1, false, 'ok'), (now() - interval '29 days', 'products', 'b', 'b', 0, 1, false, 'ok'), (now() - interval '31 days', 'products', 'c', 'c', 0, 1, false, 'ok'), (now() - interval '90 days', 'products', 'd', 'd', 0, 1, false, 'ok')");

        self::assertEquals(
            [Check::warning('Search log', '4 rows, 2 of them older than the 30 days of retention.', 'bin/console fuzzphony:search-log:purge')],
            self::named($fuzzphony, 'products', 'Search log'),
        );
    }

    public function testAMissingSearchLogTableIsAnError(): void
    {
        $index = Indexes::products('manual');
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, searchLog: true), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_search_log CASCADE');

        self::assertEquals(
            [Check::error('Search log', sprintf('Table %s does not exist, so searches cannot be logged.', '"public"."fuzzphony_search_log"'), 'bin/console fuzzphony:schema --apply')],
            self::named($fuzzphony, 'products', 'Search log'),
        );
    }
}

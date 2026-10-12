<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\TableProfile;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use PHPUnit\Framework\TestCase;

/** What the wizard learns about a table: from a sample when the table was never analysed, from the planner's statistics when it was. */
final class PostgresIntrospectorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_intro_sample, fz_intro_stats, fz_intro_a, fz_intro_b, fz_intro_c CASCADE');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_intro_sample, fz_intro_stats, fz_intro_a, fz_intro_b, fz_intro_c CASCADE');
    }

    private function profile(string $table, bool $withRelations = true): TableProfile
    {
        return (new PostgresIntrospector($this->connection))->describe($table, $withRelations);
    }

    private static function column(TableProfile $profile, string $name): ColumnProfile
    {
        $column = $profile->column($name);
        self::assertNotNull($column, $name);

        return $column;
    }

    public function testATableThatWasNeverAnalysedIsSampled(): void
    {
        $this->connection->execute(<<<'SQL'
            CREATE TABLE fz_intro_sample (
                id integer PRIMARY KEY, title varchar(40) NOT NULL, note text, price numeric(10,2), ratio double precision,
                qty smallint, active boolean, born date, seen timestamptz, token uuid, meta jsonb
            ) WITH (autovacuum_enabled = false)
            SQL);
        $this->connection->execute(<<<'SQL'
            INSERT INTO fz_intro_sample (id, title, price, qty, active, born, seen, token, meta) VALUES
                (1, 'alpha', 10.50, 3, true, '2020-01-01', now(), gen_random_uuid(), '{}'),
                (2, 'beta', 99.50, 7, false, '2020-01-02', now(), gen_random_uuid(), '{}'),
                (3, 'gamma', 20.00, 5, true, '2020-01-03', now(), gen_random_uuid(), '{}'),
                (4, 'delta', 5.25, 9, true, '2020-01-04', now(), gen_random_uuid(), '{}')
            SQL);

        $profile = $this->profile('fz_intro_sample');

        self::assertSame(['No planner statistics for "fz_intro_sample" yet; sampled up to 1000 rows. Run ANALYZE for better suggestions.'], $profile->notes);
        self::assertSame(4, $profile->estimatedRows, 'a table never analysed has no row estimate: the sample counts');
        self::assertSame('id', $profile->primaryKey);
        self::assertSame([], $profile->foreignKeys);

        $expected = [
            // name => [kind, sql type, nullable, average length, distinct, approximate max]
            'id' => [ColumnKind::Int, 'integer', false, 1.0, 4.0, 4.0],
            'title' => [ColumnKind::Text, 'character varying(40)', false, 4.75, 4.0, null],
            'note' => [ColumnKind::Text, 'text', true, null, 0.0, null],
            'price' => [ColumnKind::Float, 'numeric(10,2)', true, 4.75, 4.0, 99.5],
            'ratio' => [ColumnKind::Float, 'double precision', true, null, 0.0, null],
            'qty' => [ColumnKind::Int, 'smallint', true, 1.0, 4.0, 9.0],
            'active' => [ColumnKind::Bool, 'boolean', true, 4.25, 2.0, null],
            'born' => [ColumnKind::Date, 'date', true, 10.0, 4.0, null],
            'seen' => [ColumnKind::DateTime, 'timestamp with time zone', true, null, 1.0, null],
            'token' => [ColumnKind::Uuid, 'uuid', true, 36.0, 4.0, null],
            'meta' => [ColumnKind::Other, 'jsonb', true, 2.0, 1.0, null],
        ];
        foreach ($expected as $name => [$kind, $type, $nullable, $length, $distinct, $max]) {
            $column = self::column($profile, $name);
            self::assertSame($kind, $column->kind, $name . ' kind');
            self::assertSame($type, $column->sqlType, $name . ' type');
            self::assertSame($nullable, $column->nullable, $name . ' nullable');
            if ($length !== null) {
                self::assertSame($length, $column->averageLength, $name . ' average length');
            }
            self::assertSame($distinct, $column->distinct, $name . ' distinct');
            self::assertSame($max, $column->approximateMax, $name . ' maximum');
        }
    }

    public function testPlannerStatisticsGiveTheLargestOfTheHistogramAndTheMostCommonValues(): void
    {
        $this->connection->execute('CREATE TABLE fz_intro_stats (id integer PRIMARY KEY, skew integer NOT NULL, label text NOT NULL)');
        // half of the rows share one value (a most-common value), the others are distinct (the histogram, whose largest bound is 499)
        $this->connection->execute("INSERT INTO fz_intro_stats SELECT i, CASE WHEN i % 2 = 0 THEN 1 ELSE i END, 'label ' || i FROM generate_series(1, 500) AS i");
        $this->connection->execute('ANALYZE fz_intro_stats');

        $profile = $this->profile('fz_intro_stats');

        self::assertSame([], $profile->notes, 'statistics exist: nothing is sampled');
        self::assertSame(500, $profile->estimatedRows);
        $skew = self::column($profile, 'skew');
        self::assertSame(499.0, $skew->approximateMax, 'the histogram holds the larger values, the most common value is 1');
        self::assertSame(4.0, $skew->averageLength);
        $id = self::column($profile, 'id');
        self::assertSame(500.0, $id->distinct, 'a unique column: PostgreSQL stores -1, a fraction of the rows');
        self::assertSame(500.0, $id->approximateMax);
        self::assertNull(self::column($profile, 'label')->approximateMax, 'text has no maximum');
    }

    public function testAFractionOfRowsIsAtLeastOneRow(): void
    {
        $this->connection->execute('CREATE TABLE fz_intro_stats (id integer PRIMARY KEY, label text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_intro_stats VALUES (1, 'only')");
        $this->connection->execute('ANALYZE fz_intro_stats');

        $profile = $this->profile('fz_intro_stats');

        self::assertSame([], $profile->notes);
        self::assertSame(1.0, self::column($profile, 'id')->distinct);
    }

    public function testAnEmptiedTableStillGivesAUniqueColumnAtLeastOneValue(): void
    {
        $this->connection->execute('CREATE TABLE fz_intro_stats (id integer PRIMARY KEY, label text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_intro_stats SELECT i, 'l' || i FROM generate_series(1, 50) AS i");
        $this->connection->execute('ANALYZE fz_intro_stats');
        $this->connection->execute('TRUNCATE fz_intro_stats');

        $profile = $this->profile('fz_intro_stats');

        self::assertSame(0, $profile->estimatedRows);
        self::assertSame(1.0, self::column($profile, 'id')->distinct, 'a fraction of no rows is still one');
    }

    public function testARelatedTableIsDescribedOneLevelDeepOnly(): void
    {
        $this->connection->execute('CREATE TABLE fz_intro_c (id integer PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE fz_intro_b (id integer PRIMARY KEY, c_id integer NOT NULL REFERENCES fz_intro_c)');
        $this->connection->execute('CREATE TABLE fz_intro_a (id integer PRIMARY KEY, b_id integer NOT NULL REFERENCES fz_intro_b)');

        $profile = $this->profile('fz_intro_a');

        self::assertCount(1, $profile->foreignKeys);
        $key = $profile->foreignKeys[0];
        self::assertSame('b_id', $key->column);
        self::assertSame('fz_intro_b', $key->referencedTable);
        self::assertSame('id', $key->referencedColumn);
        self::assertNotNull($key->referenced);
        self::assertSame('fz_intro_b', $key->referenced->table);
        self::assertSame([], $key->referenced->foreignKeys, 'the table a key points to is described, and no further');

        self::assertSame([], $this->profile('fz_intro_a', withRelations: false)->foreignKeys);
    }
}

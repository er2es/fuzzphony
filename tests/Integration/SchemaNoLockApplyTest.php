<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * An apply that changes nothing about the columns takes no ACCESS EXCLUSIVE lock on the sidecar, the meta table or the search log:
 * it does not queue behind a long reader (which would stall every search behind the apply).
 */
final class SchemaNoLockApplyTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_nolock_doc, fuzzphony_nolock, fuzzphony_nolock__vocab, fuzzphony_nolock__next, fuzzphony_nolock__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_nolock_doc (id bigint PRIMARY KEY, name text NOT NULL, body text NOT NULL)');
        $index = IndexDefinition::builder('nolock')->fromQuery('SELECT id, name, body FROM fz_nolock_doc')->field('name', 'A', fuzzy: true)->field('body', 'B', fuzzy: true)->language('english')->sync('manual')->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, searchLog: true), new IndexRegistry([$index]));
        $this->fuzzphony->schema()->apply($this->connection);
    }

    /** @return list<Statement> */
    private function columnStatements(): array
    {
        $engine = new PostgresEngine($this->connection, searchLog: true);
        $index = (new IndexRegistry([IndexDefinition::builder('nolock')->fromQuery('SELECT id, name, body FROM fz_nolock_doc')->field('name', 'A', fuzzy: true)->field('body', 'B', fuzzy: true)->language('english')->sync('manual')->build()]))->get('nolock');
        $statements = [...$engine->globalSchema($index)->statements, ...$engine->indexSchema($index)->statements];

        return array_values(array_filter($statements, static fn(Statement $s): bool => str_contains($s->sql, 'ADD COLUMN')));
    }

    public function testNoStatementAddsAColumnWithoutLookingAtTheCatalogFirst(): void
    {
        $statements = $this->columnStatements();

        self::assertGreaterThan(8, count($statements), 'the sidecar columns, the three meta columns and the log column');
        foreach ($statements as $statement) {
            self::assertStringNotContainsString('ADD COLUMN IF NOT EXISTS', $statement->sql);
            self::assertStringContainsString('IF NOT EXISTS (SELECT 1 FROM pg_attribute', $statement->sql);
        }
    }

    public function testApplyingAgainWaitsForNoReaderOfTheSidecarTheMetaTableOrTheLog(): void
    {
        $other = PostgresTestCase::connect();
        $this->connection->execute('BEGIN');
        try {
            foreach (['fuzzphony_nolock', 'fuzzphony_meta', 'fuzzphony_search_log'] as $table) {
                $this->connection->execute(sprintf('LOCK TABLE %s IN ACCESS SHARE MODE', $table)); // readers that are still busy
            }
            $other->execute('BEGIN');
            $other->execute("SET LOCAL lock_timeout = '300ms'");
            foreach ($this->columnStatements() as $statement) {
                $other->execute($statement->sql); // would time out if any of them asked for ACCESS EXCLUSIVE
            }
            $other->execute('ROLLBACK');
        } finally {
            $this->connection->execute('ROLLBACK');
        }

        self::addToAssertionCount(1);
    }

    public function testAMissingColumnIsStillAdded(): void
    {
        $this->connection->execute('ALTER TABLE fuzzphony_meta DROP COLUMN rebuild_error');
        $this->connection->execute('ALTER TABLE fuzzphony_nolock DROP COLUMN tsv_capped');

        $this->fuzzphony->schema()->apply($this->connection);

        $present = $this->connection->fetchValue("SELECT count(*) FROM pg_attribute WHERE NOT attisdropped AND ((attrelid = 'fuzzphony_meta'::regclass AND attname = 'rebuild_error') OR (attrelid = 'fuzzphony_nolock'::regclass AND attname = 'tsv_capped'))");
        self::assertSame(2, (int) $present);
    }
}

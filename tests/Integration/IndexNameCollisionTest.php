<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\TestCase;

/**
 * The objects of an index are named `fuzzphony_<index>__<kind>` ("__" cannot occur in an index name), so an index called
 * `products_open` (sidecar `fuzzphony_products_open`) or `products_tsv` never collides with an object of the index `products`.
 */
final class IndexNameCollisionTest extends TestCase
{
    private const array NAMES = ['coll', 'coll_open', 'coll_tsv', 'coll_pkey', 'coll_f_price'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        foreach (self::NAMES as $name) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS fz_%1$s_doc, fuzzphony_%1$s, fuzzphony_%1$s__vocab, fuzzphony_%1$s__next, fuzzphony_%1$s__changes CASCADE', $name));
            $this->connection->execute(sprintf('CREATE TABLE fz_%s_doc (id bigint PRIMARY KEY, name text NOT NULL, secret text NOT NULL, price int NOT NULL)', $name));
            $this->connection->execute(sprintf("INSERT INTO fz_%s_doc VALUES (1, 'kettle', 'hidden words', 5)", $name));
        }
    }

    /** @return list<IndexDefinition> the index `coll` with every kind of object (a granted field, a filter) and the ones named like them */
    private function definitions(): array
    {
        return array_map(static fn(string $name): IndexDefinition => IndexDefinition::builder($name)->fromQuery(sprintf('SELECT id, name, secret, price FROM fz_%s_doc', $name))
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: 'ROLE_X')->filter('price', 'int')->language('english')->sync('manual')->build(), self::NAMES);
    }

    /** @return list<string> every relation the nine indexes own */
    private function relations(): array
    {
        return array_map(static fn(array $r): string => Coerce::str($r['relname']), $this->connection->fetchAll("SELECT relname FROM pg_class WHERE relname LIKE 'fuzzphony\_coll%' ORDER BY relname"));
    }

    public function testNoObjectOfOneIndexIsTheNameOfAnother(): void
    {
        $names = new Names();
        $seen = [];
        foreach ($this->definitions() as $definition) {
            foreach ([$names->sidecarName($definition), $names->indexName($definition, 'tsv'), $names->indexName($definition, 'fz'), $names->indexName($definition, 'f_price'), $names->indexName($definition, 'pkey'), $names->shadowName($definition), $names->vocabularyName($definition), $names->changesName($definition)] as $name) {
                self::assertArrayNotHasKey($name, $seen, $name . ' is the name of two objects');
                $seen[$name] = true;
            }
        }
    }

    public function testApplyReindexAndTheSwapWorkForIndexesNamedLikeEachOthersObjects(): void
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry($this->definitions()));
        $fuzzphony->schema()->apply($this->connection);
        $before = $this->relations();
        foreach (self::NAMES as $name) {
            $fuzzphony->reindex($name); // the swap renames the rebuild's indexes to the live names: 42P07 when one is taken
        }

        self::assertSame($before, $this->relations(), 'the swap leaves the same objects');
        foreach (self::NAMES as $name) {
            self::assertSame(1, $fuzzphony->in($name)->query('kettle')->get()->total, $name);
            foreach ($fuzzphony->inspect($name)->checks as $check) {
                self::assertNotSame(CheckStatus::Error, $check->status, $name . ': ' . $check->name . ' ' . $check->message);
            }
        }
        self::assertContains('fuzzphony_coll__tsv', $before);
        self::assertContains('fuzzphony_coll_tsv', $before, 'the sidecar of the index coll_tsv');
        self::assertContains('fuzzphony_coll__pkey', $before);
        self::assertContains('fuzzphony_coll_pkey', $before);
    }

    public function testAnIndexUnderTheOldNameIsReportedAndDroppedByTheApply(): void
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->definitions()[0]]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('coll');
        // what a release before 0.10 had: the same indexes under `<sidecar>_<kind>` (and the open-class ones with a single underscore)
        foreach (['tsv', 'fz', 'f_price'] as $kind) {
            $this->connection->execute(sprintf('ALTER INDEX fuzzphony_coll__%1$s RENAME TO fuzzphony_coll_%1$s', $kind));
        }
        $this->connection->execute('ALTER INDEX fuzzphony_coll__pkey RENAME TO fuzzphony_coll_pkey');
        $open = Coerce::str($this->connection->fetchValue("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_coll' AND indexname ~ '__open_fz_'"));
        $this->connection->execute(sprintf('ALTER INDEX %s RENAME TO %s', $open, str_replace('__open_', '_open_', $open)));

        $old = array_values(array_filter($fuzzphony->inspect('coll')->checks, static fn($check): bool => str_contains($check->message, 'OLD NAME') || str_contains($check->message, 'STALE')));
        self::assertCount(4, $old);

        $fuzzphony->schema()->apply($this->connection);

        $indexes = array_map(static fn(array $r): string => Coerce::str($r['indexname']), $this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_coll' ORDER BY indexname"));
        self::assertSame([], array_values(array_filter($indexes, static fn(string $n): bool => preg_match('/^fuzzphony_coll_(tsv|fz|f_price|open_)/', $n) === 1)), 'the old names are gone');
        self::assertContains('fuzzphony_coll__tsv', $indexes);
        self::assertContains('fuzzphony_coll_pkey', $indexes, 'the primary key is renamed by the next reindex, not dropped');
        self::assertSame(1, $fuzzphony->in('coll')->query('kettle')->get()->total);
        foreach ($fuzzphony->inspect('coll')->checks as $check) {
            self::assertNotSame(CheckStatus::Error, $check->status, $check->name . ' ' . $check->message);
        }
    }
}

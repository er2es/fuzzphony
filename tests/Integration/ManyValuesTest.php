<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A customer with several addresses: the city is a many field and a many filter (values from the child table). */
final class ManyValuesTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->drop();
        $this->connection->execute('CREATE TABLE mv_customer (id bigint PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE mv_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, city text, street text)');
        $this->connection->execute("INSERT INTO mv_customer VALUES (1, 'Alice Kovacs'), (2, 'Bob Nagy'), (3, 'Carol Szabo'), (4, 'Dave Toth')");
        $this->connection->execute("INSERT INTO mv_address (customer_id, city) VALUES (1, 'Budapest'), (1, 'Vienna'), (1, 'Budapest'), (2, 'Debrecen'), (2, 'Szeged'), (3, 'Budapest')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_customers, fuzzphony_customers__next, fuzzphony_customers__changes, fuzzphony_customers__vocab, fuzzphony_customers__values, mv_address, mv_customer, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    private function definition(string $sync = 'queue', int $limit = 50): IndexDefinition
    {
        return IndexDefinition::builder('customers')
            ->fromTable('mv_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', fuzzy: true, many: ['from' => 'mv_address', 'key' => 'customer_id', 'column' => 'city', 'limit' => $limit])
            ->filter('city', 'string', many: ['from' => 'mv_address', 'key' => 'customer_id', 'column' => 'city', 'limit' => $limit])
            ->sync($sync)
            ->build();
    }

    private function install(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    /** @return list<int|string> */
    private static function ids(\Fuzzphony\Core\Search\SearchResult $result): array
    {
        $ids = array_map(static fn($h): int|string => $h->id, $result->hits);
        sort($ids);

        return $ids;
    }

    public function testSearchByAnyValueFindsTheDocument(): void
    {
        $f = $this->install($this->definition());

        self::assertSame([1, 3], self::ids($f->in('customers')->query('budapest')->get()));
        self::assertSame([1], self::ids($f->in('customers')->query('vienna')->get()));
        self::assertSame([2], self::ids($f->in('customers')->query('szeged')->get()));
        self::assertSame([2], self::ids($f->in('customers')->query('szged')->get()), 'a typo in one value');
        self::assertSame([1, 2, 3], self::ids($f->in('customers')->query('city:budapest OR city:debrecen')->get()));
    }

    public function testTheFilterIsAnyMatchAndCountsDocuments(): void
    {
        $f = $this->install($this->definition());

        self::assertSame([1, 3], self::ids($f->in('customers')->where('city', 'Budapest')->get()));
        self::assertSame([1, 2], self::ids($f->in('customers')->whereIn('city', ['Vienna', 'Szeged'])->get()));
        self::assertSame([2, 3, 4], self::ids($f->in('customers')->where('city', '!=', 'Vienna')->get()));
        self::assertSame([4], self::ids($f->in('customers')->whereNull('city')->get()));

        $facets = $f->in('customers')->query('alice OR bob OR carol')->facets('city')->get()->facets;
        $counts = [];
        foreach ($facets['city'] as $value) {
            $counts[(string) $value->value] = $value->count;
        }
        self::assertSame(2, $counts['Budapest'], 'customer 1 has two Budapest addresses and counts once');
        self::assertSame(1, $counts['Vienna']);
        self::assertSame(1, $counts['Szeged']);
    }
}

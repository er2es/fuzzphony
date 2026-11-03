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

    /** @return array<int, string> */
    private function stamps(): array
    {
        $stamps = [];
        foreach ($this->connection->fetchAll('SELECT id, indexed_at::text AS at FROM fuzzphony_customers ORDER BY id') as $row) {
            $stamps[Coerce::int($row['id'])] = Coerce::str($row['at']);
        }

        return $stamps;
    }

    /** @return list<string> the values rows of a document as field:ord:value */
    private function values(int $doc): array
    {
        return array_map(
            static fn(array $r): string => $r['field'] . ':' . $r['ord'] . ':' . $r['v'],
            $this->connection->fetchAll('SELECT field, ord, v FROM fuzzphony_customers__values WHERE doc = :doc ORDER BY field, ord', ['doc' => $doc]),
        );
    }

    public function testTheValuesTableHoldsOneRowPerValueInOrder(): void
    {
        $this->install($this->definition());

        self::assertSame(['city:0:Budapest', 'city:1:Budapest', 'city:2:Vienna'], $this->values(1));
        self::assertSame([], $this->values(4));
    }

    /** @return iterable<string, array{string, string}> */
    public static function modes(): iterable
    {
        yield 'queue' => ['queue', 'statement'];
        yield 'queue, row' => ['queue', 'row'];
        yield 'trigger' => ['trigger', 'statement'];
        yield 'trigger, row' => ['trigger', 'row'];
    }

    #[DataProvider('modes')]
    public function testAChildRowChangeRefreshesOnlyItsDocument(string $sync, string $level): void
    {
        $index = $this->definition($sync)->withTriggerLevel(\Fuzzphony\Core\Definition\TriggerLevel::from($level));
        $f = $this->install($index);
        $before = $this->stamps();
        $worker = new Worker($this->engine);

        $this->connection->execute("INSERT INTO mv_address (customer_id, city) VALUES (4, 'Pecs')");
        $worker->runOnce([$index]);
        self::assertSame([4], self::ids($f->in('customers')->query('pecs')->get()));
        self::assertSame(['city:0:Pecs'], $this->values(4));
        $after = $this->stamps();
        self::assertNotSame($before[4], $after[4]);
        foreach ([1, 2, 3] as $other) {
            self::assertSame($before[$other], $after[$other], 'customer ' . $other . ' was not rewritten');
        }

        $this->connection->execute("UPDATE mv_address SET city = 'Gyor' WHERE customer_id = 4");
        $worker->runOnce([$index]);
        self::assertSame([], self::ids($f->in('customers')->query('pecs')->get()));
        self::assertSame([4], self::ids($f->in('customers')->query('gyor')->get()));
        self::assertSame([4], self::ids($f->in('customers')->where('city', 'Gyor')->get()));

        $this->connection->execute("UPDATE mv_address SET customer_id = 3 WHERE customer_id = 4");
        $worker->runOnce([$index]);
        self::assertSame([3], self::ids($f->in('customers')->query('gyor')->get()), 'a moved child row refreshes the old and the new owner');
        self::assertSame([], $this->values(4));

        $this->connection->execute("DELETE FROM mv_address WHERE customer_id = 3");
        $worker->runOnce([$index]);
        self::assertSame([1], self::ids($f->in('customers')->query('budapest')->get()));
        self::assertSame([], $this->values(3));
        $final = $this->stamps();
        self::assertSame($before[1], $final[1]);
        self::assertSame($before[2], $final[2]);
    }

    public function testATruncateOfTheChildTableRebuilds(): void
    {
        $index = $this->definition();
        $f = $this->install($index);

        $this->connection->execute('TRUNCATE mv_address');
        $worker = new Worker($this->engine);
        $worker->runOnce([$index]);

        self::assertSame([], self::ids($f->in('customers')->query('budapest')->get()));
        self::assertSame(0, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_customers__values')));
        self::assertSame([], self::ids($f->in('customers')->where('city', 'Budapest')->get()));
    }

    public function testAFullReindexRebuildsTheValues(): void
    {
        $index = $this->definition();
        $f = $this->install($index);
        $this->connection->execute("INSERT INTO mv_address (customer_id, city) VALUES (4, 'Pecs')");
        $this->connection->execute("DELETE FROM mv_customer WHERE id = 2");

        $f->reindex('customers');

        self::assertSame([4], self::ids($f->in('customers')->query('pecs')->get()));
        self::assertSame([], $this->values(2), 'the rows of a document that is gone are removed by the swap');
        self::assertSame(['city:0:Pecs'], $this->values(4));
    }

    public function testTheLimitKeepsTheFirstValuesInOrder(): void
    {
        $index = $this->definition(limit: 3);
        foreach (['Rome', 'Cairo', 'Perth', 'Oslo', 'Lima'] as $city) {
            $this->connection->execute("INSERT INTO mv_address (customer_id, city) VALUES (4, '" . $city . "')");
        }
        $f = $this->install($index);

        self::assertSame(['city:0:Cairo', 'city:1:Lima', 'city:2:Oslo'], $this->values(4));
        self::assertSame([4], self::ids($f->in('customers')->query('oslo')->get()));
        self::assertSame([], self::ids($f->in('customers')->query('perth')->get()), 'the fourth value is beyond the limit');
        self::assertSame([], self::ids($f->in('customers')->where('city', 'Rome')->get()));
    }

    public function testTheHitReportsWhichValueMatched(): void
    {
        $f = $this->install($this->definition());

        $hit = $f->in('customers')->query('vienna')->explainMatches()->get()->hits[0];
        self::assertSame(1, $hit->id);
        self::assertCount(1, $hit->matches);
        self::assertSame(['field' => 'city', 'word' => 'vienna', 'via' => 'exact', 'similarity' => null, 'value_ord' => 2, 'value' => 'Vienna'], $hit->matches[0]->toArray());

        $typo = $f->in('customers')->query('szged')->explainMatches()->get()->hits[0];
        self::assertSame(2, $typo->id);
        self::assertSame('Szeged', $typo->matches[0]->value);
        self::assertSame('typo', $typo->matches[0]->via);

        $both = $f->in('customers')->query('alice vienna')->explainMatches()->get()->hits[0];
        self::assertSame(['name', 'city'], array_map(static fn($m): string => $m->field, $both->matches));
        self::assertNull($both->matches[0]->value, 'a single-valued field has no value info');
    }

    public function testTheHighlightShowsTheMatchingValue(): void
    {
        $f = $this->install($this->definition());

        $hit = $f->in('customers')->query('debrecen')->highlight('city')->get()->hits[0];
        self::assertStringContainsString('Debrecen', implode(' ', $hit->highlights));
        self::assertSame('<mark>Debrecen</mark>', $hit->highlights['city'] ?? null, 'only the value that matches, not all of them');

        $two = $f->in('customers')->query('vienna')->highlight('city')->get()->hits[0];
        self::assertSame('<mark>Vienna</mark>', $two->highlights['city'] ?? null);
        $typo = $f->in('customers')->query('szged')->highlight('city')->get()->hits[0];
        self::assertStringContainsString('Szeged', $typo->highlights['city'] ?? '');
    }

    public function testAHiddenManyFieldChangesNothingForARestrictedViewer(): void
    {
        $index = IndexDefinition::builder('customers')
            ->fromTable('mv_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', fuzzy: true, granted: 'ROLE_CITY', many: ['from' => 'mv_address', 'key' => 'customer_id', 'column' => 'city'])
            ->sync('manual')
            ->build();
        $f = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $f->schema()->apply($this->connection);

        $outcomes = [];
        foreach (['none' => [], 'one' => ['Budapest'], 'matching' => ['Alice', 'Alice Kovacs', 'Budapest', 'Vienna'], 'many' => array_map(static fn(int $i): string => 'Town' . $i, range(1, 60))] as $variant => $cities) {
            $this->connection->execute('DELETE FROM mv_address');
            foreach ($cities as $city) {
                $this->connection->execute('INSERT INTO mv_address (customer_id, city) VALUES (1, :c), (3, :c)', ['c' => $city]);
            }
            $f->reindex('customers');
            foreach (['alice', 'alice OR bob', 'budapest', 'kovacs', 'alic', 'name:alice', 'alice -carol'] as $text) {
                $explained = $f->in('customers')->query($text)->restrictedToFields('name')->explainMatches()->highlight('name')->explain();
                $result = $explained->result;
                $outcomes[$text][$variant] = [
                    array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), $hit->highlights, array_map(static fn($m): array => $m->toArray(), $hit->matches)], $result->hits),
                    $result->total,
                    $result->didYouMean,
                    array_map(static fn(array $st): array => [$st['label'], $st['sql'], $st['params']], $explained->statements),
                ];
            }
        }
        foreach ($outcomes as $text => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['none'], $outcome, sprintf('"%s": the hidden values (%s) changed the outcome', $text, $variant));
            }
        }
        // and the viewer who holds the field does see the values
        self::assertSame([1, 3], self::ids($f->in('customers')->query('town7')->get()));
    }
}

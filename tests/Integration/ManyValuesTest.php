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
        $this->connection->execute('CREATE TABLE mv_customer (id bigint PRIMARY KEY, name text NOT NULL, tid integer NOT NULL DEFAULT 1)');
        $this->connection->execute('CREATE TABLE mv_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, city text, street text)');
        $this->connection->execute('CREATE INDEX mv_address_customer ON mv_address (customer_id)');
        $this->connection->execute("INSERT INTO mv_customer (id, name, tid) VALUES (1, 'Alice Kovacs', 1), (2, 'Bob Nagy', 1), (3, 'Carol Szabo', 2), (4, 'Dave Toth', 2)");
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

    /** @return list<\Fuzzphony\Core\Search\MatchedWord> */
    private static function matchesOf(\Fuzzphony\Core\Search\Hit $hit): array
    {
        return $hit->matches ?? [];
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
            static fn(array $r): string => Coerce::str($r['field']) . ':' . Coerce::str($r['ord']) . ':' . Coerce::str($r['v']),
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
        self::assertCount(1, ($hit->matches ?? []));
        self::assertSame(['field' => 'city', 'word' => 'vienna', 'via' => 'exact', 'similarity' => null, 'value_ord' => 2, 'value' => 'Vienna'], self::matchesOf($hit)[0]->toArray());

        $typo = $f->in('customers')->query('szged')->explainMatches()->get()->hits[0];
        self::assertSame(2, $typo->id);
        self::assertSame('Szeged', self::matchesOf($typo)[0]->value);
        self::assertSame('typo', self::matchesOf($typo)[0]->via);

        $both = $f->in('customers')->query('alice vienna')->explainMatches()->get()->hits[0];
        self::assertSame(['name', 'city'], array_map(static fn($m): string => $m->field, self::matchesOf($both)));
        self::assertNull(self::matchesOf($both)[0]->value, 'a single-valued field has no value info');
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
                    array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), $hit->highlights, array_map(static fn($m): array => $m->toArray(), ($hit->matches ?? []))], $result->hits),
                    $result->total,
                    $result->didYouMean,
                    array_map(static fn(array $st): array => [$st['label'], $st['sql'], $st['params']], $explained->statements),
                ];
            }
        }
        foreach ($outcomes as $text => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['none'] ?? null, $outcome, sprintf('"%s": the hidden values (%s) changed the outcome', $text, $variant));
            }
        }
        // and the viewer who holds the field does see the values
        self::assertSame([1, 3], self::ids($f->in('customers')->query('town7')->get()));
    }

    public function testTheDoctorNamesDocumentsOverTheLimitWithoutValues(): void
    {
        $index = $this->definition(limit: 2);
        $f = $this->install($index);

        $report = $f->inspect('customers');
        $values = array_values(array_filter($report->checks, static fn($c): bool => str_starts_with($c->name, 'Values ')));
        self::assertNotSame([], $values);
        $over = array_values(array_filter($values, static fn($c): bool => $c->status->value !== 'ok'));
        self::assertCount(2, $over, 'the field and the filter');
        self::assertStringContainsString('1 document(s) have more than 2 values', $over[0]->message);
        foreach ($values as $check) {
            self::assertStringNotContainsString('Budapest', $check->message);
        }

        $this->connection->execute('DELETE FROM fuzzphony_customers__values');
        $stale = array_filter($f->inspect('customers')->checks, static fn($c): bool => str_contains($c->message, 'out of date'));
        self::assertCount(1, $stale);
        $this->connection->execute('DROP TABLE fuzzphony_customers__values');
        $missing = array_filter($f->inspect('customers')->checks, static fn($c): bool => $c->name === 'Values table');
        self::assertCount(1, $missing);
    }

    public function testATenantNeverSeesAnotherTenantsValuesInResultsOrFacets(): void
    {
        $index = IndexDefinition::builder('customers')
            ->fromTable('mv_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', fuzzy: true, many: ['from' => 'mv_address', 'key' => 'customer_id', 'column' => 'city'])
            ->filter('city', 'string', many: ['from' => 'mv_address', 'key' => 'customer_id', 'column' => 'city'])
            ->filter('tid', 'int')
            ->tenant('tid')
            ->build();
        $f = $this->install($index);

        self::assertSame([1], self::ids($f->in('customers')->forTenant(1)->query('budapest')->get()), 'customer 3 (tenant 2) also lives in Budapest');
        self::assertSame([3], self::ids($f->in('customers')->forTenant(2)->query('budapest')->get()));
        self::assertSame([1], self::ids($f->in('customers')->forTenant(1)->where('city', 'Budapest')->get()));
        $facets = $f->in('customers')->forTenant(1)->query('alice OR bob OR carol')->facets('city')->get()->facets;
        $counts = [];
        foreach ($facets['city'] as $value) {
            $counts[(string) $value->value] = $value->count;
        }
        self::assertSame(['Budapest' => 1, 'Debrecen' => 1, 'Szeged' => 1, 'Vienna' => 1], $counts);
        $hit = $f->in('customers')->forTenant(2)->query('budapest')->explainMatches()->highlight('city')->get()->hits[0];
        self::assertSame(3, $hit->id);
        self::assertSame('<mark>Budapest</mark>', $hit->highlights['city']);
    }

    public function testARefreshOfOneDocumentRewritesItsValues(): void
    {
        $index = $this->definition();
        $f = $this->install($index);
        $this->connection->execute("UPDATE mv_address SET city = 'Zalaegerszeg' WHERE customer_id = 2 AND city = 'Szeged'");

        $this->engine->refresh($index, [2]);

        self::assertSame([2], self::ids($f->in('customers')->query('zalaegerszeg')->get()));
        self::assertSame(['city:0:Debrecen', 'city:1:Zalaegerszeg'], $this->values(2));
    }

    /** @return array<string, int> */
    private static function counts(\Fuzzphony\Core\Search\SearchResult $result): array
    {
        $counts = [];
        foreach ($result->facets['city'] ?? [] as $value) {
            $counts[(string) $value->value] = $value->count;
        }
        ksort($counts);

        return $counts;
    }

    public function testFacetsOfAManyFilterInEveryFacetPath(): void
    {
        $f = $this->install($this->definition());
        $all = ['' => 1, 'Budapest' => 2, 'Debrecen' => 1, 'Szeged' => 1, 'Vienna' => 1];

        // without text (browsing), a text search, exact counts: documents per value; customer 4 has no city (the NULL bucket)
        $browse = self::counts($f->in('customers')->facets('city')->get());
        self::assertSame(['' => 1, 'Budapest' => 2, 'Debrecen' => 1, 'Szeged' => 1, 'Vienna' => 1], $browse);
        self::assertSame($all, self::counts($f->in('customers')->query('alice OR bob OR carol OR dave')->facets('city')->get()));
        self::assertSame($all, self::counts($f->in('customers')->query('alice OR bob OR carol OR dave')->facets('city')->exactCounts()->get()));

        // the facet of the filter the search is narrowed by still lists the other values (disjunctive), counting documents
        $narrowed = $f->in('customers')->query('alice OR bob OR carol OR dave')->where('city', 'Budapest')->facets('city')->get();
        self::assertSame([1, 3], self::ids($narrowed));
        self::assertSame($all, self::counts($narrowed));
        $browseNarrowed = $f->in('customers')->where('city', 'Budapest')->facets('city')->get();
        self::assertSame($all, self::counts($browseNarrowed));
        self::assertSame(2, $browseNarrowed->total);
    }

    public function testTheDoctorWarnsWhenTheChildTableHasNoIndexOnItsKey(): void
    {
        $f = $this->install($this->definition());
        $named = static fn(\Fuzzphony\Core\Inspection\Check $c): bool => str_contains($c->message, 'has no index that starts with "customer_id"');

        self::assertSame([], array_filter($f->inspect('customers')->checks, $named));
        $this->connection->execute('DROP INDEX mv_address_customer');
        self::assertCount(2, array_filter($f->inspect('customers')->checks, $named), 'the field and the filter');
    }

    public function testTheDoctorDoesNotLookForManyFieldsAndFiltersInTheSourceColumns(): void
    {
        $f = $this->install($this->definition());

        $checks = [];
        foreach ($f->inspect('customers')->checks as $check) {
            $checks[$check->name] ??= $check;
        }
        self::assertSame(\Fuzzphony\Core\Inspection\CheckStatus::Ok, $checks['Field columns']->status, $checks['Field columns']->message);
        self::assertArrayNotHasKey('Filter city', $checks, 'no source-column check for a many filter');
        foreach ($f->inspect('customers')->checks as $check) {
            self::assertNotSame(\Fuzzphony\Core\Inspection\CheckStatus::Error, $check->status, $check->name . ': ' . $check->message);
            self::assertStringNotContainsString('Missing in source', $check->message);
        }
    }

    public function testTheDoctorStillReportsAnOrdinaryFieldMissingFromTheSource(): void
    {
        $this->install($this->definition());
        $broken = IndexDefinition::builder('customers')
            ->fromTable('mv_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('nickname', 'B')
            ->field('city', 'C', fuzzy: true, many: ['from' => 'mv_address', 'key' => 'customer_id', 'column' => 'city'])
            ->build();
        $f = new Fuzzphony($this->engine, new IndexRegistry([$broken]));

        $fieldColumns = null;
        foreach ($f->inspect('customers')->checks as $check) {
            if ($check->name === 'Field columns') {
                $fieldColumns = $check;
                break;
            }
        }
        self::assertNotNull($fieldColumns);
        self::assertSame(\Fuzzphony\Core\Inspection\CheckStatus::Error, $fieldColumns->status);
        self::assertStringContainsString('nickname', $fieldColumns->message);
        self::assertStringNotContainsString('city ->', $fieldColumns->message);
    }
}

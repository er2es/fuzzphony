<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use PHPUnit\Framework\TestCase;

/** many: { rank: best }: the partial GIN index of the values table (created, dropped, checked) and the manyMatch() refusals until the search implements it. */
final class ManyRankSchemaTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->drop();
        $this->connection->execute('CREATE TABLE mr_customer (id bigint PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE mr_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, city text, street text)');
        $this->connection->execute('CREATE INDEX mr_address_customer ON mr_address (customer_id)');
        $this->connection->execute("INSERT INTO mr_customer (id, name) VALUES (1, 'Kiss Anna'), (2, 'Nagy Bela')");
        $this->connection->execute("INSERT INTO mr_address (customer_id, city, street) VALUES (1, 'Budapest', 'Fo utca'), (1, 'Szeged', 'Kossuth ter'), (2, 'Szeged', 'Fo utca')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_rcust, fuzzphony_rcust__next, fuzzphony_rcust__changes, fuzzphony_rcust__vocab, fuzzphony_rcust__values, mr_address, mr_customer, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    /** @param array<string, string> $rank field => 'best'|'whole' */
    private function definition(array $rank): IndexDefinition
    {
        $many = static fn(string $column): array => ['from' => 'mr_address', 'key' => 'customer_id', 'column' => $column] + (isset($rank[$column]) ? ['rank' => $rank[$column]] : []);

        return IndexDefinition::builder('rcust')->fromTable('mr_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', fuzzy: true, many: $many('city'))
            ->field('street', 'C', many: $many('street'))
            ->sync('queue')
            ->build();
    }

    private function install(IndexDefinition $index, bool $reindex = true): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        if ($reindex) {
            $fuzzphony->reindex($index->name);
        }

        return $fuzzphony;
    }

    /** @return list<string> the indexes of the values table other than its primary key, by name */
    private function valuesIndexes(): array
    {
        return array_values(array_map(Coerce::str(...), array_column($this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_rcust__values' AND indexname NOT LIKE '%pkey' ORDER BY indexname"), 'indexname')));
    }

    /** @return array<string, list<array{string, string}>> the "Values ranking" checks: status and message */
    private static function rankingChecks(\Fuzzphony\Core\Inspection\InspectionReport $report): array
    {
        $out = [];
        foreach ($report->checks as $check) {
            if (str_starts_with($check->name, 'Values ranking')) {
                $out[$check->name][] = [$check->status->value, $check->message];
            }
        }

        return $out;
    }

    public function testApplyCreatesAPartialGinPerBestFieldOnly(): void
    {
        $index = $this->definition(['city' => 'best']);
        $this->install($index);

        $name = (new Names())->valuesBestIndexName($index, 'city');
        self::assertSame([$name], $this->valuesIndexes());
        $definition = Coerce::str($this->connection->fetchValue('SELECT indexdef FROM pg_indexes WHERE indexname = :n', ['n' => $name]));
        self::assertStringContainsString('USING gin (t)', $definition);
        self::assertStringContainsString("WHERE (field = 'city'::text)", $definition);
    }

    public function testAnIndexWithoutTheOptionGetsNone(): void
    {
        $this->install($this->definition([]));
        $first = $this->valuesIndexes();
        $this->install($this->definition(['city' => 'whole']));
        $second = $this->valuesIndexes();

        self::assertSame([[], []], [$first, $second]);
    }

    public function testSwitchingItOnNeedsNoReindexAndOffDropsTheIndex(): void
    {
        $whole = $this->definition([]);
        $best = $this->definition(['city' => 'best', 'street' => 'best']);
        $f = $this->install($whole);
        $rows = Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_rcust__values'));
        self::assertSame(3 * 2, $rows, 'two many fields, three addresses');

        $f = $this->install($best, reindex: false);
        self::assertCount(2, $this->valuesIndexes());
        self::assertSame($rows, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_rcust__values')), 'the documents are untouched');
        $reindex = array_filter($f->inspect('rcust')->checks, static fn($c): bool => str_contains($c->message, 'reindex') && $c->status->value !== 'ok');
        self::assertSame([], array_values($reindex), 'the doctor asks for no reindex');

        $this->install($this->definition(['street' => 'best']), reindex: false);
        self::assertSame([(new Names())->valuesBestIndexName($best, 'street')], $this->valuesIndexes(), 'the index of the field switched off is dropped');

        $this->install($whole, reindex: false);
        self::assertSame([], $this->valuesIndexes());
        self::assertSame(1, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_indexes WHERE tablename = 'fuzzphony_rcust__values'")), 'the primary key stays');
    }

    public function testTheDoctorChecksTheIndex(): void
    {
        $best = $this->definition(['city' => 'best']);
        $f = $this->install($best);

        $checks = self::rankingChecks($f->inspect('rcust'));
        self::assertSame(['Values ranking "city"'], array_keys($checks));
        self::assertSame('ok', $checks['Values ranking "city"'][0][0]);
        self::assertMatchesRegularExpression('/index fuzzphony_rcust__values_best_city present, \d+ kB$/', $checks['Values ranking "city"'][0][1]);

        $this->connection->execute('CREATE INDEX fuzzphony_rcust__values_best_street ON fuzzphony_rcust__values USING gin (t) WHERE field = \'street\'');
        $stale = self::rankingChecks($f->inspect('rcust'))['Values ranking'] ?? [];
        self::assertSame('warning', $stale[0][0] ?? null);
        self::assertStringContainsString('fuzzphony_rcust__values_best_street', $stale[0][1] ?? '');
        $this->connection->execute('DROP INDEX fuzzphony_rcust__values_best_street');

        $this->connection->execute('DROP INDEX ' . (new Names())->valuesBestIndexName($best, 'city'));
        $missing = self::rankingChecks($f->inspect('rcust'));
        self::assertSame('error', $missing['Values ranking "city"'][0][0]);
        self::assertStringContainsString('missing', $missing['Values ranking "city"'][0][1]);

        $f->schema()->apply($this->connection);
        self::assertSame('ok', self::rankingChecks($f->inspect('rcust'))['Values ranking "city"'][0][0], 'schema --apply is the fix');
    }

    public function testAnIndexWithoutTheOptionHasNoRankingCheck(): void
    {
        $f = $this->install($this->definition([]));
        self::assertSame([], self::rankingChecks($f->inspect('rcust')));
    }

    public function testTheGeneratedStatementsOfAnIndexWithoutTheOptionDoNotMentionIt(): void
    {
        $generator = new PostgresSchemaGenerator(new Names());
        $plain = implode("\n;\n", array_map(static fn($s): string => $s->sql, $generator->index($this->definition([]))->statements));
        $whole = implode("\n;\n", array_map(static fn($s): string => $s->sql, $generator->index($this->definition(['city' => 'whole']))->statements));
        $best = array_map(static fn($s): string => $s->sql, $generator->index($this->definition(['city' => 'best']))->statements);

        self::assertSame($plain, $whole);
        self::assertStringNotContainsString('values_best', $plain);
        // the opt-in plan differs by the index statement and the recorded definition fingerprint only
        $extra = array_values(array_filter($best, static fn(string $sql): bool => str_contains($sql, 'values_best_city') && str_starts_with($sql, 'CREATE INDEX')));
        self::assertSame(['CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_rcust__values_best_city" ON "public"."fuzzphony_rcust__values" USING gin (t) WHERE field = \'city\''], $extra);
        $rest = array_values(array_filter($best, static fn(string $sql): bool => !str_contains($sql, 'values_best_city') || str_starts_with($sql, 'DROP INDEX')));
        $plainStatements = array_map(static fn($s): string => $s->sql, $generator->index($this->definition([]))->statements);
        self::assertSame(count($plainStatements), count($rest));
        foreach ($plainStatements as $i => $sql) {
            if (!str_contains($sql, 'fuzzphony_meta')) {
                self::assertSame($sql, $rest[$i], 'statement ' . $i);
            }
        }
    }

    public function testManyMatchSingleIsRefusedUntilTheSearchImplementsIt(): void
    {
        $f = $this->install($this->definition(['city' => 'best']));

        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('not implemented');
        $f->in('rcust')->query('fo utca szeged')->manyMatch('single')->get();
    }

    public function testManyMatchNamesAreCheckedAndAHiddenViewerGetsThePlainSearch(): void
    {
        $f = $this->install($this->definition(['city' => 'best']));
        $plain = array_map(static fn($h): int|string => $h->id, $f->in('rcust')->query('szeged')->restrictedToFields('name', 'street')->get()->hits);

        $viewer = $f->in('rcust')->query('szeged')->restrictedToFields('name', 'street')->manyMatch('single');
        self::assertSame($plain, array_map(static fn($h): int|string => $h->id, $viewer->get()->hits), 'a viewer who cannot see the field: no-op');
        self::assertSame(array_map(static fn($h): int|string => $h->id, $f->in('rcust')->query('szeged')->get()->hits), array_map(static fn($h): int|string => $h->id, $f->in('rcust')->query('szeged')->manyMatch('any')->get()->hits));

        try {
            $f->in('rcust')->query('szeged')->manyMatch('single', 'street')->get();
            self::fail('expected InvalidQuery');
        } catch (InvalidQuery $e) {
            self::assertSame('manyMatch(): field "street" is not a many field with rank: best. Fields with it: city.', $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** What the PostgreSQL engine passes to the database and how it shapes what comes back. */
final class PostgresEngineBehaviourTest extends TestCase
{
    private Connection $connection;
    private IndexDefinition $index;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->index = Indexes::products('manual');
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
    }

    private function spy(?\Closure $fetchAll = null, ?\Closure $fetchValue = null): InterceptingConnection
    {
        return new InterceptingConnection($this->connection, $fetchAll, $fetchValue);
    }

    private function fuzzphony(InterceptingConnection $connection, ?IndexDefinition $index = null): Fuzzphony
    {
        return new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index ?? $this->index]));
    }

    public function testExplainDoesNotRunTheStatementUnlessAskedTo(): void
    {
        $engine = new PostgresEngine($this->connection);

        $plan = $engine->explain($this->index, new SearchQuery('wireless mouse'))->plan;

        self::assertNotSame([], $plan);
        self::assertSame([], array_filter($plan, static fn(string $line): bool => str_contains($line, 'actual time')), 'a plain EXPLAIN has no timings');
        self::assertNotSame([], array_filter(
            $engine->explain($this->index, new SearchQuery('wireless mouse'), analyze: true)->plan,
            static fn(string $line): bool => str_contains($line, 'actual time'),
        ));
    }

    public function testRefreshingNoIdsAsksTheDatabaseNothing(): void
    {
        $spy = $this->spy();

        self::assertSame(0, (new PostgresEngine($spy))->refresh($this->index, []));
        self::assertSame([], $spy->log);
    }

    public function testTheKeysetCursorOfSourceIdsIsBoundAsText(): void
    {
        $spy = $this->spy();

        $ids = (new PostgresEngine($spy))->sourceIds($this->index, 3, 2);

        self::assertSame([4, 5], $ids);
        self::assertSame([['limit' => 2, 'after' => '3']], $spy->params('SELECT doc.fz_id'));
    }

    public function testPruningOrphansWalksTheSidecarInBatchesAndStopsAfterTheLastOne(): void
    {
        $this->connection->execute('DELETE FROM fz_product WHERE id IN (2, 4)');
        $spy = $this->spy();
        $engine = new PostgresEngine($spy);

        self::assertSame(2, $engine->pruneOrphans($this->index, 2));

        $batches = $spy->statements('WITH batch AS');
        self::assertCount(3, $batches, 'ids 1-2, 3-4 and the 5 that is left: a short batch is the last');
        self::assertSame([['limit' => 2], ['limit' => 2, 'after' => '2'], ['limit' => 2, 'after' => '4']], $spy->params('WITH batch AS'));

        $spy->reset();
        $engine->pruneOrphans($this->index);
        self::assertSame([['limit' => 5000]], array_slice($spy->params('WITH batch AS'), 0, 1), 'the default batch holds five thousand ids');
    }

    public function testThePrefixOfASuggestionIsMatchedLiterally(): void
    {
        $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');
        $this->connection->execute("INSERT INTO \"fuzzphony_products__vocab\" (word, freq) VALUES ('a_b%!x', 2), ('axbyzx', 5), ('a_b%zx', 4), ('a_bq!x', 1), ('a!_b%!y', 3)");
        // the normaliser would turn the three symbols into spaces; a normaliser that keeps them must not make them wildcards
        $spy = $this->spy(fetchValue: static fn(string $sql): ?array => str_contains($sql, 'fuzzphony_norm"(:p)') ? ['a_b%!'] : null);

        self::assertSame(['a_b%!x'], (new PostgresEngine($spy))->suggestWords($this->index, 'a_b%!', 10));
    }

    public function testAFacetOverAFilterThatCannotBeCountedIsRejected(): void
    {
        $search = $this->fuzzphony($this->spy())->in('products')->query('mouse');

        try {
            $search->facets('published_at')->get();
            self::fail('a datetime filter has no facet');
        } catch (InvalidQuery $e) {
            self::assertSame('Filter "published_at" is a datetime: facets count the values of string, int, bool and date filters.', $e->getMessage());
        }
        $this->expectException(InvalidQuery::class);
        $search->facets('no_such_filter')->get();
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutAStatement(): iterable
    {
        yield 'text with no word in it' => ['**********'];
        yield 'only excluded words' => ['-cable'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('answersWithoutAStatement')]
    public function testTheTimeOfAnAnswerWithoutAStatementIsMillisecondsWithMicrosecondResolution(string $text): void
    {
        $fuzzphony = $this->fuzzphony($this->spy());
        $fractional = false;
        $microseconds = false;

        for ($i = 0; $i < 40; ++$i) {
            $took = $fuzzphony->in('products')->query($text)->get()->tookMs;
            self::assertGreaterThanOrEqual(0.0, $took);
            self::assertLessThan(1_000.0, $took, 'milliseconds, not a difference of nanosecond clocks');
            $fractional = $fractional || fmod($took, 1.0) !== 0.0;
            $microseconds = $microseconds || round($took, 2) !== $took;
            self::assertSame(round($took, 3), $took, 'rounded to a microsecond');
        }

        self::assertTrue($fractional, 'a fraction of a millisecond is kept');
        self::assertTrue($microseconds, 'three decimals are kept');
    }

    public function testWordsThatEachMatchSomethingAreNotDroppedWhenTogetherTheyMatchNothing(): void
    {
        $explanation = $this->fuzzphony($this->spy())->in('products')->query('mouse headphones')->thresholds(['did_you_mean' => false])->explain();

        self::assertSame(0, $explanation->result->total);
        self::assertSame([], $explanation->result->warnings, 'nothing was ignored');
        self::assertContains('relaxation probe', array_column($explanation->statements, 'label'));
        self::assertSame([], array_filter(array_column($explanation->statements, 'label'), static fn(string $l): bool => str_starts_with($l, 'relaxed')));
    }

    public function testASingleWordIsNotProbedAndTheDatabaseNotAskedAboutStopWords(): void
    {
        $spy = $this->spy();

        $result = $this->fuzzphony($spy)->in('products')->query('zzzzzz')->thresholds(['fuzzy_mode' => 'never', 'did_you_mean' => false])->get();

        self::assertSame(0, $result->total);
        self::assertSame([], array_filter($spy->log, static fn(array $e): bool => str_contains($e[0], 'numnode(')), 'with one word there is nothing to relax');
    }

    public function testAnEmptyQueryBrowses(): void
    {
        $explanation = $this->fuzzphony($this->spy())->in('products')->query('')->explain();

        self::assertSame(['browse'], array_column($explanation->statements, 'label'));
    }

    public function testFacetValuesAreTypedByTheFilterWhateverTheDriverDelivers(): void
    {
        $facet = static fn(string $sql): bool => str_contains($sql, 'FROM fc GROUP BY v0');
        $spy = $this->spy(fetchAll: static fn(string $sql): ?array => $facet($sql) ? [
            ['f' => 0, 'value' => 't', 'n' => '3'], ['f' => 0, 'value' => '1', 'n' => 2], ['f' => 0, 'value' => 1, 'n' => 1], ['f' => 0, 'value' => 'f', 'n' => 1],
            ['f' => 0, 'value' => '0', 'n' => 1], ['f' => 0, 'value' => true, 'n' => 1], ['f' => 0, 'value' => false, 'n' => 1], ['f' => 0, 'value' => null, 'n' => 1],
        ] : null);

        $values = $this->fuzzphony($spy)->in('products')->query('')->facets('in_stock')->get()->facets['in_stock'];

        self::assertEquals(
            [new FacetValue(true, 3), new FacetValue(true, 2), new FacetValue(true, 1), new FacetValue(false, 1), new FacetValue(false, 1), new FacetValue(true, 1), new FacetValue(false, 1), new FacetValue(null, 1)],
            $values,
            'text "t" and "1", the number 1 and true are true; "f", "0" and false are false; no value stays null',
        );
        self::assertSame([true, true, true, false, false, true, false, null], array_map(static fn(FacetValue $v): bool|int|string|null => $v->value, $values));
    }

    public function testFacetValuesOfStringIntAndBoolFiltersIncludeTheDocumentsThatHaveNone(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_facet_null, fuzzphony_facet_null, fuzzphony_facet_null__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_facet_null (id integer PRIMARY KEY, name text NOT NULL, color text, flag boolean, qty integer)');
        $this->connection->execute("INSERT INTO fz_facet_null VALUES (1, 'a', 'red', true, 1), (2, 'b', NULL, NULL, NULL), (3, 'c', 'red', false, 2)");
        $index = IndexDefinition::builder('facet_null')->fromTable('fz_facet_null')->field('name', 'A')
            ->filter('color', 'string')->filter('flag', 'bool')->filter('qty', 'int')->sync('manual')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('facet_null');

        $facets = $fuzzphony->in('facet_null')->query('')->facets('color', 'flag', 'qty')->get()->facets;

        self::assertEquals([new FacetValue('red', 2), new FacetValue(null, 1)], $facets['color']);
        self::assertEquals([new FacetValue(false, 1), new FacetValue(true, 1), new FacetValue(null, 1)], $facets['flag']);
        self::assertEquals([new FacetValue(1, 1), new FacetValue(2, 1), new FacetValue(null, 1)], $facets['qty']);
        $this->connection->execute('DROP TABLE IF EXISTS fz_facet_null, fuzzphony_facet_null, fuzzphony_facet_null__vocab CASCADE');
    }

    public function testTheBatchSizeOfPruningIsAtLeastOne(): void
    {
        $this->connection->execute('DELETE FROM fz_product WHERE id = 2');
        $engine = new PostgresEngine($this->connection);

        self::assertSame(1, $engine->pruneOrphans($this->index, 1), 'one id per statement is a batch');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Batch size must be >= 1.');
        $engine->pruneOrphans($this->index, 0);
    }

    public function testRebuildingTheVocabularyOfAMissingTableSaysSo(): void
    {
        $this->connection->execute('DROP TABLE "fuzzphony_products__vocab"');

        try {
            (new PostgresEngine($this->connection))->rebuildVocabulary($this->index);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('"public"."fuzzphony_products__vocab" does not exist or this role may not write it.', $e->getMessage());
        }
    }

    public function testTheEngineItselfRejectsAFacetItCannotCount(): void
    {
        $engine = new PostgresEngine($this->connection);

        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "published_at" is a datetime');
        $engine->search($this->index, (new SearchQuery('mouse'))->facets('published_at'));
    }

    public function testAnExactCountThatTheDatabaseDoesNotAnswerCountsNothing(): void
    {
        $this->connection->execute("INSERT INTO fz_product SELECT i, 'Extra ' || i, NULL, 1, 100, true, 1, now() FROM generate_series(6, 14) AS i");
        (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->index])))->reindex('products');
        $spy = $this->spy(fetchAll: static fn(string $sql): ?array => str_starts_with($sql, 'SELECT count(*) AS total FROM') ? [] : null);

        $result = $this->fuzzphony($spy)->in('products')->query('')->thresholds(['candidate_limit' => 10])->limit(10)->exactCounts()->get();

        self::assertSame(0, $result->total, 'no row: no documents');
        self::assertFalse($result->totalIsLowerBound);
    }
}

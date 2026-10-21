<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The search transaction (one BEGIN / COMMIT around the typo-tolerant statements, PostgresEngine::inSearchTransaction()) against the
 * per-statement path it replaces, which a connection that is not TransactionAware still takes: same index, same database, and the
 * results must be identical.
 */
final class SearchTransactionScopeTest extends TestCase
{
    private const string WORD_SIMILARITY = 'pg_trgm.word_similarity_threshold';
    private const string SIMILARITY = 'pg_trgm.similarity_threshold';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, self::rows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual', tenant: true)]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
    }

    /**
     * The conformance fixtures with more products for the first brand, so a tenant's facets have something to count.
     *
     * @return array{brands: list<array{int, string}>, products: list<array{int, string, string, int, int, bool, float, string}>}
     */
    private static function rows(): array
    {
        $rows = EngineConformanceTestCase::fixtureRows();
        $rows['products'][] = [6, 'Wireless mouse mini', 'Small wireless mouse for travel', 1, 2_490, false, 2.0, '-30 days'];
        $rows['products'][] = [7, 'Wireless headphones pro', 'Studio headphones with cable', 1, 9_990, false, 4.0, '-5 days'];
        $rows['products'][] = [8, 'Wireless keyboard', 'Quiet wireless keyboard for the office', 1, 5_990, true, 3.0, '-1 days'];

        return $rows;
    }

    /** @return array<string, array{\Closure(SearchBuilder): SearchBuilder, bool, bool}> the search, whether typo tolerance runs, whether it finds something */
    public static function searches(): array
    {
        return [
            'typo, fallback' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('mouze'), true, true],
            'typo, always' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always']), true, true],
            'typo, did you mean' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('hedphones')->thresholds(['did_you_mean' => true]), true, true],
            'typo, facets' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('mouze')->facets('in_stock'), true, true],
            'typo, facet with its own condition' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('mouze')->where('in_stock', true)->facets('in_stock'), true, true],
            'typo, two facets' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('mouze')->facets('in_stock', 'price'), true, true],
            'relaxed' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('wireless mouse offfice'), true, true],
            'relaxed, facets' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('wireless mouse offfice')->facets('in_stock'), true, true],
            'strict only' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('mouse')->thresholds(['fallback_below' => 1, 'did_you_mean' => false]), false, true],
            'strict, facets' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('mouse')->thresholds(['fallback_below' => 1])->facets('in_stock'), false, true],
            'nothing found' => [static fn(SearchBuilder $s): SearchBuilder => $s->query('zzzzzzzz'), true, false],
        ];
    }

    /** @param \Closure(SearchBuilder): SearchBuilder $search */
    #[\PHPUnit\Framework\Attributes\DataProvider('searches')]
    public function testTheScopedEngineAndThePerStatementPathAgreeForATenantAndAnActor(\Closure $search, bool $typo, bool $finds): void
    {
        $scoped = new InterceptingConnection($this->connection);
        $plain = new NotTransactionAwareConnection($this->connection);
        $actor = new Actor(tenant: 1, user: 'ada');

        $expected = ResultSnapshot::of($search($this->in($plain)->asActor($actor))->get());
        $actual = ResultSnapshot::of($search($this->in($scoped)->asActor($actor))->get());

        self::assertSame($expected, $actual);
        // the comparison is of something: the path that was meant ran, and found what it should
        self::assertSame($typo, $actual['usedFuzzy'] === true || $actual['didYouMean'] !== null || $plain->count('set_config') > 0);
        self::assertSame($finds, $actual['hits'] !== []);
        $settings = count(array_filter($scoped->log, static fn(array $entry): bool => str_contains($entry[0], 'set_config')));
        if ($typo) {
            self::assertGreaterThan(0, $settings);
            self::assertLessThan($plain->count('set_config'), $settings, 'the scoped engine sets and puts back less');
        } else {
            self::assertSame(0, $settings);
            self::assertSame(0, $plain->count('set_config'));
        }
    }

    /** @param \Closure(SearchBuilder): SearchBuilder $search */
    #[\PHPUnit\Framework\Attributes\DataProvider('searches')]
    public function testASearchLeavesTheSessionsOwnSimilarityThresholdsAsTheyWere(\Closure $search, bool $typo, bool $finds): void
    {
        // the application's own values: the transaction-local settings of the search end with its COMMIT
        $this->connection->fetchValue("SELECT set_config('" . self::WORD_SIMILARITY . "', '0.55', false), set_config('" . self::SIMILARITY . "', '0.22', false)");

        $search($this->in(new InterceptingConnection($this->connection))->asActor(new Actor(tenant: 1)))->get();

        self::assertSame('0.55', $this->connection->fetchValue("SELECT current_setting('" . self::WORD_SIMILARITY . "', true)"));
        self::assertSame('0.22', $this->connection->fetchValue("SELECT current_setting('" . self::SIMILARITY . "', true)"));
    }

    private function in(Connection $connection): SearchBuilder
    {
        return (new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: true)])))->in('products');
    }
}

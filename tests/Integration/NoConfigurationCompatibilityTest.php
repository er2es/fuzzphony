<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FederatedHit;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The 0.9 compatibility guarantee: a Fuzzphony built with a no-op SearchGate (nothing configured) sends the
 * same statements, with the same parameters, and returns the same results as one built without a gate.
 */
final class NoConfigurationCompatibilityTest extends TestCase
{
    /**
     * @return array{Fuzzphony, InterceptingConnection, Fuzzphony, InterceptingConnection} without a gate, then with a no-op gate
     */
    private function pair(bool $tenant = false): array
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $registry = new IndexRegistry([Indexes::products('manual', tenant: $tenant)]);
        $setup = new Fuzzphony(new PostgresEngine($connection), $registry);
        $setup->schema()->apply($connection);
        $setup->reindex('products');

        $plainConnection = new InterceptingConnection($connection);
        $gatedConnection = new InterceptingConnection($connection);

        return [
            new Fuzzphony(new PostgresEngine($plainConnection), $registry),
            $plainConnection,
            new Fuzzphony(new PostgresEngine($gatedConnection), $registry, new SearchGate()),
            $gatedConnection,
        ];
    }

    /** @return iterable<string, array{\Closure(SearchBuilder): SearchBuilder}> */
    public static function searches(): iterable
    {
        yield 'plain' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('mouse')];
        yield 'typo tolerant' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('wirless mouze')];
        yield 'did you mean' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('hedphones')];
        yield 'filtered' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('mouse')->where('brand_id', 2)->where('in_stock', true)];
        yield 'faceted' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('mouse')->facets('brand_id', 'in_stock')];
        yield 'filter only' => [static fn(SearchBuilder $search): SearchBuilder => $search->where('price', '>', 1_000)->limit(2, 1)];
        yield 'as you type' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('wire')->asYouType()];
    }

    /** @param \Closure(SearchBuilder): SearchBuilder $shape */
    #[DataProvider('searches')]
    public function testANoOpGateChangesNothing(\Closure $shape): void
    {
        [$plain, $plainConnection, $gated, $gatedConnection] = $this->pair();

        $plainConnection->reset();
        $gatedConnection->reset();
        $expected = $shape($plain->in('products'))->get();
        $actual = $shape($gated->in('products'))->get();

        self::assertSame($expected->ids(), $actual->ids());
        self::assertSame($expected->total, $actual->total);
        self::assertSame($expected->usedFuzzy, $actual->usedFuzzy);
        self::assertSame($expected->didYouMean, $actual->didYouMean);
        self::assertEquals($expected->facets, $actual->facets);
        self::assertSame($plainConnection->log, $gatedConnection->log, 'the same statements with the same parameters, in the same number');
        self::assertNotSame([], $plainConnection->log);

        // Explain: the reproduced statements and the interpretation are the same too.
        $plainConnection->reset();
        $gatedConnection->reset();
        $explainedPlain = $shape($plain->in('products'))->explain();
        $explainedGated = $shape($gated->in('products'))->explain();

        self::assertSame($explainedPlain->statements, $explainedGated->statements);
        self::assertSame($explainedPlain->interpretedAs, $explainedGated->interpretedAs);
        self::assertSame($explainedPlain->result->ids(), $explainedGated->result->ids());
        self::assertSame($plainConnection->log, $gatedConnection->log);
    }

    public function testATenantScopedSearchIsUnchangedToo(): void
    {
        [$plain, $plainConnection, $gated, $gatedConnection] = $this->pair(tenant: true);

        $plainConnection->reset();
        $gatedConnection->reset();
        $expected = $plain->in('products')->query('mouse')->forTenant(1)->get();
        $actual = $gated->in('products')->query('mouse')->forTenant(1)->get();

        self::assertEqualsCanonicalizing([1, 4], $actual->ids());
        self::assertSame($expected->ids(), $actual->ids());
        self::assertSame($expected->total, $actual->total);
        self::assertSame($plainConnection->log, $gatedConnection->log);
    }

    public function testAFederatedSearchIsUnchangedToo(): void
    {
        [$plain, $plainConnection, $gated, $gatedConnection] = $this->pair();

        $plainConnection->reset();
        $gatedConnection->reset();
        $expected = $plain->federated()->index('products')->query('mouse')->get();
        $actual = $gated->federated()->index('products')->query('mouse')->get();

        self::assertNotSame([], $actual->hits);
        self::assertSame(array_map(static fn(FederatedHit $hit): int|string => $hit->hit->id, $expected->hits), array_map(static fn(FederatedHit $hit): int|string => $hit->hit->id, $actual->hits));
        self::assertSame($plainConnection->log, $gatedConnection->log);
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The 0.9 guards guarantee: a viewer the guards let see every field (a guard returning Access::all(), plus a rate limit
 * that is not reached) gets what an unguarded Fuzzphony returns, from the same statements with the same parameters:
 * the restricted path is taken only for a restricted viewer, and a guard adds no statement of its own.
 */
final class GuardsCompatibilityTest extends TestCase
{
    /** @var \ArrayObject<int, string> the guards that ran, in order */
    private \ArrayObject $ran;

    /**
     * @return array{Fuzzphony, InterceptingConnection, Fuzzphony, InterceptingConnection} unguarded, then guarded
     */
    private function pair(): array
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $registry = new IndexRegistry([Indexes::products('manual')]);
        $setup = new Fuzzphony(new PostgresEngine($connection), $registry);
        $setup->schema()->apply($connection);
        $setup->reindex('products');

        $this->ran = new \ArrayObject();
        $ran = $this->ran;
        $everything = new class ($ran) implements SearchGuard {
            /** @param \ArrayObject<int, string> $ran */
            public function __construct(private readonly \ArrayObject $ran) {}

            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                $this->ran[] = $index->name;

                return Access::all();
            }
        };
        $limit = new RateLimitGuard(new RateLimiterFactory(['id' => 'search', 'policy' => 'fixed_window', 'limit' => 1_000_000, 'interval' => '1 minute'], new InMemoryStorage()), 'user');

        $plainConnection = new InterceptingConnection($connection);
        $guardedConnection = new InterceptingConnection($connection);

        return [
            new Fuzzphony(new PostgresEngine($plainConnection), $registry),
            $plainConnection,
            new Fuzzphony(new PostgresEngine($guardedConnection), $registry, new SearchGate(guards: [$everything, $limit])),
            $guardedConnection,
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
        yield 'field scoped' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('name:mouse')];
        yield 'highlighted' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('mouse')->highlight('name')];
        yield 'as you type' => [static fn(SearchBuilder $search): SearchBuilder => $search->query('wire')->asYouType()];
    }

    /** @param \Closure(SearchBuilder): SearchBuilder $shape */
    #[DataProvider('searches')]
    public function testAGuardThatAllowsEverythingChangesNothing(\Closure $shape): void
    {
        [$plain, $plainConnection, $guarded, $guardedConnection] = $this->pair();
        $ada = new Actor(user: 'ada');

        $plainConnection->reset();
        $guardedConnection->reset();
        $expected = $shape($plain->in('products'))->get();
        $actual = $shape($guarded->in('products'))->asActor($ada)->get();

        self::assertSame(['products'], $this->ran->getArrayCopy(), 'the guards really ran for the actor');
        self::assertNotSame([], $plainConnection->log);
        self::assertSame($expected->ids(), $actual->ids());
        self::assertSame($expected->total, $actual->total);
        self::assertSame($expected->usedFuzzy, $actual->usedFuzzy);
        self::assertSame($expected->didYouMean, $actual->didYouMean);
        self::assertEquals($expected->facets, $actual->facets);
        self::assertEquals($expected->hits, $actual->hits, 'the scores, breakdowns and highlights too');
        self::assertSame($plainConnection->log, $guardedConnection->log, 'the same statements with the same parameters, in the same number: a guard adds none');

        // explain() runs the search too: the reproduced statements and the interpretation are the same as well.
        $plainConnection->reset();
        $guardedConnection->reset();
        $explainedPlain = $shape($plain->in('products'))->explain();
        $explainedGuarded = $shape($guarded->in('products'))->asActor($ada)->explain();

        self::assertSame($explainedPlain->statements, $explainedGuarded->statements);
        self::assertSame($explainedPlain->interpretedAs, $explainedGuarded->interpretedAs);
        self::assertSame($explainedPlain->result->ids(), $explainedGuarded->result->ids());
        self::assertSame(count($explainedPlain->plan) > 0, count($explainedGuarded->plan) > 0, 'an unrestricted viewer still gets the plan');
        self::assertSame($plainConnection->log, $guardedConnection->log);
    }

    public function testASearchWithoutAnActorRunsNoGuard(): void
    {
        [, , $guarded, $guardedConnection] = $this->pair();

        $guardedConnection->reset();
        $guarded->in('products')->query('mouse')->get();

        self::assertSame([], $this->ran->getArrayCopy());
        self::assertNotSame([], $guardedConnection->log);
    }
}

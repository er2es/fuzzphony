<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

/** The gate must survive every copy of a FederatedSearch and every way of getting a builder from Fuzzphony. */
final class FederatedGateTest extends TestCase
{
    private const array DENIED = [['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'actor_required']]];

    /** @return iterable<string, array{string}> the call that comes last before get() */
    public static function lastCalls(): iterable
    {
        yield 'index() last' => ['index'];
        yield 'query() last' => ['query'];
        yield 'limit() last' => ['limit'];
        yield 'page() last' => ['page'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lastCalls')]
    public function testTheGateSurvivesEveryCopyOfAFederatedSearch(string $last): void
    {
        $metrics = new RecordingMetricsCollector();
        $federated = new FederatedSearch($this->engine(), new IndexRegistry([Indexes::products(tenant: true)]), gate: new SearchGate($metrics));
        $configure = static fn(SearchBuilder $b): SearchBuilder => $b->asActor(new Actor(user: 'ada'));

        $federated = match ($last) {
            'index' => $federated->query('x')->limit(5)->page(2, 5)->index('products', 1.0, $configure),
            'query' => $federated->index('products', 1.0, $configure)->limit(5)->page(2, 5)->query('x'),
            'limit' => $federated->index('products', 1.0, $configure)->query('x')->page(2, 5)->limit(5),
            default => $federated->index('products', 1.0, $configure)->query('x')->limit(5)->page(2, 5),
        };

        try {
            $federated->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }
        self::assertSame(self::DENIED, $metrics->calls);
    }

    public function testFuzzphonyInGivesABuilderWithTheGate(): void
    {
        $metrics = new RecordingMetricsCollector();
        $fuzzphony = new Fuzzphony($this->engine(), new IndexRegistry([Indexes::products(tenant: true)]), new SearchGate($metrics));

        try {
            $fuzzphony->in('products')->asActor(Actor::anonymous())->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }
        self::assertSame(self::DENIED, $metrics->calls);
    }

    public function testFuzzphonyFederatedGivesASearchWithTheGate(): void
    {
        $metrics = new RecordingMetricsCollector();
        $fuzzphony = new Fuzzphony($this->engine(), new IndexRegistry([Indexes::products(tenant: true)]), new SearchGate($metrics));

        try {
            $fuzzphony->federated()->index('products', 1.0, static fn(SearchBuilder $b): SearchBuilder => $b->asActor(Actor::anonymous()))->query('x')->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }
        self::assertSame(self::DENIED, $metrics->calls);
    }

    private function engine(): Engine
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('search');

        return $engine;
    }
}

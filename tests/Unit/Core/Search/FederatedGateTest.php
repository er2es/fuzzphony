<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchResult;
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

    /** @return iterable<string, array{string}> */
    public static function unloggedOrders(): iterable
    {
        yield 'unlogged() first' => ['first'];
        yield 'unlogged() in the middle' => ['middle'];
        yield 'unlogged() last' => ['last'];
        yield 'unlogged() between index() and page()' => ['page'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unloggedOrders')]
    public function testUnloggedSurvivesEveryCopyAndLogsNoOkRow(string $order): void
    {
        $log = new RecordingSearchLog();
        $federated = new FederatedSearch($this->searchingEngine(), new IndexRegistry([Indexes::products(), self::articles()]), gate: new SearchGate(log: $log));

        $federated = match ($order) {
            'first' => $federated->unlogged()->index('products')->query('mouse')->limit(5)->page(2, 5)->index('articles'),
            'middle' => $federated->index('products')->unlogged()->query('mouse')->index('articles')->limit(5)->page(2, 5),
            'page' => $federated->index('products')->index('articles')->query('mouse')->unlogged()->page(2, 5),
            default => $federated->index('products')->query('mouse')->limit(5)->index('articles')->page(2, 5)->unlogged(),
        };
        $federated->get();

        self::assertSame([], $log->records);
    }

    public function testAFederatedSearchIsLoggedPerIndexByDefault(): void
    {
        $log = new RecordingSearchLog();
        $federated = new FederatedSearch($this->searchingEngine(), new IndexRegistry([Indexes::products(), self::articles()]), gate: new SearchGate(log: $log));

        $federated->index('products')->index('articles')->query('mouse')->get();

        self::assertSame(['products', 'articles'], array_map(static fn(SearchRecord $r): string => $r->index, $log->records));
    }

    public function testUnloggedIsImmutable(): void
    {
        $log = new RecordingSearchLog();
        $base = (new FederatedSearch($this->searchingEngine(), new IndexRegistry([Indexes::products()]), gate: new SearchGate(log: $log)))->index('products')->query('x');

        $base->unlogged();
        $base->get();

        self::assertCount(1, $log->records, 'the original keeps logging');
    }

    public function testTheConfigureClosureCanStillSwitchTheLogOnForOneIndex(): void
    {
        $log = new RecordingSearchLog();
        $federated = new FederatedSearch($this->searchingEngine(), new IndexRegistry([Indexes::products(), self::articles()]), gate: new SearchGate(log: $log));

        $seen = [];
        $federated->unlogged()->index('products', 1.0, static function (SearchBuilder $b) use (&$seen): SearchBuilder {
            $seen[] = $b->toQuery()->log;

            return $b;
        })->query('x')->get();

        self::assertSame([false], $seen, 'the builder handed to configure is already unlogged');
    }

    public function testAnUnloggedFederatedSearchRefusedByActorRequiredIsStillRecordedAsDenied(): void
    {
        $log = new RecordingSearchLog();
        $federated = new FederatedSearch(self::createStub(Engine::class), new IndexRegistry([Indexes::products(tenant: true)]), gate: new SearchGate(log: $log));

        try {
            $federated->index('products', 1.0, static fn(SearchBuilder $b): SearchBuilder => $b->asActor(new Actor(user: 'ada')))->query('x')->unlogged()->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }

        self::assertSame([RecordStatus::Denied], array_map(static fn(SearchRecord $r): RecordStatus => $r->status, $log->records));
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

    private static function articles(): IndexDefinition
    {
        return IndexDefinition::builder('articles')->fromTable('fz_article')->field('title', 'A')->build();
    }

    private function searchingEngine(): Engine
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturn(new SearchResult([], 0, false, 1.0, false, [], 20, 0));

        return $engine;
    }

    private function engine(): Engine
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('search');

        return $engine;
    }
}

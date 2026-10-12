<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

final class SearchGateTest extends TestCase
{
    public function testAnActorWithoutATenantIsRefusedOnATenantScopedIndexBeforeTheEngineIsAsked(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('search');
        $metrics = new RecordingMetricsCollector();
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true), gate: new SearchGate($metrics));

        try {
            $builder->query('mouse')->asActor(new Actor(user: 'ada'))->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired $e) {
            self::assertStringContainsString('"products"', $e->getMessage());
        }
        self::assertSame([['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'actor_required']]], $metrics->calls);
    }

    public function testExplainIsRefusedTheSameWay(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('explain');
        $metrics = new RecordingMetricsCollector();
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true), gate: new SearchGate($metrics));

        try {
            $builder->query('mouse')->asActor(Actor::anonymous())->explain();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }
        self::assertSame([['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'actor_required']]], $metrics->calls);
    }

    public function testTheSameRefusalWithoutAGate(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('search');
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true));

        $this->expectException(ActorRequired::class);
        $builder->query('mouse')->asActor(Actor::anonymous())->get();
    }

    public function testExplainWithoutAGateIsRefusedToo(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('explain');
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true));

        $this->expectException(ActorRequired::class);
        $builder->query('mouse')->asActor(Actor::anonymous())->explain();
    }

    public function testASearchWithoutAnActorKeepsTheOldBehaviourAndTheOldMessage(): void
    {
        // the engine throws it (tests/Integration/TenantScopingTest); the gate must not turn it into ActorRequired
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('search')->willThrowException(InvalidQuery::missingTenant('products'));
        $metrics = new RecordingMetricsCollector();
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true), gate: new SearchGate($metrics));

        try {
            $builder->query('mouse')->get();
            self::fail('InvalidQuery expected');
        } catch (InvalidQuery $e) {
            self::assertNotInstanceOf(ActorRequired::class, $e);
            self::assertSame('Index "products" requires forTenant(); none was given.', $e->getMessage());
        }
        self::assertSame([], $metrics->calls);
    }

    public function testASearchWithoutAnActorAndWithoutAGateKeepsTheOldBehaviour(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('search')->willThrowException(InvalidQuery::missingTenant('products'));
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true));

        $this->expectExceptionMessage('Index "products" requires forTenant(); none was given.');
        $builder->query('mouse')->get();
    }

    public function testAnActorWithATenantPassesThroughAndTheResultIsReturned(): void
    {
        $result = SearchResult::empty(20, 0);
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('search')->with(self::anything(), self::callback(static fn(SearchQuery $q): bool => $q->tenant === 3))->willReturn($result);
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true), gate: new SearchGate());

        self::assertSame($result, $builder->query('mouse')->asActor(new Actor(tenant: 3))->get());
    }

    public function testAnActorOnAnIndexWithoutATenantPassesThrough(): void
    {
        $result = SearchResult::empty(20, 0);
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('search')->willReturn($result);
        $builder = new SearchBuilder($engine, Indexes::products(), gate: new SearchGate());

        self::assertSame($result, $builder->query('mouse')->asActor(Actor::anonymous())->get());
    }

    public function testAGatedSearchThatPassesEmitsNoMetric(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('search')->willReturn(SearchResult::empty(20, 0));
        $metrics = new RecordingMetricsCollector();
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true), gate: new SearchGate($metrics));

        $builder->query('mouse')->asActor(new Actor(tenant: 3))->get();

        self::assertSame([], $metrics->calls);
    }

    public function testTheRefusalIsLogged(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('refused'), self::callback(static fn(array $c): bool => $c['index'] === 'products'));
        $builder = new SearchBuilder(self::createStub(Engine::class), Indexes::products(tenant: true), gate: new SearchGate(logger: $logger));

        $this->expectException(ActorRequired::class);
        $builder->query('mouse')->asActor(Actor::anonymous())->get();
    }
}

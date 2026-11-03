<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** One rule for every entry point: an actor with a tenant never searches (or logs for) another tenant; asSystem() is the way across. */
final class TenantCrossingTest extends TestCase
{
    private RecordingSearchLog $log;

    private function fuzzphony(?Actor $ambient = null): Fuzzphony
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturn(new SearchResult([], 1, false, 1.0, false, [], 20, 0));
        $this->log = new RecordingSearchLog();
        $resolver = $ambient === null ? null : new class ($ambient) implements ActorResolver {
            public function __construct(private Actor $actor) {}

            public function resolve(): Actor
            {
                return $this->actor;
            }
        };

        return new Fuzzphony($engine, new IndexRegistry([Indexes::products(tenant: true)]), new SearchGate(log: $this->log, actors: $resolver));
    }

    public function testAnExplicitActorCannotSearchAnotherTenantInEitherOrder(): void
    {
        $f = $this->fuzzphony();
        foreach ([
            static fn(SearchBuilder $b): SearchBuilder => $b->asActor(new Actor(tenant: 3, user: 'ada'))->forTenant(9),
            static fn(SearchBuilder $b): SearchBuilder => $b->forTenant(9)->asActor(new Actor(tenant: 3, user: 'ada')),
        ] as $make) {
            try {
                $make($f->in('products')->query('mouse'))->get();
                self::fail('the search crossed into another tenant');
            } catch (AccessDenied $e) {
                self::assertStringNotContainsString('9', $e->getMessage(), 'the refusal names no tenant');
            }
        }
    }

    public function testTheAmbientActorCannotBeSteeredToAnotherTenantByForTenant(): void
    {
        $f = $this->fuzzphony(new Actor(tenant: 3, user: 'ada'));

        $this->expectException(AccessDenied::class);
        $f->in('products')->query('mouse')->forTenant(9)->get();
    }

    public function testTheOtherEntryPointsRefuseToo(): void
    {
        $f = $this->fuzzphony(new Actor(tenant: 3, user: 'ada'));
        $builder = $f->in('products')->query('mouse')->forTenant(9);
        foreach ([
            'explain' => static fn() => $builder->explain(),
            'getUncharged' => static fn() => $builder->getUncharged(),
            'federated' => static fn() => $f->federated()->index('products', configure: static fn($b) => $b->forTenant(9))->query('mouse')->get(),
            'logSearch' => static fn() => $f->logSearch('products', 'mouse', 1, actor: new Actor(tenant: 3, user: 'ada'), forTenant: 9),
            'logSearch ambient' => static fn() => $f->logSearch('products', 'mouse', 1, forTenant: 9),
        ] as $name => $call) {
            try {
                $call();
                self::fail($name . ' crossed into another tenant');
            } catch (AccessDenied) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->log->records === [] ? [] : array_filter($this->log->records, static fn($r) => $r->status->value === 'ok'), 'nothing was recorded as a successful search');
    }

    public function testTheActorsOwnTenantAnAnonymousActorAndTheSystemStillWork(): void
    {
        $f = $this->fuzzphony();

        self::assertSame(1, $f->in('products')->query('mouse')->asActor(new Actor(tenant: 3))->forTenant(3)->get()->total);
        self::assertSame(1, $f->in('products')->query('mouse')->forTenant(9)->asActor(Actor::anonymous())->get()->total, 'an actor without a tenant leaves the named one alone');
        self::assertSame(1, $f->in('products')->query('mouse')->asSystem()->forTenant(9)->get()->total, 'asSystem() is the explicit way across');
        self::assertSame(1, $f->in('products')->query('mouse')->asActor(new Actor(tenant: 3))->asActor(new Actor(tenant: 4))->get()->total, 'the tenant a previous actor brought is replaced');
        $logged = $f->logSearch('products', 'mouse', 1, system: true, forTenant: 9);
        self::assertSame('9', $logged->tenant);
    }
}

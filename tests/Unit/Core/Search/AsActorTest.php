<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class AsActorTest extends TestCase
{
    public function testTheActorsTenantScopesATenantScopedIndex(): void
    {
        $query = $this->builder(tenant: true)->asActor(new Actor(tenant: 5, user: 'ada'))->toQuery();

        self::assertSame(5, $query->tenant);
        self::assertSame('ada', $query->actor?->user);
    }

    public function testTheTenantIsIgnoredOnAnIndexThatIsNotTenantScoped(): void
    {
        // an application-wide resolver must not break the searches of the other indexes
        $query = $this->builder(tenant: false)->asActor(new Actor(tenant: 5, user: 'ada'))->toQuery();

        self::assertNull($query->tenant);
        self::assertSame('ada', $query->actor?->user);
    }

    public function testTheLastOfForTenantAndAsActorWins(): void
    {
        $a = $this->builder(tenant: true)->forTenant(1)->asActor(new Actor(tenant: 2))->toQuery();
        $b = $this->builder(tenant: true)->asActor(new Actor(tenant: 2))->forTenant(1)->toQuery();

        self::assertSame(2, $a->tenant);
        self::assertSame(1, $b->tenant);
    }

    public function testAnActorWithoutATenantNeverClearsOne(): void
    {
        $query = $this->builder(tenant: true)->forTenant(9)->asActor(Actor::anonymous())->toQuery();

        self::assertSame(9, $query->tenant);
        self::assertNotNull($query->actor);
    }

    private function builder(bool $tenant): SearchBuilder
    {
        return new SearchBuilder(self::createStub(Engine::class), Indexes::products(tenant: $tenant));
    }
}

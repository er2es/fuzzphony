<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
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

    public function testAnIntegerTenantIsCastForAStringTenantFilter(): void
    {
        // a resolver reading account.id (an int) for an index whose tenant column is a string
        $query = $this->stringTenantBuilder()->asActor(new Actor(tenant: 42))->toQuery();

        self::assertSame('42', $query->tenant);
        self::assertSame(42, $query->actor?->tenant, 'the actor itself is untouched');
    }

    public function testAStringTenantOnAStringFilterStaysAsItIs(): void
    {
        self::assertSame('acme', $this->stringTenantBuilder()->asActor(new Actor(tenant: 'acme'))->toQuery()->tenant);
    }

    public function testAnIntegerTenantOnAnIntegerFilterStaysAnInteger(): void
    {
        self::assertSame(5, $this->builder(tenant: true)->asActor(new Actor(tenant: 5))->toQuery()->tenant);
    }

    public function testAStringTenantOnAnIntegerFilterStaysAString(): void
    {
        // the filter's own normalization accepts a digits string for an Int filter
        self::assertSame('5', $this->builder(tenant: true)->asActor(new Actor(tenant: '5'))->toQuery()->tenant);
    }

    private function builder(bool $tenant): SearchBuilder
    {
        return new SearchBuilder(self::createStub(Engine::class), Indexes::products(tenant: $tenant));
    }

    private function stringTenantBuilder(): SearchBuilder
    {
        $index = IndexDefinition::builder('docs')
            ->fromQuery('SELECT id, title, org FROM doc')
            ->watch('doc')
            ->field('title', 'A')
            ->filter('org', 'string')
            ->tenant('org')
            ->build();

        return new SearchBuilder(self::createStub(Engine::class), $index);
    }
}

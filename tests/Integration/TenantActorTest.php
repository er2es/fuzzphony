<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The actor scopes a tenant-scoped index end to end (same fixtures as TenantScopingTest). */
final class TenantActorTest extends TestCase
{
    private function fuzzphony(): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products(tenant: true)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testASearchAsAnActorSeesOnlyItsTenant(): void
    {
        $fuzzphony = $this->fuzzphony();

        // Brand 1 (Logitech) has products 1 and 4; brand 2 (Razer) has product 2. All three mention "mouse".
        $mine = $fuzzphony->in('products')->query('mouse')->asActor(new Actor(tenant: 1, user: 'ada'))->get();
        $theirs = $fuzzphony->in('products')->query('mouse')->asActor(new Actor(tenant: 2))->get();

        self::assertEqualsCanonicalizing([1, 4], $mine->ids());
        self::assertEqualsCanonicalizing([2], $theirs->ids());
        self::assertSame([], array_intersect($mine->ids(), $theirs->ids()));
    }

    public function testAnActorWithoutATenantIsRefused(): void
    {
        $this->expectException(ActorRequired::class);

        $this->fuzzphony()->in('products')->query('mouse')->asActor(new Actor(user: 'ada'))->get();
    }

    public function testTheOldMessageSurvivesWithoutAnActor(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('requires forTenant()');

        $this->fuzzphony()->in('products')->query('mouse')->get();
    }
}

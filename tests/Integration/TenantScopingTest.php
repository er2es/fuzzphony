<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** A search must never return another tenant's rows, and must never silently skip the scope. */
final class TenantScopingTest extends TestCase
{
    private function fuzzphony(bool $tenant): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products(tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testSearchNeverReturnsAnotherTenantsRows(): void
    {
        // Brand 1 (Logitech) has products 1 and 4; brand 2 (Razer) has product 2. All three mention "mouse".
        $ids = $this->fuzzphony(tenant: true)->in('products')->forTenant(1)->query('mouse')->get()->ids();

        self::assertEqualsCanonicalizing([1, 4], $ids);
    }

    public function testOmittingForTenantThrowsBeforeAnySqlRuns(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Index "products" requires forTenant(); none was given.');

        $this->fuzzphony(tenant: true)->in('products')->query('mouse')->get();
    }

    public function testNonTenantScopedIndexIsUnaffected(): void
    {
        $ids = $this->fuzzphony(tenant: false)->in('products')->query('mouse')->get()->ids();

        self::assertEqualsCanonicalizing([1, 2, 4], $ids);
    }

    public function testDoctorReportsTenantScoping(): void
    {
        $fuzzphony = $this->fuzzphony(tenant: true);

        $messages = array_column($fuzzphony->inspect('products')->checks, 'message', 'name');

        self::assertSame('enforced via filter "brand_id"', $messages['Tenant scoping'] ?? null);
    }
}

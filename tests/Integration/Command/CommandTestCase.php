<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;

/** @internal Shared setup: a real Fuzzphony instance (real Postgres, "products" index) for CommandTester tests. */
final class CommandTestCase
{
    public readonly Connection $connection;
    public readonly PostgresEngine $engine;
    public readonly Fuzzphony $fuzzphony;

    public function __construct()
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_products CASCADE');

        $this->engine = new PostgresEngine($this->connection);
        $this->fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([Indexes::products('manual')]));
    }

    public function applySchemaAndReindex(): void
    {
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('products');
    }
}

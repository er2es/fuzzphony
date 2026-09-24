<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;

final class PostgresConformanceTest extends EngineConformanceTestCase
{
    protected function createConnection(): Connection
    {
        return PostgresTestCase::connect();
    }

    protected function createEngine(Connection $connection): Engine
    {
        return new PostgresEngine($connection);
    }

    protected function createFixtureTables(Connection $connection): void
    {
        PostgresTestCase::createFixtures($connection, self::fixtureRows());
    }
}

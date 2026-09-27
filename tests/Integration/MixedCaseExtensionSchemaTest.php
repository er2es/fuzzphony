<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** unaccent in a schema whose name needs quoting ("FzExt"): the normaliser still finds its dictionary. */
final class MixedCaseExtensionSchemaTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP SCHEMA IF EXISTS fz_mixed CASCADE');
        $this->connection->execute('CREATE SCHEMA IF NOT EXISTS "FzExt"');
        $this->connection->execute('ALTER EXTENSION unaccent SET SCHEMA "FzExt"');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('ALTER EXTENSION unaccent SET SCHEMA public');
        $this->connection->execute('DROP SCHEMA IF EXISTS fz_mixed CASCADE');
        $this->connection->execute('DROP SCHEMA IF EXISTS "FzExt"');
    }

    public function testTheNormaliserWorksWithAMixedCaseExtensionSchema(): void
    {
        (new PostgresSchemaGenerator(new Names('FzExt', 'fz_mixed')))->global(Indexes::products())->apply($this->connection);

        self::assertSame('arvizturo tukorfurogep', $this->connection->fetchValue("SELECT fz_mixed.fuzzphony_norm('Árvíztűrő  TÜKÖRFÚRÓGÉP!')"));
    }
}

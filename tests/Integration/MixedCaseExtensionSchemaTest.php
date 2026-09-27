<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Support\Coerce;
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
        $this->connection->execute('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public');
        $this->connection->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
        $this->connection->execute('CREATE SCHEMA IF NOT EXISTS "FzExt"');
        $this->connection->execute('ALTER EXTENSION unaccent SET SCHEMA "FzExt"');
    }

    /** Tolerant: a failing setUp() or test must never leave unaccent stranded or "FzExt" behind. */
    protected function tearDown(): void
    {
        $inFzExt = $this->connection->fetchValue(<<<'SQL'
            SELECT count(*) FROM pg_extension
            JOIN pg_namespace ON pg_namespace.oid = pg_extension.extnamespace
            WHERE pg_extension.extname = 'unaccent' AND pg_namespace.nspname = 'FzExt'
            SQL);
        if (Coerce::int($inFzExt) > 0) {
            $this->connection->execute('ALTER EXTENSION unaccent SET SCHEMA public');
        }
        $this->connection->execute('DROP SCHEMA IF EXISTS fz_mixed CASCADE');
        $this->connection->execute('DROP SCHEMA IF EXISTS "FzExt"');
    }

    public function testTheNormaliserWorksWithAMixedCaseExtensionSchema(): void
    {
        (new PostgresSchemaGenerator(new Names('FzExt', 'fz_mixed')))->global(Indexes::products())->apply($this->connection);

        self::assertSame('arvizturo tukorfurogep', $this->connection->fetchValue("SELECT fz_mixed.fuzzphony_norm('Árvíztűrő  TÜKÖRFÚRÓGÉP!')"));
    }
}

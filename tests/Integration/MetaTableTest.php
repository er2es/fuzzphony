<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Integration\Command\CommandTestCase;
use PHPUnit\Framework\TestCase;

/** fuzzphony_meta: apply records the layout and definition, a full reindex the documents, the doctor compares. */
final class MetaTableTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase(); // fixtures reset, fuzzphony_meta dropped, "products" (manual sync)
    }

    public function testApplyWritesTheRowAndOnlyAFullReindexTheDocumentsHash(): void
    {
        $index = $this->context->fuzzphony->registry()->get('products');
        $this->context->fuzzphony->schema()->apply($this->context->connection);

        $row = $this->row('products');
        self::assertSame(PostgresSchemaGenerator::LAYOUT_VERSION, Coerce::int($row['layout_version']));
        self::assertSame(Fingerprint::definition($index), $row['definition_hash']);
        self::assertNull($row['documents_hash']);
        self::assertNull($row['reindexed_at']);
        self::assertNotSame('', Coerce::str($row['library_version']));
        self::assertSame(1, Coerce::int($this->row('*')['layout_version']));

        $this->context->fuzzphony->reindex('products', new ReindexOptions(resumeAfter: 2));
        self::assertNull($this->row('products')['documents_hash'], 'a resumed run does not cover every document');

        $this->context->fuzzphony->reindex('products');
        $row = $this->row('products');
        self::assertSame(Fingerprint::documents($index), $row['documents_hash']);
        self::assertNotNull($row['reindexed_at']);
    }

    public function testTheDoctorReportsEachKindOfDrift(): void
    {
        $this->context->applySchemaAndReindex();
        self::assertSame(['Schema version' => CheckStatus::Ok, 'Definition' => CheckStatus::Ok, 'Documents' => CheckStatus::Ok], $this->statuses());

        $this->context->connection->execute("UPDATE fuzzphony_meta SET definition_hash = 'x', documents_hash = NULL WHERE index_name = 'products'");
        self::assertSame(['Schema version' => CheckStatus::Ok, 'Definition' => CheckStatus::Error, 'Documents' => CheckStatus::Warning], $this->statuses());
        self::assertSame('bin/console fuzzphony:reindex products', $this->check('Documents')->fix);

        $this->context->connection->execute("UPDATE fuzzphony_meta SET layout_version = 0 WHERE index_name = 'products'");
        self::assertSame("Layout 0 is older than this library's layout 1.", $this->check('Schema version')->message);
        self::assertSame(CheckStatus::Error, $this->check('Schema version')->status);

        $this->context->connection->execute("UPDATE fuzzphony_meta SET layout_version = 99, library_version = '9.0.0' WHERE index_name = 'products'");
        self::assertSame('Layout 99 was applied by a newer Fuzzphony (9.0.0); this library knows layout 1.', $this->check('Schema version')->message);
        self::assertSame('Upgrade fuzzphony/fuzzphony to 9.0.0 or later.', $this->check('Schema version')->fix);

        $this->context->connection->execute("DELETE FROM fuzzphony_meta WHERE index_name = 'products'");
        self::assertSame(['Schema version' => CheckStatus::Warning], $this->statuses());
        self::assertSame('No version record: built before 0.4, or never applied.', $this->check('Schema version')->message);

        $this->context->connection->execute('DROP TABLE fuzzphony_meta');
        self::assertSame(['Schema version' => CheckStatus::Warning], $this->statuses(), 'an install from before 0.4');
    }

    public function testARoleThatCannotReadTheVersionTableGetsAWarningInsteadOfAFailure(): void
    {
        $this->context->applySchemaAndReindex();
        $connection = $this->context->connection;
        $role = 'fz_doctor_nometa_' . getmypid(); // roles are cluster-wide; parallel (Infection) runs must not share one
        $connection->execute('DROP ROLE IF EXISTS ' . $role);
        $connection->execute('CREATE ROLE ' . $role);
        try {
            $connection->execute('GRANT SELECT ON ALL TABLES IN SCHEMA public TO ' . $role);
            $connection->execute('REVOKE SELECT ON fuzzphony_meta FROM ' . $role);
            $connection->execute('SET ROLE ' . $role);
            $checks = $this->context->fuzzphony->inspect('products')->checks;
        } finally {
            $connection->execute('RESET ROLE');
            $connection->execute('DROP OWNED BY ' . $role);
            $connection->execute('DROP ROLE ' . $role);
        }

        $versionChecks = array_values(array_filter($checks, static fn(Check $c): bool => in_array($c->name, ['Schema version', 'Definition', 'Documents'], true)));
        self::assertCount(1, $versionChecks);
        self::assertSame(CheckStatus::Warning, $versionChecks[0]->status);
        self::assertSame('Role ' . $role . ' cannot read "public"."fuzzphony_meta" (no SELECT privilege), so the version is unknown.', $versionChecks[0]->message);
        self::assertSame('GRANT SELECT ON "public"."fuzzphony_meta" TO "' . $role . '";', $versionChecks[0]->fix);
    }

    public function testDropForgetsTheRowAndWorksWithoutTheTable(): void
    {
        $this->context->applySchemaAndReindex();
        $plan = $this->context->engine->dropSchema($this->context->fuzzphony->registry()->get('products'));

        $plan->apply($this->context->connection);
        self::assertSame([], $this->context->connection->fetchAll("SELECT 1 FROM fuzzphony_meta WHERE index_name = 'products'"));
        self::assertNotSame([], $this->context->connection->fetchAll("SELECT 1 FROM fuzzphony_meta WHERE index_name = '*'"), 'the shared row stays');

        $this->context->connection->execute('DROP TABLE fuzzphony_meta');
        $plan->apply($this->context->connection); // an install from before 0.4
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_meta')"));
    }

    public function testAReindexBeforeTheFirstApplyDoesNotFail(): void
    {
        $this->context->applySchemaAndReindex();
        $this->context->connection->execute('DROP TABLE fuzzphony_meta'); // a 0.3 install, upgraded, not yet applied

        self::assertSame(5, $this->context->fuzzphony->reindex('products')->written);
    }

    /** @return array<string, mixed> */
    private function row(string $index): array
    {
        return $this->context->connection->fetchAll('SELECT * FROM fuzzphony_meta WHERE index_name = :index', ['index' => $index])[0]
            ?? self::fail(sprintf('no meta row for "%s"', $index));
    }

    /** @return array<string, CheckStatus> */
    private function statuses(): array
    {
        $statuses = [];
        foreach ($this->context->fuzzphony->inspect('products')->checks as $check) {
            if (in_array($check->name, ['Schema version', 'Definition', 'Documents'], true)) {
                $statuses[$check->name] = $check->status;
            }
        }

        return $statuses;
    }

    private function check(string $name): Check
    {
        return array_find($this->context->fuzzphony->inspect('products')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}

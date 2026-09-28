<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Integration\Command\CommandTestCase;
use PHPUnit\Framework\TestCase;

/** schema --apply upgrades an index built with an older sidecar layout (the step runner). */
final class LayoutUpgradeTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase(); // fixtures reset, "products" (manual sync)
        $this->context->applySchemaAndReindex();
    }

    public function testALayout1IndexIsUpgradedOnceAndAsksForAReindex(): void
    {
        $connection = $this->context->connection;
        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1 WHERE index_name IN ('products', '*')");
        self::assertNotNull($this->row()['documents_hash'], 'the full reindex recorded its documents');
        self::assertSame(CheckStatus::Error, $this->check('Schema version')->status);

        $this->context->fuzzphony->schema()->apply($connection);

        $row = $this->row();
        self::assertSame(PostgresSchemaGenerator::LAYOUT_VERSION, Coerce::int($row['layout_version']));
        self::assertNull($row['documents_hash'], 'the step cleared it');
        self::assertSame(CheckStatus::Ok, $this->check('Schema version')->status);
        self::assertSame(CheckStatus::Ok, $this->check('Shared objects')->status);
        self::assertSame(CheckStatus::Warning, $this->check('Documents')->status);

        $this->context->fuzzphony->reindex('products');
        $this->context->fuzzphony->schema()->apply($connection);

        self::assertSame(Fingerprint::documents($this->context->fuzzphony->registry()->get('products')), $this->row()['documents_hash'], 'a second apply runs no step');
        self::assertSame(CheckStatus::Ok, $this->check('Documents')->status);
    }

    public function testTheStepRunsNothingWithoutAVersionRowOrTable(): void
    {
        $connection = $this->context->connection;
        $steps = array_values(array_filter(
            $this->context->engine->indexSchema($this->context->fuzzphony->registry()->get('products'))->statements,
            static fn(Statement $s): bool => str_starts_with($s->description, 'Layout step'),
        ));
        self::assertCount(1, $steps);

        $connection->execute("DELETE FROM fuzzphony_meta WHERE index_name = 'products'");
        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1, documents_hash = 'kept' WHERE index_name = '*'");
        $connection->execute($steps[0]->sql);
        self::assertSame('kept', $connection->fetchValue("SELECT documents_hash FROM fuzzphony_meta WHERE index_name = '*'"), 'only the index row is ever touched');

        $connection->execute('DROP TABLE fuzzphony_meta');
        $connection->execute($steps[0]->sql); // a pre-0.4 install: no table, no step, no error
        self::assertNull($connection->fetchValue("SELECT to_regclass('fuzzphony_meta')"));
    }

    public function testALayout1SidecarGetsThePerFieldColumns(): void
    {
        $connection = $this->context->connection;
        $connection->execute('ALTER TABLE fuzzphony_products DROP COLUMN t_name, DROP COLUMN z_name, DROP COLUMN t_brand, DROP COLUMN z_brand, DROP COLUMN t_description');
        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1 WHERE index_name IN ('products', '*')");
        self::assertSame(CheckStatus::Error, $this->check('Sidecar columns')->status);

        $this->context->fuzzphony->schema()->apply($connection);

        self::assertSame(CheckStatus::Ok, $this->check('Sidecar columns')->status);
        self::assertSame('', $connection->fetchValue('SELECT z_brand FROM fuzzphony_products WHERE id = 2'), 'empty until the reindex');
        self::assertSame(CheckStatus::Warning, $this->check('Documents')->status);

        $this->context->fuzzphony->reindex('products');

        self::assertSame('razer', $connection->fetchValue('SELECT z_brand FROM fuzzphony_products WHERE id = 2'));
        self::assertSame([2], $this->context->fuzzphony->in('products')->query('brand:razer')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return $this->context->connection->fetchAll("SELECT * FROM fuzzphony_meta WHERE index_name = 'products'")[0]
            ?? self::fail('no meta row for "products"');
    }

    private function check(string $name): Check
    {
        return array_find($this->context->fuzzphony->inspect('products')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** Regression tests of the 0.12.6 review findings (shared watch positions, sync function names, explain and hidden field names). */
final class ReviewFixesV0127Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_product, fz_brand, t, public_t, rf_note, fuzzphony_products, fuzzphony_products__vocab, fuzzphony_products__next, fuzzphony_products__changes, fuzzphony_coll, fuzzphony_coll__vocab, fuzzphony_rf_notes, fuzzphony_rf_notes__vocab, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    /** @param list<array{string, ?string}> $watches table, affected-ids query */
    private function products(array $watches): Fuzzphony
    {
        $builder = IndexDefinition::builder('products')->fromTable('fz_product')->field('name', 'A')->sync('queue');
        foreach ($watches as [$table, $ids]) {
            $ids === null ? $builder->watch($table) : $builder->watch($table, $ids);
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->build()]));
        $fuzzphony->schema()->apply($this->connection);

        return $fuzzphony;
    }

    private function drained(): int
    {
        return PostgresTestCase::drain($this->connection, (new IndexRegistry([IndexDefinition::builder('products')->fromTable('fz_product')->field('name', 'A')->sync('queue')->build()]))->get('products'));
    }

    /** @return list<array{list<array{string, ?string}>, list<array{string, ?string}>}> */
    public static function watchChanges(): array
    {
        $brand = ['fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id'];
        $product = ['fz_product', null];

        return [
            'swapped' => [[$brand, $product], [$product, $brand]],
            'the first of two removed' => [[$brand, $product], [$product]],
            'one inserted before' => [[$product], [$brand, $product]],
        ];
    }

    /**
     * @param list<array{string, ?string}> $before
     * @param list<array{string, ?string}> $after
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('watchChanges')]
    public function testAChangeLoggedButNotDrainedSurvivesAChangeOfTheWatchOrder(array $before, array $after): void
    {
        PostgresTestCase::createFixtures($this->connection, ['brands' => [[1, 'Acme']], 'products' => [[1, 'Mouse', null, 1, 10, true, 1.0, '2024-01-01']]]);
        $fuzzphony = $this->products($before);
        $fuzzphony->reindex('products');
        $this->connection->execute("UPDATE fz_product SET name = 'Zyxquorp' WHERE id = 1");

        $this->products($after);

        self::assertSame(1, $this->drained(), 'the logged change is still read after the watches moved');
        self::assertSame('1', Coerce::str($this->connection->fetchValue('SELECT doc_id FROM fuzzphony_queue')));
    }

    public function testTwoWatchedTablesThatDifferOnlyByASchemaDotKeepTheirOwnSyncFunction(): void
    {
        $this->connection->execute('CREATE TABLE t (id int PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE public_t (id int PRIMARY KEY, t_id int NOT NULL)');
        $definition = IndexDefinition::builder('coll')->fromTable('public.t')->field('name', 'A')->sync('trigger')->triggerLevel('row')
            ->watch('public.t')->watch('public_t', 'SELECT t_id FROM public_t WHERE id = :id')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
        $fuzzphony->schema()->apply($this->connection);

        $this->connection->execute("INSERT INTO t VALUES (7, 'zebra')");

        self::assertSame(1, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_coll WHERE id = 7')));
        self::assertSame(2, Coerce::int($this->connection->fetchValue("SELECT count(DISTINCT p.oid) FROM pg_proc p WHERE p.proname LIKE 'fuzzphony_sync_coll__%'")));
    }

    private function notes(): Fuzzphony
    {
        $this->connection->execute('CREATE TABLE rf_note (id int PRIMARY KEY, title text NOT NULL, secret text NOT NULL, tenant_id int NOT NULL)');
        $this->connection->execute("INSERT INTO rf_note SELECT i, 'merger note ' || i, 'salary', 1 FROM generate_series(1, 30) AS i");
        $this->connection->execute("INSERT INTO rf_note SELECT i, 'merger deal ' || i, 'salary', 2 FROM generate_series(100, 400) AS i");
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('rf_notes')->fromTable('rf_note')->field('title', 'A')->field('secret', 'B')->filter('tenant_id', 'int')->tenant('tenant_id')->sync('manual')->build(),
        ]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('rf_notes');

        return $fuzzphony;
    }

    public function testATenantViewerGetsNoPlanOfATenantScopedIndex(): void
    {
        $fuzzphony = $this->notes();

        $tenant = $fuzzphony->in('rf_notes')->asActor(new Actor(tenant: 1))->query('merger')->explain(true);
        $system = $fuzzphony->in('rf_notes')->forTenant(1)->query('merger')->asSystem()->explain(false);

        self::assertCount(1, $tenant->plan);
        self::assertStringContainsString('No plan', $tenant->plan[0]);
        self::assertNotSame([], $tenant->statements);
        self::assertGreaterThan(1, count($system->plan), 'the system actor still sees the plan');
    }

    public function testARestrictedViewerGetsTheSameWarningForAHiddenAndAnUnknownField(): void
    {
        $fuzzphony = $this->notes();

        $hidden = $fuzzphony->in('rf_notes')->asActor(new Actor(tenant: 1))->restrictedToFields('title')->query('secret:salary')->get();
        $unknown = $fuzzphony->in('rf_notes')->asActor(new Actor(tenant: 1))->restrictedToFields('title')->query('nosuch:salary')->get();

        self::assertSame(['Unknown field "secret"; searched in all fields instead.'], $hidden->warnings);
        self::assertSame(['Unknown field "nosuch"; searched in all fields instead.'], $unknown->warnings);
    }
}

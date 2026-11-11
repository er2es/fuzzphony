<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use PHPUnit\Framework\TestCase;

/** Review fixes of many: { rank: best }: the count expression keeps the child key index usable, the value count never goes stale, foreign indexes survive an apply. */
final class ManyBestHardeningTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->drop();
        $this->connection->execute('CREATE TABLE hd_customer (id integer PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE hd_address (id serial PRIMARY KEY, customer_id integer NOT NULL, city text)');
        $this->connection->execute('CREATE INDEX hd_address_customer ON hd_address (customer_id)');
        $this->connection->execute("INSERT INTO hd_customer (id, name) VALUES (1, 'Kiss Anna'), (2, 'Nagy Bela')");
        $this->connection->execute("INSERT INTO hd_address (customer_id, city) VALUES (1, 'Budapest'), (1, 'Szeged'), (2, 'Szeged')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_hcust, fuzzphony_hcust__next, fuzzphony_hcust__changes, fuzzphony_hcust__vocab, fuzzphony_hcust__values, hd_address, hd_customer, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    private function definition(string $rank): IndexDefinition
    {
        return IndexDefinition::builder('hcust')->fromTable('hd_customer')
            ->field('name', 'A')
            ->field('city', 'C', many: ['from' => 'hd_address', 'key' => 'customer_id', 'column' => 'city', 'rank' => $rank])
            ->sync('queue')
            ->build();
    }

    private function install(string $rank, bool $reindex = false): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$this->definition($rank)]));
        $fuzzphony->schema()->apply($this->connection);
        if ($reindex) {
            $fuzzphony->reindex('hcust');
        }

        return $fuzzphony;
    }

    private function hasCountColumn(): bool
    {
        return $this->connection->fetchAll("SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('fuzzphony_hcust') AND attname = 'n_city' AND NOT attisdropped") !== [];
    }

    private function doctor(Fuzzphony $fuzzphony): string
    {
        $out = [];
        foreach ($fuzzphony->inspect('hcust')->checks as $check) {
            if (str_starts_with($check->name, 'Values ranking')) {
                $out[] = $check->status->value . ': ' . $check->message;
            }
        }

        return implode("\n", $out);
    }

    public function testTheCountExpressionHasNoCastOnTheChildKeyColumn(): void
    {
        $generator = new PostgresSchemaGenerator(new Names());
        $sql = implode("\n", array_map(static fn($s): string => $s->sql, $generator->index($this->definition('best'))->statements));

        self::assertStringContainsString('c."customer_id" = doc.fz_id', $sql);
        self::assertStringNotContainsString('"customer_id"::bigint = doc.fz_id', $sql);
    }

    public function testTheCountIsStillRightForAnIntegerKey(): void
    {
        $this->install('best', true);

        self::assertSame([2, 1], array_map(Coerce::int(...), array_column($this->connection->fetchAll('SELECT n_city FROM fuzzphony_hcust ORDER BY id'), 'n_city')));
    }

    public function testLeavingBestDropsTheCountAndApplyIsIdempotent(): void
    {
        $this->install('best', true);
        self::assertTrue($this->hasCountColumn());

        $this->install('whole');
        $this->install('whole');
        self::assertFalse($this->hasCountColumn());
    }

    public function testASwitchBackNeverTrustsAnOldCount(): void
    {
        $this->install('best', true);
        $this->install('whole');
        $this->connection->execute("INSERT INTO hd_address (customer_id, city) VALUES (2, 'Debrecen')");
        (new Worker($this->engine))->runOnce([$this->definition('whole')]);
        $this->install('best');

        self::assertSame([null, null], array_column($this->connection->fetchAll('SELECT n_city FROM fuzzphony_hcust ORDER BY id'), 'n_city'));
        self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_hcust__values WHERE doc = 2')));
    }

    public function testAFullReindexUnderWholeEndsInTheSameState(): void
    {
        $this->install('best', true);
        $this->install('whole', true);

        self::assertFalse($this->hasCountColumn());
    }

    public function testTheDoctorFindsAStaleCountOfAFieldThatIsNotBest(): void
    {
        $fuzzphony = $this->install('whole', true);
        $this->connection->execute('ALTER TABLE fuzzphony_hcust ADD COLUMN n_city smallint');

        self::assertStringContainsString('warning: The column n_city', $this->doctor($fuzzphony));
    }

    public function testTheDoctorFindsACountThatDiffersFromTheValues(): void
    {
        $fuzzphony = $this->install('best', true);
        self::assertStringNotContainsString('differ', $this->doctor($fuzzphony));
        $this->connection->execute('UPDATE fuzzphony_hcust SET n_city = 1 WHERE id = 1');

        self::assertStringContainsString('warning: The stored value counts (n_city)', $this->doctor($fuzzphony));
    }

    public function testApplyKeepsAnIndexThatIsNotOursOnTheValuesTable(): void
    {
        $this->install('best', true);
        $this->connection->execute('CREATE INDEX best_buy ON fuzzphony_hcust__values (ord)');
        $this->connection->execute('CREATE INDEX owner_idx_0a1b2c3d ON fuzzphony_hcust__values (v)');
        $this->connection->execute("CREATE INDEX fuzzphony_hcust__values_best_gone ON fuzzphony_hcust__values USING gin (t) WHERE field = 'gone'");

        $this->install('best');
        $names = array_column($this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_hcust__values'"), 'indexname');

        self::assertContains('best_buy', $names);
        self::assertContains('owner_idx_0a1b2c3d', $names);
        self::assertNotContains('fuzzphony_hcust__values_best_gone', $names);
        self::assertContains('fuzzphony_hcust__values_best_city', $names);
    }
}

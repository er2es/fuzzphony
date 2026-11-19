<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A fresh install in every topology: the apply leaves a clean doctor, a second apply changes nothing in the catalog, searches
 * find the fixture rows, and a write to a source table reaches the index (worker or trigger).
 */
#[Group('hardening')]
final class FreshInstallTopologyTest extends TestCase
{
    private Connection $c;

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
    }

    protected function tearDown(): void
    {
        $this->c->execute('RESET search_path');
    }

    /** @return iterable<string, array{string, string, TriggerLevel}> */
    public static function topologies(): iterable
    {
        foreach (['public', 'fz_h_own'] as $schema) {
            yield "queue, {$schema}" => [$schema, 'queue', TriggerLevel::Statement];
            yield "queue row level, {$schema}" => [$schema, 'queue', TriggerLevel::Row];
            yield "trigger statement level, {$schema}" => [$schema, 'trigger', TriggerLevel::Statement];
            yield "trigger row level, {$schema}" => [$schema, 'trigger', TriggerLevel::Row];
        }
    }

    private static function products(string $sync, TriggerLevel $level, string $name = 'products', string $table = 'fz_product'): IndexDefinition
    {
        $b = IndexDefinition::builder($name)
            ->fromQuery(sprintf('SELECT p.id, p.name, p.description, b.name AS brand, p.price FROM %s p JOIN fz_brand b ON b.id = p.brand_id', $table))
            ->watch($table)
            ->watch('fz_brand', sprintf('SELECT id FROM %s WHERE brand_id = :id', $table))
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'D')
            ->filter('price', 'int')
            ->language('english')
            ->sync($sync);
        if ($sync !== 'manual') {
            $b->triggerLevel($level);
        }

        return $b->build();
    }

    private function follow(Fuzzphony $f, PostgresEngine $engine, IndexDefinition ...$indexes): void
    {
        (new Worker($engine))->runOnce(array_values($indexes));
    }

    /** @return list<int|string> */
    private function ids(Fuzzphony $f, string $index, string $query): array
    {
        return $f->in($index)->query($query)->get()->ids();
    }

    /** @param list<IndexDefinition> $indexes */
    private function installTwice(Fuzzphony $f, array $indexes): void
    {
        $f->schema()->apply($this->c);
        $before = Harness::catalog($this->c);
        $f->schema()->apply($this->c);
        self::assertSame($before, Harness::catalog($this->c), 'a second apply changes nothing in the catalog');
        foreach ($indexes as $index) {
            $f->reindex($index->name);
        }
        $after = Harness::catalog($this->c);
        $f->schema()->apply($this->c);
        self::assertSame($after, Harness::catalog($this->c), 'an apply after the reindex changes nothing in the catalog');
        self::assertSame([], Harness::invalidIndexes($this->c));
    }

    #[DataProvider('topologies')]
    public function testFreshInstall(string $schema, string $sync, TriggerLevel $level): void
    {
        Harness::fixtures($this->c);
        $index = self::products($sync, $level);
        $engine = new PostgresEngine($this->c, 'public', $schema, searchLog: true);
        $f = new Fuzzphony($engine, new \Fuzzphony\Core\Registry\IndexRegistry([$index]));

        $this->installTwice($f, [$index]);

        self::assertSame([], Harness::problems($f, 'products'));
        self::assertContains(1, $this->ids($f, 'products', 'mouse'));
        self::assertContains(3, $this->ids($f, 'products', 'headphnoes'));

        $this->c->execute("INSERT INTO fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        $this->c->execute("UPDATE fz_brand SET name = 'Quokkabrand' WHERE id = 1");
        $this->follow($f, $engine, $index);
        self::assertSame([900], $this->ids($f, 'products', 'zebrafish'));
        self::assertContains(900, $this->ids($f, 'products', 'quokkabrand'));
        self::assertSame([], Harness::problems($f, 'products'));
    }

    /** @return iterable<string, array{string}> */
    public static function schemas(): iterable
    {
        yield 'public' => ['public'];
        yield 'own schema' => ['fz_h_own'];
    }

    #[DataProvider('schemas')]
    public function testTwoIndexesOverOneTableQueueAndTrigger(string $schema): void
    {
        Harness::fixtures($this->c);
        $a = self::products('queue', TriggerLevel::Statement, 'products');
        $b = self::products('trigger', TriggerLevel::Row, 'products_b');
        $engine = new PostgresEngine($this->c, 'public', $schema, searchLog: true);
        $f = new Fuzzphony($engine, new \Fuzzphony\Core\Registry\IndexRegistry([$a, $b]));

        $this->installTwice($f, [$a, $b]);
        self::assertSame([], Harness::problems($f, 'products'));
        self::assertSame([], Harness::problems($f, 'products_b'));

        $this->c->execute("INSERT INTO fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        self::assertSame([900], $this->ids($f, 'products_b', 'zebrafish'), 'trigger mode: at once');
        $this->follow($f, $engine, $a, $b);
        self::assertSame([900], $this->ids($f, 'products', 'zebrafish'));
        $this->c->execute('DELETE FROM fz_product WHERE id = 900');
        $this->follow($f, $engine, $a, $b);
        self::assertSame([], $this->ids($f, 'products', 'zebrafish'));
        self::assertSame([], $this->ids($f, 'products_b', 'zebrafish'));
        self::assertSame([], Harness::problems($f, 'products'));
        self::assertSame([], Harness::problems($f, 'products_b'));
    }

    /** @return iterable<string, array{string, TriggerLevel}> */
    public static function modes(): iterable
    {
        yield 'queue' => ['queue', TriggerLevel::Statement];
        yield 'queue row' => ['queue', TriggerLevel::Row];
        yield 'trigger statement' => ['trigger', TriggerLevel::Statement];
        yield 'trigger row' => ['trigger', TriggerLevel::Row];
    }

    #[DataProvider('modes')]
    public function testPartitionedSourceAndAPartitionAttachedLater(string $sync, TriggerLevel $level): void
    {
        Harness::fixtures($this->c);
        $this->c->execute('CREATE TABLE fz_part (id bigint NOT NULL, name text NOT NULL, description text, brand_id bigint NOT NULL, price integer NOT NULL, PRIMARY KEY (id)) PARTITION BY RANGE (id)');
        $this->c->execute('CREATE TABLE fz_part_a PARTITION OF fz_part FOR VALUES FROM (1) TO (100)');
        $this->c->execute("INSERT INTO fz_part VALUES (1, 'Wireless mouse', 'office', 1, 10)");
        $index = self::products($sync, $level, 'products', 'fz_part');
        $engine = new PostgresEngine($this->c, 'public', 'fz_h_own', searchLog: true);
        $f = new Fuzzphony($engine, new \Fuzzphony\Core\Registry\IndexRegistry([$index]));
        $this->installTwice($f, [$index]);
        // by design: a statement-level trigger cannot go on a partition, so the doctor warns about direct partition writes
        $expected = $level === TriggerLevel::Statement ? ['Warning Partitions of fz_part: Writes that target a partition directly are not synced (statement-level triggers cannot go on partitions); use trigger_level: row, or write through the parent.'] : [];
        self::assertSame($expected, Harness::problems($f, 'products'));

        $this->c->execute('CREATE TABLE fz_part_b (LIKE fz_part INCLUDING ALL)');
        $this->c->execute("INSERT INTO fz_part_b VALUES (150, 'Zebrafish lamp', 'aquarium', 1, 10)");
        $this->c->execute('ALTER TABLE fz_part ATTACH PARTITION fz_part_b FOR VALUES FROM (100) TO (200)');
        $doctor = implode("\n", Harness::problems($f, 'products'));
        $f->schema()->apply($this->c);
        self::assertSame($expected, Harness::problems($f, 'products'), 'after the apply that follows the attach: ' . $doctor);
        $this->c->execute("INSERT INTO fz_part VALUES (160, 'Zebrafish bowl', 'aquarium', 1, 10)");
        $this->c->execute("UPDATE fz_part SET name = 'Zebrafish mouse' WHERE id = 1");
        $this->follow($f, $engine, $index);
        $f->reindex('products');
        self::assertEqualsCanonicalizing([1, 150, 160], $this->ids($f, 'products', 'zebrafish'));
        $this->c->execute('TRUNCATE fz_part_b');
        $this->follow($f, $engine, $index);
        self::assertSame([1], $this->ids($f, 'products', 'zebrafish'));
    }

    #[DataProvider('modes')]
    public function testMultiSourceIndex(string $sync, TriggerLevel $level): void
    {
        $this->c->execute('CREATE TABLE ms_product (id bigint PRIMARY KEY, name text NOT NULL)');
        $this->c->execute('CREATE TABLE ms_article (id uuid PRIMARY KEY, headline text NOT NULL)');
        $this->c->execute("INSERT INTO ms_product VALUES (1, 'Wireless mouse')");
        $this->c->execute("INSERT INTO ms_article VALUES ('00000000-0000-0000-0000-000000000001', 'Mouse review')");
        $index = IndexDefinition::builder('site')
            ->source('product', 'SELECT id, name AS title FROM ms_product', watch: [new Watch('ms_product')])
            ->source('article', 'SELECT id, headline AS title FROM ms_article', watch: [new Watch('ms_article')], idType: 'uuid')
            ->field('title', 'A', fuzzy: true)
            ->sync($sync)
            ->triggerLevel($level)
            ->build();
        $engine = new PostgresEngine($this->c, 'public', 'fz_h_own', searchLog: true);
        $f = new Fuzzphony($engine, new \Fuzzphony\Core\Registry\IndexRegistry([$index]));
        $this->installTwice($f, [$index]);
        self::assertSame([], Harness::problems($f, 'site'));
        self::assertCount(2, $this->ids($f, 'site', 'mouse'));

        $this->c->execute("INSERT INTO ms_article VALUES ('00000000-0000-0000-0000-000000000002', 'Zebrafish care')");
        $this->follow($f, $engine, $index);
        self::assertCount(1, $this->ids($f, 'site', 'zebrafish'));
        self::assertSame([], Harness::problems($f, 'site'));
    }

    /** The source tables in an application schema the role's search_path names; Fuzzphony in its own schema or in public. */
    /** @return iterable<string, array{string, string}> */
    public static function searchPaths(): iterable
    {
        foreach (['public', 'fz_h_own'] as $schema) {
            yield "{$schema}, app schema then public" => [$schema, 'fz_h_app, public'];
            yield "{$schema}, app schema alone" => [$schema, 'fz_h_app'];
            yield "{$schema}, user schema first" => [$schema, '"$user", fz_h_app'];
        }
    }

    #[DataProvider('searchPaths')]
    public function testSourceTablesFoundThroughTheSearchPath(string $schema, string $path): void
    {
        $this->c->execute('CREATE SCHEMA fz_h_app');
        $this->c->execute('SET search_path TO ' . $path);
        Harness::fixtures($this->c);
        self::assertNotNull($this->c->fetchValue("SELECT to_regclass('fz_h_app.fz_product')"));
        $index = Indexes::products('trigger');
        $engine = new PostgresEngine($this->c, 'public', $schema, searchLog: true);
        $f = new Fuzzphony($engine, new \Fuzzphony\Core\Registry\IndexRegistry([$index]));
        $this->installTwice($f, [$index]);
        self::assertSame([], Harness::problems($f, 'products'));

        // another session whose search_path does not name the application schema: writes and searches still work
        $this->c->execute('SET search_path TO public');
        $this->c->execute("INSERT INTO fz_h_app.fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        self::assertSame([900], $this->ids($f, 'products', 'zebrafish'));
        $this->c->execute('SET search_path TO ' . $path);
        self::assertSame([], Harness::problems($f, 'products'));
    }
}

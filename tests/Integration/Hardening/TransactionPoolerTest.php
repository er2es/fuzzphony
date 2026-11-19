<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A transaction-pooling simulation: before every statement outside a transaction the session is reset (DISCARD ALL: no
 * setting, prepared statement or temporary table survives), as when each statement lands on another server connection. The
 * apply, the doctor, the worker and searches must not rely on session state.
 */
#[Group('hardening')]
final class TransactionPoolerTest extends TestCase
{
    private Connection $c;
    private int $discards = 0;

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
    }

    private function pooled(): Connection
    {
        $inner = $this->c;
        $counted = function (): void {
            ++$this->discards;
        };

        return new class ($inner, $counted) implements Connection {
            public function __construct(private readonly Connection $inner, private readonly \Closure $counted) {}

            private function reset(): void
            {
                if (!$this->inner->inTransaction()) {
                    $this->inner->execute('DISCARD ALL');
                    ($this->counted)();
                }
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->reset();

                return $this->inner->fetchAll($sql, $params);
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->reset();

                return $this->inner->fetchValue($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->reset();

                return $this->inner->execute($sql, $params);
            }

            public function transactional(callable $callback): mixed
            {
                $this->reset();

                return $this->inner->transactional(fn(): mixed => $callback($this));
            }

            public function inTransaction(): bool
            {
                return $this->inner->inTransaction();
            }
        };
    }

    /** @return iterable<string, array{string, TriggerLevel, string}> */
    public static function modes(): iterable
    {
        yield 'queue, own schema' => ['queue', TriggerLevel::Statement, 'fz_h_own'];
        yield 'queue row, public' => ['queue', TriggerLevel::Row, 'public'];
        yield 'trigger, own schema' => ['trigger', TriggerLevel::Statement, 'fz_h_own'];
    }

    #[DataProvider('modes')]
    public function testNothingReliesOnSessionState(string $sync, TriggerLevel $level, string $schema): void
    {
        $pooled = $this->pooled();
        $index = Indexes::products($sync);
        if ($level === TriggerLevel::Row) {
            $index = \Fuzzphony\Core\Definition\IndexDefinition::builder('products')
                ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
                ->watch('fz_product')
                ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id')
                ->field('name', 'A', fuzzy: true)
                ->field('brand', 'B', fuzzy: true)
                ->sync($sync)
                ->triggerLevel($level)
                ->build();
        }
        $engine = new PostgresEngine($pooled, 'public', $schema, searchLog: true);
        $f = new \Fuzzphony\Core\Fuzzphony($engine, new \Fuzzphony\Core\Registry\IndexRegistry([$index]));

        $f->schema()->apply($pooled);
        $f->schema()->apply($pooled);
        $f->reindex('products', new \Fuzzphony\Core\Sync\ReindexOptions(inPlace: true)); // docs: the swap holds a session lock, so in place behind a pooler
        self::assertSame([], Harness::invalidIndexes($this->c));
        self::assertSame([], Harness::problems($f, 'products'));

        $pooled->execute("INSERT INTO fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        (new Worker($engine))->runOnce([$index]);
        self::assertSame([900], $f->in('products')->query('zebrafish')->get()->ids());
        self::assertContains(3, $f->in('products')->query('headphnoes')->get()->ids());
        self::assertSame([], Harness::problems($f, 'products'));
        self::assertGreaterThan(20, $this->discards);
    }

    public function testAMultiSourceAndAReturnableIndexApplyBehindAPooler(): void
    {
        $pooled = $this->pooled();
        $site = \Fuzzphony\Core\Definition\IndexDefinition::builder('site')
            ->source('product', 'SELECT id, name AS title FROM fz_product', watch: [new \Fuzzphony\Core\Definition\Watch('fz_product')])
            ->source('brand', 'SELECT id, name AS title FROM fz_brand', watch: [new \Fuzzphony\Core\Definition\Watch('fz_brand')])
            ->field('title', 'A', fuzzy: true)
            ->sync('queue')
            ->build();
        $products = Indexes::products('trigger')->withReturnable(['name']);
        $f = new \Fuzzphony\Core\Fuzzphony(new PostgresEngine($pooled, 'public', 'fz_h_own'), new \Fuzzphony\Core\Registry\IndexRegistry([$site, $products]));

        $f->schema()->apply($pooled); // the plan probes the sources' columns through a temporary view
        $f->reindex('site', new \Fuzzphony\Core\Sync\ReindexOptions(inPlace: true));
        $f->reindex('products', new \Fuzzphony\Core\Sync\ReindexOptions(inPlace: true));
        self::assertSame([], Harness::problems($f, 'site'));
        self::assertStringContainsStringIgnoringCase('mouse', \Fuzzphony\Core\Support\Coerce::str($f->in('products')->query('mouse')->with('name')->get()->hits[0]->data['name'] ?? ''));
    }

    public function testTheDoctorStillFindsAnUnwatchedTableBehindAPooler(): void
    {
        $pooled = $this->pooled();
        $index = \Fuzzphony\Core\Definition\IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->field('name', 'A')
            ->field('brand', 'B')
            ->sync('trigger')
            ->build();
        $f = new \Fuzzphony\Core\Fuzzphony(new PostgresEngine($pooled, 'public', 'fz_h_own'), new \Fuzzphony\Core\Registry\IndexRegistry([$index]));
        $f->schema()->apply($pooled);
        $f->reindex('products', new \Fuzzphony\Core\Sync\ReindexOptions(inPlace: true));

        self::assertNotSame([], array_filter(Harness::problems($f, 'products'), static fn(string $p): bool => str_contains($p, 'Watches') && str_contains($p, 'fz_brand')), implode("\n", Harness::problems($f, 'products')));
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Applies racing each other, writers, a draining worker and a zero-downtime reindex, each in its own PHP process
 * (concurrent-child.php): no write fails because of an apply, two applies end in the catalog of one, and the index matches the
 * source afterwards.
 */
#[Group('hardening')]
#[Group('slow')]
final class ConcurrentApplyTest extends TestCase
{
    private Connection $c;
    private string $go;

    public static function index(string $sync): IndexDefinition
    {
        return Indexes::products($sync);
    }

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
        $this->go = sys_get_temp_dir() . '/fz-h-go-' . getmypid();
        @unlink($this->go);
    }

    protected function tearDown(): void
    {
        @unlink($this->go);
    }

    /** @return array{0: resource, 1: resource} the process and its stdout */
    private function spawn(string $action, string $sync, string $schema, float $seconds = 0): array
    {
        $env = getenv();
        $env['FUZZPHONY_TEST_DSN'] = PostgresTestCase::dsn();
        $env['FZ_GO_FILE'] = $this->go;
        $process = proc_open([PHP_BINARY, __DIR__ . '/concurrent-child.php', $action, $sync, $schema, (string) $seconds], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);

        return [$process, $pipes[1]];
    }

    /**
     * @param list<array{0: resource, 1: resource}> $children
     *
     * @return list<array{ok: bool, error: string, errors: int, count: int}>
     */
    private function wait(array $children): array
    {
        $results = [];
        foreach ($children as [$process, $out]) {
            $text = (string) stream_get_contents($out);
            proc_close($process);
            $lines = array_values(array_filter(explode("\n", trim($text))));
            $decoded = json_decode((string) end($lines), true);
            self::assertIsArray($decoded, 'child output: ' . $text);
            $results[] = ['ok' => (bool) $decoded['ok'], 'error' => Coerce::str($decoded['error']), 'errors' => Coerce::int($decoded['errors']), 'count' => Coerce::int($decoded['count'])];
        }

        return $results;
    }

    private function go(): void
    {
        usleep(300000); // the children have connected
        touch($this->go);
    }

    private function f(string $sync, string $schema): Fuzzphony
    {
        return Harness::fuzzphony($this->c, [self::index($sync)], $schema);
    }

    /** The sidecar holds exactly the source's ids. */
    private function assertInSync(string $schema): void
    {
        $sidecar = (new Names('public', $schema))->sidecar(self::index('queue'));
        $diff = Coerce::int($this->c->fetchValue(sprintf('SELECT count(*) FROM ((SELECT id FROM fz_product EXCEPT SELECT id FROM %1$s) UNION ALL (SELECT id FROM %1$s EXCEPT SELECT id FROM fz_product)) AS d', $sidecar)));
        self::assertSame(0, $diff, 'the sidecar holds exactly the source rows');
    }

    /** @return iterable<string, array{string, string}> */
    public static function modes(): iterable
    {
        yield 'queue, public' => ['queue', 'public'];
        yield 'trigger, own schema' => ['trigger', 'fz_h_own'];
    }

    #[DataProvider('modes')]
    public function testThreeAppliesAtOnceOnAFreshDatabaseTakeTurns(string $sync, string $schema): void
    {
        $f = $this->f($sync, $schema);
        $f->schema()->apply($this->c);
        $reference = Harness::catalog($this->c);
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);

        $children = [$this->spawn('apply', $sync, $schema), $this->spawn('apply', $sync, $schema), $this->spawn('apply', $sync, $schema)];
        $this->go();
        $results = $this->wait($children);

        foreach ($results as $r) {
            self::assertSame('', $r['error'], 'the applies take turns, none fails on a duplicate catalog entry');
        }
        $f = $this->f($sync, $schema);
        $f->schema()->apply($this->c);
        self::assertSame([], Harness::invalidIndexes($this->c));
        self::assertSame($reference, Harness::catalog($this->c));
        $f->reindex('products');
        self::assertSame([], Harness::problems($f, 'products'));
    }

    #[DataProvider('modes')]
    public function testApplyWhileWritersWriteAndAWorkerDrains(string $sync, string $schema): void
    {
        $f = $this->f($sync, $schema);
        $f->schema()->apply($this->c);
        $f->reindex('products');
        $children = [$this->spawn('write', $sync, $schema, 4), $this->spawn('write', $sync, $schema, 4)];
        if ($sync === 'queue') {
            $children[] = $this->spawn('drain', $sync, $schema, 4);
        }
        $this->go();
        $names = new Names('public', $schema);
        for ($i = 0; $i < 4; ++$i) {
            // something for the apply to do each time: a sidecar index to build again and the trigger function to replace
            $this->c->execute(sprintf('DROP INDEX IF EXISTS %s', $names->index('fuzzphony_products__tsv')));
            $f->schema()->apply($this->c);
            usleep(200000);
        }
        $results = $this->wait($children);
        foreach ($results as $r) {
            self::assertSame(0, $r['errors'], 'no write or drain failed because of an apply: ' . $r['error']);
            self::assertGreaterThan(0, $r['count']);
        }
        (new Worker(new PostgresEngine($this->c, 'public', $schema, searchLog: true)))->runOnce([self::index($sync)]);
        $this->assertInSync($schema);
        self::assertSame([], Harness::invalidIndexes($this->c));
        self::assertSame([], Harness::problems($f, 'products'));
    }

    #[DataProvider('modes')]
    public function testApplyDuringAZeroDowntimeReindex(string $sync, string $schema): void
    {
        $this->c->execute("INSERT INTO fz_product SELECT g, 'Bulk lamp ' || g, 'bulk', 1, 10, true, 1, now() FROM generate_series(1000, 40000) AS g");
        $f = $this->f($sync, $schema);
        $f->schema()->apply($this->c);
        $f->reindex('products');
        $children = [$this->spawn('reindex', $sync, $schema), $this->spawn('write', $sync, $schema, 3)];
        $this->go();
        usleep(500000);
        $names = new Names('public', $schema);
        $this->c->execute(sprintf('DROP INDEX IF EXISTS %s', $names->index('fuzzphony_products__tsv')));
        $applied = '';
        try {
            $f->schema()->apply($this->c);
        } catch (\Throwable $e) {
            $applied = $e->getMessage();
        }
        [$reindex, $writer] = $this->wait($children);
        self::assertSame(0, $writer['errors'], $writer['error']);
        self::assertTrue($reindex['ok'] || $applied === '', 'the reindex or the apply finishes: ' . $reindex['error'] . ' / ' . $applied);

        $f->schema()->apply($this->c);
        (new Worker(new PostgresEngine($this->c, 'public', $schema, searchLog: true)))->runOnce([self::index($sync)]);
        $f->reindex('products');
        $this->assertInSync($schema);
        self::assertSame([], Harness::invalidIndexes($this->c));
        self::assertSame([], Harness::problems($f, 'products'), 'reindex: ' . $reindex['error'] . ' / apply: ' . $applied);
    }
}

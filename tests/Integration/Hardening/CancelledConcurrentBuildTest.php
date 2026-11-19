<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * An apply waits for a concurrent build of another session on the sidecar; that build is cancelled and leaves its index INVALID.
 * The waiting apply drops it and builds it again instead of taking it for built.
 */
#[Group('hardening')]
final class CancelledConcurrentBuildTest extends TestCase
{
    private Connection $c;

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
    }

    protected function tearDown(): void
    {
        Harness::cleanup();
    }

    /** @return array{0: resource, 1: resource} */
    private function spawn(string $action, string $sql = ''): array
    {
        $env = getenv();
        $env['FUZZPHONY_TEST_DSN'] = PostgresTestCase::dsn();
        $env['FZ_GO_FILE'] = '';
        $env['FZ_SQL'] = $sql;
        $process = proc_open([PHP_BINARY, __DIR__ . '/concurrent-child.php', $action, 'queue', 'public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);

        return [$process, $pipes[1]];
    }

    /** @param array{0: resource, 1: resource} $child */
    private function errorOf(array $child): string
    {
        $text = trim((string) stream_get_contents($child[1]));
        proc_close($child[0]);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'child output: ' . $text);

        return Coerce::str($decoded['error']);
    }

    private function until(string $sql, string $what): void
    {
        for ($i = 0; $i < 200 && $this->c->fetchValue($sql) !== true; ++$i) {
            usleep(50000);
        }
        self::assertTrue($this->c->fetchValue($sql), $what);
    }

    public function testAnApplyRebuildsTheIndexABuildItWaitedForLeftInvalid(): void
    {
        $f = Harness::fuzzphony($this->c, [Indexes::products('queue')]);
        $f->schema()->apply($this->c);
        $this->c->execute('DROP INDEX fuzzphony_products__tsv');

        // a writer holds the build in its first phase: the index is in the catalog, INVALID, and the build is in progress
        $writer = PostgresTestCase::connect();
        $writer->execute('BEGIN');
        $writer->execute('LOCK TABLE fuzzphony_products IN ROW EXCLUSIVE MODE');
        $build = $this->spawn('sql', 'CREATE INDEX CONCURRENTLY fuzzphony_products__tsv ON fuzzphony_products USING gin (tsv)');
        $this->until("SELECT EXISTS (SELECT 1 FROM pg_stat_progress_create_index WHERE relid = 'fuzzphony_products'::regclass)", 'the build started');

        $apply = $this->spawn('apply');
        $this->until("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE query LIKE 'SELECT EXISTS (SELECT 1 FROM pg_stat_progress_create_index WHERE relid%' AND pid <> pg_backend_pid())", 'the apply waits');
        $this->c->execute("SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE query LIKE 'CREATE INDEX CONCURRENTLY fuzzphony_products__tsv%'");
        self::assertStringContainsString('cancel', $this->errorOf($build));
        $writer->execute('ROLLBACK');

        self::assertSame('', $this->errorOf($apply));
        self::assertSame([], Harness::invalidIndexes($this->c));
        self::assertTrue($this->c->fetchValue("SELECT EXISTS (SELECT 1 FROM pg_index WHERE indexrelid = to_regclass('fuzzphony_products__tsv') AND indisvalid)"));
    }
}

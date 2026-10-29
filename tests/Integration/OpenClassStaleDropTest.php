<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Replacing the open-class indexes (OpenClass) when the granted fields change: for an index with a long name (the open-class names
 * keep their `_open_..._<hash>` tail whatever the length), without a lock that stalls searches, and never touching an index that is not ours.
 */
final class OpenClassStaleDropTest extends TestCase
{
    private const string NAME = 'open_stale_with_a_rather_long_index_name';
    private const string TABLE = 'fuzzphony_open_stale_with_a_rather_long_index_name';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_stale_doc, ' . self::TABLE . ', ' . self::TABLE . '__vocab, ' . self::TABLE . '__next, ' . self::TABLE . '__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_stale_doc (id bigint PRIMARY KEY, name text NOT NULL, secret text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_stale_doc VALUES (1, 'kettle', 'hidden words')");
    }

    private function fuzzphony(bool $nameGranted): Fuzzphony
    {
        $definition = IndexDefinition::builder(self::NAME)->fromQuery('SELECT id, name, secret FROM fz_stale_doc')
            ->field('name', 'A', fuzzy: true, granted: $nameGranted ? 'ROLE_X' : null)->field('secret', 'B', fuzzy: true, granted: $nameGranted ? null : 'ROLE_X')
            ->language('english')->sync('manual')->build();

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
    }

    /** @return list<string> */
    private function openIndexes(): array
    {
        return array_map(
            static fn(array $row): string => Coerce::str($row['indexname']),
            $this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = '" . self::TABLE . "' AND indexname ~ '_open_(fz|tsv)_[0-9a-f]{8}$' ORDER BY indexname"),
        );
    }

    public function testALongIndexNameKeepsTheTailAndTheStaleIndexesAreDroppedAndReported(): void
    {
        $first = $this->fuzzphony(true);
        $first->schema()->apply($this->connection);
        $old = $this->openIndexes();
        self::assertCount(2, $old);
        foreach ($old as $name) {
            self::assertLessThanOrEqual(63, strlen($name));
        }
        self::assertStringContainsString('_open_', $old[0]);

        $second = $this->fuzzphony(false);
        $stale = array_filter($second->inspect(self::NAME)->checks, static fn($check): bool => $check->status === CheckStatus::Error && str_contains($check->message, 'STALE'));
        self::assertCount(2, $stale, 'the doctor finds the stale indexes of a long name');
        foreach ($stale as $check) {
            self::assertStringContainsString('DROP INDEX CONCURRENTLY "public"."', (string) $check->fix);
        }

        $tester = new CommandTester(new SchemaCommand($second, $this->connection));
        $tester->execute(['--apply' => true], ['interactive' => false]);
        self::assertStringContainsString('restart long-running processes', $tester->getDisplay(true), 'the reminder is printed for a long name too');
        $now = $this->openIndexes();
        self::assertCount(2, $now);
        self::assertSame([], array_intersect($old, $now));

        for ($i = 0; $i < 2; ++$i) {
            $second->schema()->apply($this->connection);
            self::assertSame($now, $this->openIndexes(), 'idempotent');
        }
        $errors = array_filter($second->inspect(self::NAME)->checks, static fn($check): bool => $check->status === CheckStatus::Error);
        self::assertSame([], array_values($errors), 'the doctor is silent');
    }

    public function testAnIndexOfTheTableWhoseNameOnlyLooksLikeOursIsNeverDropped(): void
    {
        $this->fuzzphony(true)->schema()->apply($this->connection);
        $this->connection->execute('CREATE INDEX mine_open_fz_myindex1 ON ' . self::TABLE . ' (id)');
        $this->connection->execute('CREATE INDEX my_open_tsv_zzzzzzzz ON ' . self::TABLE . ' (id)');

        $second = $this->fuzzphony(false);
        $second->schema()->apply($this->connection);

        self::assertSame(2, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_indexes WHERE indexname IN ('mine_open_fz_myindex1', 'my_open_tsv_zzzzzzzz')")));
        $names = array_map(static fn($check): string => $check->name, $second->inspect(self::NAME)->checks);
        self::assertNotContains('Index mine_open_fz_myindex1', $names, 'the doctor does not call it stale');
    }

    public function testTheDropDoesNotQueueSearchesBehindIt(): void
    {
        $current = $this->fuzzphony(false);
        $current->schema()->apply($this->connection);
        $current->reindex(self::NAME);
        // two stale ones next to the right ones, so the apply below builds nothing and only drops
        $this->connection->execute('CREATE INDEX old_open_fz_deadbeef ON ' . self::TABLE . ' (id)');
        $this->connection->execute('CREATE INDEX old_open_tsv_deadbeef ON ' . self::TABLE . ' (id)');

        // the child applies; when it is about to drop it waits for the reader to be in its transaction (the apply's own ALTER TABLE takes
        // a brief ACCESS EXCLUSIVE lock, so the reader must come after it), then the concurrent drop waits for that reader
        $flag = tempnam(sys_get_temp_dir(), 'fzflag');
        @unlink($flag);
        $script = $flag . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$c = Fuzzphony\Core\Database\PdoConnection::fromDsn(getenv("FUZZPHONY_TEST_DSN"));'
            . '$d = Fuzzphony\Core\Definition\IndexDefinition::builder(' . var_export(self::NAME, true) . ')->fromQuery("SELECT id, name, secret FROM fz_stale_doc")'
            . '->field("name", "A", fuzzy: true)->field("secret", "B", fuzzy: true, granted: "ROLE_X")->language("english")->sync("manual")->build();'
            . '(new Fuzzphony\Core\Fuzzphony(new Fuzzphony\Engine\Postgres\PostgresEngine($c), new Fuzzphony\Core\Registry\IndexRegistry([$d])))->schema()->apply($c, static function ($s) {'
            . 'if (str_starts_with($s->description, "Drop an open-class")) { touch(' . var_export($flag . '.ready', true) . '); for ($i = 0; $i < 300 && !is_file(' . var_export($flag . '.go', true) . '); ++$i) { usleep(100000); } }'
            . '});');
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $reader = PostgresTestCase::connect();

        try {
            for ($i = 0; $i < 300 && !is_file($flag . '.ready'); ++$i) {
                usleep(100_000);
            }
            self::assertFileExists($flag . '.ready');
            $reader->execute('BEGIN');
            $reader->fetchValue('SELECT count(*) FROM ' . self::TABLE);
            touch($flag . '.go');
            // wait until the drop is running (it waits for the reader)
            $waiting = false;
            for ($i = 0; $i < 100 && !$waiting; ++$i) {
                usleep(100_000);
                $waiting = $this->connection->fetchValue("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE query LIKE 'DROP INDEX CONCURRENTLY%' AND state = 'active')") === true;
            }
            if (!$waiting) {
                $reader->execute('COMMIT');
                self::fail('no wait: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            }

            $start = microtime(true);
            $hits = $this->fuzzphony(false)->in(self::NAME)->query('kettle')->restrictedToFields('name')->get();
            self::assertLessThan(2.0, microtime(true) - $start, 'a search is not queued behind the drop');
            self::assertNotEmpty($hits->hits);
        } finally {
            $reader->execute('COMMIT');
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $code = proc_close($process);
            @unlink($script);
            @unlink($flag . '.ready');
            @unlink($flag . '.go');
        }
        self::assertSame(0, $code, $out);
        $left = $this->openIndexes();
        self::assertCount(2, $left);
        self::assertSame([], array_filter($left, static fn(string $name): bool => str_ends_with($name, 'deadbeef')));
    }
}

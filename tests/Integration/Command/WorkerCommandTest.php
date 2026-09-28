<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerCommandTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
    }

    public function testWithoutAQueueModeIndexThereIsNothingToDo(): void
    {
        // CommandTestCase's default index syncs "manual" (queue-free), so the worker has no work.
        $this->context->applySchemaAndReindex();
        $tester = new CommandTester(new WorkerCommand($this->context->fuzzphony));

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No index uses the "queue" sync mode; nothing to do.', $tester->getDisplay());
    }

    public function testOnceDrainsWhateverIsAlreadyQueuedAndExits(): void
    {
        $fuzzphony = $this->queueModeFuzzphony();
        $tester = new CommandTester(new WorkerCommand($fuzzphony));

        // "queue" sync enqueues on INSERT/UPDATE/DELETE via a trigger, so this row lands in the queue.
        $this->context->connection->execute("UPDATE fz_product SET name = 'Wireless mouse v2' WHERE id = 1");

        $status = $tester->execute(['--once' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Processed 1 queued item(s).', $tester->getDisplay());
    }

    public function testOnceRunsTheRebuildATruncateQueued(): void
    {
        $fuzzphony = $this->queueModeFuzzphony();
        $connection = $this->context->connection;
        $connection->execute('TRUNCATE fz_brand CASCADE'); // fz_product goes too; both are watched: still one job

        self::assertSame(['*'], $this->queued());
        $tester = new CommandTester(new WorkerCommand($fuzzphony));
        $status = $tester->execute(['--once' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Processed 1 queued item(s).', $tester->getDisplay());
        self::assertSame(0, Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_products')), 'the source is empty, and so is the index');
        self::assertSame(0, Coerce::int($connection->fetchValue("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'products'")));
    }

    /** Like the demo's application role: read access to the source, DML on Fuzzphony's tables, no DDL. */
    public function testAWorkerRoleWithoutDdlRightsRunsTheJobInPlace(): void
    {
        $fuzzphony = $this->queueModeFuzzphony();
        $connection = $this->context->connection;
        $connection->execute('TRUNCATE fz_brand CASCADE');
        self::assertSame(['*'], $this->queued());

        // PostgreSQL 15+ grants no CREATE on public: a shadow build would fail, the job falls back to in place
        $this->asRole(true, static function () use ($fuzzphony): void {
            self::assertSame(1, (new Worker($fuzzphony->engine()))->runOnce([$fuzzphony->registry()->get('products')]));
        });

        self::assertSame([], $this->queued());
        self::assertSame(0, Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_products')), 'the empty source emptied the index in place');
        self::assertNull($connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
    }

    public function testAFailedRebuildKeepsItsJob(): void
    {
        $fuzzphony = $this->queueModeFuzzphony();
        $this->context->connection->execute('TRUNCATE fz_brand CASCADE');

        $this->asRole(false, static function () use ($fuzzphony): void {
            try {
                (new Worker($fuzzphony->engine()))->runOnce([$fuzzphony->registry()->get('products')]);
                self::fail('the failure reaches the caller');
            } catch (EngineFailure $e) {
                self::assertStringContainsString('permission denied', $e->getMessage());
            }
        });

        self::assertSame(['*'], $this->queued(), 'the next cycle runs it again');
    }

    /** Runs $work as a role like the demo's worker, with read access to the source only when $source. */
    private function asRole(bool $source, \Closure $work): void
    {
        $connection = $this->context->connection;
        $role = 'fz_worker_' . getmypid();
        $connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $connection->execute(sprintf('CREATE ROLE %s', $role));
        try {
            if ($source) {
                $connection->execute(sprintf('GRANT SELECT ON fz_product, fz_brand TO %s', $role));
            }
            $connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products, fuzzphony_meta, fuzzphony_queue TO %s', $role));
            $connection->execute(sprintf('SET ROLE %s', $role));
            try {
                $work();
            } finally {
                $connection->execute('RESET ROLE');
            }
        } finally {
            $connection->execute(sprintf('DROP OWNED BY %s', $role));
            $connection->execute(sprintf('DROP ROLE %s', $role));
        }
    }

    /** @return list<string> */
    private function queued(): array
    {
        return array_map(Coerce::str(...), array_column($this->context->connection->fetchAll("SELECT doc_id FROM fuzzphony_queue WHERE index_name = 'products'"), 'doc_id'));
    }

    public function testWithoutOnceItRunsUntilTheTimeLimitAndReportsCycles(): void
    {
        $fuzzphony = $this->queueModeFuzzphony();
        // A tiny idle sleep keeps this test fast; --time-limit=1 forces the run loop to stop.
        $tester = new CommandTester(new WorkerCommand($fuzzphony, idleSleep: 0.02));

        $this->context->connection->execute("UPDATE fz_product SET name = 'Wireless mouse v3' WHERE id = 1");

        $status = $tester->execute(['--time-limit' => '1'], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Worker started for: products', $display);
        self::assertStringContainsString('1 item(s)', $display);
        self::assertMatchesRegularExpression('/Stopped after \d+ item\(s\)\./', $display);
    }

    public function testGetSubscribedSignalsIsAPlainListAndHandleSignalKeepsTheWorkerRunning(): void
    {
        $command = new WorkerCommand($this->context->fuzzphony);

        self::assertIsList($command->getSubscribedSignals());

        // "false" tells the console component to keep running so the in-flight batch finishes;
        // a real shutdown then happens through Worker::stop(), asserted via the other tests above.
        self::assertFalse($command->handleSignal(15));
    }

    private function queueModeFuzzphony(): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->context->engine, new IndexRegistry([Indexes::products('queue')]));
        $fuzzphony->schema()->apply($this->context->connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }
}

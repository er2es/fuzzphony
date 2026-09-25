<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
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

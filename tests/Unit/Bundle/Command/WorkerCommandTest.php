<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:worker against an engine that records what it is asked (no database): batch sizes, progress output, shutdown. */
final class WorkerCommandTest extends TestCase
{
    /** @var list<int> the batch size of every processQueue() call */
    private array $limits = [];

    /** @return iterable<string, array{array<string, string>, int}> */
    public static function batchSizes(): iterable
    {
        yield 'the command default' => [[], 500];
        yield 'zero is raised to one' => [['--batch' => '0'], 1];
        yield 'negative is raised to one' => [['--batch' => '-9'], 1];
        yield 'one' => [['--batch' => '1'], 1];
        yield 'two' => [['--batch' => '2'], 2];
        yield 'a large batch is kept' => [['--batch' => '5000'], 5000];
    }

    /** @param array<string, string> $options */
    #[DataProvider('batchSizes')]
    public function testTheBatchSize(array $options, int $expected): void
    {
        $tester = new CommandTester(new WorkerCommand($this->fuzzphony($this->queue([0]))));

        $status = $tester->execute(['--once' => true, ...$options], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([$expected], $this->limits);
    }

    public function testTheBatchSizeOfTheConstructorIsTheDefault(): void
    {
        $tester = new CommandTester(new WorkerCommand($this->fuzzphony($this->queue([0])), batchSize: 77));

        $tester->execute(['--once' => true], ['interactive' => false]);
        self::assertSame([77], $this->limits);

        $this->limits = [];
        $tester->execute(['--once' => true, '--batch' => '3'], ['interactive' => false]);
        self::assertSame([3], $this->limits, 'the option wins');
    }

    public function testVerboseOutputListsEveryCycleThatProcessedSomething(): void
    {
        $tester = new CommandTester(new WorkerCommand($this->fuzzphony($this->queue([3, 0]))));

        $tester->execute(['--time-limit' => '0'], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $display = $tester->getDisplay(true);
        self::assertMatchesRegularExpression('/^\[\d\d:\d\d:\d\d\] 3 item\(s\)$/m', $display);
        self::assertStringContainsString('Worker started for: products (Ctrl+C / SIGTERM stops gracefully)', $display);
        self::assertStringContainsString('Stopped after 3 item(s).', $display);
    }

    public function testAQuietCycleAndANormalVerbosityPrintNoCycleLine(): void
    {
        $idle = new CommandTester(new WorkerCommand($this->fuzzphony($this->queue([0]))));
        $idle->execute(['--time-limit' => '0'], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        self::assertDoesNotMatchRegularExpression('/\[\d\d:\d\d:\d\d\]/', $idle->getDisplay(true), 'nothing processed, nothing to list');
        self::assertStringContainsString('Stopped after 0 item(s).', $idle->getDisplay(true));

        $busy = new CommandTester(new WorkerCommand($this->fuzzphony($this->queue([3, 0]))));
        $busy->execute(['--time-limit' => '0'], ['interactive' => false]);
        self::assertDoesNotMatchRegularExpression('/\[\d\d:\d\d:\d\d\]/', $busy->getDisplay(true), 'only with -v');
        self::assertStringContainsString('Stopped after 3 item(s).', $busy->getDisplay(true));
    }

    public function testTheSignalsItListensTo(): void
    {
        if (!\defined('SIGTERM')) {
            self::markTestSkipped('The pcntl extension is not loaded: the command listens to no signal.');
        }

        self::assertSame([\SIGTERM, \SIGINT], (new WorkerCommand($this->fuzzphony($this->queue([]))))->getSubscribedSignals());
    }

    public function testASignalStopsTheRunningWorkerAfterTheCurrentBatchButTheConsoleKeepsRunning(): void
    {
        $command = null;
        $handled = null;
        $calls = 0;
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function () use (&$command, &$handled, &$calls): int {
            ++$calls;
            if ($calls === 1) {
                self::assertInstanceOf(WorkerCommand::class, $command);
                $handled = $command->handleSignal(15); // SIGTERM arrives while the first batch runs

                return 2;
            }
            if ($calls > 3) {
                throw new \LogicException('The worker did not stop.');
            }

            return 0;
        });
        $command = new WorkerCommand($this->fuzzphony($engine), idleSleep: 0.01);
        $tester = new CommandTester($command);

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($handled, 'false: let the batch finish');
        self::assertStringContainsString('Stopped after 2 item(s).', $tester->getDisplay(true));
    }

    public function testASignalBeforeAnyRunIsHarmless(): void
    {
        self::assertFalse((new WorkerCommand($this->fuzzphony($this->queue([]))))->handleSignal(2));
    }

    /**
     * @param list<int> $processed what processQueue() returns on its calls, 0 after that
     */
    private function queue(array $processed): Engine
    {
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(function (IndexDefinition $index, int $limit) use (&$processed): int {
            $this->limits[] = $limit;

            return array_shift($processed) ?? 0;
        });

        return $engine;
    }

    private function fuzzphony(Engine $engine): Fuzzphony
    {
        return new Fuzzphony($engine, new IndexRegistry([Indexes::products('queue')]));
    }
}

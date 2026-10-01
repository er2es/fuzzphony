<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\RebuildAlreadyRunning;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    public function testRunOnceDrainsEachIndexUntilItsQueueIsEmpty(): void
    {
        $products = Indexes::products();
        $other = Indexes::products('queue', 'fz_other');
        $other = $other->withName('other');

        // Each index's queue yields exactly one non-empty batch, then reports empty.
        $remaining = [$products->name => 2, $other->name => 3];
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::exactly(4))->method('processQueue')->willReturnCallback(static function (IndexDefinition $index) use (&$remaining): int {
            $processed = $remaining[$index->name];
            $remaining[$index->name] = 0;

            return $processed;
        });

        $total = (new Worker($engine))->runOnce([$products, $other], 100);

        self::assertSame(5, $total);
    }

    public function testARequestedRebuildRunsFirstAndCountsAsOneItem(): void
    {
        $index = Indexes::products();
        $calls = [];
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'requested';

            return true;
        });
        $engine->expects(self::once())->method('beginRebuild')->with($index, false)->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'rebuild';

            return true;
        });
        $engine->expects(self::once())->method('sourceIds')->with($index, null, 5_000)->willReturn([]);
        // the TRUNCATE established that the rows are gone: an empty source empties the index (pruneEmpty)
        $engine->expects(self::once())->method('finishRebuild');
        $engine->expects(self::never())->method('abortRebuild');
        $engine->expects(self::never())->method('recordRebuildFailure');
        $engine->method('processQueue')->willReturnCallback(static function () use (&$calls): int {
            $calls[] = 'queue';

            return 0;
        });

        self::assertSame(1, (new Worker($engine))->runOnce([$index]));
        self::assertSame(['requested', 'rebuild', 'queue'], $calls);
    }

    public function testARebuildAnotherRunHoldsIsLeftForTheNextCycle(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturn(true);
        $engine->expects(self::exactly(2))->method('beginRebuild')->willThrowException(new RebuildAlreadyRunning('A rebuild of "products" is already running.'));
        $engine->expects(self::never())->method('recordRebuildFailure');
        $engine->expects(self::exactly(3))->method('processQueue')->willReturnOnConsecutiveCalls(4, 0, 0);
        $worker = new Worker($engine, static fn(): float => 0.0);

        self::assertSame(4, $worker->runOnce([Indexes::products()]));
        self::assertSame([], $worker->rebuildFailures(), 'not a failure');
        self::assertSame(0, $worker->runOnce([Indexes::products()]), 'tried again right away: no back-off');
    }

    public function testAFailedRebuildIsRecordedAndTheWorkerCarriesOn(): void
    {
        $products = Indexes::products();
        $other = Indexes::products()->withName('other');
        $failure = new EngineFailure('the swap could not lock');
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturnCallback(static fn(IndexDefinition $index): bool => $index === $products);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('sourceIds')->willReturn([]);
        $engine->method('finishRebuild')->willThrowException($failure);
        $engine->expects(self::once())->method('abortRebuild')->with($products, true);
        $engine->expects(self::once())->method('recordRebuildFailure')->with($products, 'the swap could not lock');
        $queues = [];
        $engine->method('processQueue')->willReturnCallback(static function (IndexDefinition $index) use (&$queues): int {
            $queues[] = $index->name;

            return 0;
        });
        $worker = new Worker($engine);

        self::assertSame(0, $worker->runOnce([$products, $other]));
        self::assertSame(['products', 'other'], $queues, "the index's queued ids and the other indexes still sync");
        self::assertSame(['products' => $failure], $worker->rebuildFailures());
    }

    public function testAnyOtherInvalidArgumentIsAFailureToo(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturn(true);
        $engine->method('beginRebuild')->willThrowException($failure = new InvalidArgument('Batch size must be >= 1.'));
        $engine->expects(self::once())->method('recordRebuildFailure');
        $worker = new Worker($engine);

        $worker->runOnce([Indexes::products()]);
        self::assertSame(['products' => $failure], $worker->rebuildFailures());
    }

    public function testAFailureThatCannotBeRecordedIsStillReported(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('rebuildRequested')->willReturn(true);
        $engine->method('beginRebuild')->willThrowException($failure = new EngineFailure('no source'));
        $engine->method('recordRebuildFailure')->willThrowException(new EngineFailure('no meta columns'));
        $worker = new Worker($engine);

        self::assertSame(0, $worker->runOnce([Indexes::products()]));
        self::assertSame(['products' => $failure], $worker->rebuildFailures(), "the rebuild's failure, not the record's");
    }

    public function testAFailedRebuildIsTriedAgainAfterABackOffThatDoublesUpToAnHour(): void
    {
        $now = 1_000.0;
        $engine = self::createStub(Engine::class);
        $engine->method('rebuildRequested')->willReturn(true);
        $attempts = 0;
        $engine->method('beginRebuild')->willReturnCallback(static function () use (&$attempts): bool {
            if (++$attempts <= 10) { // the first ten attempts fail
                throw new EngineFailure('down');
            }

            return true;
        });
        $engine->method('sourceIds')->willReturn([]);
        $worker = new Worker($engine, static function () use (&$now): float {
            return $now;
        });
        $attemptsAt = static function (float $at) use (&$now, $worker, &$attempts): int {
            $now = $at;
            $worker->runOnce([Indexes::products()]);

            return $attempts;
        };

        self::assertSame(1, $attemptsAt(1_000.0));
        self::assertSame(1, $attemptsAt(1_059.9), '60 s after the first failure');
        self::assertSame([], $worker->rebuildFailures(), 'nothing failed in a cycle that skipped the job');
        self::assertSame(2, $attemptsAt(1_060.0));
        self::assertSame(2, $attemptsAt(1_179.9), '120 s after the second');
        self::assertSame(3, $attemptsAt(1_180.0));
        $at = 1_180.0;
        foreach ([240, 480, 960, 1_920, 3_600, 3_600, 3_600] as $i => $delay) {
            self::assertSame(3 + $i, $attemptsAt($at + $delay - 0.1), sprintf('waits %d s', $delay));
            self::assertSame(4 + $i, $attemptsAt($at += $delay));
        }
        self::assertSame(11, $attemptsAt($at += 3_600), 'succeeds');
        self::assertSame(12, $attemptsAt($at), 'a success resets the back-off');
    }

    public function testRunStopsAfterATimeLimitEvenWithWorkStillPending(): void
    {
        $index = Indexes::products();
        $engine = self::createStub(Engine::class);
        // Always report 3 processed then 0 (queue "drained" per runOnce call) so runOnce()'s
        // internal loop terminates but run()'s outer loop would otherwise continue forever.
        $engine->method('processQueue')->willReturnOnConsecutiveCalls(3, 0);

        $cycles = [];
        $total = (new Worker($engine))->run(
            [$index],
            100,
            idleSleepSeconds: 0.01,
            timeLimitSeconds: 0,
            onCycle: function (int $processed) use (&$cycles): void {
                $cycles[] = $processed;
            },
        );

        self::assertSame(3, $total);
        self::assertSame([3], $cycles, 'the time limit (0s) must stop the loop right after the first cycle');
    }

    public function testRunSleepsWhenIdleAndStopsGracefullyMidCycle(): void
    {
        $index = Indexes::products();
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturn(0); // queue always empty: every cycle is "idle"

        $worker = new Worker($engine);
        $cycles = 0;
        $total = $worker->run(
            [$index],
            100,
            idleSleepSeconds: 0.001,
            timeLimitSeconds: null,
            onCycle: function (int $processed) use (&$cycles, $worker): void {
                $cycles++;
                // Simulate a signal handler calling stop() mid-run: the loop must finish the
                // current cycle (including the idle sleep) and then exit on its own.
                $worker->stop();
            },
        );

        self::assertSame(0, $total);
        self::assertSame(1, $cycles, 'stop() must end the loop after exactly one more cycle');
    }
}

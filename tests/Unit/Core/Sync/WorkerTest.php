<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
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
        $engine->expects(self::never())->method('requestRebuild');
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
        $engine->method('beginRebuild')->willThrowException(new InvalidArgument('A rebuild of "products" is already running.'));
        $engine->expects(self::never())->method('requestRebuild'); // the lock was never taken, so neither was the job
        $engine->expects(self::exactly(2))->method('processQueue')->willReturnOnConsecutiveCalls(4, 0);

        self::assertSame(4, (new Worker($engine))->runOnce([Indexes::products()]));
    }

    public function testAFailedRebuildQueuesItsJobAgain(): void
    {
        $index = Indexes::products();
        $failure = new EngineFailure('the swap could not lock');
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturn(true);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('sourceIds')->willReturn([]);
        $engine->method('finishRebuild')->willThrowException($failure);
        // the rebuild took the job when it started: without it the next worker would not know the index is stale
        $engine->expects(self::once())->method('requestRebuild')->with($index);
        $engine->expects(self::never())->method('processQueue');

        try {
            (new Worker($engine))->runOnce([$index]);
            self::fail('the failure reaches the caller');
        } catch (EngineFailure $e) {
            self::assertSame($failure, $e);
        }
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

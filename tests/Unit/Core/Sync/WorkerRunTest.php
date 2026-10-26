<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class WorkerRunTest extends TestCase
{
    public function testRunOnceTakesFiveHundredItemsAtATimeByDefault(): void
    {
        $index = Indexes::products();
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('processQueue')->with($index, 500)->willReturn(0);

        self::assertSame(0, (new Worker($engine))->runOnce([$index]));
    }

    public function testRunTakesFiveHundredItemsAtATimeByDefault(): void
    {
        $index = Indexes::products();
        $batchSizes = [];
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function (IndexDefinition $index, int $limit) use (&$batchSizes): int {
            $batchSizes[] = $limit;

            return 0;
        });

        (new Worker($engine))->run([$index], timeLimitSeconds: 0);

        self::assertSame([500], $batchSizes);
    }

    public function testTheTotalOfAllCyclesIsReturned(): void
    {
        $index = Indexes::products();
        $engine = self::createStub(Engine::class);
        // cycle 1: 3 items then empty; cycle 2: 2 items then empty; cycle 3: nothing
        $engine->method('processQueue')->willReturnOnConsecutiveCalls(3, 0, 2, 0, 0);
        $worker = new Worker($engine);
        $cycles = [];

        $total = $worker->run([$index], 10, 0.0, null, static function (int $processed) use (&$cycles, $worker): void {
            $cycles[] = $processed;
            if (count($cycles) === 3) {
                $worker->stop();
            }
        });

        self::assertSame([3, 2, 0], $cycles, 'without a time limit it keeps running until stopped');
        self::assertSame(5, $total);
    }

    public function testAnIdleCycleSleepsForTheConfiguredTime(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturn(0);
        $worker = new Worker($engine);
        $cycles = 0;

        $started = microtime(true);
        $worker->run([Indexes::products()], 10, 0.03, null, static function () use (&$cycles, $worker): void {
            if (++$cycles === 2) {
                $worker->stop();
            }
        });
        $elapsed = microtime(true) - $started;

        self::assertGreaterThanOrEqual(0.055, $elapsed, 'two idle cycles of 0.03 s each');
    }

    public function testABusyCycleDoesNotSleep(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnOnConsecutiveCalls(4, 0, 4, 0);
        $worker = new Worker($engine);
        $cycles = 0;

        $started = microtime(true);
        $worker->run([Indexes::products()], 10, 5.0, null, static function () use (&$cycles, $worker): void {
            if (++$cycles === 2) {
                $worker->stop();
            }
        });

        self::assertLessThan(2.0, microtime(true) - $started, 'a cycle that found work goes straight on');
    }

    public function testTheDefaultIdleSleepIsOneSecond(): void
    {
        $parameters = [];
        foreach ((new \ReflectionMethod(Worker::class, 'run'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        self::assertSame(500, $parameters['batchSize']);
        self::assertSame(1.0, $parameters['idleSleepSeconds']);
        self::assertNull($parameters['timeLimitSeconds']);
    }

    public function testAGenerousTimeLimitDoesNotEndTheRun(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturn(0);
        $worker = new Worker($engine);
        $cycles = 0;

        $worker->run([Indexes::products()], 10, 0.0, 3600, static function () use (&$cycles, $worker): void {
            if (++$cycles === 3) {
                $worker->stop();
            }
        });

        self::assertSame(3, $cycles);
    }

    public function testStopInsideTheDrainEndsItAfterTheBatchInProgress(): void
    {
        $worker = null;
        $calls = 0;
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function () use (&$worker, &$calls): int {
            ++$calls;
            if ($calls === 3) {
                $worker->stop(); // SIGTERM arrives while the third batch runs
            }
            if ($calls > 50) {
                throw new \LogicException('The drain did not stop.');
            }

            return 5; // always more work
        });
        $worker = new Worker($engine);

        $total = $worker->run([Indexes::products()], 10, 0.0);

        self::assertSame(3, $calls);
        self::assertSame(15, $total);
    }

    public function testTheTimeLimitEndsTheDrainOfAnAlwaysBusyIndex(): void
    {
        $now = 0.0;
        $calls = 0;
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function () use (&$now, &$calls): int {
            $now += 0.4; // every batch takes 0.4 s
            if (++$calls > 50) {
                throw new \LogicException('The drain ignored the time limit.');
            }

            return 5;
        });

        $total = (new Worker($engine, static function () use (&$now): float {
            return $now;
        }))->run([Indexes::products()], 10, 0.0, 1);

        self::assertSame(3, $calls, '0.4 + 0.4 + 0.4 reaches the 1 s limit');
        self::assertSame(15, $total);
    }

    public function testABusyIndexDoesNotStarveTheNext(): void
    {
        $a = Indexes::products();
        $b = $a->withName('b');
        $order = [];
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function (IndexDefinition $index) use (&$order): int {
            $order[] = $index->name;
            if (count($order) > 40) {
                throw new \LogicException('Nobody was served in turn.');
            }

            return $index->name === 'b' ? 0 : 5; // "products" always has work, "b" has none
        });
        $worker = new Worker($engine);
        $cycle = 0;

        $worker->run([$a, $b], 10, 0.0, null, static function () use (&$cycle, $worker): void {
            $worker->stop();
        });

        self::assertContains('b', $order, 'b is served although products is never empty');
        $firstB = array_search('b', $order, true);
        self::assertLessThanOrEqual(10, $firstB, 'after at most one turn of products');
    }

    public function testTheIndexesTakeTurnsInRoundRobin(): void
    {
        $a = Indexes::products();
        $b = $a->withName('b');
        $order = [];
        $left = ['products' => 25, 'b' => 25];
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function (IndexDefinition $index) use (&$order, &$left): int {
            $order[] = $index->name;
            if ($left[$index->name] <= 0) {
                return 0;
            }
            $left[$index->name] -= 1;

            return 1;
        });

        $total = (new Worker($engine))->runOnce([$a, $b]);

        self::assertSame(50, $total, 'everything is drained in the end');
        self::assertSame(['products', 'b'], array_slice(array_values(array_unique($order)), 0, 2));
        self::assertSame(array_fill(0, 10, 'products'), array_slice($order, 0, 10), 'a turn is ten batches');
        self::assertSame(array_fill(0, 10, 'b'), array_slice($order, 10, 10));
        self::assertSame(array_fill(0, 10, 'products'), array_slice($order, 20, 10), 'then products again');
    }
}

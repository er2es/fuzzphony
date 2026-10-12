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
}

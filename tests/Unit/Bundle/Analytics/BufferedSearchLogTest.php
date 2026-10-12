<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Analytics;

use Fuzzphony\Bundle\Analytics\BufferedSearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingLogger;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

final class BufferedSearchLogTest extends TestCase
{
    private function record(string $query): SearchRecord
    {
        return new SearchRecord(new \DateTimeImmutable('2026-10-12 10:00:00'), 'products', null, null, $query, 1, 1.0, false);
    }

    public function testRecordsAreHeldUntilTheFlushAndWrittenInOneCall(): void
    {
        $store = new FakeSearchLogStore();
        $log = new BufferedSearchLog($store, new RecordingMetricsCollector());

        $log->record($this->record('a'));
        $log->record($this->record('b'));
        self::assertCount(0, $store->batches);

        $log->flush();

        self::assertCount(1, $store->batches);
        self::assertCount(2, $store->batches[0]);
        self::assertSame(['a', 'b'], array_map(static fn(SearchRecord $r): string => $r->query, $store->batches[0]));
    }

    public function testAFlushWithNothingBufferedWritesNothing(): void
    {
        $store = new FakeSearchLogStore();

        (new BufferedSearchLog($store, new RecordingMetricsCollector()))->flush();

        self::assertSame([], $store->batches);
    }

    public function testAFlushedBufferIsNotWrittenTwice(): void
    {
        $store = new FakeSearchLogStore();
        $log = new BufferedSearchLog($store, new RecordingMetricsCollector());

        $log->record($this->record('a'));
        $log->flush();
        $log->flush();

        self::assertCount(1, $store->batches);
    }

    public function testAFailedWriteIsCountedLoggedAndTheBufferCleared(): void
    {
        $store = new FakeSearchLogStore(fail: true);
        $metrics = new RecordingMetricsCollector();
        $logger = new RecordingLogger();
        $log = new BufferedSearchLog($store, $metrics, $logger);

        $log->record($this->record('a'));
        $log->record($this->record('b'));
        $log->flush(); // must not throw
        $store->fail = false;
        $log->flush(); // the failed batch is not retried forever

        self::assertSame([['increment', 'fuzzphony.search_log.failed', 2.0, []]], $metrics->calls);
        self::assertSame([], $store->batches);
        self::assertCount(1, $logger->calls);
        self::assertSame('error', $logger->calls[0][0]);
        self::assertSame(2, $logger->calls[0][1]['count']);
        self::assertSame('disk full', $logger->calls[0][1]['message']);
    }

    public function testAFailedWriteWithoutALoggerIsStillCounted(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new BufferedSearchLog(new FakeSearchLogStore(fail: true), $metrics);

        $log->record($this->record('a'));
        $log->flush();

        self::assertCount(1, $metrics->calls);
    }

    public function testTheThousandthRecordWritesTheBufferSoALongRunningProcessDoesNotGrowWithoutLimit(): void
    {
        $store = new FakeSearchLogStore();
        $log = new BufferedSearchLog($store, new RecordingMetricsCollector());

        for ($i = 1; $i <= 999; ++$i) {
            $log->record($this->record('q' . $i));
        }
        self::assertCount(0, $store->batches, '999 records stay buffered');

        $log->record($this->record('q1000'));

        self::assertCount(1, $store->batches);
        self::assertCount(1000, $store->batches[0]);
        $log->flush();
        self::assertCount(1, $store->batches, 'the buffer is empty again');

        $log->record($this->record('next'));
        $log->flush();
        self::assertCount(2, $store->batches);
        self::assertCount(1, $store->batches[1]);
    }

    public function testAFailureOnTheThousandthRecordNeverThrowsIntoTheSearch(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new BufferedSearchLog(new FakeSearchLogStore(fail: true), $metrics);

        for ($i = 1; $i <= 1000; ++$i) {
            $log->record($this->record('q' . $i));
        }

        self::assertSame([['increment', 'fuzzphony.search_log.failed', 1000.0, []]], $metrics->calls);
    }

    public function testResetFlushesSoAWorkerWritesWhatItHoldsBetweenMessages(): void
    {
        $store = new FakeSearchLogStore();
        $log = new BufferedSearchLog($store, new RecordingMetricsCollector());
        self::assertInstanceOf(ResetInterface::class, $log);

        $log->record($this->record('a'));
        $log->reset();

        self::assertCount(1, $store->batches);
        self::assertSame('a', $store->batches[0][0]->query);
    }

    public function testResetNeverThrowsWhenTheWriteFails(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new BufferedSearchLog(new FakeSearchLogStore(fail: true), $metrics);

        $log->record($this->record('a'));
        $log->reset();

        self::assertCount(1, $metrics->calls);
    }

    public function testTheStoreIsOnlyResolvedWhenThereIsSomethingToWrite(): void
    {
        $resolved = 0;
        $store = new FakeSearchLogStore();
        $log = new BufferedSearchLog(static function () use (&$resolved, $store): FakeSearchLogStore {
            ++$resolved;

            return $store;
        }, new RecordingMetricsCollector());

        $log->flush();
        $log->reset();
        self::assertSame(0, $resolved, 'an empty buffer never builds the engine behind it');

        $log->record($this->record('a'));
        $log->flush();
        $log->record($this->record('b'));
        $log->flush();

        self::assertSame(1, $resolved, 'resolved once, on the first write');
        self::assertCount(2, $store->batches);
    }
}

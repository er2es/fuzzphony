<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Analytics;

use Fuzzphony\Bundle\Analytics\BufferedSearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingLogger;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

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
}

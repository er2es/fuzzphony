<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Observability;

use Fuzzphony\Core\Observability\LoggingMetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LoggingMetricsCollectorTest extends TestCase
{
    public function testIncrementLogsAStructuredLine(): void
    {
        $logger = self::spy();
        (new LoggingMetricsCollector($logger))->increment('fuzzphony.queue.processed', ['index' => 'products'], 7);

        self::assertSame([[LogLevel::DEBUG, [
            'event' => 'fuzzphony.queue.processed',
            'by' => 7,
            'index' => 'products',
        ]]], $logger->calls);
    }

    public function testIncrementDefaultsByToOne(): void
    {
        $logger = self::spy();
        (new LoggingMetricsCollector($logger))->increment('fuzzphony.search.fallback');

        self::assertSame([[LogLevel::DEBUG, ['event' => 'fuzzphony.search.fallback', 'by' => 1]]], $logger->calls);
    }

    public function testObserveLogsTheValue(): void
    {
        $logger = self::spy();
        (new LoggingMetricsCollector($logger))->observe('fuzzphony.search.took_ms', 12.4, ['index' => 'products']);

        self::assertSame([[LogLevel::DEBUG, [
            'event' => 'fuzzphony.search.took_ms',
            'value' => 12.4,
            'index' => 'products',
        ]]], $logger->calls);
    }

    public function testGaugeLogsTheValue(): void
    {
        $logger = self::spy();
        (new LoggingMetricsCollector($logger))->gauge('fuzzphony.queue.depth', 3.0, ['index' => 'products']);

        self::assertSame([[LogLevel::DEBUG, [
            'event' => 'fuzzphony.queue.depth',
            'value' => 3.0,
            'index' => 'products',
        ]]], $logger->calls);
    }

    public function testTheLabelsAreLoggedAsTheyAreGiven(): void
    {
        $logger = self::spy();
        (new LoggingMetricsCollector($logger))->observe('fuzzphony.search.took_ms', 12.4, ['index' => 'products']);

        self::assertSame([[LogLevel::DEBUG, ['event' => 'fuzzphony.search.took_ms', 'value' => 12.4, 'index' => 'products']]], $logger->calls);
    }

    public function testThereIsNoOptionToLogTheSearchText(): void
    {
        self::assertCount(1, (new \ReflectionClass(LoggingMetricsCollector::class))->getConstructor()?->getParameters() ?? []);
    }

    private static function spy(): RecordingLogger
    {
        return new RecordingLogger();
    }
}

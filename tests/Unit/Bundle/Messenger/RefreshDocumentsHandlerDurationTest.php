<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Messenger;

use Fuzzphony\Bundle\Messenger\RefreshDocuments;
use Fuzzphony\Bundle\Messenger\RefreshDocumentsHandler;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ScriptedHrtime.php';

/** The duration the handler reports: milliseconds, between the start and the end of the refresh, to three decimals. */
final class RefreshDocumentsHandlerDurationTest extends TestCase
{
    protected function tearDown(): void
    {
        ScriptedHrtime::$readings = [];
    }

    public function testTheDurationIsReportedInMillisecondsRoundedToThreeDecimals(): void
    {
        ScriptedHrtime::$readings = [5_000_000_000, 5_001_234_567]; // 1.234567 ms apart
        $engine = self::createStub(Engine::class);
        $metrics = new RecordingMetricsCollector();

        (new RefreshDocumentsHandler(new Fuzzphony($engine, new IndexRegistry([Indexes::products()])), $metrics))(new RefreshDocuments('products', [1]));

        self::assertSame([['observe', 'fuzzphony.messenger.refresh.duration_ms', 1.235, ['index' => 'products']]], $metrics->calls);
    }
}

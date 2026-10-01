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

/** Fuzzphony is final and cannot be mocked, so these go through a real Fuzzphony with a stub Engine. */
final class RefreshDocumentsHandlerTest extends TestCase
{
    public function testInvokingRebuildsTheDocumentsNamedInTheMessage(): void
    {
        $index = Indexes::products();
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('refresh')->with($index, [1, 2])->willReturn(2);

        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([$index]));
        $handler = new RefreshDocumentsHandler($fuzzphony);

        $handler(new RefreshDocuments('products', [1, 2]));
    }

    public function testASuccessfulRefreshObservesDuration(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('refresh')->with(Indexes::products(), [1, 2])->willReturn(2);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products()]));
        $metrics = new RecordingMetricsCollector();

        (new RefreshDocumentsHandler($fuzzphony, $metrics))(new RefreshDocuments('products', [1, 2]));

        self::assertCount(1, $metrics->calls);
        self::assertSame('observe', $metrics->calls[0][0]);
        self::assertSame('fuzzphony.messenger.refresh.duration_ms', $metrics->calls[0][1]);
        self::assertSame(['index' => 'products'], $metrics->calls[0][3]);
    }

    public function testAFailedRefreshIncrementsErrorsAndRethrows(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('refresh')->willThrowException(new \RuntimeException('db gone'));
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products()]));
        $metrics = new RecordingMetricsCollector();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db gone');
        try {
            (new RefreshDocumentsHandler($fuzzphony, $metrics))(new RefreshDocuments('products', [1]));
        } finally {
            self::assertSame([['increment', 'fuzzphony.messenger.refresh.errors', 1.0, ['index' => 'products']]], $metrics->calls);
        }
    }
}

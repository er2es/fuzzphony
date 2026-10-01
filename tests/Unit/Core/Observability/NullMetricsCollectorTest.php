<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Observability;

use Fuzzphony\Core\Observability\NullMetricsCollector;
use PHPUnit\Framework\TestCase;

final class NullMetricsCollectorTest extends TestCase
{
    public function testEveryMethodIsANoOpAndReturnsNothing(): void
    {
        $collector = new NullMetricsCollector();

        $collector->increment('fuzzphony.search.fallback', ['index' => 'products']);
        $collector->increment('fuzzphony.search.fallback');
        $collector->observe('fuzzphony.search.took_ms', 12.4, ['index' => 'products']);
        $collector->gauge('fuzzphony.queue.depth', 3.0, ['index' => 'products']);

        $this->addToAssertionCount(4);
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Sync\ImmediateRefreshDispatcher;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class ImmediateRefreshDispatcherTest extends TestCase
{
    public function testRefreshesInChunks(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::exactly(3))->method('refresh')->willReturn(1);

        (new ImmediateRefreshDispatcher($engine))->dispatch(Indexes::products(), range(1, 2500));
    }
}

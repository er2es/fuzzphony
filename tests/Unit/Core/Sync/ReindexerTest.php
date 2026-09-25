<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Sync\Reindexer;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class ReindexerTest extends TestCase
{
    public function testBatchSizeBelowOneIsRejected(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::never())->method('sourceIds');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be >= 1.');

        (new Reindexer($engine))->run(Indexes::products(), 0);
    }
}

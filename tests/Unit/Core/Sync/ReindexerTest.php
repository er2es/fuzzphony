<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Sync\Reindexer;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReindexerTest extends TestCase
{
    public function testBatchesUntilAShortBatchAndReportsProgress(): void
    {
        $engine = $this->engine([[1, 2], [3]]);
        $engine->expects(self::exactly(2))->method('refresh')->willReturnOnConsecutiveCalls(2, 1);
        $engine->expects(self::once())->method('pruneOrphans')->with(self::anything(), 2)->willReturn(4);
        $engine->expects(self::once())->method('recordReindex');
        $progress = [];

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(
            batchSize: 2,
            onBatch: static function (int $processed, int|string $lastId) use (&$progress): void {
                $progress[] = [$processed, $lastId];
            },
        ));

        self::assertSame([[2, 2], [3, 3]], $progress);
        self::assertSame(3, $result->written);
        self::assertSame(4, $result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource);
    }

    public function testAResumedRunStartsAfterTheIdAndNeverPrunes(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('sourceIds')->with(self::anything(), 7, 5_000)->willReturn([8]);
        $engine->method('refresh')->willReturn(1);
        $engine->expects(self::never())->method('pruneOrphans');
        $engine->expects(self::never())->method('recordReindex');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(resumeAfter: 7));

        self::assertSame(1, $result->written);
        self::assertNull($result->pruned);
    }

    public function testPruneFalseSkipsPruning(): void
    {
        $engine = $this->engine([[1]]);
        $engine->method('refresh')->willReturn(1);
        $engine->expects(self::never())->method('pruneOrphans');
        $engine->expects(self::once())->method('recordReindex'); // a full run without pruning still rebuilt every document

        self::assertNull((new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(prune: false))->pruned);
    }

    public function testAnEmptySourceIsOnlyPrunedWhenAskedTo(): void
    {
        $engine = $this->engine([[]]);
        $engine->expects(self::never())->method('pruneOrphans');
        $engine->expects(self::never())->method('recordReindex');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());

        self::assertSame(0, $result->written);
        self::assertNull($result->pruned);
        self::assertTrue($result->pruneSkippedEmptySource);

        $forced = $this->engine([[]]);
        $forced->expects(self::once())->method('pruneOrphans')->willReturn(5);
        $forced->expects(self::once())->method('recordReindex');
        $result = (new Reindexer($forced))->run(Indexes::products(), new ReindexOptions(pruneEmpty: true));

        self::assertSame(5, $result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource);
    }

    /**
     * @param list<list<int>> $batches what sourceIds() returns, call by call
     *
     * @return Engine&MockObject
     */
    private function engine(array $batches): Engine
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('sourceIds')->willReturnOnConsecutiveCalls(...$batches);

        return $engine;
    }
}

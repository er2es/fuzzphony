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

    public function testAnEmptySourceIsNotRecordedAsReindexedWithoutPruning(): void
    {
        $engine = $this->engine([[]]);
        $engine->expects(self::never())->method('pruneOrphans');
        $engine->expects(self::never())->method('recordReindex');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(prune: false));

        self::assertSame(0, $result->written);
        self::assertNull($result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource, 'pruning was not asked for, so it was not skipped either');

        $forced = $this->engine([[]]);
        $forced->expects(self::never())->method('pruneOrphans');
        $forced->expects(self::once())->method('recordReindex');
        (new Reindexer($forced))->run(Indexes::products(), new ReindexOptions(prune: false, pruneEmpty: true));
    }

    public function testAFullRunBuildsNextToTheLiveIndexAndSwapsItIn(): void
    {
        $engine = $this->engine([[1, 2], [3]]);
        $engine->expects(self::once())->method('beginRebuild')->with(self::anything(), false)->willReturn(true);
        $engine->expects(self::exactly(2))->method('refreshShadow')->willReturnOnConsecutiveCalls(2, 1);
        $engine->expects(self::never())->method('refresh');
        $engine->expects(self::never())->method('pruneOrphans');
        $engine->expects(self::once())->method('finishRebuild');
        $engine->expects(self::never())->method('abortRebuild');
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
        self::assertTrue($result->swapped);
        self::assertNull($result->pruned, 'the orphans went with the old index');
        self::assertFalse($result->pruneSkippedEmptySource);
    }

    public function testInPlaceOrWithoutPruningNoRebuildIsStarted(): void
    {
        foreach ([new ReindexOptions(inPlace: true, resumeAfter: 7), new ReindexOptions(prune: false)] as $options) {
            $engine = $this->engine([[8]]);
            $engine->expects(self::never())->method('beginRebuild');
            $engine->expects(self::once())->method('refresh')->willReturn(1);

            self::assertFalse((new Reindexer($engine))->run(Indexes::products(), $options)->swapped);
        }
    }

    public function testAFullInPlaceRunFirstDiscardsALeftOverRebuildWithoutTheSessionLock(): void
    {
        $order = [];
        $engine = $this->engine([[1]]);
        $engine->expects(self::once())->method('discardLeftoverRebuild')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'discard';

            return true;
        });
        $engine->expects(self::never())->method('beginRebuild');
        $engine->expects(self::never())->method('abortRebuild');
        $engine->expects(self::once())->method('refresh')->willReturnCallback(static function () use (&$order): int {
            $order[] = 'refresh';

            return 1;
        });

        self::assertFalse((new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(inPlace: true))->swapped);
        self::assertSame(['discard', 'refresh'], $order, 'a later resume must not continue it: in place changed the live index');
    }

    public function testOnlyAFullInPlaceRunDiscardsALeftOverRebuild(): void
    {
        foreach ([new ReindexOptions(inPlace: true, resumeAfter: 7), new ReindexOptions(prune: false), new ReindexOptions()] as $options) {
            $engine = $this->engine([[8]]);
            $engine->expects(self::never())->method('discardLeftoverRebuild');
            $engine->method('refresh')->willReturn(1);
            (new Reindexer($engine))->run(Indexes::products(), $options);
        }
    }

    public function testWhenTheEngineCannotBuildNextToTheLiveIndexTheRunGoesInPlace(): void
    {
        $engine = $this->engine([[1]]);
        $engine->expects(self::once())->method('beginRebuild')->willReturn(false);
        $engine->expects(self::once())->method('refresh')->willReturn(1);
        $engine->expects(self::never())->method('refreshShadow');
        $engine->expects(self::once())->method('pruneOrphans')->willReturn(2);

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());

        self::assertFalse($result->swapped);
        self::assertSame(2, $result->pruned);
    }

    public function testAResumedRunContinuesALeftOverRebuildAndSwapsEvenWithNothingLeft(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('beginRebuild')->with(self::anything(), true)->willReturn(true);
        $engine->expects(self::once())->method('sourceIds')->with(self::anything(), 7, 5_000)->willReturn([]);
        $engine->expects(self::once())->method('finishRebuild');
        $engine->expects(self::never())->method('abortRebuild');
        $engine->expects(self::once())->method('recordReindex'); // the rebuild was started by a full run

        self::assertTrue((new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(resumeAfter: 7))->swapped);
    }

    public function testAnEmptySourceDiscardsTheRebuildUnlessPruneEmpty(): void
    {
        $engine = $this->engine([[]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), false);
        $engine->expects(self::never())->method('finishRebuild');
        $engine->expects(self::never())->method('recordReindex');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());

        self::assertFalse($result->swapped);
        self::assertTrue($result->pruneSkippedEmptySource);

        $forced = $this->engine([[]]);
        $forced->method('beginRebuild')->willReturn(true);
        $forced->expects(self::never())->method('abortRebuild');
        $forced->expects(self::once())->method('finishRebuild');
        $forced->expects(self::once())->method('recordReindex');

        self::assertTrue((new Reindexer($forced))->run(Indexes::products(), new ReindexOptions(pruneEmpty: true))->swapped);
    }

    public function testAFailedBuildKeepsTheRebuildForResumingAndRethrows(): void
    {
        $engine = $this->engine([[1, 2]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('refreshShadow')->willReturn(2);
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), true);
        $engine->expects(self::never())->method('finishRebuild');
        $engine->expects(self::never())->method('recordReindex');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('killed');

        (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(batchSize: 2, onBatch: static function (): void {
            throw new \RuntimeException('killed');
        }));
    }

    public function testAFailedSwapAlsoKeepsTheRebuild(): void
    {
        $engine = $this->engine([[1]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('finishRebuild')->willThrowException(new \RuntimeException('deadlock'));
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), true);
        $engine->expects(self::never())->method('recordReindex');

        $this->expectExceptionMessage('deadlock');

        (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());
    }

    public function testAFailureToReleaseTheRebuildDoesNotHideTheFirstError(): void
    {
        $engine = $this->engine([[1]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('finishRebuild')->willThrowException(new \RuntimeException('first'));
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), true)->willThrowException(new \RuntimeException('second'));

        $this->expectExceptionMessage('first');

        (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());
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

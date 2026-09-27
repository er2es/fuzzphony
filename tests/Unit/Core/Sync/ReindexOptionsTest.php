<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\ReindexResult;
use PHPUnit\Framework\TestCase;

final class ReindexOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new ReindexOptions();

        self::assertSame(5_000, $options->batchSize);
        self::assertNull($options->resumeAfter);
        self::assertTrue($options->prune);
        self::assertFalse($options->pruneEmpty);
        self::assertNull($options->onBatch);
        self::assertSame(1, (new ReindexOptions(batchSize: 1))->batchSize, 'one is the smallest batch');
    }

    public function testABatchSizeBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Batch size must be >= 1, got 0.');

        new ReindexOptions(batchSize: 0);
    }

    public function testResultDefaults(): void
    {
        $result = new ReindexResult(3);

        self::assertSame(3, $result->written);
        self::assertNull($result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource);
    }
}

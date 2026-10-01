<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Tests\Integration\Command\CommandTestCase;
use PHPUnit\Framework\TestCase;

/** Fuzzphony::reindex() drops orphans by default (a swap, or pruning in place), with an opt-out and a guard against an empty (invisible) source. */
final class ReindexPruningTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->context->applySchemaAndReindex();
    }

    public function testAFullRunSwapsAndLeavesTheOrphansBehind(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4'); // manual sync: 4 is an orphan

        $result = $this->context->fuzzphony->reindex('products');

        self::assertTrue($result->swapped);
        self::assertNull($result->pruned, 'it went with the old index');
        self::assertSame(4, $this->indexed());
    }

    public function testInPlaceItPrunesAndReportsHowMany(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4');

        $result = $this->context->fuzzphony->reindex('products', new ReindexOptions(inPlace: true));

        self::assertFalse($result->swapped);
        self::assertSame(1, $result->pruned);
        self::assertSame(4, $this->indexed());
    }

    public function testPruneFalseLeavesTheIndexAlone(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4');

        $result = $this->context->fuzzphony->reindex('products', new ReindexOptions(prune: false));

        self::assertNull($result->pruned);
        self::assertSame(5, $this->indexed());
    }

    public function testASourceWithoutRowsIsNotPrunedUnlessForced(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $result = $this->context->fuzzphony->reindex('products');

        self::assertSame(0, $result->written);
        self::assertTrue($result->pruneSkippedEmptySource);
        self::assertNull($result->pruned);
        self::assertSame(5, $this->indexed());
        self::assertFalse($result->swapped);
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"), 'the empty rebuild is discarded');

        $forced = $this->context->fuzzphony->reindex('products', new ReindexOptions(pruneEmpty: true));

        self::assertTrue($forced->swapped);
        self::assertSame(0, $this->indexed());
    }

    private function indexed(): int
    {
        return Coerce::int($this->context->connection->fetchValue('SELECT count(*) FROM fuzzphony_products'));
    }
}

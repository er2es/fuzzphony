<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Tests\Integration\Command\CommandTestCase;
use PHPUnit\Framework\TestCase;

/** Fuzzphony::reindex() prunes by default, with an opt-out and a guard against an empty (invisible) source. */
final class ReindexPruningTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->context->applySchemaAndReindex();
    }

    public function testPrunesByDefaultAndReportsHowMany(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4'); // manual sync: 4 is an orphan

        $result = $this->context->fuzzphony->reindex('products');

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

        $forced = $this->context->fuzzphony->reindex('products', new ReindexOptions(pruneEmpty: true));

        self::assertSame(5, $forced->pruned);
        self::assertSame(0, $this->indexed());
    }

    private function indexed(): int
    {
        return Coerce::int($this->context->connection->fetchValue('SELECT count(*) FROM fuzzphony_products'));
    }
}

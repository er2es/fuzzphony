<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Support\Coerce;
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
        $pruned = [];

        $this->context->fuzzphony->reindex('products', onPruned: static function (int $removed) use (&$pruned): void {
            $pruned[] = $removed;
        });

        self::assertSame([1], $pruned);
        self::assertSame(4, $this->indexed());
    }

    public function testPruneFalseLeavesTheIndexAlone(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4');
        $called = false;

        $this->context->fuzzphony->reindex('products', onPruned: static function () use (&$called): void {
            $called = true;
        }, prune: false);

        self::assertFalse($called);
        self::assertSame(5, $this->indexed());
    }

    public function testASourceWithoutRowsIsNotPrunedUnlessForced(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');
        $events = [];

        $written = $this->context->fuzzphony->reindex(
            'products',
            onPruned: static function (int $removed) use (&$events): void {
                $events[] = 'pruned ' . $removed;
            },
            onPruneSkipped: static function () use (&$events): void {
                $events[] = 'skipped';
            },
        );

        self::assertSame(0, $written);
        self::assertSame(['skipped'], $events);
        self::assertSame(5, $this->indexed());

        $this->context->fuzzphony->reindex('products', onPruned: static function (int $removed) use (&$events): void {
            $events[] = 'pruned ' . $removed;
        }, pruneEmpty: true);

        self::assertSame(['skipped', 'pruned 5'], $events);
        self::assertSame(0, $this->indexed());
    }

    private function indexed(): int
    {
        return Coerce::int($this->context->connection->fetchValue('SELECT count(*) FROM fuzzphony_products'));
    }
}

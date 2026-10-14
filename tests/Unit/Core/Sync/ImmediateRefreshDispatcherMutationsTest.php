<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Sync\ImmediateRefreshDispatcher;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class ImmediateRefreshDispatcherMutationsTest extends TestCase
{
    public function testTheIdsAreRefreshedInChunksOfExactlyAThousand(): void
    {
        $chunks = [];
        $engine = self::createStub(Engine::class);
        $engine->method('refresh')->willReturnCallback(static function (IndexDefinition $index, array $ids) use (&$chunks): int {
            $chunks[] = $ids;

            return count($ids);
        });

        (new ImmediateRefreshDispatcher($engine))->dispatch(Indexes::products(), range(1, 2001));

        self::assertSame([1000, 1000, 1], array_map(count(...), $chunks));
        self::assertSame(range(1, 1000), $chunks[0]);
        self::assertSame(1001, $chunks[1][0]);
        self::assertSame([2001], $chunks[2]);
    }
}

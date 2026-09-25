<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Capabilities;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SearchBuilderTest extends TestCase
{
    private function builder(): SearchBuilder
    {
        $engine = new class implements Engine {
            public function name(): string
            {
                throw new \LogicException('not used by this test');
            }

            public function capabilities(): Capabilities
            {
                throw new \LogicException('not used by this test');
            }

            public function globalSchema(IndexDefinition ...$indexes): SchemaPlan
            {
                throw new \LogicException('not used by this test');
            }

            public function indexSchema(IndexDefinition $index): SchemaPlan
            {
                throw new \LogicException('not used by this test');
            }

            public function dropSchema(IndexDefinition $index): SchemaPlan
            {
                throw new \LogicException('not used by this test');
            }

            public function search(IndexDefinition $index, SearchQuery $query): SearchResult
            {
                throw new \LogicException('not used by this test');
            }

            public function explain(IndexDefinition $index, SearchQuery $query, bool $analyze = false): Explanation
            {
                throw new \LogicException('not used by this test');
            }

            public function refresh(IndexDefinition $index, array $ids): int
            {
                throw new \LogicException('not used by this test');
            }

            public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array
            {
                throw new \LogicException('not used by this test');
            }

            public function pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int
            {
                throw new \LogicException('not used by this test');
            }

            public function processQueue(IndexDefinition $index, int $limit): int
            {
                throw new \LogicException('not used by this test');
            }

            public function queueSize(IndexDefinition $index): int
            {
                throw new \LogicException('not used by this test');
            }

            public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport
            {
                throw new \LogicException('not used by this test');
            }
        };

        return new SearchBuilder($engine, Indexes::products());
    }

    public function testWhereInAddsAnInCondition(): void
    {
        $query = $this->builder()->whereIn('brand_id', [1, 2, 3])->toQuery();

        self::assertCount(1, $query->conditions);
        self::assertSame('brand_id', $query->conditions[0]->filter);
        self::assertSame([1, 2, 3], $query->conditions[0]->value);
    }

    public function testWhereNullAddsAnIsNullCondition(): void
    {
        $query = $this->builder()->whereNull('brand_id')->toQuery();

        self::assertCount(1, $query->conditions);
        self::assertSame('brand_id', $query->conditions[0]->filter);
    }

    public function testWhereNullFalseAddsAnIsNotNullCondition(): void
    {
        $query = $this->builder()->whereNull('brand_id', false)->toQuery();

        self::assertCount(1, $query->conditions);
    }

    public function testToQueryReturnsTheAccumulatedQuery(): void
    {
        $query = $this->builder()->query('mouse')->limit(5)->toQuery();

        self::assertInstanceOf(SearchQuery::class, $query);
        self::assertSame('mouse', $query->text);
        self::assertSame(5, $query->limit);
    }
}

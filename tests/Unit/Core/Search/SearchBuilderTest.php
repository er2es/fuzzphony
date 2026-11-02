<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SearchBuilderTest extends TestCase
{
    private function builder(): SearchBuilder
    {
        $engine = self::createStub(Engine::class);

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

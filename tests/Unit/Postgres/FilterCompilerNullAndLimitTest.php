<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The NULL-aware operators and the size of an IN() list, as the SQL they compile to. */
final class FilterCompilerNullAndLimitTest extends TestCase
{
    private function compile(SearchQuery $query, ?ParameterBag $params = null): string
    {
        return (new FilterCompiler(Indexes::products()))->compile($query->conditions, $params ?? new ParameterBag());
    }

    public function testNullChecksOfEveryOperator(): void
    {
        self::assertSame('s."f_price" IS NULL', $this->compile((new SearchQuery())->whereNull('price')), 'whereNull()');
        self::assertSame('s."f_price" IS NULL', $this->compile((new SearchQuery())->where('price', null)), '= null');
        self::assertSame('s."f_price" IS NOT NULL', $this->compile((new SearchQuery())->where('price', '!=', null)), '!= null');
    }

    public function testNotEqualComparesNullSafelyAgainstTheBoundValue(): void
    {
        $params = new ParameterBag();

        $sql = $this->compile((new SearchQuery())->where('price', '!=', 5), $params);

        self::assertSame('s."f_price" IS DISTINCT FROM :p0', $sql);
        self::assertSame(['p0' => 5], $params->all());
    }

    public function testEqualBindsItsValue(): void
    {
        $params = new ParameterBag();

        self::assertSame('s."f_price" = :p0', $this->compile((new SearchQuery())->where('price', 7), $params));
        self::assertSame(['p0' => 7], $params->all());
    }

    public function testAThousandValuesInAListAreAllowed(): void
    {
        $params = new ParameterBag();

        $sql = $this->compile((new SearchQuery())->whereIn('price', range(1, 1000)), $params);

        self::assertStringStartsWith('s."f_price" IN (:p0, :p1, ', $sql);
        self::assertStringEndsWith(':p999)', $sql);
        self::assertCount(1000, $params->all());
    }
}

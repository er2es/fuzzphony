<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class FilterCompilerTest extends TestCase
{
    public function testCompilesEveryOperatorWithBoundValues(): void
    {
        $query = (new SearchQuery())
            ->where('price', '>=', '100')
            ->where('in_stock', true)
            ->whereIn('price', [1, 2])
            ->whereBetween('published_at', '2026-01-01', new \DateTimeImmutable('2026-02-01T00:00:00+00:00'))
            ->whereNull('published_at', false)
            ->where('price', '!=', null);
        $params = new ParameterBag();

        $sql = (new FilterCompiler(Indexes::products()))->compile($query->conditions, $params);

        self::assertSame(
            's."f_price" >= :p0 AND s."f_in_stock" = :p1 AND s."f_price" IN (:p2, :p3) AND s."f_published_at" BETWEEN :p4 AND :p5 AND s."f_published_at" IS NOT NULL AND s."f_price" IS NOT NULL',
            $sql,
        );
        self::assertSame(100, $params->all()['p0'], 'numeric strings are converted for int filters');
        self::assertTrue($params->all()['p1']);
        self::assertSame('2026-02-01T00:00:00+00:00', $params->all()['p5']);
    }

    public function testEmptyInLists(): void
    {
        $compiler = new FilterCompiler(Indexes::products());

        self::assertSame('FALSE', $compiler->compile((new SearchQuery())->whereIn('price', [])->conditions, new ParameterBag()));
        self::assertSame('TRUE', $compiler->compile((new SearchQuery())->where('price', 'not in', [])->conditions, new ParameterBag()));
    }

    public function testNoConditionsMeansTrue(): void
    {
        self::assertSame('TRUE', (new FilterCompiler(Indexes::products()))->compile([], new ParameterBag()));
    }

    public function testWrongValueTypesAreDeveloperErrors(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "in_stock" expects a bool value, got string.');
        (new FilterCompiler(Indexes::products()))->validate(...(new SearchQuery())->where('in_stock', 'yes')->conditions);
    }

    public function testUnknownFilterIsReported(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Did you mean "price"?');
        (new FilterCompiler(Indexes::products()))->validate(...(new SearchQuery())->where('prices', 1)->conditions);
    }

    public function testBetweenNeedsTwoValues(): void
    {
        $this->expectException(InvalidQuery::class);
        (new FilterCompiler(Indexes::products()))->validate(...(new SearchQuery())->where('price', 'between', [1])->conditions);
    }

    public function testInWithANonArrayValueIsADeveloperError(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "price" IN expects a list of values.');
        (new FilterCompiler(Indexes::products()))->validate(...(new SearchQuery())->where('price', 'in', 'not-an-array')->conditions);
    }

    public function testInRejectsMoreThanOneThousandValues(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "price" accepts at most 1000 values in IN().');
        (new FilterCompiler(Indexes::products()))->validate(...(new SearchQuery())->whereIn('price', range(1, 1001))->conditions);
    }
}

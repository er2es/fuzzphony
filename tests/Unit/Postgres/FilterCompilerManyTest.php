<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use PHPUnit\Framework\TestCase;

/** How a filter with several values per document (many) and the IN lists of any filter compile. */
final class FilterCompilerManyTest extends TestCase
{
    private static function compiler(): FilterCompiler
    {
        $index = IndexDefinition::builder('customers')
            ->fromTable('customer')
            ->field('name', 'A')
            ->filter('city', 'string', many: ['from' => 'address', 'key' => 'customer_id', 'column' => 'city'])
            ->filter('price', 'int')
            ->sync('manual')
            ->build();

        return new FilterCompiler($index);
    }

    private static function sql(SearchQuery $query): string
    {
        return self::compiler()->compile($query->conditions, new ParameterBag());
    }

    public function testNullChecksAreTheEmptinessOfTheValues(): void
    {
        $none = 'coalesce(cardinality(s."f_city"), 0) = 0';

        self::assertSame($none, self::sql((new SearchQuery())->whereNull('city')));
        self::assertSame('NOT (' . $none . ')', self::sql((new SearchQuery())->whereNull('city', false)));
        self::assertSame($none, self::sql((new SearchQuery())->where('city', null)));
        self::assertSame('NOT (' . $none . ')', self::sql((new SearchQuery())->where('city', '!=', null)));
    }

    public function testOneValue(): void
    {
        self::assertSame('coalesce(s."f_city" @> ARRAY[:p0]::text[], false)', self::sql((new SearchQuery())->where('city', 'Budapest')));
    }

    public function testNegationsOfAValueAreRefusedBecauseTheStoredValuesAreTruncated(): void
    {
        foreach ([['!=', 'Budapest', '!='], ['not in', ['a', 'b'], 'NOT IN'], ['not in', [], 'NOT IN']] as [$operator, $value, $shown]) {
            try {
                self::sql((new SearchQuery())->where('city', $operator, $value));
                self::fail($shown . ' on a many filter');
            } catch (InvalidQuery $e) {
                self::assertStringContainsString('Filter "city" takes several values per document and stores only the first ones, so ' . $shown . ' could let through', $e->getMessage());
            }
        }
    }

    public function testListsOfValuesAreAnOverlap(): void
    {
        self::assertSame('coalesce(s."f_city" && ARRAY[:p0, :p1]::text[], false)', self::sql((new SearchQuery())->whereIn('city', ['a', 'b'])));
        self::assertSame('FALSE', self::sql((new SearchQuery())->whereIn('city', [])));
    }

    public function testNotInIsRefusedAsAnOrderingOperatorIsAndNamesTheOperator(): void
    {
        try {
            self::sql((new SearchQuery())->where('city', '>', 'a'));
            self::fail('a range on a many filter');
        } catch (InvalidQuery $e) {
            self::assertSame('Filter "city" takes several values per document: it supports =, IN and null checks (any value matches), not >.', $e->getMessage());
        }
    }

    public function testAnInListMayHoldAThousandValuesAndNoMore(): void
    {
        $thousand = range(1, 1000);

        self::assertStringStartsWith('s."f_price" IN (:p0, :p1', self::sql((new SearchQuery())->whereIn('price', $thousand)));
        self::assertStringStartsWith('coalesce(s."f_city" && ARRAY[:p0', self::sql((new SearchQuery())->whereIn('city', array_map('strval', $thousand))));
        foreach (['price', 'city'] as $filter) {
            try {
                self::sql((new SearchQuery())->whereIn($filter, array_map(static fn(int $i): string => (string) $i, range(1, 1001))));
                self::fail('too many values for ' . $filter);
            } catch (InvalidQuery $e) {
                self::assertSame(sprintf('Filter "%s" accepts at most 1000 values in IN().', $filter), $e->getMessage());
            }
        }
    }
}

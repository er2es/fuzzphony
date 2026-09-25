<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testIsImmutable(): void
    {
        $base = new SearchQuery('mouse');
        $filtered = $base->where('price', '<', 100);

        self::assertSame([], $base->conditions);
        self::assertCount(1, $filtered->conditions);
        self::assertNotSame($base, $filtered);
    }

    public function testTwoArgumentWhereMeansEquals(): void
    {
        $condition = (new SearchQuery())->where('in_stock', true)->conditions[0];

        self::assertSame(Operator::Eq, $condition->operator);
        self::assertTrue($condition->value);
    }

    public function testOperatorAliases(): void
    {
        self::assertSame(Operator::Neq, Operator::parse('<>'));
        self::assertSame(Operator::Eq, Operator::parse('=='));
        self::assertSame(Operator::NotIn, Operator::parse('NOT   IN'));
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessageMatches('/Unknown operator "~".*<=/');
        Operator::parse('~');
    }

    public function testParsingAnOperatorInstanceReturnsItUnchanged(): void
    {
        self::assertSame(Operator::Gte, Operator::parse(Operator::Gte));
    }

    public function testPagination(): void
    {
        $query = (new SearchQuery())->page(3, 25);

        self::assertSame(25, $query->limit);
        self::assertSame(50, $query->offset);
    }

    public function testRejectsAbsurdLimits(): void
    {
        $this->expectException(InvalidQuery::class);
        (new SearchQuery())->limit(0);
    }

    public function testHighlightFieldsAreUnique(): void
    {
        self::assertSame(['name', 'brand'], (new SearchQuery())->highlight('name')->highlight('brand', 'name')->highlight);
    }

    public function testRankingOverridesAccumulate(): void
    {
        $query = (new SearchQuery())->ranking(['fuzzy' => 0.9])->ranking(['boost' => 0.1, 'fuzzy' => 0.8]);

        self::assertSame(['fuzzy' => 0.8, 'boost' => 0.1], $query->rankingOverrides);
    }

    public function testForTenantSetsTheTenantValue(): void
    {
        $query = (new SearchQuery())->forTenant(42);

        self::assertSame(42, $query->tenant);
    }

    public function testTenantDefaultsToNull(): void
    {
        self::assertNull((new SearchQuery())->tenant);
    }

    public function testTenantSurvivesASubsequentWither(): void
    {
        $query = (new SearchQuery())->forTenant(42)->withText('x')->limit(5);

        self::assertSame(42, $query->tenant);
    }

    public function testMissingTenantMessage(): void
    {
        $exception = InvalidQuery::missingTenant('products');

        self::assertSame('Index "products" requires forTenant(); none was given.', $exception->getMessage());
    }

    public function testUnexpectedTenantMessage(): void
    {
        $exception = InvalidQuery::unexpectedTenant('products');

        self::assertSame('Index "products" is not tenant-scoped; forTenant() has no effect here.', $exception->getMessage());
    }

    public function testUnknownProfileMessage(): void
    {
        $exception = InvalidQuery::unknownProfile('products', 'obsolete', ['default', 'popular']);

        self::assertSame('Index "products" has no ranking profile "obsolete". Known profiles: default, popular.', $exception->getMessage());
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Offset must be >= 0.');
        new SearchQuery(offset: -1);
    }

    public function testThreeArgumentWhereRejectsANonStringNonOperatorOperator(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('The operator must be a string such as "<=" or an Operator case.');
        (new SearchQuery())->where('price', 42, 100);
    }

    public function testAConditionsListContainingANonConditionIsIgnored(): void
    {
        // conditions is a public constructor parameter with no runtime type enforcement of its
        // items; a caller that bypasses the fluent API can still hand it garbage. The next
        // with*() call must not silently accept a corrupted list: it keeps the previous value.
        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime fallback)
        $query = new SearchQuery(conditions: ['not a condition']);

        $after = $query->where('price', '=', 1);

        self::assertSame(['not a condition'], $after->conditions);
    }

    public function testAHighlightListContainingANonStringIsIgnored(): void
    {
        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime fallback)
        $query = new SearchQuery(highlight: [42]);

        $after = $query->highlight('name');

        self::assertSame([42], $after->highlight);
    }

    public function testThresholdOverridesWithNonStringKeysAreIgnored(): void
    {
        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime fallback)
        $query = (new SearchQuery())->thresholds([5 => 'x']);

        self::assertSame([], $query->thresholdOverrides);
    }
}

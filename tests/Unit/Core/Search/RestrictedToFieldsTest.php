<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class RestrictedToFieldsTest extends TestCase
{
    private function builder(): SearchBuilder
    {
        return new SearchBuilder(self::createStub(Engine::class), Indexes::products());
    }

    public function testTheFieldsReachTheQuery(): void
    {
        self::assertSame(['name', 'brand'], $this->builder()->restrictedToFields('name', 'brand')->toQuery()->allowedFields);
    }

    public function testWithoutTheCallEveryFieldIsAllowed(): void
    {
        self::assertNull($this->builder()->toQuery()->allowedFields);
        self::assertNull((new SearchQuery())->allowedFields);
    }

    public function testAnUnknownFieldIsRefusedNamingTheKnownOnes(): void
    {
        try {
            $this->builder()->restrictedToFields('name', 'salary');
            self::fail('Expected an InvalidQuery');
        } catch (InvalidQuery $e) {
            self::assertSame('Index "products" has no field "salary". Known fields: name, brand, description.', $e->getMessage());
        }
    }

    public function testASecondCallCanOnlyNarrow(): void
    {
        $builder = $this->builder()->restrictedToFields('name', 'brand');

        self::assertSame(['brand'], $builder->restrictedToFields('brand', 'description')->toQuery()->allowedFields);
        self::assertSame([], $builder->restrictedToFields()->toQuery()->allowedFields, 'an empty list leaves no field');
        self::assertSame(['name', 'brand'], $builder->toQuery()->allowedFields, 'the builder is immutable');
    }

    public function testDuplicatesAreDropped(): void
    {
        self::assertSame(['name'], $this->builder()->restrictedToFields('name', 'name')->toQuery()->allowedFields);
    }

    public function testEveryWithPathKeepsTheList(): void
    {
        $query = $this->builder()->restrictedToFields('name')->toQuery();

        self::assertSame(['name'], $query->withText('x')->allowedFields);
        self::assertSame(['name'], $query->where('price', '<', 5)->allowedFields);
        self::assertSame(['name'], $query->whereIn('price', [1])->allowedFields);
        self::assertSame(['name'], $query->whereBetween('price', 1, 2)->allowedFields);
        self::assertSame(['name'], $query->whereNull('price')->allowedFields);
        self::assertSame(['name'], $query->forTenant(3)->allowedFields);
        self::assertSame(['name'], $query->withActor(Actor::anonymous())->allowedFields);
        self::assertSame(['name'], $query->profile('popular')->allowedFields);
        self::assertSame(['name'], $query->limit(5, 5)->allowedFields);
        self::assertSame(['name'], $query->page(2)->allowedFields);
        self::assertSame(['name'], $query->highlight('name')->allowedFields);
        self::assertSame(['name'], $query->thresholds(['min_score' => 0.1])->allowedFields);
        self::assertSame(['name'], $query->ranking(['fuzzy' => 0.8])->allowedFields);
        self::assertSame(['name'], $query->facets('brand_id')->allowedFields);
        self::assertSame(['name'], $query->facetValues(5)->allowedFields);
        self::assertSame(['name'], $query->asYouType()->allowedFields);
        self::assertSame(['name'], $query->exactCounts()->allowedFields);
        self::assertSame(['name'], $query->unlogged()->allowedFields);
    }

    public function testNoListStaysNoListAndAnEmptyListStaysEmpty(): void
    {
        self::assertNull((new SearchQuery())->withText('x')->allowedFields, 'null stays null through copy()');
        self::assertSame([], (new SearchQuery())->restrictedTo([])->withText('x')->limit(3)->allowedFields);
    }
}

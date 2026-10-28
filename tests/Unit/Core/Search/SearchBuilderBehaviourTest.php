<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SearchBuilderBehaviourTest extends TestCase
{
    private function builder(?Engine $engine = null): SearchBuilder
    {
        return new SearchBuilder($engine ?? self::createStub(Engine::class), Indexes::products());
    }

    public function testWhereNullMeansIsNullUnlessToldOtherwise(): void
    {
        self::assertSame(Operator::IsNull, $this->builder()->whereNull('brand_id')->toQuery()->conditions[0]->operator);
        self::assertSame(Operator::IsNotNull, $this->builder()->whereNull('brand_id', false)->toQuery()->conditions[0]->operator);
    }

    public function testPageUsesTwentyHitsUnlessToldOtherwise(): void
    {
        $query = $this->builder()->page(3)->toQuery();

        self::assertSame(20, $query->limit);
        self::assertSame(40, $query->offset);
        self::assertSame(5, $this->builder()->page(2, 5)->toQuery()->limit);
    }

    public function testAnUnknownProfileFailsWhenItIsChosen(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Index "products" has no ranking profile "nope".');
        $this->builder()->profile('nope');
    }

    public function testBadThresholdsFailWhenTheyAreSetNotWhenTheSearchRuns(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Index "products": invalid thresholds for this query: "min_score" must be a number.');
        $this->builder()->thresholds(['min_score' => 'not a number']);
    }

    public function testATypoInAPerQueryThresholdOrRankingIsAnInvalidQueryNamingTheIndex(): void
    {
        foreach ([
            static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['min_scor' => 1]),
            static fn(SearchBuilder $b): SearchBuilder => $b->ranking(['bost' => 1]),
            static fn(SearchBuilder $b): SearchBuilder => $b->ranking(['label_weights' => ['E' => 1]]),
        ] as $attempt) {
            try {
                $attempt($this->builder());
                self::fail('InvalidQuery expected');
            } catch (InvalidQuery $e) {
                self::assertStringStartsWith('Index "products": invalid ', $e->getMessage());
                self::assertInstanceOf(InvalidDefinition::class, $e->getPrevious());
            }
        }
    }

    public function testExplainIsNotAnalyzedByDefault(): void
    {
        $explanation = new Explanation('x', [], [], SearchResult::empty(20, 0));
        $calls = [];
        $engine = self::createStub(Engine::class);
        $engine->method('explain')->willReturnCallback(static function (IndexDefinition $index, SearchQuery $query, bool $analyze) use (&$calls, $explanation): Explanation {
            $calls[] = $analyze;

            return $explanation;
        });

        $builder = $this->builder($engine)->query('mouse');
        self::assertSame($explanation, $builder->explain());
        self::assertSame($explanation, $builder->explain(true));
        self::assertSame([false, true], $calls);
    }
}

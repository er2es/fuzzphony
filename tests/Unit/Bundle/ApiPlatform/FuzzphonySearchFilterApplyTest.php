<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the filter asks the engine and when it leaves the collection alone, against an engine that records the queries (no database). */
final class FuzzphonySearchFilterApplyTest extends TestCase
{
    /** @var list<SearchQuery> */
    private array $queries = [];

    /** @return iterable<string, array{int|null, int}> */
    public static function maxResults(): iterable
    {
        yield 'the default' => [null, 500];
        yield 'zero is raised to one' => [0, 1];
        yield 'negative is raised to one' => [-7, 1];
        yield 'one' => [1, 1];
        yield 'two' => [2, 2];
        yield 'below the cap' => [999, 999];
        yield 'the cap' => [1000, 1000];
        yield 'above the cap' => [1001, 1000];
        yield 'far above the cap' => [100000, 1000];
    }

    #[DataProvider('maxResults')]
    public function testTheNumberOfMatchesIsLimitedToOneToAThousand(?int $maxResults, int $expected): void
    {
        $filter = $maxResults === null ? $this->filter() : $this->filter(maxResults: $maxResults);

        $filter->apply($this->queryBuilder(), new QueryNameGenerator(), 'products', null, ['filters' => ['q' => 'mouse']]);

        self::assertCount(1, $this->queries);
        self::assertSame($expected, $this->queries[0]->limit);
        self::assertSame('mouse', $this->queries[0]->text);
        self::assertSame('default', $this->queries[0]->profile);
    }

    public function testTheParameterAndTheProfileCanBeChosen(): void
    {
        $filter = $this->filter(parameterName: 'search', profile: 'popular');

        $filter->apply($this->queryBuilder(), new QueryNameGenerator(), 'products', null, ['filters' => ['q' => 'ignored', 'search' => 'mouse']]);

        self::assertSame('mouse', $this->queries[0]->text);
        self::assertSame('popular', $this->queries[0]->profile);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function untouched(): iterable
    {
        yield 'no filters at all' => [[], 'products'];
        yield 'filters of another shape' => [['filters' => 'q=mouse'], 'products'];
        yield 'no parameter' => [['filters' => ['other' => 'mouse']], 'products'];
        yield 'an empty text' => [['filters' => ['q' => '']], 'products'];
        yield 'a blank text' => [['filters' => ['q' => " \t\n"]], 'products'];
        yield 'a text that is no string' => [['filters' => ['q' => ['mouse']]], 'products'];
        yield 'a resource without an index' => [['filters' => ['q' => 'mouse']], 'Some\\Other\\Resource'];
    }

    /** @param array<string, mixed> $context */
    #[DataProvider('untouched')]
    public function testTheCollectionIsLeftAloneWithoutSomethingToSearchFor(array $context, string $resourceClass): void
    {
        $queryBuilder = $this->queryBuilder();
        $before = $queryBuilder->getDQL();

        $this->filter()->apply($queryBuilder, new QueryNameGenerator(), $resourceClass, null, $context);

        self::assertSame([], $this->queries, 'the engine was not asked');
        self::assertSame($before, $queryBuilder->getDQL());
    }

    public function testNoHitsRestrictsTheCollectionToNothingAndHitsToTheirIdsInRankingOrder(): void
    {
        $queryBuilder = $this->queryBuilder();
        $this->filter()->apply($queryBuilder, new QueryNameGenerator(), 'products', null, ['filters' => ['q' => 'nothing']]);
        self::assertStringContainsString('WHERE 1 = 0', $queryBuilder->getDQL());

        $queryBuilder = $this->queryBuilder();
        $this->filter(hits: [3, 1, 2])->apply($queryBuilder, new QueryNameGenerator(), 'products', null, ['filters' => ['q' => 'mouse']]);
        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('a.id IN (:fuzzphony_ids_p1)', $dql);
        self::assertStringContainsString('(CASE WHEN a.id = :fuzzphony_rank_p2 THEN 0 WHEN a.id = :fuzzphony_rank_p3 THEN 1 WHEN a.id = :fuzzphony_rank_p4 THEN 2 ELSE 3 END) AS HIDDEN fuzzphony_rank', $dql);
        self::assertStringContainsString('ORDER BY fuzzphony_rank ASC', $dql);
        self::assertEquals([3, 1, 2], $queryBuilder->getParameter('fuzzphony_ids_p1')?->getValue());
    }

    /** @param list<int> $hits ids the engine finds */
    private function filter(array $hits = [], string $parameterName = 'q', string $profile = 'default', ?int $maxResults = null): FuzzphonySearchFilter
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query) use ($hits): SearchResult {
            $this->queries[] = $query;

            return new SearchResult(array_map(static fn(int $id): Hit => new Hit($id, 1.0, new ScoreBreakdown()), $hits), count($hits), false, 1.0, false, [], $query->limit, 0);
        });
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]));

        return $maxResults === null
            ? new FuzzphonySearchFilter($fuzzphony, $parameterName, $profile)
            : new FuzzphonySearchFilter($fuzzphony, $parameterName, $profile, $maxResults);
    }

    private function queryBuilder(): QueryBuilder
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getSingleIdentifierFieldName')->willReturn('id');
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        return (new QueryBuilder($entityManager))->select('a')->from(\stdClass::class, 'a');
    }
}

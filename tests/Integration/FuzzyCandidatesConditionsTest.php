<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The candidates of a typo-tolerant word (FuzzyQueryCompiler::probes()) are asked with the search's own conditions:
 * an explain() on a tenant-scoped index never shows, in its bound ids, a document of another tenant, and a filtered
 * search probes only the documents that pass the filter.
 * Fixtures: "mouse" is in products 1 and 4 (brand 1), and 2 (brand 2, price 19 990).
 */
final class FuzzyCandidatesConditionsTest extends TestCase
{
    private function fuzzphony(bool $tenant): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: $tenant)]), new SearchGate());
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testAnExplainOnATenantScopedIndexBindsNoIdOfAnotherTenant(): void
    {
        $explanation = $this->fuzzphony(true)->in('products')->query('mousse')->thresholds(['fuzzy_mode' => 'always'])->asActor(new Actor(tenant: 1))->explain();

        self::assertEqualsCanonicalizing([1, 4], $explanation->result->ids());
        self::assertSame(['1', '4'], self::boundIds($explanation), 'product 2 (brand 2) matches the word, but is another tenant\'s');
    }

    public function testAFilteredSearchProbesOnlyTheDocumentsThatPassTheFilter(): void
    {
        $explanation = $this->fuzzphony(false)->in('products')->query('mousse')->where('price', '<', 5_000)->thresholds(['fuzzy_mode' => 'always'])->explain();

        self::assertEqualsCanonicalizing([1, 4], $explanation->result->ids());
        self::assertSame(['1', '4'], self::boundIds($explanation));
    }

    public function testWithoutConditionsEveryMatchIsACandidate(): void
    {
        $explanation = $this->fuzzphony(false)->in('products')->query('mousse')->thresholds(['fuzzy_mode' => 'always'])->explain();

        self::assertSame(['1', '2', '4'], self::boundIds($explanation));
    }

    public function testAFacetLeavingItsFilterOutStillCountsEveryValue(): void
    {
        // the candidates passed in_stock = false; the in_stock facet leaves that condition out
        $result = $this->fuzzphony(false)->in('products')->query('mousse')->where('in_stock', false)->thresholds(['fuzzy_mode' => 'always'])->facets('in_stock')->get();

        self::assertSame([], $result->ids());
        self::assertSame([[true, 3]], array_map(static fn($v): array => [$v->value, $v->count], $result->facets['in_stock']));
    }

    /**
     * The ids bound as candidates (`CAST(:p AS bigint[])`) in every statement, sorted, without duplicates.
     *
     * @return list<string>
     */
    private static function boundIds(Explanation $explanation): array
    {
        $ids = [];
        foreach ($explanation->statements as $statement) {
            foreach ($statement['params'] as $value) {
                if (is_string($value) && preg_match('/^\{[\d,]*\}$/', $value) === 1) {
                    array_push($ids, ...array_filter(explode(',', trim($value, '{}')), static fn(string $id): bool => $id !== ''));
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Each typo-tolerant word is asked for its matches alone first (FuzzyQueryCompiler::probes()) and the search uses
 * them as ids: the same documents, without the trigram index returning every row that merely starts alike.
 */
final class FuzzyCandidatesTest extends TestCase
{
    public function testTheSearchUsesTheIdsOfEachWordAndFindsTheSameDocuments(): void
    {
        $search = $this->fuzzphony(2000)->in('products');

        $result = $search->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])->limit(5)->get();

        self::assertSame([1, 2, 3, 4, 5], self::ids($result));
        $sql = $search->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])->limit(5)->explain()->statements[0]['sql'];
        self::assertStringContainsString('s.id = ANY(q.id', $sql, 'the words are asked for first');
        self::assertStringNotContainsString('OPERATOR("public".<%)', $sql);
    }

    public function testAWordWithMoreMatchesThanTheCandidateLimitKeepsItsTrigramCondition(): void
    {
        $search = $this->fuzzphony(10)->in('products');

        $result = $search->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])->limit(5)->get();

        self::assertSame([1, 2, 3, 4, 5], self::ids($result), 'the same documents either way');
        $sql = $search->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])->limit(5)->explain()->statements[0]['sql'];
        self::assertStringNotContainsString('ANY(q.id', $sql);
        self::assertStringContainsString('OPERATOR("public".<%)', $sql);
    }

    public function testAWordThatMatchesNothingGivesAnEmptyListAndNoDocument(): void
    {
        $search = $this->fuzzphony(2000)->in('products');

        $result = $search->query('qzxwvu')->thresholds(['fuzzy_mode' => 'always'])->get();

        self::assertSame([], self::ids($result));
    }

    public function testTheFacetsOfASearchUseTheSameIds(): void
    {
        $result = $this->fuzzphony(2000)->in('products')->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])->facets('in_stock')->limit(5)->get();

        self::assertSame(['in_stock'], array_keys($result->facets));
        self::assertSame(12, $result->facets['in_stock'][0]->count);
    }

    private function fuzzphony(int $candidateLimit): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        $products = [];
        for ($i = 1; $i <= 12; ++$i) {
            $products[] = [$i, 'Wireless mouse ' . $i, 'Silent mouse', 1, 3_990, true, 2.0, 'now'];
        }
        PostgresTestCase::createFixtures($connection, ['brands' => [[1, 'Acme']], 'products' => $products]);
        $index = Indexes::products('manual')->withThresholds(new Thresholds(candidateLimit: $candidateLimit, didYouMean: false));
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    /** @return list<int|string> */
    private static function ids(SearchResult $result): array
    {
        return array_map(static fn($hit) => $hit->id, $result->hits);
    }
}

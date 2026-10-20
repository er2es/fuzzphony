<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SearchSqlBuilderMutationsTest extends TestCase
{
    public function testTheFacetReadsItsFilterColumnOfTheSidecarTableNotOfTheScoredSet(): void
    {
        $sql = (new SearchSqlBuilder(Indexes::products()))->facet('brand_id', "'mouse'", 'mouse', new Term('mouse'), [], new RankingProfile(), new Thresholds(), 10, false)['sql'];

        self::assertStringContainsString('SELECT s."f_brand_id" AS value, count(*) AS n', $sql);
        self::assertStringContainsString("GROUP BY s.\"f_brand_id\"\nORDER BY n DESC", $sql);
    }

    public function testSharedFacetsReadTheCandidatesOnceAndKeepEachFacetsOrderAndLimit(): void
    {
        $min = (new Thresholds())->minScore;
        $sql = (new SearchSqlBuilder(Indexes::products()))->facets(['brand_id', 'in_stock'], "'mouse'", 'mouse', new Term('mouse'), [], new RankingProfile(), new Thresholds(), 7, false)['sql'];

        self::assertSame(1, substr_count($sql, 'cand AS ('), 'one candidate stage');
        self::assertStringContainsString("fc AS (
    SELECT s.\"f_brand_id\" AS v0, s.\"f_in_stock\" AS v1
    FROM scored AS sc JOIN ", $sql);
        self::assertStringContainsString('WHERE sc.relevance >= ' . \Fuzzphony\Engine\Postgres\Sql\Sql::float($min) . "
)", $sql);
        self::assertStringContainsString('SELECT 0 AS f, g.rk, g.value::text AS value, g.n FROM (SELECT v0 AS value, count(*) AS n, row_number() OVER (ORDER BY count(*) DESC, v0 NULLS LAST) AS rk FROM fc GROUP BY v0 ORDER BY rk LIMIT 7) AS g', $sql);
        self::assertStringContainsString('SELECT 1 AS f, g.rk, g.value::int::text AS value, g.n FROM (SELECT v1 AS value, count(*) AS n, row_number() OVER (ORDER BY count(*) DESC, v1 NULLS LAST) AS rk FROM fc GROUP BY v1 ORDER BY rk LIMIT 7) AS g', $sql, 'a bool travels as 1 / 0');
        self::assertStringContainsString("
UNION ALL
", $sql);
        self::assertStringEndsWith("
ORDER BY f, rk", $sql);
        self::assertStringContainsString("cand AS (
", $sql);
        self::assertStringNotContainsString('LIMIT 7) AS g' . "
" . 'SELECT', $sql);
    }

    public function testSharedFacetsOfASearchWithoutTextReadTheSidecarOnceUpToTheCandidateLimitUnlessExact(): void
    {
        $builder = new SearchSqlBuilder(Indexes::products());
        $thresholds = new Thresholds();

        $capped = $builder->browseFacets(['brand_id', 'in_stock'], [], $thresholds, 5, false)['sql'];
        self::assertStringContainsString('SELECT s."f_brand_id" AS v0, s."f_in_stock" AS v1 FROM ', $capped);
        self::assertStringContainsString("
    LIMIT " . $thresholds->candidateLimit . "
)", $capped);
        self::assertSame(1, substr_count($capped, 'AS v0'), 'the sidecar is read once');

        $exact = $builder->browseFacets(['brand_id', 'in_stock'], [], $thresholds, 5, true)['sql'];
        self::assertStringNotContainsString('LIMIT ' . $thresholds->candidateLimit, $exact);
        self::assertStringContainsString('LIMIT 5) AS g', $exact);
    }
}

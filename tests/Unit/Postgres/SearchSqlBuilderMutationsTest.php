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
}

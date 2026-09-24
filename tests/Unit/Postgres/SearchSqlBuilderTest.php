<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SearchSqlBuilderTest extends TestCase
{
    public function testOnlyUserInputIsBound(): void
    {
        $conditions = (new SearchQuery())->where('price', '<', 500)->conditions;
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', true, $conditions, new RankingProfile(), new Thresholds(minScore: 0.1), 20, 40);

        self::assertSame(['p0' => "'mouse'", 'p1' => 'mouse', 'p2' => 500, 'p3' => 500], $statement['params']);
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[]", $statement['sql']);
        self::assertStringContainsString('q.norm <% s.fz', $statement['sql']);
        self::assertStringContainsString('WHERE relevance >= 0.1', $statement['sql']);
        self::assertStringContainsString('LIMIT 20 OFFSET 40', $statement['sql']);
        self::assertStringContainsString('LIMIT 2000', $statement['sql']);
    }

    public function testExclusionsAlsoFilterFuzzyCandidates(): void
    {
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("('wireless' & !'cable')", 'wireless', true, [], new RankingProfile(), new Thresholds(), 10, 0, "'cable'");

        self::assertStringContainsString('AND NOT (s.tsv @@ q.excl)', $statement['sql']);
        self::assertContains("'cable'", $statement['params']);
    }

    public function testTextOnlyHasNoFuzzyBranch(): void
    {
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', false, [], new RankingProfile(), new Thresholds(), 10, 0);

        self::assertStringNotContainsString('fuzzy AS (', $statement['sql']);
        self::assertStringContainsString('0 AS fuzzy_n', $statement['sql']);
    }

    public function testBoostAndRecencyOnlyWhenTheProfileUsesThem(): void
    {
        $builder = new SearchSqlBuilder(Indexes::products());

        self::assertStringNotContainsString('s.boost', $builder->ranked("'a'", 'a', false, [], new RankingProfile(), new Thresholds(), 10, 0)['sql']);
        $popular = $builder->ranked("'a'", 'a', false, [], new RankingProfile(boost: 0.1, recency: 0.3, recencyHalfLifeDays: 14), new Thresholds(), 10, 0)['sql'];
        self::assertStringContainsString('0.1 * coalesce(s.boost, 0)', $popular);
        self::assertStringContainsString('(86400.0 * 14)', $popular);
    }

    public function testBrowseIsBoundedByTheCandidateLimit(): void
    {
        $statement = (new SearchSqlBuilder(Indexes::products()))->browse([], new RankingProfile(), new Thresholds(candidateLimit: 100), 10, 0);

        self::assertStringContainsString('LIMIT 100', $statement['sql']);
        self::assertSame([], $statement['params']);
    }
}

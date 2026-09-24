<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
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
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', new Term('mouse'), $conditions, new RankingProfile(), new Thresholds(minScore: 0.1), 20, 40);

        // q.tsq, q.norm, fts filter, fuzzy predicate (tsquery, needle), fuzzy score (needle, tsquery), fuzzy filter
        self::assertSame(
            ['p0' => "'mouse'", 'p1' => 'mouse', 'p2' => 500, 'p3' => "'mouse'", 'p4' => 'mouse', 'p5' => 'mouse', 'p6' => "'mouse'", 'p7' => 500],
            $statement['params'],
        );
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[]", $statement['sql']);
        self::assertStringContainsString("WHERE (s.tsv @@ to_tsquery('fuzzphony_english'::regconfig, :p3) OR fuzzphony_norm(:p4) <% s.fz) AND", $statement['sql']);
        self::assertStringContainsString('(GREATEST(word_similarity(fuzzphony_norm(:p5), s.fz)', $statement['sql']);
        self::assertStringNotContainsString('q.norm <% s.fz', $statement['sql'], 'the whole query is no longer one trigram check');
        self::assertStringContainsString('WHERE relevance >= 0.1', $statement['sql']);
        self::assertStringContainsString('LIMIT 20 OFFSET 40', $statement['sql']);
        self::assertStringContainsString('LIMIT 2000', $statement['sql']);
    }

    public function testNegationIsCompiledIntoTheFuzzyPredicateAtAnyDepth(): void
    {
        $root = (new QueryParser())->parse('(mouse -cable) | trackpad')->root;
        self::assertNotNull($root);
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("(('mouse' & !'cable') | 'trackpad')", 'mouse trackpad', $root, [], new RankingProfile(), new Thresholds(), 10, 0);

        self::assertMatchesRegularExpression('/fuzzy AS \(.*AND NOT \(s\.tsv @@ to_tsquery\(\'fuzzphony_english\'::regconfig, :p\d+\)\)\) OR /s', $statement['sql']);
        self::assertContains("'cable'", $statement['params']);
        self::assertStringNotContainsString('excl', $statement['sql']);
    }

    public function testStopWordsAreDroppedFromTheFuzzyBranch(): void
    {
        $root = (new QueryParser())->parse('mouse for')->root;
        self::assertNotNull($root);
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("('mouse' & 'for')", 'mouse for', $root, [], new RankingProfile(), new Thresholds(), 10, 0, ["'for'"]);

        self::assertSame(['p0' => "('mouse' & 'for')", 'p1' => 'mouse for', 'p2' => "'mouse'", 'p3' => 'mouse', 'p4' => 'mouse', 'p5' => "'mouse'"], $statement['params']);
    }

    public function testTextOnlyHasNoFuzzyBranch(): void
    {
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0);

        self::assertStringNotContainsString('fuzzy AS (', $statement['sql']);
        self::assertStringContainsString('0 AS fuzzy_n', $statement['sql']);
    }

    public function testBoostAndRecencyOnlyWhenTheProfileUsesThem(): void
    {
        $builder = new SearchSqlBuilder(Indexes::products());

        self::assertStringNotContainsString('s.boost', $builder->ranked("'a'", 'a', null, [], new RankingProfile(), new Thresholds(), 10, 0)['sql']);
        $popular = $builder->ranked("'a'", 'a', null, [], new RankingProfile(boost: 0.1, recency: 0.3, recencyHalfLifeDays: 14), new Thresholds(), 10, 0)['sql'];
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

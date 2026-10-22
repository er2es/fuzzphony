<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The guarded selection of the strict branch: the fast CTE, and the ordered select that runs only when the fast one hit its limit. */
final class SearchSqlBuilderCandidatesTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, int}> candidate limit, limit, offset, expected window */
    public static function windows(): iterable
    {
        yield 'the first page: the candidate limit' => [2000, 20, 0, 2000];
        yield 'a page inside the limit' => [2000, 20, 1980, 2000];
        yield 'a page that ends past the limit: the end of the page' => [2000, 20, 1990, 2010];
        yield 'a deep page' => [2000, 20, 4980, 5000];
        yield 'a page larger than the limit' => [10, 20, 0, 20];
        yield 'the hard cap' => [2000, 20, 9990, 10_000];
        yield 'past the hard cap' => [2000, 20, 50_000, 10_000];
        yield 'the limit at the hard cap' => [10_000, 20, 0, 10_000];
    }

    #[DataProvider('windows')]
    public function testTheWindowIsTheCandidateLimitOrTheEndOfThePageAtMostTheHardCap(int $candidateLimit, int $limit, int $offset, int $expected): void
    {
        self::assertSame($expected, SearchSqlBuilder::window(new Thresholds(candidateLimit: $candidateLimit), $limit, $offset));
    }

    public function testTheStrictBranchIsTheFastSelectionAndAGuardedOrderedOne(): void
    {
        $sql = self::statement(new Thresholds(candidateLimit: 500), 20, 0);

        // the fast CTE is the statement of the limit, only named
        self::assertStringContainsString("fts_fast AS (\n    SELECT s.id, ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32)::double precision AS r_text\n    FROM \"public\".\"fuzzphony_products\" AS s CROSS JOIN q\n    WHERE s.tsv @@ q.tsq AND TRUE\n    LIMIT 500\n)", $sql);
        // the fast rows when they did not hit the limit ...
        self::assertStringContainsString('SELECT id, r_text FROM fts_fast WHERE (SELECT count(*) FROM fts_fast) < 500', $sql);
        // ... else the best of the first matches by the key of the page (relevance, the bonuses, then the id), as many as the window
        self::assertStringContainsString('WHERE (SELECT count(*) FROM fts_fast) >= 500', $sql);
        self::assertStringContainsString('ORDER BY 1 * w.r_text + w.exact_bonus + w.prefix_bonus + w.boost_bonus + w.recency_bonus DESC, w.id', $sql);
        self::assertStringContainsString("        LIMIT 500\n    ) AS o", $sql);
        self::assertStringContainsString("            LIMIT 100000\n        ) AS w", $sql, 'the scan budget');
        // the page orders by the same sum
        self::assertStringContainsString('relevance + exact_bonus + prefix_bonus + boost_bonus + recency_bonus AS score', $sql);
        // whether the matches were cut off is the fast selection's count, not the ordered one's
        self::assertStringContainsString('(SELECT count(*) FROM fts_fast) AS fts_n', $sql);
    }

    public function testTheBonusesOfTheOrderedSelectAreThoseOfTheScoredCte(): void
    {
        $sql = self::statement(new Thresholds(), 20, 0, new RankingProfile(boost: 0.3, recency: 0.2));

        preg_match_all("/CASE WHEN q\\.norm <> '' AND s\\.exact = q\\.norm THEN 0\\.5 ELSE 0 END::double precision/", $sql, $exact);
        preg_match_all("/CASE WHEN q\\.norm <> '' AND s\\.exact <> q\\.norm AND left\\(s\\.exact, length\\(q\\.norm\\)\\) = q\\.norm THEN 0\\.2 ELSE 0 END::double precision/", $sql, $prefix);
        preg_match_all('/0\.3 \* coalesce\(s\.boost, 0\)/', $sql, $boost);
        preg_match_all('/coalesce\(0\.2 \* exp\(/', $sql, $recency);
        foreach ([$exact, $prefix, $boost, $recency] as $matches) {
            self::assertCount(2, $matches[0], 'once in the ordered select, once in scored');
        }
    }

    public function testTheOrderedSelectKeepsTheLookupTheRecheckTheFiltersAndTheTenant(): void
    {
        $conditions = (new SearchQuery())->where('price', '<', 500)->where('in_stock', true)->conditions;
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, $conditions, new RankingProfile(), new Thresholds(), 20, 0);

        // the filters are compiled twice: in the fast CTE and in the ordered select
        self::assertSame(2, substr_count($statement['sql'], 's."f_price" < :p2'));
        self::assertSame(2, substr_count($statement['sql'], 's."f_in_stock" = :p3'));
        $ordered = [self::orderedSelect($statement['sql'])];
        self::assertStringContainsString('s.tsv @@ q.tsq', $ordered[0]);
        self::assertStringContainsString('s."f_price" < :p2 AND s."f_in_stock" = :p3', $ordered[0]);
    }

    public function testARestrictedViewersOrderedSelectReadsTheAllowedFieldsOnly(): void
    {
        $hidden = self::statement(new Thresholds(), 20, 0, new RankingProfile(boost: 0.3), ['brand', 'description']);
        $ordered = [self::orderedSelect($hidden)];
        self::assertStringContainsString('s."t_brand"', $ordered[0]);
        self::assertStringNotContainsString('s.tsv, q.tsq', $ordered[0]);
        self::assertStringNotContainsString('t_name', $ordered[0], 'the hidden field is not read');
        self::assertStringNotContainsString('s.exact', $ordered[0], 'the primary field is hidden: no exact or prefix bonus');
        self::assertStringContainsString('0::double precision AS exact_bonus, 0::double precision AS prefix_bonus', $ordered[0]);
        self::assertStringContainsString('s.tsv @@ q.tsq AND (s.tsv @@ q.sft0', $ordered[0], 'the lookup and the recheck');
        self::assertStringContainsString('q.sft0', $ordered[0], 'the recheck against the allowed fields');

        $allowed = self::statement(new Thresholds(), 20, 0, new RankingProfile(), ['name']);
        $ordered = [self::orderedSelect($allowed)];
        self::assertStringContainsString('s.exact = q.norm', $ordered[0]);
        self::assertStringNotContainsString('t_brand', $ordered[0]);
    }

    public function testMinScoreIsAConditionOfTheOrderedSelectOnlyWithoutTypoTolerance(): void
    {
        $strict = self::statement(new Thresholds(minScore: 0.15), 20, 0);
        self::assertStringContainsString('(SELECT count(*) FROM fts_fast) >= 2000 AND 1 * w.r_text >= 0.15', $strict);
        self::assertStringNotContainsString('w.r_text >= ', self::statement(new Thresholds(), 20, 0));

        // a typo-tolerant statement: a document may pass on its similarity, the strict selection does not cut by the text rank alone
        $root = self::parse('mouse');
        $fuzzy = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', $root, [], new RankingProfile(), new Thresholds(minScore: 0.15, fuzzySimilarity: 0.3), 20, 0, [], $root)['sql'];
        self::assertStringContainsString('fts_fast', $fuzzy);
        self::assertStringNotContainsString('w.r_text >= ', $fuzzy);
    }

    public function testFacetsSelectTheBestCandidatesAndExactCountsAndTheBrowseKeepTheFastSelection(): void
    {
        $builder = new SearchSqlBuilder(Indexes::products());
        $thresholds = new Thresholds();
        $profile = new RankingProfile();

        // the facets count the best `candidate_limit` matches, whatever the order of the heap: the ordered selection of the hits
        foreach ([
            $builder->facet('in_stock', "'mouse'", 'mouse', null, [], $profile, $thresholds, 10, false)['sql'],
            $builder->facets(['in_stock', 'brand_id'], "'mouse'", 'mouse', null, [], $profile, $thresholds, 10, false)['sql'],
        ] as $sql) {
            self::assertStringContainsString('fts_fast AS (', $sql);
            self::assertStringContainsString('ORDER BY 1 * w.r_text', $sql);
            self::assertStringContainsString('LIMIT 100000', $sql);
        }
        // a deep page does not make the facets count more candidates: their window is the candidate limit
        self::assertStringContainsString('count(*) FROM fts_fast) < 2000', $builder->facet('in_stock', "'mouse'", 'mouse', null, [], $profile, $thresholds, 10, false)['sql']);

        $statements = [
            $builder->facet('in_stock', "'mouse'", 'mouse', null, [], $profile, $thresholds, 10, true)['sql'],
            $builder->facets(['in_stock', 'brand_id'], "'mouse'", 'mouse', null, [], $profile, $thresholds, 10, true)['sql'],
            $builder->exactTotal("'mouse'", 'mouse', null, [], $profile, $thresholds)['sql'],
            $builder->browse([], $profile, $thresholds, 20, 0)['sql'],
        ];
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('fts_fast', $sql);
            self::assertStringNotContainsString('count(*) FROM fts)', $sql);
        }
        self::assertStringNotContainsString('LIMIT 2000', $statements[0], 'the exact counts have no limit at all');
        self::assertStringNotContainsString('LIMIT 2000', $statements[2], 'the exact count has no limit at all');
    }

    public function testTheScanBudgetIsAConstructorSeamOfTheBuilder(): void
    {
        $sql = (new SearchSqlBuilder(Indexes::products(), new Names(), [], null, false, 250_000))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 20, 0)['sql'];

        self::assertStringContainsString("            LIMIT 250000\n        ) AS w", $sql);
    }

    /** @return iterable<string, array{int, int, int, int}> budget, words, window, expected */
    public static function scans(): iterable
    {
        yield 'one word: the whole budget' => [100_000, 1, 2000, 100_000];
        yield 'four words' => [100_000, 4, 2000, 25_000];
        yield 'sixteen words' => [100_000, 16, 2000, 6250];
        yield 'never under the window' => [100_000, 64, 2000, 2000];
        yield 'a deep window is the floor' => [100_000, 16, 10_000, 10_000];
        yield 'no word counts as one' => [100_000, 0, 2000, 100_000];
    }

    #[DataProvider('scans')]
    public function testTheScanBudgetIsDividedByTheWordsOfTheQueryButNeverUnderTheWindow(int $budget, int $words, int $window, int $expected): void
    {
        self::assertSame($expected, SearchSqlBuilder::scanLimit($budget, $words, $window));
    }

    public function testTheScannedMatchesOfAQueryShrinkWithItsWords(): void
    {
        $builder = new SearchSqlBuilder(Indexes::products(), new Names(), [], null, false, 400_000);
        $statement = static fn(string $tsquery): string => $builder->ranked($tsquery, 'mouse', null, [], new RankingProfile(), new Thresholds(candidateLimit: 100), 20, 0)['sql'];

        self::assertStringContainsString("            LIMIT 400000\n        ) AS w", $statement("'mouse'"));
        self::assertStringContainsString("            LIMIT 100000\n        ) AS w", $statement("('mouse' | 'cable' | 'usb' | 'hub')"));
        self::assertStringContainsString("            LIMIT 25000\n        ) AS w", $statement("('mouse' | 'a' | 'b' | 'c' | 'd' | 'e' | 'f' | 'g' | 'h' | 'i' | 'j' | 'k' | 'l' | 'm' | 'n' | 'o')"));
        // the words that are excluded are not looked up for the rank: they do not count, and the rank reads the query without them
        $excluded = $statement("('mouse' & !'a' & !'b' & !'c' & !'d' & !'e')");
        self::assertStringContainsString("            LIMIT 400000\n        ) AS w", $excluded);
        self::assertStringContainsString('AS tsr', $excluded);
        self::assertStringContainsString('s.tsv @@ q.tsq', $excluded, 'the match is still the whole query');
    }

    /** @return iterable<string, array{string, string}> */
    public static function exclusions(): iterable
    {
        yield 'no exclusion: the query itself' => ["('a' & 'b')", "('a' & 'b')"];
        yield 'an excluded word of an AND' => ["('a' & !'b')", "'a'"];
        yield 'several' => ["('a' & !'b' & !'c' & !'d')", "'a'"];
        yield 'an excluded group' => ["('a' & !('b' & 'c'))", "'a'"];
        yield 'an excluded phrase' => ["('a' & !('b' <-> 'c'))", "'a'"];
        yield 'a phrase kept' => ["(('a' <-> 'b') & !'c')", "('a' <-> 'b')"];
        yield 'labels and prefixes kept' => ["('a':A & 'b':* & !'c')", "('a':A & 'b':*)"];
        yield 'an OR with an excluded part is true: left out of its AND' => ["('a' & ('b' | !'c'))", "'a'"];
        yield 'an AND with an excluded part inside an OR' => ["(('a' & !'b') | 'c')", "('a' | 'c')"];
        yield 'an OR that is true as a whole: the query itself' => ["('a' | !'b')", "('a' | !'b')"];
        yield 'nothing but exclusions: the query itself' => ["(!'a' & !'b')", "(!'a' & !'b')"];
        yield 'a form it does not know: the query itself' => ["'a' & !'b'", "'a' & !'b'"];
    }

    #[DataProvider('exclusions')]
    public function testTheRankQueryLeavesOutTheExclusions(string $tsquery, string $expected): void
    {
        self::assertSame($expected, TsQueryCompiler::withoutExclusions($tsquery));
    }

    public function testTheWordsOfAQueryAreCounted(): void
    {
        self::assertSame(1, TsQueryCompiler::operands("'a'"));
        self::assertSame(3, TsQueryCompiler::operands("(('a' <-> 'b') | 'c':*)"));
        self::assertSame(1, TsQueryCompiler::operands(''));
    }

    public function testADeepPageSelectsTheWholeWindowInBothSelections(): void
    {
        $sql = self::statement(new Thresholds(candidateLimit: 100), 20, 380);

        self::assertStringContainsString("WHERE s.tsv @@ q.tsq AND TRUE\n    LIMIT 400\n)", $sql);
        self::assertStringContainsString('(SELECT count(*) FROM fts_fast) >= 400', $sql);
        self::assertStringContainsString("        LIMIT 400\n    ) AS o", $sql);
        self::assertStringContainsString('LIMIT 20 OFFSET 380', $sql);
    }

    /** The `fts` CTE: the guarded fast rows and the ordered select. */
    private static function orderedSelect(string $sql): string
    {
        self::assertSame(1, preg_match('/fts AS \(.*?\n\)/s', $sql, $match));

        return $match[0] ?? '';
    }

    /** @param list<string>|null $allowed */
    private static function statement(Thresholds $thresholds, int $limit, int $offset, RankingProfile $profile = new RankingProfile(), ?array $allowed = null): string
    {
        if ($allowed === null) {
            return (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], $profile, $thresholds, $limit, $offset)['sql'];
        }
        $root = self::parse('mouse');

        return (new SearchSqlBuilder(Indexes::products(), new Names(), [], $allowed))->ranked("'mouse'", 'mouse', null, [], $profile, $thresholds, $limit, $offset, [], $root)['sql'];
    }

    private static function parse(string $text): Node
    {
        $root = (new QueryParser())->parse($text)->root;
        self::assertNotNull($root);

        return $root;
    }
}

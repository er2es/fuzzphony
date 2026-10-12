<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The statement of a viewer who may see only some fields: rank, bonuses, recheck (Indexes::products(): name A, brand B, description D). */
final class SearchSqlBuilderRestrictedTest extends TestCase
{
    public function testTheTextRankReadsOnlyTheAllowedFields(): void
    {
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.\"t_name\", q.tsq, 32)", self::ranked(['name'], 'mouse'));
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], (s.\"t_name\" || s.\"t_brand\"), q.tsq, 32)", self::ranked(['name', 'brand'], 'mouse'));
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], ''::tsvector, q.tsq, 32)", self::ranked([], 'mouse'));
        self::assertStringNotContainsString('s.tsv, q.tsq', self::ranked(['name'], 'mouse'));
    }

    public function testTheBonusesAreZeroWhenThePrimaryFieldIsHidden(): void
    {
        $hidden = self::ranked(['brand', 'description'], 'mouse');
        self::assertStringContainsString('0::double precision AS exact_bonus', $hidden);
        self::assertStringContainsString('0::double precision AS prefix_bonus', $hidden);
        self::assertStringNotContainsString('s.exact', $hidden, 'the exact column is the primary field');

        $allowed = self::ranked(['name'], 'mouse');
        self::assertStringContainsString("CASE WHEN q.norm <> '' AND s.exact = q.norm THEN 0.5 ELSE 0 END::double precision AS exact_bonus", $allowed);
        self::assertStringContainsString("CASE WHEN q.norm <> '' AND s.exact <> q.norm AND left(s.exact, length(q.norm)) = q.norm THEN 0.2 ELSE 0 END::double precision AS prefix_bonus", $allowed);
    }

    public function testTheStrictBranchIsRecheckedWithoutAFieldScopedWord(): void
    {
        $sql = self::ranked(['name'], 'mouse');

        self::assertStringContainsString('WHERE s.tsv @@ q.tsq AND (s.tsv @@ q.sft0 AND s."t_name" @@ q.sft0) AND TRUE', $sql);
    }

    public function testAnExcludedWordNeverNarrowsTheCandidatesByAHiddenField(): void
    {
        $sql = self::ranked(['name'], 'mouse -cable');

        // q.tsq ('mouse' & !'cable') would drop a document with "cable" in its hidden description; the index lookup is positive only
        self::assertMatchesRegularExpression('/AS tsq, .* AS tsp,/', $sql);
        self::assertStringContainsString('WHERE s.tsv @@ q.tsp AND ((s.tsv @@ q.sft0 AND s."t_name" @@ q.sft0) AND NOT ((s.tsv @@ q.sft1 AND s."t_name" @@ q.sft1))) AND TRUE', $sql);
        self::assertStringNotContainsString('s.tsv @@ q.tsq', $sql);
    }

    public function testAnOrWithAnExcludedBranchHasNoIndexLookupOnlyTheRecheck(): void
    {
        $sql = self::ranked(['name'], 'mouse | -cable');

        self::assertStringContainsString("fts AS (\n    SELECT s.id, ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.\"t_name\", q.tsq, 32)::double precision AS r_text\n    FROM \"public\".\"fuzzphony_products\" AS s CROSS JOIN q\n    WHERE ((s.tsv @@ q.sft0 AND s.\"t_name\" @@ q.sft0) OR NOT ((s.tsv @@ q.sft1 AND s.\"t_name\" @@ q.sft1))) AND TRUE", $sql);
    }

    public function testARestrictedStrictBranchWithoutTheQueryFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        (new SearchSqlBuilder(Indexes::products(), new Names(), [], ['name']))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0);
    }

    public function testTheFuzzyBranchFacetsTheExactTotalAndTheProbeAreRestricted(): void
    {
        $root = self::parse('mouse');
        $builder = new SearchSqlBuilder(Indexes::products(), new Names(), [], ['name']);
        $thresholds = new Thresholds(fuzzySimilarity: 0.3);
        $statements = [
            $builder->ranked("'mouse'", 'mouse', $root, [], new RankingProfile(), $thresholds, 10, 0, [], $root)['sql'],
            $builder->facet('in_stock', "'mouse'", 'mouse', $root, [], new RankingProfile(), $thresholds, 10, false, [], $root)['sql'],
            $builder->exactTotal("'mouse'", 'mouse', $root, [], new RankingProfile(), $thresholds, [], $root)['sql'],
        ];
        foreach ($statements as $sql) {
            self::assertStringContainsString('s."t_name" @@ q.sft0', $sql);
            self::assertStringContainsString('word_similarity(q.fn', $sql);
            self::assertDoesNotMatchRegularExpression('/t_brand|z_brand|t_description|word_similarity\([^)]*s\.fz\)/', $sql);
        }
        $probe = $builder->probe([new Term('mouse'), new Term('cable')], true, [], $thresholds)['sql'];
        self::assertStringContainsString('s."t_name" @@ q.ft0', $probe);
        self::assertDoesNotMatchRegularExpression('/t_brand|z_brand|t_description/', $probe);
    }

    public function testAProbeOfWordsOnHiddenFieldsMatchesNothing(): void
    {
        $probe = (new SearchSqlBuilder(Indexes::products(), new Names(), [], []))->probe([new Term('mouse'), new Term('cable')], true, [], new Thresholds())['sql'];

        self::assertStringContainsString('q AS MATERIALIZED (SELECT NULL AS none)', $probe);
        self::assertStringContainsString('CROSS JOIN q WHERE false AND TRUE', $probe);
    }

    public function testNullIsByteIdenticalToTheUnrestrictedStatement(): void
    {
        $conditions = (new SearchQuery())->where('price', '<', 500)->conditions;
        foreach (['mouse', 'brand:razr -cable', 'wireless (mouse | -brand:logitech)', '"usb receiver" ergo*'] as $text) {
            $root = self::parse($text);
            $tsquery = (new TsQueryCompiler(Indexes::products()))->compile($root);
            $scoped = (new TsQueryCompiler(Indexes::products()))->hasFieldScope($root) ? $root : null;
            $plain = new SearchSqlBuilder(Indexes::products(), new Names(), ['x' => [1]]);
            $null = new SearchSqlBuilder(Indexes::products(), new Names(), ['x' => [1]], null);
            foreach ([new Thresholds(), new Thresholds(fuzzySimilarity: 0.3)] as $t) {
                $args = [$tsquery, $text, $root, $conditions, new RankingProfile(), $t];
                self::assertSame($plain->ranked(...[...$args, 10, 0, [], $scoped]), $null->ranked(...[...$args, 10, 0, [], $scoped]), $text);
                self::assertSame($plain->facet('in_stock', ...[...$args, 10, true, [], $scoped]), $null->facet('in_stock', ...[...$args, 10, true, [], $scoped]), $text);
                self::assertSame($plain->exactTotal(...[...$args, [], $scoped]), $null->exactTotal(...[...$args, [], $scoped]), $text);
                self::assertSame($plain->probe([new Term('mouse'), new Term('cable')], true, $conditions, $t), $null->probe([new Term('mouse'), new Term('cable')], true, $conditions, $t), $text);
            }
        }
    }

    /** @param list<string> $allowed */
    private static function ranked(array $allowed, string $text): string
    {
        $root = self::parse($text);
        $tsquery = (new TsQueryCompiler(Indexes::products()))->compile($root);

        return (new SearchSqlBuilder(Indexes::products(), new Names(), [], $allowed))->ranked($tsquery, 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

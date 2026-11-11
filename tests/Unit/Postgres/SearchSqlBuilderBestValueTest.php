<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Ranking\TypoThresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\BestValueRank;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use PHPUnit\Framework\TestCase;

/** many: { rank: best }: the rank expression of the statement (spec 0.12 section 5.2), and nothing else changes. */
final class SearchSqlBuilderBestValueTest extends TestCase
{
    private const string W = "'{0.1,0.2,0.4,1}'::real[]";

    private static function index(string $rank = 'best', string $phones = 'whole'): IndexDefinition
    {
        $many = static fn(string $column, string $rank): array => ['from' => 'address', 'key' => 'customer_id', 'column' => $column, 'rank' => $rank];

        return IndexDefinition::builder('people')->fromTable('customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', fuzzy: true, granted: 'ROLE_CITY', many: $many('city', $rank))
            ->field('phone', 'D', many: $many('phone', $phones))
            ->field('note', 'B')
            ->filter('kind', 'string')
            ->build();
    }

    /**
     * @param list<string>|null $allowed
     * @param list<string>      $bestValues
     */
    private static function ranked(IndexDefinition $index, ?array $allowed, string $text, array $bestValues = [], bool $fuzzy = false): string
    {
        $root = self::parse($text);
        $compiler = new TsQueryCompiler($index);
        $scoped = $allowed !== null || $compiler->dependsOnPositions($root) ? $root : null;

        return (new SearchSqlBuilder($index, new Names(), [], $allowed, bestValues: $bestValues))->ranked($compiler->compile($root), $text, $fuzzy ? $root : null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $scoped)['sql'];
    }

    public function testTheRankIsTheBestValueWithTheWholeFieldRankAsFallback(): void
    {
        $sql = self::ranked(self::index(), null, 'fo utca szeged');

        self::assertStringContainsString("to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p1) AS tsany", $sql);
        self::assertStringContainsString(
            'coalesce(nullif(CASE WHEN NOT s."t_city" @@ q.tsany THEN NULL WHEN s."n_city" = 1 AND s.tsv_capped IS FALSE THEN NULL ELSE (SELECT max(ts_rank_cd(' . self::W . ', s."t_name" || v.t || s."t_phone" || s."t_note", q.tsq, 32)) FROM "public"."fuzzphony_people__values" AS v'
            . " WHERE v.doc = s.id AND v.field = 'city' AND v.t @@ q.tsany) END, 0), ts_rank_cd(" . self::W . ', s.tsv, q.tsq, 32))::double precision AS r_text',
            $sql,
        );
        self::assertStringNotContainsString('bv_', $sql, 'the best value is reported only when asked');
    }

    public function testEveryVisibleBestFieldTakesPartAndTheBestCounts(): void
    {
        $sql = self::ranked(self::index('best', 'best'), null, 'szeged');

        // with several fields a document with one value takes part with the whole-field rank (what its substitution gives)
        $whole = 'ts_rank_cd(' . self::W . ', s.tsv, q.tsq, 32)';
        self::assertStringContainsString('coalesce(nullif(greatest(CASE WHEN NOT s."t_city" @@ q.tsany THEN NULL WHEN s."n_city" = 1 AND s.tsv_capped IS FALSE THEN ' . $whole . ' ELSE (SELECT max(ts_rank_cd(' . self::W . ', s."t_name" || v.t || s."t_phone" || s."t_note", q.tsq, 32))', $sql);
        self::assertStringContainsString("v.field = 'city' AND v.t @@ q.tsany) END, CASE WHEN NOT s.\"t_phone\" @@ q.tsany THEN NULL WHEN s.\"n_phone\" = 1 AND s.tsv_capped IS FALSE THEN " . $whole . ' ELSE (SELECT max(ts_rank_cd(' . self::W . ', s."t_name" || s."t_city" || v.t || s."t_note", q.tsq, 32))', $sql);
        self::assertStringContainsString("v.field = 'phone' AND v.t @@ q.tsany) END), 0), ", $sql);
    }

    public function testARestrictedViewerSubstitutesInItsOwnFieldsOnly(): void
    {
        $sql = self::ranked(self::index('best', 'best'), ['name', 'city'], 'szeged');

        self::assertStringContainsString('ts_rank_cd(' . self::W . ', s."t_name" || v.t, q.tsq, 32)', $sql);
        self::assertStringNotContainsString("v.field = 'phone'", $sql, 'a field the viewer may not see never takes part');
        self::assertDoesNotMatchRegularExpression('/t_phone|t_note/', $sql);
        self::assertStringContainsString('(s."t_name" || s."t_city"), q.tsq, 32)', $sql, 'the fallback: the visible vector');
        self::assertStringContainsString('WHEN s."n_city" = 1 THEN NULL ELSE', $sql, 'one value: the visible vector is the substituted one, capped or not');
    }

    public function testTheRankQueryWithoutExclusionsAndPrefixesAndLabelsReachTheValues(): void
    {
        self::assertSame("'fo' | 'utca'", BestValueRank::anyQuery("('fo' & 'utca' & 'fo')"));
        self::assertSame("'sze':* | 'szeged':C", BestValueRank::anyQuery("('sze':* & 'szeged':C & !'kossuth')"));
        self::assertNull(BestValueRank::anyQuery("!'kossuth'"));
        self::assertNull(BestValueRank::anyQuery(''));

        $sql = self::ranked(self::index(), null, 'fo utca -kossuth');
        self::assertStringContainsString('q.tsr, 32)) FROM', $sql, 'the value rank reads the rank query without the exclusion');
    }

    public function testTheBestValueColumnsAndTheScanBudget(): void
    {
        $sql = self::ranked(self::index(), null, 'fo utca szeged', ['city', 'phone']);
        self::assertStringContainsString(
            ', (SELECT (SELECT b.ord FROM (SELECT v.ord, ts_rank_cd(' . self::W . ', s."t_name" || v.t || s."t_phone" || s."t_note", q.tsq, 32) AS r FROM "public"."fuzzphony_people__values" AS v'
            . " WHERE v.doc = s.id AND v.field = 'city' AND v.t @@ q.tsany) AS b WHERE b.r > 0 ORDER BY b.r DESC, b.ord LIMIT 1) FROM \"public\".\"fuzzphony_people\" AS s CROSS JOIN q WHERE s.id = page.id) AS \"bv_city\"\nFROM",
            $sql,
        );
        self::assertStringNotContainsString('bv_phone', $sql, 'a rank: whole field has no best value');
        // three words and one best field (two words' worth): the budget of one word divided by five
        self::assertStringContainsString('LIMIT 20000', $sql);
        self::assertStringContainsString('LIMIT 33333', self::ranked(self::index('whole'), null, 'fo utca szeged'));
    }

    public function testWithoutAVisibleBestFieldTheStatementIsTheWholeFieldOne(): void
    {
        $whole = self::index('whole');
        $best = self::index();
        $thresholds = [new Thresholds(), new Thresholds(typo: new TypoThresholds(similarity: 0.3))];
        foreach ([['name'], ['name', 'note'], []] as $allowed) {
            foreach (['szeged', 'fo utca -kossuth', 'city:szeged', '"fo utca" sze*', 'kiss OR nagy'] as $text) {
                $root = self::parse($text);
                $tsquery = (new TsQueryCompiler($best))->compile($root);
                $a = new SearchSqlBuilder($whole, new Names(), ['x' => [1]], $allowed);
                $b = new SearchSqlBuilder($best, new Names(), ['x' => [1]], $allowed, bestValues: ['city']);
                foreach ($thresholds as $t) {
                    $args = [$tsquery, $text, $root, [], new RankingProfile(), $t];
                    self::assertSame($a->ranked(...[...$args, 10, 0, [], $root]), $b->ranked(...[...$args, 10, 0, [], $root]), $text);
                    self::assertSame($a->ranked(...[...$args, 10, 0, [], $root, false, ['conditions' => [], 'filter' => 'kind', 'values' => 5]]), $b->ranked(...[...$args, 10, 0, [], $root, false, ['conditions' => [], 'filter' => 'kind', 'values' => 5]]), $text);
                    self::assertSame($a->facets(['kind'], ...[...$args, 10, true, [], $root]), $b->facets(['kind'], ...[...$args, 10, true, [], $root]), $text);
                    self::assertSame($a->exactTotal(...[...$args, [], $root]), $b->exactTotal(...[...$args, [], $root]), $text);
                    self::assertSame($a->probe([new Term('kiss'), new Term('szeged')], true, [], $t), $b->probe([new Term('kiss'), new Term('szeged')], true, [], $t), $text);
                }
            }
        }
        // a rank: whole field is never best-ranked, whatever is asked
        self::assertSame(self::ranked($whole, null, 'szeged'), self::ranked($whole, null, 'szeged', ['city']));
        self::assertStringNotContainsString('tsany', self::ranked($whole, null, 'szeged', ['city'], true));
    }

    public function testEveryRankedSelectionUsesTheBestValueRank(): void
    {
        $sql = self::ranked(self::index(), null, 'szeged', [], true);
        // the cheap and the ordered full-text selection (the typo branch has no text rank)
        self::assertSame(2, substr_count($sql, "v.field = 'city' AND v.t @@ q.tsany"));
        $root = self::parse('szeged');
        $wide = (new SearchSqlBuilder(self::index(), new Names()))->ranked("'szeged'", 'szeged', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], null, false, ['conditions' => [], 'filter' => 'kind', 'values' => 5])['sql'];
        self::assertSame(3, substr_count($wide, "v.field = 'city' AND v.t @@ q.tsany"), 'wide, fts_fast and the ordered selection');
        $total = (new SearchSqlBuilder(self::index(), new Names()))->exactTotal("'szeged'", 'szeged', $root, [], new RankingProfile(), new Thresholds())['sql'];
        self::assertSame(1, substr_count($total, "v.field = 'city' AND v.t @@ q.tsany"));
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

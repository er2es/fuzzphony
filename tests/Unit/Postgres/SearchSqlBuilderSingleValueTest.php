<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use PHPUnit\Framework\TestCase;

/** manyMatch('single'): the single-value predicate of the statement (spec 0.12 section 5.3). */
final class SearchSqlBuilderSingleValueTest extends TestCase
{
    private const string FROM = "EXISTS (SELECT 1 FROM (SELECT v.t, v.z FROM \"public\".\"fuzzphony_people__values\" AS v WHERE v.doc = s.id AND v.field = 'city' UNION ALL SELECT ''::tsvector, ''::text) AS v1 WHERE ";

    private static function index(): IndexDefinition
    {
        $many = static fn(string $column, string $rank): array => ['from' => 'address', 'key' => 'customer_id', 'column' => $column, 'rank' => $rank];

        return IndexDefinition::builder('people')->fromTable('customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', fuzzy: true, granted: 'ROLE_CITY', many: $many('city', 'best'))
            ->field('phone', 'D', many: $many('phone', 'whole'))
            ->field('note', 'B', granted: 'ROLE_NOTE')
            ->build();
    }

    /**
     * @param list<string>|null $allowed
     * @param list<string>      $single
     */
    private static function ranked(?array $allowed, string $text, array $single, bool $fuzzy = false): string
    {
        $index = self::index();
        $root = (new QueryParser())->parse($text)->root ?? throw new \LogicException('no query');

        return (new SearchSqlBuilder($index, new Names(), [], $allowed, single: $single))
            ->ranked((new TsQueryCompiler($index))->compile($root), $text, $fuzzy ? $root : null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];
    }

    public function testEveryWordOnTheVisibleColumnsWithTheFieldBoundToOneValue(): void
    {
        $sql = self::ranked(null, 'fo szeged -kossuth', ['city']);
        self::assertStringContainsString(self::FROM . '((s."t_name" @@ q.meft0 OR v1.t @@ q.meft0 OR s."t_phone" @@ q.meft0 OR s."t_note" @@ q.meft0) AND (s."t_name" @@ q.meft1 OR v1.t @@ q.meft1 OR s."t_phone" @@ q.meft1 OR s."t_note" @@ q.meft1) AND true))', $sql);
        self::assertStringNotContainsString('s.id IN (SELECT v.doc', $sql, 'an unscoped word: no values-first lookup');
        self::assertStringNotContainsString('EXISTS (SELECT 1 FROM (SELECT v.t', self::ranked(null, 'fo szeged', []), 'no mode: no predicate');

        $restricted = self::ranked(['name', 'city'], '"fo utca" szeged', ['city']);
        self::assertStringContainsString(self::FROM . '((s."t_name" @@ q.meft0 OR v1.t @@ q.meft0 OR (s."t_name" || v1.t) @@ q.meft0) AND (s."t_name" @@ q.meft1 OR v1.t @@ q.meft1)))', $restricted, 'only the visible fields, a phrase within a field or across the visible ones');
    }

    public function testTheTypoBranchJudgesTheWordOnTheValue(): void
    {
        $sql = self::ranked(['name', 'city'], 'szeged', ['city'], true);
        self::assertMatchesRegularExpression('/AND EXISTS \(SELECT 1 FROM .* AS v1 WHERE \(\(s\."t_name" @@ q\.mfft\d+ OR v1\.t @@ q\.mfft\d+\) OR GREATEST\("public"\.word_similarity\(q\.mffn\d+, s\."z_name"\), "public"\.word_similarity\(q\.mffn\d+, v1\.z\)\) >= q\.mfth\d+\)\)/', $sql);
        self::assertStringNotContainsString('OPERATOR("public".<%) v1', $sql);

        $scoped = self::ranked(null, 'city:szeged', ['city'], true);
        self::assertMatchesRegularExpression('/v1\.t @@ q\.mfft\d+ OR "public"\.word_similarity\(q\.mffn\d+, v1\.z\) >= q\.mfth\d+/', $scoped, 'a scoped word on its own value');
    }

    public function testTheValuesFirstLookupOnlyWhenEveryPositiveWordIsScopedToTheField(): void
    {
        $in = "s.id IN (SELECT v.doc FROM \"public\".\"fuzzphony_people__values\" AS v WHERE v.field = 'city' AND v.t @@ q.tsf)";
        self::assertStringContainsString($in, self::ranked(null, 'city:szeged city:fo -kossuth', ['city']));
        self::assertStringNotContainsString('q.tsf', self::ranked(null, 'city:szeged name:kiss', ['city']));
        self::assertStringNotContainsString('q.tsf', self::ranked(null, 'phone:1234 phone:5678', ['city']), 'a field outside the mode');
    }
}

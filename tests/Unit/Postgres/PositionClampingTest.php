<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The statements of an unrestricted viewer after a very long field (see UnrestrictedLongFieldTest for the behaviour):
 * `tsv` caps its positions at 16 383, so a phrase and a weighted word of a later field are looked up by their words and
 * decided by the fields' own columns. A query that has neither keeps the statement it had. Indexes::products(): name A,
 * brand B, description D.
 */
final class PositionClampingTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> query, has a sequence, depends on positions */
    public static function queries(): iterable
    {
        yield 'a word' => ['mouse', false, false];
        yield 'two words' => ['wireless mouse', false, false];
        yield 'an excluded word' => ['mouse -cable', false, false];
        yield 'a prefix' => ['mous*', false, false];
        yield 'an unknown field' => ['colour:red', false, false];
        yield 'a phrase' => ['"usb receiver"', true, true];
        yield 'a word that splits' => ['usb-c', true, true];
        yield 'an excluded phrase' => ['mouse -"usb receiver"', true, true];
        yield 'a phrase in a group' => ['mouse (cable | "usb receiver")', true, true];
        yield 'a scoped phrase' => ['name:"usb receiver"', true, true];
        yield 'a scoped word' => ['brand:sony', false, true];
        yield 'an excluded scoped word' => ['mouse -brand:sony', false, true];
        yield 'a one-word phrase' => ['"mouse"', false, false];
        yield 'an unknown field with a phrase' => ['colour:"red car"', true, true];
    }

    #[DataProvider('queries')]
    public function testWhichQueriesDependOnPositions(string $text, bool $sequence, bool $positional): void
    {
        $compiler = new TsQueryCompiler(Indexes::products());
        $root = self::parse($text);

        self::assertSame($sequence, $compiler->hasSequence($root), 'sequence');
        self::assertSame($positional, $compiler->dependsOnPositions($root), 'depends on positions');
    }

    public function testTheLaterVectorsStartAtEachFieldAfterTheFirst(): void
    {
        $names = new Names();

        self::assertSame(['s."t_brand" || s."t_description"', 's."t_description"'], $names->laterVectors(Indexes::products()));
        self::assertSame([], $names->laterVectors(self::singleField()));
        self::assertSame(['c."t_brand" || c."t_description"', 'c."t_description"'], $names->laterVectors(Indexes::products(), 'c'), 'the alias is the one of the statement');
    }

    public function testAPhraseIsLookedUpByItsWordsAndReadFromTsvOrTheLaterFields(): void
    {
        $params = new ParameterBag();
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3)))->compile(self::parse('"usb receiver"'), $params);

        self::assertNotNull($match);
        self::assertSame([
            "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS ft0",
            "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p1) AS fl1",
            '"public"."fuzzphony_norm"(:p2) AS fn2',
        ], $match->columns);
        self::assertSame(['p0' => "('usb' <-> 'receiver')", 'p1' => "('usb' & 'receiver')", 'p2' => 'usb receiver'], $params->all());
        self::assertSame(
            '((s.tsv @@ q.fl1 AND (s.tsv @@ q.ft0 OR (s."t_brand" || s."t_description") @@ q.ft0 OR (s."t_description") @@ q.ft0)) OR q.fn2 OPERATOR("public".<%) s.fz)',
            $match->predicate,
            'a phrase is on tsv, or inside the vector of the fields from any later one on (a run of fields tsv reads in order)',
        );
    }

    public function testAScopedPhraseIsDecidedByItsFieldAlone(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3));
        $params = new ParameterBag();
        $scope = $compiler->scope(self::parse('brand:"usb receiver"'), $params);

        self::assertNotNull($scope);
        self::assertSame('(s.tsv @@ q.sfl1 AND s."t_brand" @@ q.sft0)', $scope['predicate']);
        self::assertSame(['p0' => "('usb':B <-> 'receiver':B)", 'p1' => "('usb' & 'receiver')"], $params->all());
    }

    public function testAnExcludedPhraseIsReadTheSameWay(): void
    {
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3)))->compile(self::parse('mouse -"usb receiver"'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('NOT ((s.tsv @@ q.fl3 AND (s.tsv @@ q.ft2 OR (s."t_brand" || s."t_description") @@ q.ft2 OR (s."t_description") @@ q.ft2)))', $match->predicate);
    }

    public function testAWordWithoutAWeightKeepsItsCondition(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3));

        foreach (['mouse' => '(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', 'mouse -cable' => '((s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz) AND NOT (s.tsv @@ q.ft2))'] as $text => $predicate) {
            $match = $compiler->compile(self::parse($text), new ParameterBag());
            self::assertNotNull($match);
            self::assertSame($predicate, $match->predicate, $text);
        }
    }

    public function testAnIndexWithOneFieldHasNoLaterFieldToLose(): void
    {
        $match = (new FuzzyQueryCompiler(self::singleField(), new Thresholds(fuzzySimilarity: 0.3)))->compile(self::parse('"usb receiver"'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate);
    }

    public function testThePhraseStatementLooksUpByWordsAndRanksWhatTsvDoesNotMatchOnTheFields(): void
    {
        $sql = self::ranked(Indexes::products(), "('usb' <-> 'receiver')", '"usb receiver"');

        self::assertMatchesRegularExpression('/AS tsq, .* AS tsp,/', $sql);
        self::assertStringContainsString('WHERE s.tsv @@ q.tsp AND (s.tsv @@ q.sfl1 AND (s.tsv @@ q.sft0 OR (s."t_brand" || s."t_description") @@ q.sft0 OR (s."t_description") @@ q.sft0)) AND TRUE', $sql);
        self::assertStringNotContainsString('s.tsv @@ q.tsq AND', $sql);
        // a document tsv matches keeps its rank on tsv; one it does not is ranked on the first field and on the runs of later fields
        self::assertStringContainsString("CASE WHEN s.tsv @@ q.tsq THEN ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32) ELSE GREATEST(", $sql);
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.\"t_name\", q.tsq, 32), ts_rank_cd('{0.1,0.2,0.4,1}'::real[], (s.\"t_brand\" || s.\"t_description\"), q.tsq, 32), ts_rank_cd('{0.1,0.2,0.4,1}'::real[], (s.\"t_description\"), q.tsq, 32)) END::double precision AS r_text", $sql);
    }

    public function testAQueryWithoutAPhraseOrAScopeKeepsItsStatement(): void
    {
        $plain = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0);
        $withRoot = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], null);

        self::assertSame($plain, $withRoot);
        self::assertStringContainsString("WHERE s.tsv @@ q.tsq AND TRUE\n", $plain['sql']);
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32)::double precision AS r_text", $plain['sql']);
        self::assertStringNotContainsString('tsp', $plain['sql']);
    }

    public function testWithTheCappedColumnTheLaterFieldsAreReadOnlyForACappedDocument(): void
    {
        $params = new ParameterBag();
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3), cappedColumn: static fn(): bool => true))->compile(self::parse('"usb receiver"'), $params);

        self::assertNotNull($match);
        self::assertSame(
            '((s.tsv @@ q.fl1 AND (s.tsv @@ q.ft0 OR (s.tsv_capped IS NOT FALSE AND EXISTS (SELECT 1 FROM "public"."fuzzphony_products" AS c WHERE c.id = s.id AND ((c."t_brand" || c."t_description") @@ q.ft0 OR (c."t_description") @@ q.ft0))))) OR q.fn2 OPERATOR("public".<%) s.fz)',
            $match->predicate,
            'tsv first (the statement\'s old cost); a capped or not known (NULL) document reads the later fields from its own row, not as columns of the scanned one',
        );
        self::assertStringNotContainsString(' s."t_description"', $match->predicate);
    }

    public function testWithoutTheCappedColumnEveryDocumentTsvDoesNotMatchReadsTheLaterFields(): void
    {
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3), cappedColumn: static fn(): bool => false))->compile(self::parse('"usb receiver"'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('(s.tsv @@ q.ft0 OR (s."t_brand" || s."t_description") @@ q.ft0 OR (s."t_description") @@ q.ft0)', $match->predicate);
        self::assertStringNotContainsString('tsv_capped', $match->predicate);
    }

    public function testTheColumnIsAskedAboutOnlyByAStatementThatNeedsIt(): void
    {
        $asked = 0;
        $ask = static function () use (&$asked): bool {
            ++$asked;

            return true;
        };
        $thresholds = new Thresholds(fuzzySimilarity: 0.3);
        foreach (['mouse', 'mouse -cable', 'colour:red', 'mous*'] as $text) {
            (new FuzzyQueryCompiler(Indexes::products(), $thresholds, cappedColumn: $ask))->compile(self::parse($text), new ParameterBag());
            (new SearchSqlBuilder(Indexes::products(), cappedColumn: $ask))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0);
        }
        (new FuzzyQueryCompiler(self::singleField(), $thresholds, cappedColumn: $ask))->compile(self::parse('"usb receiver"'), new ParameterBag());
        (new FuzzyQueryCompiler(Indexes::products(), $thresholds, cappedColumn: $ask))->compile(self::parse('brand:sony'), new ParameterBag());
        self::assertSame(0, $asked, 'a plain word, an exclusion of a word, a prefix, an unknown field, one field, and a scoped word (the field decides) need no flag');

        (new FuzzyQueryCompiler(Indexes::products(), $thresholds, cappedColumn: $ask))->compile(self::parse('"usb receiver"'), new ParameterBag());
        self::assertSame(1, $asked);
    }

    public function testWithTheCappedColumnTheRankReadsTheFieldsOnlyForACappedDocument(): void
    {
        $root = self::parse('"usb receiver"');
        $sql = (new SearchSqlBuilder(Indexes::products(), cappedColumn: static fn(): bool => true))->ranked("('usb' <-> 'receiver')", 'usb receiver', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];

        self::assertStringContainsString("CASE WHEN s.tsv_capped IS NOT FALSE AND NOT (s.tsv @@ q.tsq) THEN (SELECT GREATEST(ts_rank_cd('{0.1,0.2,0.4,1}'::real[], c.\"t_name\", q.tsq, 32), ts_rank_cd('{0.1,0.2,0.4,1}'::real[], (c.\"t_brand\" || c.\"t_description\"), q.tsq, 32), ts_rank_cd('{0.1,0.2,0.4,1}'::real[], (c.\"t_description\"), q.tsq, 32)) FROM \"public\".\"fuzzphony_products\" AS c WHERE c.id = s.id) ELSE ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32) END::double precision AS r_text", $sql);
        self::assertStringContainsString('WHERE s.tsv @@ q.tsp AND ', $sql);
    }

    public function testAnIndexWithOneFieldKeepsTheStrictLookupAndTheRank(): void
    {
        $sql = self::ranked(self::singleField(), "('usb' <-> 'receiver')", '"usb receiver"');

        self::assertStringContainsString('WHERE s.tsv @@ q.tsq AND ', $sql);
        self::assertStringNotContainsString('tsp', $sql);
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32)::double precision AS r_text", $sql);
    }

    private static function singleField(): IndexDefinition
    {
        return IndexDefinition::builder('notes')->fromQuery('SELECT id, body FROM fz_note')->field('body', 'A', fuzzy: true)->sync('manual')->build();
    }

    private static function ranked(IndexDefinition $index, string $tsquery, string $text): string
    {
        $root = self::parse($text);

        return (new SearchSqlBuilder($index))->ranked($tsquery, 'usb receiver', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

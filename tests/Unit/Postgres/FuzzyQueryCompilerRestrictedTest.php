<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The restricted path: a viewer who may see only some fields (SearchQuery::$allowedFields). Every word is rechecked
 * against the allowed fields' own columns; a hidden field never decides a match or a score.
 * Fields of Indexes::products(): name (A, fuzzy), brand (B, fuzzy), description (D).
 */
final class FuzzyQueryCompilerRestrictedTest extends TestCase
{
    public function testOneAllowedFieldChecksOnlyItsColumns(): void
    {
        $params = new ParameterBag();
        $match = self::compiler(['name'])->compile(self::parse('mouse'), $params);

        self::assertNotNull($match);
        self::assertSame(
            '((s.tsv @@ q.ft0 AND s."t_name" @@ q.ft0) OR (q.fn1 OPERATOR("public".<%) s.fz AND "public".word_similarity(q.fn1, s."z_name") >= 0.3))',
            $match->predicate,
            'the index finds the candidates (tsv, fz), the allowed field decides',
        );
        self::assertSame('GREATEST("public".word_similarity(q.fn1, s."z_name"), CASE WHEN (s.tsv @@ q.ft0 AND s."t_name" @@ q.ft0) THEN 1.0 ELSE 0.0 END)', $match->score);
        self::assertStringNotContainsString('t_brand', $match->predicate . $match->score);
        self::assertStringNotContainsString('t_description', $match->predicate . $match->score);
        self::assertStringNotContainsString('z_brand', $match->predicate . $match->score);
    }

    public function testSeveralAllowedFieldsAreOredAndTheSimilarityIsTheBestOfThem(): void
    {
        $match = self::compiler(['name', 'brand'])->compile(self::parse('mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('(s.tsv @@ q.ft0 AND (s."t_name" @@ q.ft0 OR s."t_brand" @@ q.ft0))', $match->predicate);
        self::assertStringContainsString('GREATEST("public".word_similarity(q.fn1, s."z_name"), "public".word_similarity(q.fn1, s."z_brand"))', $match->score);
        self::assertStringNotContainsString('word_similarity(q.fn1, s.fz)', $match->predicate . $match->score, 'the combined text holds the hidden description too');
    }

    public function testAPhraseOfSeveralAllowedFieldsIsAlsoReadFromTheirJoinedVectors(): void
    {
        $params = new ParameterBag();
        $match = self::compiler(['name', 'brand'])->compile(self::parse('"usb receiver"'), $params);

        self::assertNotNull($match);
        self::assertStringContainsString('(s.tsv @@ q.fl1 AND (s."t_name" @@ q.ft0 OR s."t_brand" @@ q.ft0 OR (s."t_name" || s."t_brand") @@ q.ft0))', $match->predicate);
        self::assertContains("('usb' & 'receiver')", $params->all(), 'the candidates come from the AND of the words');
        self::assertContains("('usb' <-> 'receiver')", $params->all());
        self::assertStringNotContainsString('t_description', $match->predicate . $match->score);
    }

    public function testAPhraseOfOneAllowedFieldOrAWordIsAsItWas(): void
    {
        $one = self::compiler(['name'])->compile(self::parse('"usb receiver"'), new ParameterBag());
        self::assertNotNull($one);
        self::assertStringContainsString('(s.tsv @@ q.fl1 AND s."t_name" @@ q.ft0)', $one->predicate, 'the lookup is the AND of the words, the field decides');
        self::assertStringNotContainsString('||', $one->predicate);
        $word = self::compiler(['name', 'brand'])->compile(self::parse('usb'), new ParameterBag());
        self::assertNotNull($word);
        self::assertStringNotContainsString('||', $word->predicate);
        $scoped = self::compiler(['name', 'brand'])->compile(self::parse('name:"usb receiver"'), new ParameterBag());
        self::assertNotNull($scoped);
        self::assertStringNotContainsString('||', $scoped->predicate, 'a scoped phrase stays in its own field');
    }

    public function testTheLengthAwareRecheckUsesTheAllowedColumns(): void
    {
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names(), [], ['name']))->compile(self::parse('mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('(q.fn1 OPERATOR("public".<%) s.fz AND "public".word_similarity(q.fn1, s."z_name") >= q.th2)', $match->predicate);
        self::assertStringContainsString('CASE WHEN "public".word_similarity(q.fn1, s."z_name") >= q.th2 THEN "public".word_similarity(q.fn1, s."z_name") ELSE 0.0 END', $match->score);
    }

    public function testWithoutAnAllowedFuzzyFieldAWordIsExactOnly(): void
    {
        $compiler = self::compiler(['description']);
        $match = $compiler->compile(self::parse('mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 AND s."t_description" @@ q.ft0)', $match->predicate);
        self::assertStringNotContainsString('<%', $match->predicate . $match->score);
        self::assertStringNotContainsString('z_', $match->predicate . $match->score);
        self::assertSame([], $compiler->probes(self::parse('mouse'), 10));
        self::assertFalse($compiler->hasFuzzyLeaf(self::parse('mouse')));
        self::assertNull($compiler->lowestSimilarity(self::parse('mouse')));
    }

    public function testNoAllowedFieldMatchesNothing(): void
    {
        $params = new ParameterBag();
        $match = self::compiler([])->compile(self::parse('mouse'), $params);

        self::assertNotNull($match);
        self::assertSame('false', $match->predicate);
        self::assertSame('CASE WHEN false THEN 1.0 ELSE 0.0 END', $match->score);
        self::assertSame([], $params->all(), 'nothing to bind');
        self::assertSame(['predicate' => 'false', 'columns' => []], self::compiler([])->scope(self::parse('mouse'), new ParameterBag()));
    }

    public function testAWordScopedToAHiddenFieldMatchesNothing(): void
    {
        $match = self::compiler(['name'])->compile(self::parse('brand:sony'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('false', $match->predicate);
        self::assertSame([], self::compiler(['name'])->probes(self::parse('brand:sony'), 10), 'no typo tolerance on a hidden field');
    }

    public function testAWordScopedToAnAllowedFieldIsLookedUpWithoutItsWeight(): void
    {
        $restricted = self::compiler(['name', 'brand'])->compile(self::parse('brand:razr'), $a = new ParameterBag());
        $unrestricted = self::compiler(null)->compile(self::parse('brand:razr'), $b = new ParameterBag());

        self::assertNotNull($restricted);
        self::assertNotNull($unrestricted);
        // tsv keeps only the highest weight of a lexeme at a clamped position (after a long hidden field), so its lookup has none
        self::assertSame('((s.tsv @@ q.fl1 AND s."t_brand" @@ q.ft0) OR (q.fn2 OPERATOR("public".<%) s.fz AND q.fn2 OPERATOR("public".<%) s."z_brand"))', $restricted->predicate);
        self::assertSame(["'razr':B", "'razr'", 'razr'], array_values($a->all()));
        self::assertSame('((s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) OR (q.fn1 OPERATOR("public".<%) s.fz AND q.fn1 OPERATOR("public".<%) s."z_brand"))', $unrestricted->predicate, 'the unrestricted path is as it was');
    }

    public function testTheStrictBranchRechecksEveryWordAgainstTheAllowedFields(): void
    {
        self::assertSame(
            ['predicate' => '((s.tsv @@ q.sft0 AND s."t_name" @@ q.sft0) AND (s.tsv @@ q.sft1 AND s."t_name" @@ q.sft1))', 'columns' => [
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS sft0",
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p1) AS sft1",
            ]],
            self::compiler(['name'])->scope(self::parse('wireless mouse'), new ParameterBag()),
        );
    }

    public function testAnExcludedWordExcludesOnlyByTheAllowedFields(): void
    {
        $predicate = self::compiler(['name'])->compile(self::parse('mouse -cable'), new ParameterBag())?->predicate;

        // a document with "cable" only in its (hidden) description must not be excluded: that would tell the viewer it has the word
        self::assertSame('(((s.tsv @@ q.ft0 AND s."t_name" @@ q.ft0) OR (q.fn1 OPERATOR("public".<%) s.fz AND "public".word_similarity(q.fn1, s."z_name") >= 0.3)) AND NOT ((s.tsv @@ q.ft2 AND s."t_name" @@ q.ft2)))', $predicate);
    }

    public function testAnExcludedGroupIsCompiledWordByWordSoANestedNegationNeverReadsAHiddenField(): void
    {
        $params = new ParameterBag();
        $predicate = self::compiler(['name'])->scope(self::parse('mouse -(cable | -wireless)'), $params)['predicate'] ?? null;

        // "s.tsv @@ ('cable' | !'wireless')" would be false for a document with "wireless" only in a hidden field
        self::assertSame('((s.tsv @@ q.sft0 AND s."t_name" @@ q.sft0) AND NOT (((s.tsv @@ q.sft1 AND s."t_name" @@ q.sft1) OR NOT ((s.tsv @@ q.sft2 AND s."t_name" @@ q.sft2)))))', $predicate);
        self::assertSame(['p0' => "'mouse'", 'p1' => "'cable'", 'p2' => "'wireless'"], $params->all(), 'every tsquery is one positive word');
        self::assertSame(["'mouse'", "'cable'", "'wireless'"], self::compiler(['name'])->leafQueries(self::parse('mouse -(cable | -wireless)')), 'its stop words are asked word by word');
    }

    public function testAPhraseAndAPrefixAreRecheckedLikeAnyWord(): void
    {
        $phrase = self::compiler(['name'])->scope(self::parse('"usb receiver"'), $p = new ParameterBag());
        $prefix = self::compiler(['name'])->scope(self::parse('recei*'), $q = new ParameterBag());

        self::assertSame('(s.tsv @@ q.sfl1 AND s."t_name" @@ q.sft0)', $phrase['predicate'] ?? null);
        self::assertSame(['p0' => "('usb' <-> 'receiver')", 'p1' => "('usb' & 'receiver')"], $p->all());
        self::assertSame('(s.tsv @@ q.sft0 AND s."t_name" @@ q.sft0)', $prefix['predicate'] ?? null);
        self::assertSame(['p0' => "'recei':*"], $q->all());
    }

    public function testTheProbesUseTheRestrictedTrigramCondition(): void
    {
        $probes = self::compiler(['name'])->probes(self::parse('mouse'), 10);

        self::assertCount(1, $probes);
        self::assertStringContainsString('WHERE ("public"."fuzzphony_norm"(:p0) OPERATOR("public".<%) s.fz AND "public".word_similarity("public"."fuzzphony_norm"(:p0), s."z_name") >= 0.3)', array_values($probes)[0]['sql']);
    }

    public function testTheRelaxationLeavesAreRestrictedToo(): void
    {
        $compiled = self::compiler(['name'])->leafConditions([new Term('mouse'), new Term('cable')], new ParameterBag(), [], false);

        self::assertSame(['(s.tsv @@ q.ft0 AND s."t_name" @@ q.ft0)', '(s.tsv @@ q.ft1 AND s."t_name" @@ q.ft1)'], $compiled['predicates']);
        $fuzzy = self::compiler(['name'])->leafConditions([new Term('mouse')], new ParameterBag(), [], true);
        self::assertStringContainsString('word_similarity(q.fn1, s."z_name") >= 0.3', (string) $fuzzy['predicates'][0]);
    }

    public function testNullIsTheUnrestrictedSqlUntouched(): void
    {
        foreach (['mouse', 'brand:razr -cable', '(mouse | "usb receiver") -(brand:sony | cable)', 'ergnoo*'] as $query) {
            foreach ([new Thresholds(fuzzySimilarity: 0.3), new Thresholds()] as $thresholds) {
                $plain = new FuzzyQueryCompiler(Indexes::products(), $thresholds);
                $null = new FuzzyQueryCompiler(Indexes::products(), $thresholds, new Names(), [], null);
                self::assertEquals($plain->compile(self::parse($query), $a = new ParameterBag()), $null->compile(self::parse($query), $b = new ParameterBag()), $query);
                self::assertSame($a->all(), $b->all());
                self::assertSame($plain->scope(self::parse($query), new ParameterBag()), $null->scope(self::parse($query), new ParameterBag()), $query);
                self::assertSame($plain->probes(self::parse($query), 10), $null->probes(self::parse($query), 10), $query);
                self::assertSame($plain->leafQueries(self::parse($query)), $null->leafQueries(self::parse($query)), $query);
            }
        }
    }

    /** @param list<string>|null $allowed */
    private static function compiler(?array $allowed): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3), new Names(), [], $allowed);
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

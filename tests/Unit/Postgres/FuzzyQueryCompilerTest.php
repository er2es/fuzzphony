<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Sql\FuzzyMatch;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuzzyQueryCompilerTest extends TestCase
{
    public function testOneLeafIsExactOrFuzzyAndEveryValueIsBound(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse('mouse'), $params);

        self::assertNotNull($match);
        // the values are q columns (bound once each); predicate and score only reference them
        self::assertSame(["to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS ft0", '"public"."fuzzphony_norm"(:p1) AS fn1'], $match->columns);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate);
        self::assertSame('GREATEST("public".word_similarity(q.fn1, s.fz), CASE WHEN s.tsv @@ q.ft0 THEN 1.0 ELSE 0.0 END)', $match->score);
        self::assertSame(['p0' => "'mouse'", 'p1' => 'mouse'], $params->all());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function structures(): iterable
    {
        yield 'every word must match on its own' => [
            'wireles mice',
            "(fz['wireles'|wireles] AND fz['mice'|mice])",
            "((sim[wireles|'wireles'] + sim[mice|'mice']) / 2)",
        ];
        yield 'or' => [
            'mouse | trackpad',
            "(fz['mouse'|mouse] OR fz['trackpad'|trackpad])",
            "GREATEST(sim[mouse|'mouse'], sim[trackpad|'trackpad'])",
        ];
        yield 'negation is exact only and does not score' => [
            'mouse -cable',
            "(fz['mouse'|mouse] AND NOT (ts['cable']))",
            "sim[mouse|'mouse']",
        ];
        yield 'group and negation' => [
            '(mouse | trackpad) -cable',
            "((fz['mouse'|mouse] OR fz['trackpad'|trackpad]) AND NOT (ts['cable']))",
            "GREATEST(sim[mouse|'mouse'], sim[trackpad|'trackpad'])",
        ];
        yield 'negation nested in a group' => [
            '(mouse -cable) | trackpad',
            "((fz['mouse'|mouse] AND NOT (ts['cable'])) OR fz['trackpad'|trackpad])",
            "GREATEST(guard[(fz['mouse'|mouse] AND NOT (ts['cable'])) => sim[mouse|'mouse']], sim[trackpad|'trackpad'])",
        ];
        yield 'nested groups' => [
            'wireless (mouse | "usb receiver")',
            "(fz['wireless'|wireless] AND (fz['mouse'|mouse] OR fz[('usb' <-> 'receiver')|usb receiver]))",
            "((sim[wireless|'wireless'] + GREATEST(sim[mouse|'mouse'], sim[usb receiver|('usb' <-> 'receiver')])) / 2)",
        ];
        yield 'phrase is one needle' => [
            '"noise cancelling"',
            "fz[('noise' <-> 'cancelling')|noise cancelling]",
            "sim[noise cancelling|('noise' <-> 'cancelling')]",
        ];
        yield 'prefix keeps :* on the exact side, the prefix text is the needle' => [
            'ergnoo*',
            "fz['ergnoo':*|ergnoo]",
            "sim[ergnoo|'ergnoo':*]",
        ];
        yield 'words below fuzzyMinLength stay exact' => [
            'ab mouse',
            "(ts['ab'] AND fz['mouse'|mouse])",
            "((hit['ab'] + sim[mouse|'mouse']) / 2)",
        ];
        yield 'exact-only words score with numeric 1.0 / 0.0, so the mean is never an integer division' => [
            'ab cd',
            "(ts['ab'] AND ts['cd'])",
            "((hit['ab'] + hit['cd']) / 2)",
        ];
        yield 'an OR branch scores only when its own predicate holds' => [
            'mouse | (wireles headphones)',
            "(fz['mouse'|mouse] OR (fz['wireles'|wireles] AND fz['headphones'|headphones]))",
            "GREATEST(sim[mouse|'mouse'], guard[(fz['wireles'|wireles] AND fz['headphones'|headphones]) => ((sim[wireles|'wireles'] + sim[headphones|'headphones']) / 2)])",
        ];
        yield 'an exact-only AND branch is guarded too' => [
            'mouse | (ab cd)',
            "(fz['mouse'|mouse] OR (ts['ab'] AND ts['cd']))",
            "GREATEST(sim[mouse|'mouse'], guard[(ts['ab'] AND ts['cd']) => ((hit['ab'] + hit['cd']) / 2)])",
        ];
        yield 'a negation inside an OR branch keeps the branch guarded' => [
            'mouse | (wireles -silent)',
            "(fz['mouse'|mouse] OR (fz['wireles'|wireles] AND NOT (ts['silent'])))",
            "GREATEST(sim[mouse|'mouse'], guard[(fz['wireles'|wireles] AND NOT (ts['silent'])) => sim[wireles|'wireles']])",
        ];
        yield 'plain leaves of an OR need no guard' => [
            'wireles | mouse | keybord',
            "(fz['wireles'|wireles] OR fz['mouse'|mouse] OR fz['keybord'|keybord])",
            "GREATEST(sim[wireles|'wireles'], sim[mouse|'mouse'], sim[keybord|'keybord'])",
        ];
        yield 'an unknown field is searched everywhere, like the strict query does' => [
            'nosuch:mouse',
            "fz['mouse'|mouse]",
            "sim[mouse|'mouse']",
        ];
        yield 'a negated group stays exact only and does not score' => [
            'mouse -(wireless cable)',
            "(fz['mouse'|mouse] AND NOT (ts[('wireless' & 'cable')]))",
            "sim[mouse|'mouse']",
        ];
        yield 'hyphenated word' => [
            'e-mail',
            "fz[('e' <-> 'mail')|e mail]",
            "sim[e mail|('e' <-> 'mail')]",
        ];
    }

    #[DataProvider('structures')]
    public function testStructure(string $input, string $predicate, string $score): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse($input), $params);

        self::assertNotNull($match);
        self::assertSame($predicate, self::shorthand($match->predicate, $match, $params));
        self::assertSame($score, self::shorthand($match->score, $match, $params));
    }

    public function testANegatedGroupAloneCompilesToNull(): void
    {
        self::assertNull($this->compiler()->compile(self::parse('-(mouse cable)'), new ParameterBag()));
    }

    /** A negated stop word drops out of the group entirely (its exact() is null too), leaving the rest intact. */
    public function testANegatedStopWordIsDroppedWhileTheRestOfTheGroupSurvives(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse('mouse -for'), $params, ["'for'"]);

        self::assertNotNull($match);
        self::assertSame("fz['mouse'|mouse]", self::shorthand($match->predicate, $match, $params));
    }

    /** Two negations with nothing positive to score: the group has no score, so the whole thing is null. */
    public function testAllNegatedLeavesWithNoPositiveScoreCompileToNull(): void
    {
        self::assertNull($this->compiler()->compile(self::parse('-mouse -cable'), new ParameterBag()));
    }

    public function testAnOrBranchThatBecomesNullIsDropped(): void
    {
        $params = new ParameterBag();
        // "the" is a stop word, so the OR keeps only its negation: it cannot score, it only excludes
        $match = $this->compiler()->compile(self::parse('wireles (the | -mouse)'), $params, ["'the'"]);

        self::assertNotNull($match);
        self::assertSame("(fz['wireles'|wireles] AND NOT (ts['mouse']))", self::shorthand($match->predicate, $match, $params));
        self::assertSame("sim[wireles|'wireles']", self::shorthand($match->score, $match, $params));
    }

    public function testOneCompilerInstanceCompilesDifferentQueriesIndependently(): void
    {
        $compiler = $this->compiler();
        $first = $compiler->compile(self::parse('mouse cable'), new ParameterBag());
        $secondParams = new ParameterBag();
        $second = $compiler->compile(self::parse('lamp'), $secondParams);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertCount(4, $first->columns);
        self::assertSame(["to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS ft0", '"public"."fuzzphony_norm"(:p1) AS fn1'], $second->columns);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $second->predicate);
        self::assertSame(['p0' => "'lamp'", 'p1' => 'lamp'], $secondParams->all());
    }

    public function testStopWordLeavesAreDropped(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse('mouse for gamng'), $params, ["'for'"]);

        self::assertNotNull($match);
        self::assertSame("(fz['mouse'|mouse] AND fz['gamng'|gamng])", self::shorthand($match->predicate, $match, $params));
        self::assertSame("((sim[mouse|'mouse'] + sim[gamng|'gamng']) / 2)", self::shorthand($match->score, $match, $params));
    }

    public function testALeafWithoutLettersOrDigitsIsDropped(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(new AllOf([new Term('mouse'), new Term('+++')]), $params);

        self::assertNotNull($match);
        self::assertSame("fz['mouse'|mouse]", self::shorthand($match->predicate, $match, $params));
    }

    public function testNothingToScoreCompilesToNull(): void
    {
        self::assertNull($this->compiler()->compile(new Not(new Term('cable')), new ParameterBag()));
        self::assertNull($this->compiler()->compile(self::parse('for'), new ParameterBag(), ["'for'"]));
    }

    public function testUserTextNeverAppearsInTheSql(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse("robert'); DROP TABLE x; -- \"o'reilly books\" -secret name:evil"), $params);

        self::assertNotNull($match);
        $sql = implode(' ', [$match->predicate, $match->score, ...$match->columns]);
        foreach (['robert', 'drop', 'table', 'reilly', 'books', 'secret', 'evil'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $sql);
        }
        self::assertContains("'robert'", $params->all());
        self::assertContains('o reilly books', $params->all());
        self::assertContains("'secret'", $params->all());
    }

    public function testHasFuzzyLeaf(): void
    {
        $compiler = $this->compiler();

        self::assertTrue($compiler->hasFuzzyLeaf(self::parse('mouse')));
        self::assertTrue($compiler->hasFuzzyLeaf(self::parse('ab (cd | mouse)')));
        self::assertFalse($compiler->hasFuzzyLeaf(self::parse('ab cd')), 'every word is below fuzzyMinLength');
        self::assertFalse($compiler->hasFuzzyLeaf(self::parse('ab -mouse')), 'negated words never match fuzzily');
        self::assertFalse($compiler->hasFuzzyLeaf(self::parse('for ab'), ["'for'"]), 'stop words are dropped');
        self::assertFalse((new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzyMinLength: 6)))->hasFuzzyLeaf(self::parse('mouse')));
        self::assertTrue($compiler->hasFuzzyLeaf(self::parse('abc')), 'exactly fuzzyMinLength is long enough');
        self::assertFalse($compiler->hasFuzzyLeaf(self::parse('"a b"')), 'the length counts letters, not the spaces of a phrase');
        self::assertFalse($compiler->hasFuzzyLeaf(self::parse('éé')), 'the length counts characters, not bytes');

        $noFuzzyFields = IndexDefinition::builder('plain')->fromTable('fz_product')->field('name', 'A')->build();
        self::assertFalse((new FuzzyQueryCompiler($noFuzzyFields, new Thresholds()))->hasFuzzyLeaf(self::parse('mouse')));
    }

    public function testLeafQueriesListEveryDroppableUnit(): void
    {
        self::assertSame(
            ["'wireless'", "'mouse'", "('usb' <-> 'receiver')", "'cable'"],
            $this->compiler()->leafQueries(self::parse('wireless (mouse | "usb receiver") -cable wireless')),
        );
    }

    public function testLeafConditionsAreTheSearchesPerLeafConditions(): void
    {
        $params = new ParameterBag();
        $leaves = [new Term('wireless'), new Term('ab'), new Term('for'), new Term('aluminum')];
        $conditions = $this->compiler()->leafConditions($leaves, $params, ["'for'"], fuzzy: true);

        // exact or trigram, like the fuzzy branch; below fuzzyMinLength exact only; a stop word has none
        self::assertSame(['(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', 's.tsv @@ q.ft2', null, '(s.tsv @@ q.ft3 OR q.fn4 OPERATOR("public".<%) s.fz)'], $conditions['predicates']);
        self::assertSame([
            "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS ft0",
            '"public"."fuzzphony_norm"(:p1) AS fn1',
            "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p2) AS ft2",
            "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p3) AS ft3",
            '"public"."fuzzphony_norm"(:p4) AS fn4',
        ], $conditions['columns']);
        self::assertSame(['p0' => "'wireless'", 'p1' => 'wireless', 'p2' => "'ab'", 'p3' => "'aluminum'", 'p4' => 'aluminum'], $params->all());
    }

    public function testLeafConditionsWithoutTheFuzzyBranchAreExactOnly(): void
    {
        $params = new ParameterBag();
        $conditions = $this->compiler()->leafConditions([new Term('wireless'), self::parse('name:"usb receiver"')], $params, [], fuzzy: false);

        self::assertSame(['s.tsv @@ q.ft0', '(s.tsv @@ q.ft1 AND s."t_name" @@ q.ft1)'], $conditions['predicates']);
        self::assertSame(['p0' => "'wireless'", 'p1' => "('usb':A <-> 'receiver':A)"], $params->all());
    }

    public function testAFieldScopedWordMatchesOnlyItsFieldOnBothSides(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse('brand:razr'), $params);

        self::assertNotNull($match);
        self::assertSame(["to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS ft0", '"public"."fuzzphony_norm"(:p1) AS fn1'], $match->columns);
        // GIN(tsv) / GIN(fz) find the candidates, the field's own columns decide
        self::assertSame('((s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) OR (q.fn1 OPERATOR("public".<%) s.fz AND q.fn1 OPERATOR("public".<%) s."z_brand"))', $match->predicate);
        self::assertSame('GREATEST("public".word_similarity(q.fn1, s."z_brand"), CASE WHEN (s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) THEN 1.0 ELSE 0.0 END)', $match->score);
        self::assertSame(['p0' => "'razr':B", 'p1' => 'razr'], $params->all());
    }

    public function testAScopedWordOfAFieldThatIsNotFuzzyIsExactOnly(): void
    {
        $match = $this->compiler()->compile(self::parse('description:office mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('((s.tsv @@ q.ft0 AND s."t_description" @@ q.ft0) AND (s.tsv @@ q.ft1 OR q.fn2 OPERATOR("public".<%) s.fz))', $match->predicate);
        self::assertFalse($this->compiler()->hasFuzzyLeaf(self::parse('description:office')));
    }

    public function testAnExcludedScopedWordIsCheckedAgainstItsField(): void
    {
        $match = $this->compiler()->compile(self::parse('mouse -brand:logitech'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('((s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz) AND NOT ((s.tsv @@ q.ft2 AND s."t_brand" @@ q.ft2)))', $match->predicate);
    }

    public function testAnExcludedGroupWithAScopedWordExcludesEachWordByItsOwnField(): void
    {
        $params = new ParameterBag();
        $compiler = $this->compiler();
        $match = $compiler->compile(self::parse('-(brand:sony | cable) mouse'), $params);

        self::assertNotNull($match);
        self::assertSame('(NOT (((s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) OR s.tsv @@ q.ft1)) AND (s.tsv @@ q.ft2 OR q.fn3 OPERATOR("public".<%) s.fz))', $match->predicate, 'the word after the excluded group is typo-tolerant again');
        self::assertSame(['p0' => "'sony':B", 'p1' => "'cable'", 'p2' => "'mouse'", 'p3' => 'mouse'], $params->all());
        self::assertSame(
            ['predicate' => '(NOT (((s.tsv @@ q.sft0 AND s."t_brand" @@ q.sft0) OR s.tsv @@ q.sft1)) AND s.tsv @@ q.sft2)', 'columns' => [
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS sft0",
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p1) AS sft1",
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p2) AS sft2",
            ]],
            $compiler->scope(self::parse('-(brand:sony | cable) mouse'), new ParameterBag()),
            'scope() stays exact only after the excluded group',
        );
        self::assertSame(["'mouse'", "'sony':B", "'cable'"], $compiler->leafQueries(self::parse('mouse -(brand:sony | cable)')), 'its words are stop-word checked one by one');
        self::assertSame(["'mouse'", "('sony' | 'cable')"], $compiler->leafQueries(self::parse('mouse -(sony | cable)')), 'without a field scope the excluded group stays one unit');

        $stop = $compiler->compile(self::parse('mouse -(brand:the | cable)'), new ParameterBag(), ["'the':B"]);
        self::assertNotNull($stop);
        self::assertSame('((s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz) AND NOT (s.tsv @@ q.ft2))', $stop->predicate, 'a stop word in it is dropped');
        $nothing = $compiler->compile(self::parse('mouse -(brand:the)'), new ParameterBag(), ["'the':B"]);
        self::assertNotNull($nothing);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $nothing->predicate, 'an excluded group of stop words excludes nothing');
    }

    public function testTheScopeKeepsAnExcludedScopedWordInsideAnOr(): void
    {
        self::assertSame(
            '(s.tsv @@ q.sft0 AND (s.tsv @@ q.sft1 OR NOT ((s.tsv @@ q.sft2 AND s."t_brand" @@ q.sft2))))',
            $this->compiler()->scope(self::parse('wireless (mouse | -brand:logitech)'), new ParameterBag())['predicate'] ?? null,
        );
    }

    public function testAnUnknownFieldStillSearchesEveryField(): void
    {
        $match = $this->compiler()->compile(self::parse('colour:reds'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate);
    }

    public function testTheScopeIsTheExactOnlyQueryWithItsOwnColumns(): void
    {
        $params = new ParameterBag();
        $compiler = $this->compiler();

        self::assertSame([
            'predicate' => '((s.tsv @@ q.sft0 AND s."t_brand" @@ q.sft0) OR s.tsv @@ q.sft1)',
            'columns' => [
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS sft0",
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p1) AS sft1",
            ],
        ], $compiler->scope(self::parse('brand:sony | mouse'), $params));
        self::assertSame(['p0' => "'sony':B", 'p1' => "'mouse'"], $params->all());
        self::assertNull($compiler->scope(self::parse('brand:the'), new ParameterBag(), ["'the':B"]), 'a stop word leaves nothing to check');

        $match = $compiler->compile(self::parse('mouse'), new ParameterBag());
        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate, 'compile() afterwards is typo-tolerant again, with its own column names');
    }

    /** @return iterable<string, array{string, string|null}> the query and the similarity its word must reach (null: only "<%") */
    public static function lengthBands(): iterable
    {
        yield 'a short word is strict' => ['lamp', '0.60'];
        yield 'a medium word is stricter than the lowest band' => ['mouse', '0.45'];
        yield 'seven letters are still medium' => ['wireles', '0.45'];
        yield 'a long word keeps the lowest band, no recheck' => ['headphones', null];
        yield 'a phrase counts its letters without the spaces' => ['"lamp cap"', '0.45'];
        yield 'a long phrase needs no recheck' => ['"noise cancelling"', null];
    }

    #[DataProvider('lengthBands')]
    public function testAWordMustReachTheSimilarityOfItsLength(string $input, ?string $similarity): void
    {
        $match = self::lengthAware()->compile(self::parse($input), new ParameterBag());

        self::assertNotNull($match);
        if ($similarity === null) {
            self::assertStringNotContainsString('word_similarity', $match->predicate);
            self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate);

            return;
        }
        self::assertSame(
            sprintf('(s.tsv @@ q.ft0 OR (q.fn1 OPERATOR("public".<%%) s.fz AND "public".word_similarity(q.fn1, s.fz) >= %s))', $similarity),
            $match->predicate,
        );
        self::assertStringNotContainsString('>=', $match->score, 'the score is the similarity itself, not a threshold');
    }

    public function testAFlatSimilarityNeverAddsARecheck(): void
    {
        foreach ([0.3, 0.5] as $flat) {
            $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: $flat)))->compile(self::parse('mouse'), new ParameterBag());

            self::assertNotNull($match);
            self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate, "flat $flat");
        }
    }

    public function testAFieldScopedWordIsRecheckedAgainstItsOwnColumn(): void
    {
        $match = self::lengthAware()->compile(self::parse('name:mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('"public".word_similarity(q.fn1, s."z_name") >= 0.45', $match->predicate);
    }

    public function testTheRelaxationProbeUsesTheSameSimilarity(): void
    {
        $leaf = self::parse('mouse');
        $conditions = self::lengthAware()->leafConditions([$leaf], new ParameterBag(), [], true);

        self::assertStringContainsString('word_similarity(q.fn1, s.fz) >= 0.45', (string) $conditions['predicates'][0]);
    }

    private function compiler(): FuzzyQueryCompiler
    {
        // flat: these tests are about the structure; the length-aware SQL has its own tests
        return new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3));
    }

    private static function lengthAware(): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler(Indexes::products(), new Thresholds());
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }

    /**
     * Substitutes the bound values for the q columns and abbreviates the leaf expressions, so the
     * structure is readable: fz[tsquery|needle], sim[needle|tsquery], ts[tsquery], hit[tsquery], guard[predicate => score].
     * testOneLeafIsExactOrFuzzyAndEveryValueIsBound pins the unabbreviated SQL.
     */
    private static function shorthand(string $sql, FuzzyMatch $match, ParameterBag $params): string
    {
        $values = [];
        foreach ($match->columns as $column) {
            if (preg_match('/:(p\d+)\)? AS (f[tn]\d+)$/', $column, $m) !== 1) {
                self::fail('Unexpected q column: ' . $column);
            }
            $values['q.' . $m[2]] = '{' . (string) ($params->all()[$m[1]] ?? '?') . '}';
        }
        $sql = strtr($sql, $values);

        $sql = (string) preg_replace(
            [
                '/\(s\.tsv @@ \{([^}]*)\} OR \{([^}]*)\} OPERATOR\("public"\.<%\) s\.fz\)/',
                '/GREATEST\("public"\.word_similarity\(\{([^}]*)\}, s\.fz\), CASE WHEN s\.tsv @@ \{([^}]*)\} THEN 1\.0 ELSE 0\.0 END\)/',
                '/CASE WHEN s\.tsv @@ \{([^}]*)\} THEN 1\.0 ELSE 0\.0 END/',
                '/s\.tsv @@ \{([^}]*)\}/',
            ],
            ['fz[$1|$2]', 'sim[$1|$2]', 'hit[$1]', 'ts[$1]'],
            $sql,
        );
        // an OR branch guard, innermost first: guard[predicate => score]
        do {
            $sql = (string) preg_replace('/CASE WHEN ((?:(?!CASE).)*?) THEN ((?:(?!CASE).)*?) ELSE 0\.0 END/', 'guard[$1 => $2]', $sql, -1, $count);
        } while ($count > 0);

        return $sql;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Exact-only words next to typo-tolerant ones, and the alternatives a synonym adds to a word (the relaxation probe's leaves). */
final class FuzzyQueryCompilerExactLeavesTest extends TestCase
{
    private static function compiler(): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3));
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }

    public function testAnExactOnlyWordInAnOrScoresPlainlyNextToATypoTolerantOne(): void
    {
        $match = self::compiler()->compile(self::parse('mouse | ab'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('((s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz) OR s.tsv @@ q.ft2)', $match->predicate);
        self::assertSame(
            'GREATEST(GREATEST("public".word_similarity(q.fn1, s.fz), CASE WHEN s.tsv @@ q.ft0 THEN 1.0 ELSE 0.0 END), CASE WHEN s.tsv @@ q.ft2 THEN 1.0 ELSE 0.0 END)',
            $match->score,
            'the exact-only word needs no guard of its own predicate',
        );
    }

    public function testASynonymGroupIsOneTypoTolerantUnitWithTheFuzzyBranchAndExactOnlyWithout(): void
    {
        $group = new AnyOf([new Term('mouse'), new Term('rodent')]);

        $fuzzy = self::compiler()->leafConditions([$group, new Term('wireless')], new ParameterBag(), [], fuzzy: true);
        self::assertSame(
            [
                '((s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz) OR (s.tsv @@ q.ft2 OR q.fn3 OPERATOR("public".<%) s.fz))',
                '(s.tsv @@ q.ft4 OR q.fn5 OPERATOR("public".<%) s.fz)',
            ],
            $fuzzy['predicates'],
            'each alternative is exact or trigram, and the word after the group is not held to exact only',
        );

        $exact = self::compiler()->leafConditions([$group, new Term('wireless')], new ParameterBag(), [], fuzzy: false);
        self::assertSame(['(s.tsv @@ q.ft0 OR s.tsv @@ q.ft1)', 's.tsv @@ q.ft2'], $exact['predicates']);
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Details of the typo-tolerant compiler that the other tests of it leave open. */
final class FuzzyQueryCompilerMutationsTest extends TestCase
{
    public function testSeveralAllowedFuzzyFieldsScoreByTheBestOfThem(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3), new Names(), [], ['name', 'brand']);
        $match = $compiler->compile(self::parse('mouse'), new ParameterBag());

        self::assertNotNull($match);
        $best = 'GREATEST("public".word_similarity(q.fn1, s."z_name"), "public".word_similarity(q.fn1, s."z_brand"))';
        self::assertSame(
            sprintf('GREATEST(%s, CASE WHEN (s.tsv @@ q.ft0 AND (s."t_name" @@ q.ft0 OR s."t_brand" @@ q.ft0)) THEN 1.0 ELSE 0.0 END)', $best),
            $match->score,
        );
        self::assertStringContainsString(sprintf('(q.fn1 OPERATOR("public".<%%) s.fz AND %s >= 0.3)', $best), $match->predicate);
    }

    public function testTheOnlyAllowedFuzzyFieldIsFoundWhateverItsPositionInTheIndex(): void
    {
        // the first field the viewer may see is not typo-tolerant, the second is
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, p.description FROM fz_product p')
            ->field('description', 'D')
            ->field('name', 'A', fuzzy: true)
            ->sync('manual')
            ->build();
        $compiler = new FuzzyQueryCompiler($index, new Thresholds(fuzzySimilarity: 0.3), new Names(), [], ['description', 'name']);

        $match = $compiler->compile(self::parse('mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('(q.fn1 OPERATOR("public".<%) s.fz AND "public".word_similarity(q.fn1, s."z_name") >= 0.3)', $match->predicate);
        self::assertStringStartsWith('GREATEST("public".word_similarity(q.fn1, s."z_name"), CASE WHEN', $match->score, 'the similarity of the one field, no GREATEST of its own');
    }

    public function testARepeatedWordIsProbedOnceAndTheNextWordStillIs(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3));

        $probes = $compiler->probes(self::parse('mouse mouse keyboard'), 10);

        self::assertCount(2, $probes, 'mouse once, keyboard after it');
    }

    public function testTheRelaxationLeavesAfterAnExpansionAreCompiledToo(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3));
        $expansion = new AnyOf([new Term('mouse'), new Term('rodent', false, true)], expansion: true);

        $compiled = $compiler->leafConditions([$expansion, new Term('cable')], new ParameterBag(), [], false);

        self::assertCount(2, $compiled['predicates']);
        self::assertNotNull($compiled['predicates'][0]);
        self::assertNotNull($compiled['predicates'][1]);
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

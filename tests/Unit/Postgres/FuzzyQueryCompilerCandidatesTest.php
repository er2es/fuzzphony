<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The candidates of a typo-tolerant word: probes() asks for them, the compiler uses them (see the class comment of FuzzyQueryCompiler). */
final class FuzzyQueryCompilerCandidatesTest extends TestCase
{
    public function testEveryTrigramWordGetsOneProbeWithItsOwnSimilarity(): void
    {
        $probes = self::compiler()->probes(self::parse('wireles mouse'), 2000);

        self::assertCount(2, $probes);
        $similarities = array_map(static fn(array $probe): float => $probe['similarity'], array_values($probes));
        // 7 letters: one typo, (7 + 1 - 3) / (7 + 1 + 3) + 0.03; 5 letters: (5 + 1 - 3) / (5 + 1 + 3) + 0.03
        self::assertEqualsWithDelta([0.4845454545, 0.3633333333], $similarities, 0.0001);
        $first = array_values($probes)[0];
        self::assertSame(
            "SELECT s.id\nFROM \"public\".\"fuzzphony_products\" AS s\nWHERE (\"public\".\"fuzzphony_norm\"(:p0) OPERATOR(\"public\".<%) s.fz AND \"public\".word_similarity(\"public\".\"fuzzphony_norm\"(:p0), s.fz) >= ",
            substr($first['sql'], 0, (int) strpos($first['sql'], '(CASE')),
        );
        self::assertStringEndsWith("\nLIMIT 2001", $first['sql']);
        self::assertSame(["p0" => "wireles"], $first["params"]);
    }

    public function testAFlatSimilarityIsTheSessionsAndNeedsNoRecheck(): void
    {
        $probes = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3), new Names()))->probes(self::parse('mouse'), 10);

        self::assertCount(1, $probes);
        $probe = array_values($probes)[0];
        self::assertSame(0.3, $probe['similarity']);
        self::assertStringContainsString("WHERE \"public\".\"fuzzphony_norm\"(:p0) OPERATOR(\"public\".<%) s.fz\nLIMIT 11", $probe['sql']);
    }

    public function testAProbeAppliesTheSearchsConditions(): void
    {
        $conditions = (new \Fuzzphony\Core\Query\SearchQuery())->where('brand_id', '=', 1)->where('price', '<', 5000)->conditions;
        $probe = array_values((new FuzzyQueryCompiler(Indexes::products(), new Thresholds(fuzzySimilarity: 0.3), new Names()))->probes(self::parse('mouse'), 10, [], $conditions))[0];

        self::assertSame("SELECT s.id\nFROM \"public\".\"fuzzphony_products\" AS s\nWHERE \"public\".\"fuzzphony_norm\"(:p0) OPERATOR(\"public\".<%) s.fz AND s.\"f_brand_id\" = :p1 AND s.\"f_price\" < :p2\nLIMIT 11", $probe['sql']);
        self::assertSame(['p0' => 'mouse', 'p1' => 1, 'p2' => 5000], $probe['params']);
    }

    public function testAWordIsProbedOnceNegatedAndShortWordsNever(): void
    {
        self::assertCount(1, self::compiler()->probes(self::parse('mouse mouse'), 10), 'the same word, the same matches');
        self::assertCount(1, self::compiler()->probes(self::parse('mouse -cable'), 10), 'a negated word is exact only');
        self::assertSame([], self::compiler()->probes(self::parse('ab'), 10), 'below fuzzyMinLength the word matches exactly');
        self::assertCount(2, self::compiler()->probes(self::parse('mouse | trackpad'), 10), 'both sides of an OR');
    }

    public function testAPrefixAndAFieldAreDifferentWordsFromThePlainOne(): void
    {
        self::assertCount(2, self::compiler()->probes(self::parse('mouse mouse*'), 10), 'a prefix needs less similarity');
        self::assertCount(2, self::compiler()->probes(self::parse('mouse name:mouse'), 10), 'a field is checked against its own column');
        $scoped = array_values(self::compiler()->probes(self::parse('name:mouse'), 10))[0];
        self::assertStringContainsString('s."z_name"', $scoped["sql"]);
    }

    public function testAnExpandedWordKeepsItsOwnProbe(): void
    {
        // the alternatives of a synonym are words of their own: their typos are found as before
        $probes = self::compiler()->probes(self::parse('tvs | television'), 10);

        self::assertCount(2, $probes);
    }

    public function testACandidateReplacesTheTrigramConditionOfItsWord(): void
    {
        $compiler = self::compiler();
        $root = self::parse('mouse');
        $key = array_key_first($compiler->probes($root, 10));
        self::assertNotNull($key);

        $params = new ParameterBag();
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names(), [$key => [3, 17]]))->compile($root, $params);

        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 OR s.id = ANY(q.id3))', $match->predicate);
        self::assertSame('CAST(:p3 AS bigint[]) AS id3', $match->columns[3]);
        self::assertSame('{3,17}', $params->all()['p3']);
        // the score still measures the similarity, so the ranking is the same
        self::assertStringContainsString('word_similarity(q.fn1, s.fz)', $match->score);
    }

    public function testAWordWithoutCandidatesKeepsItsTrigramCondition(): void
    {
        $match = (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names(), ['another word' => [1]]))->compile(self::parse('mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertStringContainsString('OPERATOR("public".<%) s.fz', $match->predicate);
        self::assertStringNotContainsString('ANY', $match->predicate);
    }

    public function testNoMatchesIsAnEmptyListNotAMissingOne(): void
    {
        $root = self::parse('mouse');
        $key = array_key_first(self::compiler()->probes($root, 10));
        self::assertNotNull($key);

        $params = new ParameterBag();
        (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names(), [$key => []]))->compile($root, $params);

        self::assertSame('{}', $params->all()['p3']);
    }

    public function testTheIdsAreCastToTheTypeOfTheIdColumn(): void
    {
        foreach ([[IdType::Uuid, 'uuid[]', '{"6f1d0c1e-0000-4000-8000-000000000001"}'], [IdType::String, 'text[]', '{"a\"b"}']] as [$type, $cast, $literal]) {
            $index = IndexDefinition::builder('things')->fromTable('thing')->idType($type)->field('name', 'A', fuzzy: true)->build();
            $root = self::parse('mouse');
            $key = array_key_first((new FuzzyQueryCompiler($index, new Thresholds(), new Names()))->probes($root, 10));
            self::assertNotNull($key);

            $params = new ParameterBag();
            $match = (new FuzzyQueryCompiler($index, new Thresholds(), new Names(), [$key => [$type === IdType::Uuid ? '6f1d0c1e-0000-4000-8000-000000000001' : 'a"b']]))->compile($root, $params);

            self::assertNotNull($match);
            self::assertSame(sprintf('CAST(:p3 AS %s) AS id3', $cast), $match->columns[3]);
            self::assertSame($literal, $params->all()['p3']);
        }
    }

    public function testAProbeAtALevelAsksForTheDocumentsThatCloseAndLeavesOutTheSwappedVariants(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names(), transpositions: true);
        $node = self::parse('wireles mouse');
        $own = $compiler->probes($node, 2000);
        $keys = array_keys($own);
        self::assertCount(2, $keys);

        $probes = $compiler->probes($node, 6000, [], [], [$keys[1] => 0.8]);

        self::assertSame([$keys[1]], array_keys($probes), 'only the words asked for');
        self::assertSame(0.8, $probes[$keys[1]]['similarity']);
        self::assertStringContainsString('>= 0.8', $probes[$keys[1]]['sql']);
        self::assertStringEndsWith("
LIMIT 6001", $probes[$keys[1]]['sql']);
        self::assertStringNotContainsString('OR s.tsv @@', $probes[$keys[1]]['sql'], 'no variants at a raised level');
        self::assertStringContainsString('OR', $own[$keys[1]]['sql'], 'the own probe has them');
    }

    public function testAnotherCompilationOfTheSameQueryNamesItsColumnsApart(): void
    {
        $compiler = self::compiler();
        $params = new ParameterBag();
        $plain = $compiler->compile(self::parse('mouse'), $params);
        $pooled = $compiler->compile(self::parse('mouse'), $params, [], 'p');

        self::assertNotNull($plain);
        self::assertNotNull($pooled);
        self::assertStringContainsString(' AS ft0', $plain->columns[0]);
        self::assertStringContainsString(' AS pft0', $pooled->columns[0]);
        self::assertSame(1, $plain->words);
    }

    private static function compiler(): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names());
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

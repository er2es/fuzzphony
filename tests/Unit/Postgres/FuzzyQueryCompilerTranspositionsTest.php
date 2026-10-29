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
use Fuzzphony\Engine\Postgres\Sql\FuzzyMatch;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The swapped-letter variants of a typed word (Transpositions) in the statements of the typo-tolerant branch. */
final class FuzzyQueryCompilerTranspositionsTest extends TestCase
{
    private static function parse(string $text): Node
    {
        return (new QueryParser(256, 16))->parse($text)->root ?? throw new \LogicException('no query');
    }

    /**
     * @param list<string>|null $allowed
     * @param array<string, list<int|string>> $candidates
     */
    private static function compiler(?IndexDefinition $index = null, bool $on = true, ?array $allowed = null, array $candidates = [], ?Thresholds $thresholds = null): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler($index ?? Indexes::products(), $thresholds ?? new Thresholds(), new Names(), $candidates, $allowed, $on);
    }

    /** @return array{FuzzyMatch, array<string, scalar|null>} */
    private static function compile(FuzzyQueryCompiler $compiler, string $text): array
    {
        $params = new ParameterBag();
        $match = $compiler->compile(self::parse($text), $params);
        self::assertNotNull($match);

        return [$match, $params->all()];
    }

    public function testItIsOffUnlessAskedFor(): void
    {
        [$off] = self::compile(self::compiler(on: false), 'mosue');
        [$default] = self::compile(new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names()), 'mosue');

        self::assertStringNotContainsString('tv', $off->predicate . implode('', $off->columns));
        self::assertEquals($off, $default);
    }

    public function testAWordGetsOneTsqueryOfItsVariantsAsOneMoreAlternative(): void
    {
        [$match, $params] = self::compile(self::compiler(), 'mosue');

        self::assertContains("'omsue' | 'msoue' | 'mouse' | 'moseu'", $params);
        self::assertStringContainsString('to_tsquery(', implode("\n", $match->columns));
        self::assertStringContainsString(' AS tv3', implode("\n", $match->columns));
        // exact, trigram, or a variant in a typo-tolerant field (the description is not one)
        self::assertMatchesRegularExpression('/^\(s\.tsv @@ q\.ft0 OR \(.+\) OR \(s\.tsv @@ q\.tv3 AND \(s\."t_name" @@ q\.tv3 OR s\."t_brand" @@ q\.tv3\)\)\)$/', $match->predicate);
        self::assertStringNotContainsString('t_description', $match->predicate);
    }

    public function testAVariantScoresTheLeastATypoOfTheWordMayScoreSoItRanksBelowAnExactHit(): void
    {
        [$match] = self::compile(self::compiler(), 'mosue');
        // by length: the word's own threshold (q.th2); an exact match is 1.0
        self::assertStringContainsString('THEN q.th2 ELSE 0.0 END)', $match->score);
        self::assertStringContainsString('THEN 1.0 ELSE 0.0 END', $match->score);

        [$flat] = self::compile(self::compiler(thresholds: new Thresholds(fuzzySimilarity: 0.3)), 'mosue');
        self::assertStringContainsString('THEN 0.3 ELSE 0.0 END)', $flat->score);
    }

    public function testAScopedWordIsLookedUpInItsOwnFieldOnly(): void
    {
        [$match] = self::compile(self::compiler(), 'name:mosue');

        // the variants' own condition: the column numbers depend on the other conditions of the statement
        self::assertMatchesRegularExpression('/\(s\.tsv @@ q\.tv\d+ AND s\."t_name" @@ q\.tv\d+\)/', $match->predicate);
        self::assertStringNotContainsString('t_brand', $match->predicate);
    }

    public function testAnIndexWithEveryFieldTypoTolerantNeedsNoFieldCheck(): void
    {
        $index = IndexDefinition::builder('things')->fromQuery('SELECT id, a, b FROM things')->watch('things')->field('a', 'A', fuzzy: true)->field('b', 'B', fuzzy: true)->build();
        [$match] = self::compile(self::compiler($index), 'mosue');

        self::assertStringContainsString(' OR s.tsv @@ q.tv3)', $match->predicate);
        self::assertStringNotContainsString('t_a', $match->predicate);
    }

    public function testARestrictedViewerGetsTheVariantsInItsTypoTolerantFieldsOnly(): void
    {
        [$brand] = self::compile(self::compiler(allowed: ['brand', 'description']), 'mosue');
        [$name] = self::compile(self::compiler(allowed: ['name']), 'mosue');

        self::assertStringContainsString('(s.tsv @@ q.tv', $brand->predicate);
        self::assertStringContainsString('s."t_brand" @@ q.tv', $brand->predicate);
        self::assertStringNotContainsString('t_name', $brand->predicate . $brand->score, 'a hidden field never decides');
        self::assertStringNotContainsString('t_description" @@ q.tv', $brand->predicate, 'a field that is not typo-tolerant');
        self::assertStringContainsString('s."t_name" @@ q.tv', $name->predicate);
        self::assertStringNotContainsString('t_brand', $name->predicate . $name->score);
    }

    /** @return iterable<string, array{string}> */
    public static function withoutVariants(): iterable
    {
        yield 'a prefix' => ['mosue*'];
        yield 'a phrase' => ['"mosue pad"'];
        yield 'three letters' => ['mos'];
        yield 'a code' => ['rtx4090'];
        yield 'sixteen letters' => ['mosuemosuemosuem'];
        yield 'a word with a hyphen' => ['wi-fi'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('withoutVariants')]
    public function testSomeWordsGetNoVariants(string $text): void
    {
        $compiler = self::compiler();
        $parsed = self::parse($text);

        self::assertStringNotContainsString(' AS tv', implode("\n", ($compiler->compile($parsed, new ParameterBag())->columns ?? [])));
    }

    public function testAnAlternativeASynonymAddedGetsNoVariants(): void
    {
        $node = new AnyOf([new Term('tevision'), new Term('television', synonym: true)]);
        $params = new ParameterBag();
        $match = self::compiler()->compile($node, $params);

        self::assertNotNull($match);
        self::assertSame(1, substr_count(implode("\n", $match->columns), ' AS tv'), 'the typed word only');
        self::assertContains("'etvision' | 'tveision' | 'teivsion' | 'tevsiion' | 'teviison' | 'tevisoin' | 'tevisino'", $params->all());
        self::assertCount(1, array_filter($params->all(), static fn(mixed $p): bool => is_string($p) && str_contains($p, ' | ')), 'no tsquery of variants for the synonym');
    }

    public function testAQueryAddsAtMostFortyEightVariantsAndAWordWhoseVariantsDoNotFitGetsNone(): void
    {
        // seven different letters each: six variants a word, so eight words use the whole budget
        $words = ['abcdefg', 'bcdefgh', 'cdefghi', 'defghij', 'efghijk', 'fghijkl', 'ghijklm', 'hijklmn', 'ijklmno'];
        [$match, $params] = self::compile(self::compiler(), implode(' ', $words));

        self::assertSame(8, substr_count(implode("\n", $match->columns), ' AS tv'));
        $variantQueries = array_filter($params, static fn(mixed $p): bool => is_string($p) && str_contains($p, ' | '));
        self::assertCount(8, $variantQueries);
        self::assertSame(48, array_sum(array_map(static fn(string $q): int => substr_count($q, ' | ') + 1, $variantQueries)));
    }

    public function testTheProbeOfAWordFindsTheVariantsToo(): void
    {
        $probes = self::compiler()->probes(self::parse('mosue'), 2000);
        $probe = array_values($probes)[0];

        self::assertStringContainsString(') OR (s.tsv @@ to_tsquery(', $probe['sql']);
        self::assertStringContainsString('s."t_name" @@ to_tsquery(', $probe['sql']);
        // every use of the variants is bound on its own (the native prepare of a client cannot reuse a name)
        $bound = array_filter($probe['params'], static fn(mixed $p): bool => $p === "'omsue' | 'msoue' | 'mouse' | 'moseu'");
        self::assertCount(3, $bound);
        self::assertStringEndsWith("\nLIMIT 2001", $probe['sql']);

        $off = array_values(self::compiler(on: false)->probes(self::parse('mosue'), 2000))[0];
        self::assertStringNotContainsString('to_tsquery', $off['sql']);
    }

    public function testWithTheProbedIdsTheVariantOnlyScoresAndTheIdsDecideWhoIsACandidate(): void
    {
        $compiler = self::compiler();
        $key = array_keys($compiler->probes(self::parse('mosue'), 2000))[0];
        [$match] = self::compile(self::compiler(candidates: [$key => [1, 2]]), 'mosue');

        self::assertSame(1, preg_match('/^\(s\.tsv @@ q\.ft0 OR s\.id = ANY\(q\.id\d\)\)$/', $match->predicate), $match->predicate);
        self::assertStringContainsString('q.tv', $match->score);
    }

    public function testAKeyProbedWithoutVariantsIsNotTheKeyOfTheStatementWithThem(): void
    {
        $on = array_keys(self::compiler()->probes(self::parse('mosue'), 2000))[0];
        $off = array_keys(self::compiler(on: false)->probes(self::parse('mosue'), 2000))[0];

        self::assertNotSame($on, $off);
    }

    public function testTheRelaxationProbeSeesTheVariantsToo(): void
    {
        $params = new ParameterBag();
        $conditions = self::compiler()->leafConditions([self::parse('mosue')], $params, [], true);

        self::assertStringContainsString('q.tv', (string) $conditions['predicates'][0]);
        $exactOnly = self::compiler()->leafConditions([self::parse('mosue')], new ParameterBag(), [], false);
        self::assertStringNotContainsString('q.tv', (string) $exactOnly['predicates'][0]);
    }
}

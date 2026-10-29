<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\OpenClass;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The open class (OpenClass): an index with a granted field has two more indexes over its open fields, and a restricted viewer who holds
 * none of the granted fields looks its words up in them, when the gate says they may be used. Name A and category B are open, brand B
 * and description D are granted (description is not typo-tolerant).
 */
final class OpenClassSqlTest extends TestCase
{
    private const string OPEN_VECTOR = '(s."t_name" || s."t_category")';
    private const string OPEN_TRIGRAM = '(s."z_name" || \' \' || s."z_category")';

    private static function index(bool $granted = true): IndexDefinition
    {
        return IndexDefinition::builder('items')->fromQuery('SELECT id, name, category, brand, description FROM fz_item')
            ->field('name', 'A', fuzzy: true)->field('category', 'B', fuzzy: true)
            ->field('brand', 'B', fuzzy: true, granted: $granted ? 'ROLE_BRAND' : null)->field('description', 'D', granted: $granted ? 'ROLE_DESCRIPTION' : null)
            ->filter('price', 'int')->sync('manual')->build();
    }

    public function testAnIndexWithAGrantedFieldHasTwoMoreIndexesOverItsOpenFields(): void
    {
        $indexes = (new PostgresSchemaGenerator())->indexes(self::index());

        $names = array_keys($indexes);
        self::assertCount(5, $names);
        self::assertSame(['fuzzphony_items_tsv', 'fuzzphony_items_fz'], array_slice($names, 0, 2));
        self::assertMatchesRegularExpression('/^fuzzphony_items_open_fz_[0-9a-f]{8}$/', $names[2]);
        self::assertMatchesRegularExpression('/^fuzzphony_items_open_tsv_[0-9a-f]{8}$/', $names[3]);
        self::assertSame('fuzzphony_items_f_price', $names[4]);
        self::assertSame('USING gin (("z_name" || \' \' || "z_category") "public".gin_trgm_ops)', $indexes[$names[2]]);
        self::assertSame('USING gin (("t_name" || "t_category"))', $indexes[$names[3]]);
    }

    public function testAnIndexWithoutAGrantedFieldHasNoneOfThem(): void
    {
        self::assertSame(['fuzzphony_items_tsv', 'fuzzphony_items_fz', 'fuzzphony_items_f_price'], array_keys((new PostgresSchemaGenerator())->indexes(self::index(false))));
        self::assertSame([], OpenClass::indexes(self::index(false), new Names()));
    }

    public function testASingleOpenFieldIsIndexedOnItsOwnColumn(): void
    {
        $index = IndexDefinition::builder('items')->fromQuery('SELECT id, name, secret FROM fz_item')
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: 'ROLE_X')->sync('manual')->build();

        $indexes = OpenClass::indexes($index, new Names());
        self::assertSame(['USING gin (("z_name") "public".gin_trgm_ops)', 'USING gin (("t_name"))'], array_values($indexes));
        self::assertMatchesRegularExpression('/^open_fz_[0-9a-f]{8}$/', array_keys($indexes)[0]);
        self::assertMatchesRegularExpression('/^open_tsv_[0-9a-f]{8}$/', array_keys($indexes)[1]);
    }

    public function testTheNameFollowsTheDefinitionSoAChangeOfGrantedFieldsIsANewIndex(): void
    {
        $one = IndexDefinition::builder('items')->fromQuery('SELECT id, name, secret FROM fz_item')
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: 'ROLE_X')->sync('manual')->build();
        $other = IndexDefinition::builder('items')->fromQuery('SELECT id, name, secret FROM fz_item')
            ->field('name', 'A', fuzzy: true, granted: 'ROLE_X')->field('secret', 'B', fuzzy: true)->sync('manual')->build();

        self::assertSame(OpenClass::expectedNames($one, new Names()), OpenClass::expectedNames($one, new Names()));
        self::assertNotSame(OpenClass::expectedNames($one, new Names()), OpenClass::expectedNames($other, new Names()));
    }

    public function testALongNameKeepsTheTailAndTwoLongNamesStayApart(): void
    {
        $names = new Names();
        $long = static fn(string $name): IndexDefinition => IndexDefinition::builder($name)->fromQuery('SELECT id, name, secret FROM t')
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: 'ROLE_X')->sync('manual')->build();
        $a = OpenClass::expectedNames($long(str_repeat('x', 40) . 'a'), $names);
        $b = OpenClass::expectedNames($long(str_repeat('x', 40) . 'b'), $names);

        foreach ([...$a, ...$b] as $name) {
            self::assertLessThanOrEqual(Names::MAX_IDENTIFIER_BYTES, strlen($name));
            self::assertTrue(OpenClass::isOpenClassName($name), $name);
        }
        self::assertSame([], array_intersect($a, $b));
        self::assertFalse(OpenClass::isOpenClassName('fuzzphony_products_open_fz_myindex1'));
        self::assertFalse(OpenClass::isOpenClassName('fuzzphony_products_open_fz_0123456'));
    }

    public function testNoTypoTolerantOpenFieldMeansNoTrigramIndex(): void
    {
        $index = IndexDefinition::builder('items')->fromQuery('SELECT id, name, secret FROM fz_item')
            ->field('name', 'A')->field('secret', 'B', fuzzy: true, granted: 'ROLE_X')->sync('manual')->build();

        $keys = array_keys(OpenClass::indexes($index, new Names()));
        self::assertCount(1, $keys);
        self::assertStringStartsWith('open_tsv_', $keys[0]);
    }

    public function testTheLookupsOfAViewerWhoHoldsNoGrantedFieldGoThroughTheOpenClass(): void
    {
        $sql = self::ranked(['name', 'category'], 'mouse', true);

        self::assertStringContainsString('WHERE ' . self::OPEN_VECTOR . ' @@ q.tsq AND', $sql);
        self::assertStringNotContainsString('s.tsv', $sql);
        self::assertStringContainsString('OPERATOR("public".<%) ' . self::OPEN_TRIGRAM, $sql);
        self::assertStringNotContainsString('s.fz', $sql);
        // the recheck, the rank and the score are the allowed fields' own columns, as before
        self::assertStringContainsString('s."t_name" @@ q.sft0', $sql);
        self::assertStringContainsString('word_similarity(q.fn', $sql);
    }

    public function testOneAllowedFieldLooksUpInTheWholeOpenClass(): void
    {
        $sql = self::ranked(['category'], 'mouse', true);

        self::assertStringContainsString(self::OPEN_VECTOR . ' @@ q.tsq', $sql);
        self::assertStringContainsString('OPERATOR("public".<%) ' . self::OPEN_TRIGRAM, $sql);
    }

    public function testAPhraseAndAnExclusionAreLookedUpByTheirWordsInTheOpenClass(): void
    {
        $phrase = self::ranked(['name', 'category'], '"red mouse"', true);
        $excluded = self::ranked(['name', 'category'], 'mouse -cable', true);

        self::assertMatchesRegularExpression('/WHERE \(s\."t_name" \|\| s\."t_category"\) @@ q\.tsp AND/', $phrase);
        self::assertMatchesRegularExpression('/WHERE \(s\."t_name" \|\| s\."t_category"\) @@ q\.tsp AND/', $excluded);
        self::assertStringNotContainsString('s.tsv', $phrase . $excluded);
    }

    public function testTheProbesAndTheRelaxationUseTheOpenClassToo(): void
    {
        $builder = self::builder(['name', 'category'], static fn(): bool => true);
        $probe = $builder->probe([new Term('mouse'), new Term('cable')], true, [], new Thresholds())['sql'];
        $probes = (new FuzzyQueryCompiler(self::index(), new Thresholds(), new Names(), [], ['name', 'category'], false, null, static fn(): bool => true))->probes(self::parse('mouse'), 100);

        self::assertStringContainsString(self::OPEN_VECTOR . ' @@ q.ft0', $probe);
        self::assertStringContainsString('OPERATOR("public".<%) ' . self::OPEN_TRIGRAM, $probe);
        self::assertStringNotContainsString('s.tsv', $probe);
        self::assertStringContainsString('OPERATOR("public".<%) ' . self::OPEN_TRIGRAM, array_values($probes)[0]['sql']);
        self::assertStringNotContainsString('s.fz', array_values($probes)[0]['sql']);
    }

    public function testAScopedWordKeepsItsOwnColumnAndLooksUpInTheOpenClass(): void
    {
        $sql = self::ranked(['name', 'category'], 'name:mouse', true);

        self::assertStringContainsString(self::OPEN_VECTOR . ' @@ ', $sql);
        self::assertStringContainsString('s."t_name" @@ ', $sql);
        self::assertStringNotContainsString('s.tsv', $sql);
    }

    /**
     * @param list<string>|null $allowed
     */
    private static function assertToday(?array $allowed, string $text): void
    {
        $calls = 0;
        $asked = static function () use (&$calls): bool {
            ++$calls;

            return true;
        };
        $root = self::parse($text);
        $tsquery = (new TsQueryCompiler(self::index()))->compile($root);
        $withGate = self::builder($allowed, $asked)->ranked($tsquery, 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];
        $without = self::builder($allowed, null)->ranked($tsquery, 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];

        self::assertSame($without, $withGate, json_encode($allowed) . ' ' . $text);
        self::assertSame(0, $calls, 'the gate is not even asked');
        self::assertStringNotContainsString('"z_category"', $withGate);
    }

    public function testEveryoneElseKeepsTodaysStatementsByteForByte(): void
    {
        foreach (['mouse', '"red mouse"', 'name:mouse', 'mouse -cable', 'brand:makita'] as $text) {
            self::assertToday(null, $text);
            self::assertToday(['name', 'brand'], $text);
            self::assertToday(['brand'], $text);
            self::assertToday(['name', 'category', 'brand', 'description'], $text);
            self::assertToday(['description'], $text);
        }
    }

    public function testNoRoleIsNeededToAnIndexWithoutGrantedFieldsAndTheGateIsNotAsked(): void
    {
        $calls = 0;
        $gate = static function () use (&$calls): bool {
            ++$calls;

            return true;
        };
        $root = self::parse('mouse');
        $tsquery = (new TsQueryCompiler(self::index(false)))->compile($root);
        $builder = new SearchSqlBuilder(self::index(false), new Names(), [], ['name', 'category'], true, openClass: $gate);
        $sql = $builder->ranked($tsquery, 'mouse', $root, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root)['sql'];

        self::assertSame(0, $calls);
        self::assertStringContainsString('s.tsv @@ q.tsq', $sql);
        self::assertStringContainsString('OPERATOR("public".<%) s.fz', $sql);
        self::assertStringNotContainsString('"t_category" ||', $sql);
    }

    public function testWithoutTheGateOrWhenItSaysNoTheLookupsStayOnFzAndTsv(): void
    {
        foreach ([null, static fn(): bool => false] as $gate) {
            $sql = self::ranked(['name', 'category'], 'mouse', false, $gate);

            self::assertStringContainsString('s.tsv @@ q.tsq', $sql);
            self::assertStringContainsString('OPERATOR("public".<%) s.fz', $sql);
        }
    }

    public function testTheGateIsAskedOncePerCompilerAndOnlyWhenTheViewerCouldUseIt(): void
    {
        $calls = 0;
        $gate = static function () use (&$calls): bool {
            ++$calls;

            return true;
        };
        $root = self::parse('mouse');
        $tsquery = (new TsQueryCompiler(self::index()))->compile($root);
        self::builder(['name', 'category'], $gate)->ranked($tsquery, 'mouse', $root, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root);

        self::assertSame(1, $calls);
    }

    public function testAViewerWithNoAllowedFieldIsNotSentToTheOpenClass(): void
    {
        $sql = self::ranked([], 'mouse', true);

        self::assertStringNotContainsString('t_category', $sql);
    }

    /**
     * @param list<string>|null       $allowed
     * @param (\Closure(): bool)|null $gate
     */
    private static function builder(?array $allowed, ?\Closure $gate): SearchSqlBuilder
    {
        return new SearchSqlBuilder(self::index(), new Names(), [], $allowed, true, openClass: $gate);
    }

    /**
     * @param list<string>             $allowed
     * @param (\Closure(): bool)|null  $gate    wins over $open
     */
    private static function ranked(array $allowed, string $text, bool $open, ?\Closure $gate = null): string
    {
        $root = self::parse($text);
        $tsquery = (new TsQueryCompiler(self::index()))->compile($root);
        $builder = self::builder($allowed, $gate ?? ($open ? static fn(): bool => true : null));

        return $builder->ranked($tsquery, 'mouse', $root, [], new RankingProfile(), new Thresholds(fuzzySimilarity: 0.3), 10, 0, [], $root)['sql'];
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}

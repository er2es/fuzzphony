<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\ManyRank;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\ManyMatch;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use PHPUnit\Framework\TestCase;

/** many: { rank: best }: the definition (every way to write it), the fingerprint split and the manyMatch() query API. */
final class ManyRankTest extends TestCase
{
    private const array CITY = ['from' => 'address', 'key' => 'customer_id', 'column' => 'city'];

    private static function index(ManyRank|string|null $rank): IndexDefinition
    {
        $many = $rank === null ? self::CITY : [...self::CITY, 'rank' => $rank];

        return IndexDefinition::builder('customers')->fromTable('customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', many: $many)
            ->build();
    }

    public function testTheDefaultIsWholeAndNothingIsWrittenForIt(): void
    {
        $many = new ManyValues('address', 'customer_id', 'city');

        self::assertSame(ManyRank::Whole, $many->rank);
        self::assertSame(self::CITY, $many->toArray());
        self::assertSame(self::CITY, ManyValues::fromArray('i', 'Field "city"', [...self::CITY, 'rank' => 'whole'])->toArray());
    }

    public function testBestIsReadFromAStringAndAnEnumAndWrittenBack(): void
    {
        $fromString = ManyValues::fromArray('i', 'Field "city"', [...self::CITY, 'rank' => 'best']);
        $fromEnum = new ManyValues('address', 'customer_id', 'city', rank: ManyRank::Best);

        self::assertEquals($fromString, $fromEnum);
        self::assertSame([...self::CITY, 'rank' => 'best'], $fromEnum->toArray());
        self::assertEquals($fromEnum, ManyValues::fromArray('i', 'x', $fromEnum->toArray()));
        self::assertEquals($fromEnum, ManyValues::fromArray('i', 'x', [...self::CITY, 'rank' => ManyRank::Best]));
    }

    public function testBuilderYamlAndExportersAgree(): void
    {
        $builder = self::index('best');
        $yaml = (new ArrayDefinitionLoader())->load('customers', [
            'source' => ['table' => 'customer'],
            'fields' => ['name' => ['weight' => 'A', 'fuzzy' => true], 'city' => ['weight' => 'C', 'many' => [...self::CITY, 'rank' => 'best']]],
        ]);

        self::assertEquals($builder, $yaml);
        $array = (new ArrayExporter())->export($builder);
        self::assertStringContainsString('"rank":"best"', json_encode($array, JSON_THROW_ON_ERROR));
        self::assertEquals($builder, (new ArrayDefinitionLoader())->load('customers', $array));
        $code = (new BuilderExporter())->export($builder);
        self::assertStringContainsString("'rank' => 'best'", $code);
        self::assertFalse((new AttributeExporter())->supports($builder));
        self::assertStringNotContainsString('rank', (new BuilderExporter())->export(self::index(null)));
    }

    public function testOnlyTheDefinitionFingerprintSeesTheRank(): void
    {
        $whole = self::index(null);
        $best = self::index('best');

        self::assertNotSame(Fingerprint::definition($whole), Fingerprint::definition($best), 'schema --apply is needed');
        self::assertSame(Fingerprint::documents($whole), Fingerprint::documents($best), 'no reindex is needed');
        self::assertSame(Fingerprint::definition($whole), Fingerprint::definition(self::index('whole')), 'an explicit whole is the 0.11 hash');
        self::assertSame(['address', 'customer_id', 'city', 50, 'id'], (new ManyValues('address', 'customer_id', 'city'))->fingerprint());
        self::assertSame(['address', 'customer_id', 'city', 50, 'id', 'best'], (new ManyValues('address', 'customer_id', 'city', rank: ManyRank::Best))->fingerprint());
    }

    public function testAnUnknownRankIsRefusedWithAHint(): void
    {
        foreach ([
            fn() => self::index('bst'),
            fn() => (new ArrayDefinitionLoader())->load('customers', ['source' => ['table' => 'customer'], 'fields' => ['name' => 'A', 'city' => ['weight' => 'C', 'many' => [...self::CITY, 'rank' => 'bst']]]]),
        ] as $make) {
            try {
                $make();
                self::fail('expected InvalidDefinition');
            } catch (InvalidDefinition $e) {
                self::assertStringContainsString('Field "city": many "rank" must be "whole" (the default: the values ranked as one text) or "best" (the best single value), got \'bst\'. Did you mean "best"?', $e->getMessage());
            }
        }
        try {
            (new ArrayDefinitionLoader())->load('customers', ['source' => ['table' => 'customer'], 'fields' => ['name' => 'A', 'city' => ['weight' => 'C', 'many' => [...self::CITY, 'rank' => 5]]]]);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('got 5.', $e->getMessage());
        }
    }

    public function testAFilterDoesNotRank(): void
    {
        $expected = 'Filter "city": many "rank" applies to fields only (a filter does not rank).';
        try {
            (new ArrayDefinitionLoader())->load('customers', ['source' => ['table' => 'customer'], 'fields' => ['name' => 'A'], 'filters' => ['city' => ['type' => 'string', 'many' => [...self::CITY, 'rank' => 'best']]]]);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
        try {
            IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A')->filter('city', 'string', many: [...self::CITY, 'rank' => 'best'])->build();
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
        // whole on a filter is the default and stays accepted
        self::assertNotNull(IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A')->filter('city', 'string', many: [...self::CITY, 'rank' => 'whole'])->build()->filter('city')->many);
    }

    public function testTheIndexListsItsBestFields(): void
    {
        self::assertSame(['city'], array_map(static fn($f): string => $f->name, self::index('best')->manyBestFields()));
        self::assertSame([], self::index(null)->manyBestFields());
        self::assertSame([], self::index('whole')->manyBestFields());
    }

    private static function builder(?IndexDefinition $index = null): SearchBuilder
    {
        return new SearchBuilder(self::createStub(Engine::class), $index ?? self::index('best'));
    }

    public function testManyMatchIsCarriedByTheQuery(): void
    {
        $query = self::builder()->toQuery();
        self::assertSame(ManyMatch::Any, $query->manyMatch);
        self::assertSame([], $query->manyMatchFields);

        $single = self::builder()->manyMatch('single', 'city', 'city')->toQuery();
        self::assertSame(ManyMatch::Single, $single->manyMatch);
        self::assertSame(['city'], $single->manyMatchFields);
        self::assertSame(ManyMatch::Single, self::builder()->manyMatch(ManyMatch::Single)->toQuery()->manyMatch);
        self::assertSame(ManyMatch::Any, self::builder()->manyMatch('single')->manyMatch('any')->toQuery()->manyMatch, 'the last call wins');
        self::assertSame(ManyMatch::Any, self::builder(self::index(null))->manyMatch('any')->toQuery()->manyMatch, 'any is always allowed');
        self::assertSame(ManyMatch::Single, self::builder()->manyMatch('single')->query('x')->limit(5)->where('a', 1)->toQuery()->manyMatch, 'other calls keep it');
    }

    public function testBadArgumentsAreRefused(): void
    {
        $cases = [
            [fn() => self::builder()->manyMatch('all'), 'manyMatch() takes "any" or "single", got "all".'],
            [fn() => self::builder()->manyMatch('any', 'city'), "manyMatch('any') takes no fields."],
            [fn() => self::builder(self::index(null))->manyMatch('single'), 'Index "customers" has no field with many: { rank: best }: manyMatch(\'single\') needs one (declare it and run fuzzphony:schema --apply).'],
        ];
        foreach ($cases as [$make, $message]) {
            try {
                $make();
                self::fail('expected InvalidQuery');
            } catch (InvalidQuery $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}

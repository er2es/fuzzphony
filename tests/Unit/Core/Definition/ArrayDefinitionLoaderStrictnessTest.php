<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** YAML that worked by accident (typos, strings for booleans, keys an override cannot apply) fails with a message that names the place. */
final class ArrayDefinitionLoaderStrictnessTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function typos(): iterable
    {
        yield 'field option' => [['fields' => ['title' => ['wieght' => 'A']]], 'Field "title": unknown option(s): wieght. Allowed: weight, fuzzy, highlight, column, granted. Did you mean "weight" for "wieght"?'];
        yield 'field option fuzy' => [['fields' => ['title' => ['weight' => 'A', 'fuzy' => true]]], 'Field "title": unknown option(s): fuzy.'];
        yield 'filter option' => [['fields' => ['title' => 'A'], 'filters' => ['price' => ['type' => 'int', 'colum' => 'p']]], 'Filter "price": unknown option(s): colum. Allowed: type, column. Did you mean "column" for "colum"?'];
        yield 'source option' => [['source' => ['tabel' => 'article'], 'fields' => ['title' => 'A']], '"source": unknown option(s): tabel. Allowed: table, query, id. Did you mean "table" for "tabel"?'];
        yield 'watch option' => [['fields' => ['title' => 'A'], 'watch' => ['article' => ['ids' => 'SELECT :id', 'colums' => ['title']]]], 'Watch "article": unknown option(s): colums. Allowed: ids, key, columns. Did you mean "columns" for "colums"?'];
        yield 'top level with a hint' => [['feilds' => []], 'Unknown option(s): feilds. Allowed: source, id_type, fields, filters, watch, sync, language, unaccent, boost, recency, profiles, thresholds, class, trigger_level, tenant, synonyms, log. Did you mean "fields" for "feilds"?'];
        yield 'profiles as a string' => [['fields' => ['title' => 'A'], 'profiles' => 'popular'], '"profiles" must be a map of profile name => ranking options'];
        yield 'a profile as a string' => [['fields' => ['title' => 'A'], 'profiles' => ['popular' => 'x']], 'Profile "popular" must be a map of ranking options, got \'x\'.'];
        yield 'thresholds as a string' => [['fields' => ['title' => 'A'], 'thresholds' => 'loose'], '"thresholds" must be a map of options'];
        yield 'fields as a list' => [['fields' => ['name', 'body']], '"fields" must be a map of field name => a weight letter (A-D) or options (e.g. fields: { title: A, body: { weight: C, fuzzy: true } }), got a list.'];
        yield 'filters as a list' => [['fields' => ['title' => 'A'], 'filters' => ['price']], '"filters" must be a map'];
        yield 'a field as a number' => [['fields' => ['title' => 5]], 'Field "title" must be a weight letter (A-D) or a map of options (weight, fuzzy, highlight, column, granted), got 5.'];
        yield 'granted without a value (null)' => [['fields' => ['title' => ['weight' => 'A', 'granted' => null]]], 'Field "title" "granted" must be a non-empty string, the attribute that unlocks the field (granted: ROLE_HR), got null. Remove the key to leave the field open to everyone.'];
        yield 'granted as a list of roles' => [['fields' => ['title' => ['weight' => 'A', 'granted' => ['ROLE_A', 'ROLE_B']]]], 'Field "title" "granted" must be a non-empty string'];
        yield 'granted false' => [['fields' => ['title' => ['weight' => 'A', 'granted' => false]]], 'Field "title" "granted" must be a non-empty string, the attribute that unlocks the field (granted: ROLE_HR), got false.'];
        yield 'granted empty' => [['fields' => ['title' => ['weight' => 'A', 'granted' => ' ']]], 'Field "title" "granted" must be a non-empty string'];
        yield 'source as a list' => [['source' => ['article'], 'fields' => ['title' => 'A']], '"source" must be a map'];
        yield 'an unknown class' => [['fields' => ['title' => 'A'], 'class' => 'App\\Entity\\Nope'], '"class" must be the name of an existing class, got \'App\\\\Entity\\\\Nope\'.'];
        yield 'a string option given a list' => [['fields' => ['title' => 'A'], 'boost' => ['x']], '"boost" must be a string, got a list.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('typos')]
    public function testLoadRefusesWithAMessageThatNamesThePlace(array $config, string $message): void
    {
        $config += ['source' => ['table' => 'article']];

        try {
            (new ArrayDefinitionLoader())->load('articles', $config);
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertSame('articles', $e->index);
            self::assertStringContainsString($message, implode("\n", $e->violations));
        }
    }

    public function testAGrantedFieldKeepsItsAttributeAndAFieldWithoutTheKeyStaysOpen(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', ['source' => ['table' => 'article'], 'fields' => ['title' => 'A', 'salary' => ['weight' => 'B', 'granted' => 'ROLE_HR']]]);

        self::assertSame([null, 'ROLE_HR'], array_map(static fn($f): ?string => $f->granted, $definition->fields));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function stringBooleans(): iterable
    {
        yield 'fuzzy "false"' => [['fields' => ['title' => ['weight' => 'A', 'fuzzy' => 'false']]]];
        yield 'fuzzy "true"' => [['fields' => ['title' => ['weight' => 'A', 'fuzzy' => 'true']]]];
        yield 'fuzzy 1' => [['fields' => ['title' => ['weight' => 'A', 'fuzzy' => 1]]]];
        yield 'highlight "no"' => [['fields' => ['title' => ['weight' => 'A', 'highlight' => 'no']]]];
        yield 'unaccent "false"' => [['fields' => ['title' => 'A'], 'unaccent' => 'false']];
        yield 'log "false"' => [['fields' => ['title' => 'A'], 'log' => 'false']];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('stringBooleans')]
    public function testABooleanMustBeABoolean(array $config): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/must be true or false \(a boolean, not a string\), got /');

        (new ArrayDefinitionLoader())->load('articles', $config + ['source' => ['table' => 'article']]);
    }

    public function testRealBooleansStillWork(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', [
            'source' => ['table' => 'article'],
            'fields' => ['title' => ['weight' => 'A', 'fuzzy' => true, 'highlight' => false], 'body' => ['weight' => 'C', 'fuzzy' => false]],
            'unaccent' => false,
            'log' => false,
        ]);

        self::assertSame([true, false, false], [$definition->field('title')?->fuzzy, $definition->field('title')?->highlight, $definition->field('body')?->fuzzy]);
        self::assertFalse($definition->text->unaccent);
        self::assertFalse($definition->log);
    }

    public function testEveryProblemIsListedAtOnce(): void
    {
        try {
            (new ArrayDefinitionLoader())->load('articles', ['source' => ['tabel' => 'a'], 'fields' => ['title' => ['wieght' => 'A', 'fuzzy' => 'false']], 'logg' => true]);
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertCount(4, $e->violations);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function overrideKeysThatCannotApply(): iterable
    {
        yield 'fields' => ['fields', ['name' => 'A']];
        yield 'filters' => ['filters', ['price' => 'int']];
        yield 'source' => ['source', ['table' => 'other']];
        yield 'id_type' => ['id_type', 'uuid'];
        yield 'class' => ['class', Product::class];
    }

    #[DataProvider('overrideKeysThatCannotApply')]
    public function testAnOverrideRefusesWhatItCannotApplyToAnAttributeIndex(string $key, mixed $value): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);

        try {
            (new ArrayDefinitionLoader())->override($base, [$key => $value]);
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertSame($base->name, $e->index);
            self::assertCount(1, $e->violations);
            self::assertStringContainsString(sprintf('"%s" cannot be set by a YAML override: index "%s" is defined by attributes', $key, $base->name), $e->violations[0]);
        }
    }

    public function testAnOverrideStillTakesEverythingItCanApply(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);

        $merged = (new ArrayDefinitionLoader())->override($base, [
            'sync' => 'queue', 'trigger_level' => 'row', 'language' => 'german', 'unaccent' => false, 'boost' => 'price', 'recency' => 'price',
            'log' => false, 'profiles' => ['x' => ['boost' => 0.1]], 'thresholds' => ['min_score' => 0.2], 'synonyms' => [['tv', 'television']],
            'watch' => ['brand' => 'SELECT :id'],
        ]);

        self::assertFalse($merged->log);
        self::assertFalse($merged->text->unaccent);
    }

    public function testAnOverrideTypoGetsADidYouMean(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/Did you mean "thresholds" for "treshold"\?/');

        (new ArrayDefinitionLoader())->override($base, ['treshold' => []]);
    }

    public function testAThresholdsTypoNamesTheRealIndex(): void
    {
        try {
            (new ArrayDefinitionLoader())->load('articles', ['source' => ['table' => 'article'], 'fields' => ['title' => 'A'], 'thresholds' => ['min_scor' => 1]]);
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertSame('articles', $e->index);
            self::assertStringStartsWith('thresholds: Unknown threshold option(s): min_scor.', $e->violations[0]);
            self::assertStringContainsString('Search index "articles" is misconfigured', $e->getMessage());
        }
    }

    public function testARankingProfileTypoNamesTheRealIndexAndTheProfile(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);

        foreach ([
            static fn() => (new ArrayDefinitionLoader())->load('articles', ['source' => ['table' => 'article'], 'fields' => ['title' => 'A'], 'profiles' => ['popular' => ['bost' => 1]]]),
            static fn() => (new ArrayDefinitionLoader())->override($base, ['profiles' => ['popular' => ['bost' => 1]]]),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('InvalidDefinition expected');
            } catch (InvalidDefinition $e) {
                self::assertNotSame('ranking', $e->index);
                self::assertStringStartsWith('profile "popular": Unknown ranking option(s): bost.', $e->violations[0]);
            }
        }
    }

    public function testAnEmptySectionIsNotAMistake(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', ['source' => ['table' => 'article'], 'fields' => ['title' => 'A'], 'filters' => [], 'watch' => [], 'profiles' => [], 'thresholds' => []]);

        self::assertSame([], $definition->filters);
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\TestCase;

final class ArrayDefinitionLoaderDetailsTest extends TestCase
{
    private static function violations(\Closure $run): string
    {
        try {
            $run();
        } catch (InvalidDefinition $e) {
            return implode("\n", $e->violations);
        }
        self::fail('Expected InvalidDefinition.');
    }

    public function testAQuerySourceKeepsItsIdColumn(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', [
            'source' => ['query' => 'SELECT a.pid, a.title FROM article a', 'id' => 'pid'],
            'fields' => ['title' => 'A'],
            'watch' => ['article' => 'SELECT :id'],
        ]);

        self::assertSame('pid', $definition->source->idColumn);
        self::assertSame('SELECT a.pid, a.title FROM article a', $definition->source->query);
    }

    public function testEveryProfileIsLoaded(): void
    {
        $config = ['profiles' => ['fresh' => ['recency' => 0.5], 'loud' => ['exact_bonus' => 2.0]], 'recency' => 'created_at'];
        $definition = (new ArrayDefinitionLoader())->load('articles', $config + ['source' => ['table' => 'article'], 'fields' => ['title' => 'A']]);

        $names = array_keys($definition->profiles);
        sort($names);
        self::assertSame(['default', 'fresh', 'loud'], $names);
        self::assertSame(2.0, $definition->profile('loud')->exactBonus);

        $merged = (new ArrayDefinitionLoader())->override($definition, ['profiles' => ['one' => ['text' => 2.0], 'two' => ['fuzzy' => 0.1]]]);
        $names = array_keys($merged->profiles);
        sort($names);
        self::assertSame(['default', 'fresh', 'loud', 'one', 'two'], $names);
    }

    public function testScalarOptionsAreReadAsStrings(): void
    {
        $violations = self::violations(static fn() => (new ArrayDefinitionLoader())->load('articles', [
            'source' => ['table' => 'article'],
            'fields' => ['title' => 'A'],
            'boost' => 5,
        ]));

        self::assertStringContainsString('The boost column "5" is not a valid column name.', $violations);
    }

    public function testOverridingOnlyTheLanguageKeepsUnaccentAndTheOtherWayRound(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class)->withText(new TextConfig('english', false));
        $loader = new ArrayDefinitionLoader();

        $language = $loader->override($base, ['language' => 'german']);
        self::assertSame('german', $language->text->language);
        self::assertFalse($language->text->unaccent, 'only the language was overridden');

        $unaccent = $loader->override($base, ['unaccent' => true]);
        self::assertSame('english', $unaccent->text->language);
        self::assertTrue($unaccent->text->unaccent);
    }

    public function testOverrideWatchesAcceptTheShortAndTheLongForm(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);

        $merged = (new ArrayDefinitionLoader())->override($base, ['watch' => [
            'brand' => 'SELECT id FROM product WHERE brand_id = :id',
            'tag' => ['ids' => 'SELECT id FROM product WHERE tag_id = :id', 'key' => 'tag_id', 'columns' => ['name']],
        ]]);

        $watches = array_slice($merged->watches, count($base->watches));
        self::assertEquals([
            new Watch('brand', 'SELECT id FROM product WHERE brand_id = :id'),
            new Watch('tag', 'SELECT id FROM product WHERE tag_id = :id', 'tag_id', ['name']),
        ], $watches);
    }

    public function testAnOverrideIsValidated(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class);

        $violations = self::violations(static fn() => (new ArrayDefinitionLoader())->override($base, ['boost' => 'not a column']));

        self::assertStringContainsString('The boost column "not a column" is not a valid column name.', $violations);
    }

    public function testWatchColumnsAreAListWhateverTheirKeys(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => ['first' => 'name', 'second' => 'price']]],
        ]);

        self::assertSame(['name', 'price'], $definition->watches[0]->columns);
    }

    public function testBadWatchColumnsAreExplained(): void
    {
        $config = static fn(mixed $columns): array => [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => $columns]],
        ];

        self::assertSame(
            'Watch "columns" must be a list of strings (e.g. columns: [name]), got string.',
            self::violations(static fn() => (new ArrayDefinitionLoader())->load('products', $config('name'))),
        );
        self::assertSame(
            'Watch "columns" must be a list of strings (e.g. columns: [name]), got an array containing a non-string entry.',
            self::violations(static fn() => (new ArrayDefinitionLoader())->load('products', $config(['name', 42]))),
        );
        self::assertSame(
            'Watch "columns" must be a list of strings (e.g. columns: [name]), got int.',
            self::violations(static fn() => (new ArrayDefinitionLoader())->load('products', $config(42))),
        );
    }

    public function testAMalformedSynonymsMapIsExplained(): void
    {
        $config = ['source' => ['table' => 'article'], 'fields' => ['title' => 'A']];

        self::assertSame(
            '"synonyms" as a map takes "file" (a path) and "entries" (a list of groups and rules).',
            self::violations(static fn() => (new ArrayDefinitionLoader())->load('articles', $config + ['synonyms' => ['file' => 5]])),
        );
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use PHPUnit\Framework\TestCase;

/** `returnable`: the source columns SearchBuilder::with() may return, in the definition. */
final class ReturnableDefinitionTest extends TestCase
{
    private static function single(): IndexDefinition
    {
        return IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->returnable('title', 'slug')->returnable('slug', 'price')->build();
    }

    private static function multi(): IndexDefinition
    {
        return IndexDefinition::builder('site')
            ->sourceFromTable('item', 'item', returnable: ['slug', 'price'])
            ->source('article', 'SELECT id, headline AS title, url FROM article', idType: 'uuid', returnable: ['url', 'url'], watch: [new Watch('article')])
            ->sourceFromTable('tag', 'tag')
            ->field('title', 'A')
            ->build();
    }

    public function testTheBuilderTheYamlAndTheWithersGiveTheSameAllowList(): void
    {
        $yaml = (new ArrayDefinitionLoader())->load('items', ['source' => ['table' => 'item'], 'fields' => ['title' => 'A'], 'returnable' => ['title', 'slug', 'price']]);

        self::assertSame(['title', 'slug', 'price'], self::single()->returnable);
        self::assertEquals(self::single(), $yaml);
        self::assertSame(['slug'], self::single()->withReturnable(['slug'])->returnable);
        self::assertSame(['slug'], self::single()->withName('other')->withReturnable(['slug'])->withName('x')->returnable);
        self::assertTrue(self::single()->hasReturnable());
        self::assertFalse(IndexDefinition::builder('plain')->fromTable('t')->field('a', 'A')->build()->hasReturnable());
    }

    public function testASourceDeclaresItsOwnColumns(): void
    {
        $index = self::multi();
        $yaml = (new ArrayDefinitionLoader())->load('site', ['sources' => ['item' => ['table' => 'item', 'returnable' => ['slug', 'price']], 'article' => ['query' => 'SELECT id, headline AS title, url FROM article', 'id_type' => 'uuid', 'returnable' => ['url'], 'watch' => ['article' => 'SELECT :id']], 'tag' => ['table' => 'tag']], 'fields' => ['title' => 'A']]);

        self::assertEquals($index, $yaml);
        self::assertSame(['slug', 'price', 'url'], $index->returnableNames());
        self::assertSame(['slug', 'price'], $index->returnableOf('item'));
        self::assertSame(['url'], $index->returnableOf('article'));
        self::assertSame([], $index->returnableOf('tag'));
        self::assertSame([], $index->returnableOf('nope'));
        self::assertSame(['url'], $index->forSource($index->sourceNamed('article') ?? throw new \LogicException())->returnable);
        self::assertSame(['slug', 'price'], self::single()->returnableOf('items') === [] ? [] : array_slice(self::single()->returnableOf('items'), 1));
    }

    public function testTheShapeIsValidated(): void
    {
        foreach ([
            [['source' => ['table' => 'item'], 'fields' => ['title' => 'A'], 'returnable' => 'title'], '"returnable" must be a list of source column names'],
            [['source' => ['table' => 'item'], 'fields' => ['title' => 'A'], 'returnable' => ['title' => 1]], '"returnable" must be a list of source column names'],
            [['source' => ['table' => 'item'], 'fields' => ['title' => 'A'], 'returnable' => ['bad name']], 'Returnable column "bad name" is not a valid column name'],
            [['sources' => ['item' => ['table' => 'item', 'returnable' => 'x']], 'fields' => ['title' => 'A']], 'Source "item": "returnable" must be a list'],
            [['sources' => ['item' => ['table' => 'item']], 'returnable' => ['x'], 'fields' => ['title' => 'A']], 'inside each source'],
        ] as [$config, $message]) {
            try {
                (new ArrayDefinitionLoader())->load('x', $config);
                self::fail('InvalidDefinition expected for ' . $message);
            } catch (InvalidDefinition $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Source "item": Returnable column "bad name"');
        IndexDefinition::builder('site')->sourceFromTable('item', 'item', returnable: ['bad name'])->field('title', 'A')->build();
    }

    public function testAtMostFiftyColumns(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('At most 50 returnable columns');
        IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->returnable(...array_map(static fn(int $i): string => 'c' . $i, range(1, 51)))->build();
    }

    public function testADuplicateInAWitherIsReported(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Returnable column "a" is listed twice');
        \Fuzzphony\Core\Definition\DefinitionValidator::assertValid(self::single()->withReturnable(['a', 'a']));
    }

    public function testAYamlOverrideCanSetItOnASingleSourceIndexOnly(): void
    {
        $loader = new ArrayDefinitionLoader();
        $overridden = $loader->override(self::single(), ['returnable' => ['title']]);
        self::assertSame(['title'], $overridden->returnable);
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('"returnable" cannot be set by a YAML override on index "site"');
        $loader->override(self::multi(), ['returnable' => ['title']]);
    }

    public function testTheFingerprintFollowsItAndAnIndexWithoutItKeepsItsHash(): void
    {
        $plain = IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->build();

        self::assertNotSame(Fingerprint::definition($plain), Fingerprint::definition(self::single()));
        self::assertNotSame(Fingerprint::definition(self::single()), Fingerprint::definition(self::single()->withReturnable(['title'])));
        self::assertSame(Fingerprint::documents($plain), Fingerprint::documents(self::single()), 'the stored documents do not change');
        self::assertNotSame(Fingerprint::definition(self::multi()), Fingerprint::definition(IndexDefinition::builder('site')->sourceFromTable('item', 'item', returnable: ['slug'])->source('article', 'SELECT id, headline AS title, url FROM article', idType: 'uuid', returnable: ['url'], watch: [new Watch('article')])->sourceFromTable('tag', 'tag')->field('title', 'A')->build()));
        self::assertSame(
            Fingerprint::definition(IndexDefinition::builder('s')->sourceFromTable('a', 'a')->field('t', 'A')->build()),
            Fingerprint::definition(IndexDefinition::builder('s')->sourceFromTable('a', 'a', returnable: [])->field('t', 'A')->build()),
        );
    }

    public function testTheExportersRoundTripItAndTheAttributesSayTheyCannotExpressIt(): void
    {
        $index = self::single();
        self::assertSame(['title', 'slug', 'price'], (new ArrayExporter())->export($index)['returnable']);
        self::assertStringContainsString("returnable: [title, slug, price]", (new YamlExporter())->export($index));
        self::assertStringContainsString("->returnable('title', 'slug', 'price')", (new BuilderExporter())->export($index));
        self::assertEquals($index, (new ArrayDefinitionLoader())->load('items', (new ArrayExporter())->export($index)));
        self::assertFalse((new AttributeExporter())->supports($index));
        self::assertTrue((new AttributeExporter())->supports(IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->build()));
    }

    public function testAHitCarriesTheDataOnlyWhenThereIsSome(): void
    {
        $hit = new Hit('items', 1, 1.0, new ScoreBreakdown());
        $with = $hit->withData(['slug' => 'a', 'price' => null])->withMergedScore(0.5)->withMatches([]);

        self::assertSame([], $hit->data);
        self::assertArrayNotHasKey('data', $hit->toArray());
        self::assertSame(['slug' => 'a', 'price' => null], $with->data);
        self::assertSame(['slug' => 'a', 'price' => null], ($with->toArray()['data'] ?? null));
        self::assertSame(['slug' => 'a', 'price' => null], ($with->jsonSerialize()['data'] ?? null));
    }
}

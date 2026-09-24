<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\TestCase;

final class ExportersTest extends TestCase
{
    public function testArrayExportRoundTripsThroughTheLoader(): void
    {
        $original = Indexes::products()->with(thresholds: (new Thresholds())->with(['min_score' => 0.05, 'fuzzy_mode' => 'always']));

        $reloaded = (new ArrayDefinitionLoader())->load('products', (new ArrayExporter())->export($original));

        self::assertEquals($original, $reloaded);
    }

    public function testAttributeDefinitionsRoundTripToo(): void
    {
        $original = (new AttributeDefinitionLoader())->load(Product::class)->with(entityClass: null);

        self::assertEquals($original, (new ArrayDefinitionLoader())->load('products', (new ArrayExporter())->export($original)));
    }

    public function testYamlIsReadableAndQuotesWhatNeedsQuoting(): void
    {
        $yaml = (new YamlExporter())->export(Indexes::products());

        self::assertStringStartsWith("fuzzphony:\n  indexes:\n    products:\n      source:\n        query: 'SELECT p.id", $yaml);
        self::assertStringContainsString("        fz_brand: 'SELECT id FROM fz_product WHERE brand_id = :id'\n", $yaml);
        self::assertStringContainsString("        name:\n          weight: A\n          fuzzy: true\n", $yaml);
        self::assertStringContainsString("        description: D\n", $yaml);
        self::assertStringContainsString("        popular:\n          boost: 0.1\n          recency: 0.3\n", $yaml);
    }

    public function testBuilderCodeIsValidPhp(): void
    {
        $code = (new BuilderExporter())->export(Indexes::products());

        $tokens = token_get_all("<?php\n" . $code, TOKEN_PARSE); // throws \ParseError on invalid code
        self::assertNotEmpty($tokens);
        self::assertStringContainsString("->field('name', 'A', fuzzy: true)", $code);
        self::assertStringContainsString("->profile('popular', new RankingProfile(boost: 0.1, recency: 0.3))", $code);
    }

    public function testAttributeExportIsValidPhpAndLoadsBackTheSameIndex(): void
    {
        $original = IndexDefinition::builder('gadgets')->fromTable('gadget')
            ->field('title', 'A', fuzzy: true)->field('body', 'D', column: 'Body')
            ->filter('price', 'int')->filter('in_stock', 'bool')
            ->recencyBy('created_at')->language('german')->build();
        $exporter = new AttributeExporter();
        self::assertTrue($exporter->supports($original));

        $code = $exporter->export($original, 'GadgetExport' . bin2hex(random_bytes(4)));
        $tokens = token_get_all($code, TOKEN_PARSE);
        self::assertNotEmpty($tokens);
        eval(substr($code, 5));
        if (preg_match('/final class (\w+)/', $code, $m) !== 1) {
            self::fail('Could not find the exported class name.');
        }

        /** @var class-string $class */
        $class = '\\' . $m[1];
        $loaded = (new AttributeDefinitionLoader())->load($class);
        self::assertSame(
            array_map(static fn($f): array => [$f->name, $f->weight, $f->fuzzy, $f->highlight, $f->column()], $original->fields),
            array_map(static fn($f): array => [$f->name, $f->weight, $f->fuzzy, $f->highlight, $f->column()], $loaded->fields),
        );
        self::assertSame(
            array_map(static fn($f): array => [$f->name, $f->type, $f->column()], $original->filters),
            array_map(static fn($f): array => [$f->name, $f->type, $f->column()], $loaded->filters),
        );
        self::assertSame('gadget', $loaded->source->table);
        self::assertSame('fuzzphony_german', $loaded->text->configName());
    }

    public function testMultilineQueriesUseBlockScalars(): void
    {
        $index = IndexDefinition::builder('notes')->fromQuery("SELECT n.id, n.title\nFROM note n")->watch('note')->field('title', 'A')->build();

        self::assertStringContainsString("        query: |\n          SELECT n.id, n.title\n          FROM note n\n", (new YamlExporter())->export($index));
    }

    public function testJoinedSourcesCannotBeAttributes(): void
    {
        self::assertFalse((new AttributeExporter())->supports(Indexes::products()));
    }
}

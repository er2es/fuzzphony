<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use PHPUnit\Framework\TestCase;

/** The exact text the exporters produce: this is what people paste into their projects. */
final class ExportersOutputTest extends TestCase
{
    /** The expected text is a heredoc: normalise it when the checkout converted line endings. */
    private static function lf(string $text): string
    {
        return str_replace("
", "
", $text);
    }

    private static function rich(): IndexDefinition
    {
        return IndexDefinition::builder('my_products')
            ->fromQuery("SELECT p.id, p.name\nFROM product p", 'pid')
            ->watch('product', 'SELECT :id', 'pid')
            ->field('name', 'A', fuzzy: true)
            ->filter('brand_id', 'int')
            ->filter('colour', 'string', 'Colour')
            ->language('german', unaccent: false)
            ->boostBy('popularity')
            ->recencyBy('created_at')
            ->tenant('brand_id')
            ->profile('plain', new RankingProfile())
            ->profile('popular', new RankingProfile(exactBonus: 0.7, boost: 0.1, recencyHalfLifeDays: 7.0))
            ->build();
    }

    public function testBuilderCodeIsExactlyTheFluentEquivalent(): void
    {
        $expected = <<<'PHP'
            use Fuzzphony\Core\Definition\IndexDefinition;
            use Fuzzphony\Core\Ranking\RankingProfile;

            $myProducts = IndexDefinition::builder('my_products')
                ->fromQuery(<<<'SQL'
                    SELECT p.id, p.name
                    FROM product p
                    SQL, 'pid')
                ->watch('product', 'SELECT :id', 'pid')
                ->field('name', 'A', fuzzy: true)
                ->filter('brand_id', 'int')
                ->filter('colour', 'string', 'Colour')
                ->language('german', false)
                ->boostBy('popularity')
                ->recencyBy('created_at')
                ->tenant('brand_id')
                ->profile('plain', new RankingProfile())
                ->profile('popular', new RankingProfile(exactBonus: 0.7, boost: 0.1, recencyHalfLifeDays: 7.0))
                ->build();

            PHP;

        self::assertSame(self::lf($expected), (new BuilderExporter())->export(self::rich()));
    }

    public function testBuilderCodeOmitsEverythingThatIsDefault(): void
    {
        $index = IndexDefinition::builder('products')->fromTable('product')->field('name', 'B')->build();

        $expected = <<<'PHP'
            use Fuzzphony\Core\Definition\IndexDefinition;
            use Fuzzphony\Core\Ranking\RankingProfile;

            $products = IndexDefinition::builder('products')
                ->fromTable('product')
                ->field('name', 'B')
                ->language('english')
                ->build();

            PHP;

        self::assertSame(self::lf($expected), (new BuilderExporter())->export($index));
    }

    public function testADefaultProfileThatWasChangedIsExported(): void
    {
        $index = IndexDefinition::builder('products')->fromTable('product')->field('name', 'B')
            ->profile('default', new RankingProfile(fuzzy: 0.25))->build();

        self::assertStringContainsString("    ->profile('default', new RankingProfile(fuzzy: 0.25))\n    ->build();", (new BuilderExporter())->export($index));
    }

    public function testAttributeCodeIsExactlyTheAttributeEquivalent(): void
    {
        $index = IndexDefinition::builder('gadgets')->fromTable('gadget')
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'D', column: 'Body')
            ->filter('price', 'int')
            ->build();

        $expected = <<<'PHP'
            <?php

            use Fuzzphony\Core\Attribute\Searchable;
            use Fuzzphony\Core\Attribute\SearchField;
            use Fuzzphony\Core\Attribute\SearchFilter;
            use Fuzzphony\Core\Definition\SyncMode;
            use Fuzzphony\Core\Definition\TriggerLevel;

            // Add these to your existing entity (keep your #[ORM\...] mapping).
            #[Searchable(name: 'gadgets', language: 'english', table: 'gadget')]
            final class Gadget
            {
                #[SearchField('A', fuzzy: true, column: 'title')]
                public ?string $title = null;

                #[SearchField('D', column: 'Body')]
                public ?string $body = null;

                #[SearchFilter(type: 'int', column: 'price')]
                public ?int $price = null;
            }

            PHP;

        self::assertSame(self::lf($expected), (new AttributeExporter())->export($index, 'Gadget'));
    }

    public function testAttributeSupportRequiresATableSourceAndNoWatches(): void
    {
        $exporter = new AttributeExporter();
        $table = IndexDefinition::builder('gadgets')->fromTable('gadget')->field('title', 'A');

        self::assertTrue($exporter->supports($table->build()));
        self::assertFalse($exporter->supports($table->watch('gadget_tag', 'SELECT gadget_id FROM x WHERE id = :id')->build()), 'a table source with a watch');
        self::assertFalse($exporter->supports(IndexDefinition::builder('gadgets')->fromQuery('SELECT id, title FROM gadget')->sync('manual')->field('title', 'A')->build()), 'a query source without a watch');
    }

    public function testYamlStaysParseableForTrickyValues(): void
    {
        $index = IndexDefinition::builder('products')->fromTable('NO')->field('name', 'A')->boostBy('popularity')
            ->thresholds((new Thresholds())->with(['candidate_limit' => 500, 'min_score' => 1.0, 'fuzzy_similarity' => 0.25]))
            ->synonyms([['it\'s', 'it is'], ['TRUE', 'yes please']])
            ->profile('popular', new RankingProfile(text: 2.0, boost: 0.0000004))
            ->build();

        $yaml = (new YamlExporter())->export($index);

        self::assertStringContainsString("        table: 'NO'\n", $yaml, 'a word YAML reads as a boolean is quoted whatever its case');
        self::assertStringContainsString("          0: 'it''s'\n", $yaml, 'quotes are doubled');
        self::assertStringContainsString("          0: 'TRUE'\n", $yaml);
        self::assertStringContainsString("        candidate_limit: 500\n", $yaml);
        self::assertStringContainsString("        min_score: 1\n", $yaml, 'a whole float has no trailing dot');
        self::assertStringContainsString("        fuzzy_similarity: 0.25\n", $yaml);
        self::assertStringContainsString("          text: 2\n", $yaml);
    }

    public function testAnUnchangedDefaultProfileInFrontOfOthersDoesNotHideThem(): void
    {
        $index = IndexDefinition::builder('products')->fromTable('product')->field('name', 'B')->build()
            ->withProfiles(['default' => new RankingProfile(), 'loud' => new RankingProfile(exactBonus: 2.0)]);

        $code = (new BuilderExporter())->export($index);

        self::assertStringContainsString("    ->profile('loud', new RankingProfile(exactBonus: 2.0))\n", $code);
        self::assertStringNotContainsString("->profile('default'", $code);
    }

    public function testEveryMultilineValueKeepsTheKeysBeforeIt(): void
    {
        $index = IndexDefinition::builder('notes')->fromQuery("SELECT n.id, n.title\nFROM note n")
            ->watch('note')
            ->watch('tag', "SELECT note_id\nFROM note_tag WHERE tag_id = :id")
            ->field('title', 'A')->build();

        $yaml = (new YamlExporter())->export($index);

        self::assertStringContainsString("        note: 'SELECT :id'\n        tag: |\n          SELECT note_id\n          FROM note_tag WHERE tag_id = :id\n", $yaml);
    }
}

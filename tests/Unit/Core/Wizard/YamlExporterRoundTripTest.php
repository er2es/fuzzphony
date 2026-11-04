<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** The YAML the exporter writes reads back as the same data, whatever the line endings and nesting of the values. */
final class YamlExporterRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function queries(): iterable
    {
        yield 'no final newline' => ["SELECT id,\n  title FROM item"];
        yield 'one final newline' => ["SELECT id,\n  title FROM item\n"];
        yield 'two final newlines' => ["SELECT id,\n  title FROM item\n\n"];
        yield 'four final newlines' => ["SELECT id,\n  title FROM item\n\n\n\n"];
        yield 'a blank line inside' => ["SELECT id\n\nFROM item"];
        yield 'a line of blanks' => ["SELECT id\n   \nFROM item"];
        yield 'unicode and slashes stay readable' => ["SELECT id, '\u{e9}/\u{151}' AS title\n \nFROM item"];
    }

    #[DataProvider('queries')]
    public function testAMultilineQueryReadsBackAsTheSameText(string $query): void
    {
        $index = IndexDefinition::builder('items')->fromQuery($query)->watch('item')->field('title', 'A')->build();

        $parsed = Yaml::parse((new YamlExporter())->export($index));

        self::assertSame($index->source->query, $parsed['fuzzphony']['indexes']['items']['source']['query']);
    }

    public function testATextThatStartsWithABlankIsWrittenQuotedWithoutEscapingTextOrSlashes(): void
    {
        $node = new \ReflectionMethod(YamlExporter::class, 'node');

        $yaml = $node->invoke(new YamlExporter(), ['note' => " \u{e9}/\u{151}\nline"], 0);

        self::assertSame("note: \" \u{e9}/\u{151}\\nline\"\n", $yaml);
    }

    public function testTheBlockIndicatorFollowsTheNumberOfFinalNewlines(): void
    {
        $node = new \ReflectionMethod(YamlExporter::class, 'node');
        $write = static fn(string $text): string => $node->invoke(new YamlExporter(), ['k' => $text], 0);

        self::assertSame("k: |-\n  a\n  b\n", $write("a\nb"));
        self::assertSame("k: |\n  a\n  b\n", $write("a\nb\n"));
        self::assertSame("k: |+\n  a\n  b\n\n", $write("a\nb\n\n"));
        self::assertSame("k: |+\n  a\n  b\n\n\n\n", $write("a\nb\n\n\n\n"));
    }

    public function testListsOfMapsAndOfListsNestUnderTheirDash(): void
    {
        $data = ['rules' => [['name' => 'a', 'tags' => ['x', 'y']], ['name' => 'b']], 'grid' => [[['p' => 1]], ['q', 'r']]];
        $node = new \ReflectionMethod(YamlExporter::class, 'node');

        $yaml = $node->invoke(new YamlExporter(), $data, 0);

        self::assertSame($data, Yaml::parse($yaml));
        self::assertStringContainsString("rules:\n  -\n    name: a\n", $yaml);
    }
}

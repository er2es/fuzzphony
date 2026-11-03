<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Coverage;

use Fuzzphony\Core\Definition\QueryFile;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\MatchedWord;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Support\ErrorSummary;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The edges of small helpers: JSON shapes, malformed input that must pass through untouched, YAML scalars. */
final class ShapeEdgesTest extends TestCase
{
    public function testFacetValueAndMatchedWordEncodeAsTheirArrays(): void
    {
        self::assertSame('{"value":"red","count":3}', json_encode(new FacetValue('red', 3)));
        self::assertSame(
            '{"field":"name","word":"phon","via":"typo","similarity":0.8}',
            json_encode(new MatchedWord('name', 'phon', MatchedWord::TYPO, 0.8)),
        );
    }

    public function testAMissingBaseDirectoryIsReportedNotRead(): void
    {
        try {
            QueryFile::read('catalog', 'a.sql', sys_get_temp_dir() . '/fz_no_such_dir_' . bin2hex(random_bytes(4)));
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('base directory', implode("\n", $e->violations));
        }
    }

    public function testASqlStateIsReadFromGetSqlStateToo(): void
    {
        $dbal = new class ('driver') extends \RuntimeException {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        self::assertSame('40001', ErrorSummary::sqlState($dbal));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'a lone exclusion' => ['!'];
        yield 'an operator where an operand belongs' => ["(!'a' & &)"];
        yield 'two different glues in one group' => ["(!'a' & 'b' | 'c')"];
        yield 'text the compiler never writes' => ["!'a' junk"];
    }

    #[DataProvider('malformed')]
    public function testARankQueryInAnUnknownFormIsLeftAlone(string $tsquery): void
    {
        self::assertSame($tsquery, TsQueryCompiler::withoutExclusions($tsquery));
    }

    /** @param array<array-key, mixed> $data */
    private static function yaml(string $method, array $data, int $depth = 0): string
    {
        $m = new \ReflectionMethod(YamlExporter::class, $method);

        return Coerce::str($m->invoke(new YamlExporter(), $data, $depth));
    }

    public function testYamlWritesEmptyMapsAndNestedSequences(): void
    {
        self::assertSame("a: {}\n", self::yaml('node', ['a' => []]));
        self::assertSame(
            "- {}\n- [xx, yy]\n-\n  - [p]\n  - {}\n",
            self::yaml('sequence', [[], ['xx', 'yy'], [['p'], []]]),
        );
    }

    public function testYamlBlockScalarsKeepTheirTrailingNewlines(): void
    {
        self::assertSame("k: |-\n  a\n  b\n", self::yaml('node', ['k' => "a\nb"]));
        self::assertSame("k: |\n  a\n  b\n", self::yaml('node', ['k' => "a\nb\n"]));
        self::assertSame('k: " a\nb"' . "\n", self::yaml('node', ['k' => " a\nb"]), 'a text that starts with a blank is quoted');
    }
}

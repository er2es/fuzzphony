<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A code block of a recipe (docs/recipes/*.md) that sits under `<!-- tested: path/to/file -->` is a copy of that file,
 * and under `<!-- tested: path/to/file#name -->` a copy of the lines between `// region: name` and `// endregion` in it
 * (`#` and `{# #}` comments work as well, for YAML and Twig). This test fails when the two differ, so the text of a
 * recipe cannot drift away from the code that the other tests of tests/Recipes run. Indentation common to all lines
 * and trailing spaces do not count.
 */
final class RecipeDocsTest extends TestCase
{
    private const string MARKER = '/<!-- tested: (?<path>[^\s#>]+)(?:#(?<region>[\w-]+))? -->\r?\n```[\w-]*\r?\n(?<block>.*?)\r?\n```/s';

    /** @return iterable<string, array{string}> */
    public static function recipes(): iterable
    {
        $files = glob(self::root() . '/docs/recipes/*.md');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            yield basename($file, '.md') => [$file];
        }
    }

    #[DataProvider('recipes')]
    public function testEveryMarkedBlockIsTheCodeThatTheTestsRun(string $recipe): void
    {
        self::assertFileExists($recipe);
        $markdown = file_get_contents($recipe);
        self::assertIsString($markdown);
        $count = preg_match_all(self::MARKER, $markdown, $blocks, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(3, $count, basename($recipe) . ' should embed its tested code');

        foreach ($blocks as $block) {
            $source = self::root() . '/' . $block['path'];
            self::assertFileExists($source, sprintf('%s refers to %s', basename($recipe), $block['path']));
            $code = file_get_contents($source);
            self::assertIsString($code);
            $region = $block['region'] ?? '';
            $expected = $region === '' ? $code : self::region($code, $region, $block['path']);

            self::assertSame(
                self::normalise($expected),
                self::normalise($block['block']),
                sprintf('%s: the block under "tested: %s" differs from the file', basename($recipe), $block['path'] . ($region === '' ? '' : '#' . $region)),
            );
        }
    }

    public function testARegionIsTheLinesBetweenItsMarkers(): void
    {
        $code = "<?php\n// region: one\n    a();\n\n    b();\n// endregion\nc();\n# region: two\nx\n# endregion\n";

        self::assertSame("    a();\n\n    b();", self::region($code, 'one', 'sample'));
        self::assertSame('x', self::region($code, 'two', 'sample'));
    }

    public function testDriftIsDetected(): void
    {
        self::assertNotSame(self::normalise("a();\nb();"), self::normalise("a();\nc();"));
        self::assertSame(self::normalise("    a();\n\n    b();  \n"), self::normalise("a();\n\nb();"), 'common indentation and trailing spaces do not count');
    }

    public function testAMissingRegionFails(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        self::region("// region: one\nx\n// endregion\n", 'two', 'sample');
    }

    private static function region(string $code, string $name, string $file): string
    {
        $lines = [];
        $inside = false;
        $found = false;
        foreach (self::lines($code) as $line) {
            if (preg_match('/^\s*(?:\/\/|#|\{#)\s*region:\s*' . preg_quote($name, '/') . '\s*(?:#\})?\s*$/', $line) === 1) {
                $inside = true;
                $found = true;

                continue;
            }
            if ($inside && preg_match('/^\s*(?:\/\/|#|\{#)\s*endregion\s*(?:#\})?\s*$/', $line) === 1) {
                $inside = false;

                continue;
            }
            if ($inside) {
                $lines[] = $line;
            }
        }
        self::assertTrue($found, sprintf('%s has no "region: %s"', $file, $name));

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return explode("
", str_replace("
", "
", $text));
    }

    private static function normalise(string $code): string
    {
        $lines = array_map('rtrim', self::lines(trim($code, "\r\n")));
        $indent = PHP_INT_MAX;
        foreach ($lines as $line) {
            if ($line !== '') {
                $indent = min($indent, strlen($line) - strlen(ltrim($line)));
            }
        }

        return implode("\n", array_map(static fn(string $line): string => $line === '' ? '' : substr($line, $indent), $lines));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

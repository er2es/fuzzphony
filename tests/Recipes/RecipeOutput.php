<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes;

use PHPUnit\Framework\Assert;

/**
 * The "what you should see" blocks of a recipe are files (tests/Recipes/<Name>/expected/*.txt) that the recipe embeds
 * verbatim (RecipeDocsTest) and that this class compares with the real output of the command: every line of the file
 * must be a line of the output. What varies from run to run (times, durations) is replaced by a fixed value first.
 *
 * @internal
 */
final class RecipeOutput
{
    public static function assertShown(string $expectedFile, string $actual): void
    {
        $expected = file_get_contents($expectedFile);
        Assert::assertIsString($expected, $expectedFile);
        $lines = array_map('rtrim', explode("\n", self::normalise($actual)));
        foreach (explode("\n", trim($expected)) as $line) {
            Assert::assertContains(rtrim($line), $lines, sprintf("%s: this line is not in the output:\n%s", basename($expectedFile), self::normalise($actual)));
        }
    }

    public static function normalise(string $output): string
    {
        $output = str_replace("\r\n", "\n", $output);
        $output = (string) preg_replace('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d [+-]\d\d:\d\d/', '2026-10-12 09:14:51 +00:00', $output);

        return (string) preg_replace('/ in \d+\.\ds\b/', ' in 0.1s', $output);
    }
}

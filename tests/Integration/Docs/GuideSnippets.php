<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Docs;

use PHPUnit\Framework\Assert;

/**
 * @internal Keeps a guide and its tests in step. A fenced block that follows `<!-- tested: name -->` is
 * the snippet the test registered under `name`; a table that follows `<!-- tested-table: name -->` is
 * read cell by cell. Any `php` block without a marker is reported, so no snippet can go untested.
 */
final class GuideSnippets
{
    /**
     * @return array{blocks: array<string, string>, untestedPhp: list<int>, tables: array<string, list<list<string>>>}
     */
    public static function read(string $markdown): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $blocks = [];
        $untested = [];
        $tables = [];
        $count = count($lines);

        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            $marker = preg_match('/^<!-- tested(-table)?: ([a-z0-9-]+) -->$/', $line, $m) === 1 ? $m : null;

            if ($marker !== null) {
                $name = $marker[2];
                Assert::assertArrayNotHasKey($name, $blocks + $tables, sprintf('The marker "%s" is used twice.', $name));
                $j = $i + 1;
                while ($j < $count && trim($lines[$j]) === '') {
                    ++$j;
                }
                if ($marker[1] === '-table') {
                    $rows = [];
                    for (; $j < $count && str_starts_with($lines[$j], '|'); ++$j) {
                        $cells = array_map(trim(...), explode('|', trim($lines[$j], '| ')));
                        if (preg_match('/^:?-+:?$/', $cells[0]) !== 1) {
                            $rows[] = $cells;
                        }
                    }
                    Assert::assertNotSame([], $rows, sprintf('No table follows the marker "%s".', $name));
                    $tables[$name] = $rows;
                    $i = $j - 1;
                    continue;
                }
                Assert::assertTrue(isset($lines[$j]) && preg_match('/^```[a-z]+$/', $lines[$j]) === 1, sprintf('No fenced block follows the marker "%s".', $name));
                $code = [];
                for (++$j; $j < $count && $lines[$j] !== '```'; ++$j) {
                    $code[] = rtrim($lines[$j]);
                }
                $blocks[$name] = implode("\n", $code);
                $i = $j;
                continue;
            }

            if ($line === '```php') {
                $untested[] = $i + 1;
                while ($i + 1 < $count && $lines[$i + 1] !== '```') {
                    ++$i;
                }
            }
        }

        return ['blocks' => $blocks, 'untestedPhp' => $untested, 'tables' => $tables];
    }

    /**
     * Fails when the guide shows other code than the test ran, or a registered snippet is missing.
     *
     * @param array<string, string> $registered name => the exact code the test runs
     */
    public static function assertGuideShows(string $guide, array $registered): void
    {
        $read = self::read((string) file_get_contents($guide));

        Assert::assertSame([], $read['untestedPhp'], 'Every php block of the guide needs a "<!-- tested: name -->" marker (lines of the unmarked blocks).');
        Assert::assertEqualsCanonicalizing(array_keys($registered), array_keys($read['blocks']), 'The guide and the test register different snippets.');
        foreach ($registered as $name => $code) {
            Assert::assertSame(trim($code), trim($read['blocks'][$name] ?? ''), sprintf('The guide shows other code than the test ran for "%s".', $name));
        }
    }
}

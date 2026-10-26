<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SynonymExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The words of a synonym member: the fast path for plain words gives exactly what the query parser gives. */
final class SynonymMembersTest extends TestCase
{
    /** @return list<string> */
    private static function viaFastPath(string $member): array
    {
        return array_values(array_map(strval(...), (array) (new \ReflectionMethod(SynonymExpander::class, 'words'))->invoke(null, $member)));
    }

    /** @return list<string> */
    private static function viaParser(string $member): array
    {
        return array_map(mb_strtolower(...), NodeInspector::positiveWords((new QueryParser())->parse($member)->root));
    }

    /** @return iterable<string, array{string}> */
    public static function members(): iterable
    {
        foreach ([
            'tv', 'Television', 'solid state drive', 'Solid State Drive', 'a b', 'ÁRVÍZTŰRŐ tükörfúrógép', 'x1 y2 z3', '日本語 テスト',
            'AND', 'OR', 'NOT', 'and', 'or', 'not', 'rock AND roll', 'rock OR roll', 'NOT rock', 'rock NOT', 'a AND', 'ANDROID phone', 'phone ANDROID',
            'android and not or', 'wifi-6 router', "o'clock", 'c++ compiler', 'tv*', '"solid state"', 'a:b', 'brand:logitech mouse', '-cable', 'a -b', '(a b)', 'a|b',
            '  padded  ', "tab\tseparated", "line\nbreak", "trailing\n", 'double  space', '', ' ', '*', '+plus', '12 34', 'ä ö ü', 'ǅ ǆ',
            'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen',
            'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen',
            'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen AND sixteen seventeen',
            str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 257), str_repeat('é', 300),
            str_repeat('word ', 51) . 'tail', str_repeat('word ', 51) . 'AND', str_repeat('wor ', 63) . 'ANDROID', str_repeat('x', 253) . ' AND', str_repeat('x', 253) . ' ANDROID', str_repeat('x', 255) . ' b', str_repeat('x', 254) . ' b',
        ] as $i => $member) {
            yield $i . ': ' . substr(addcslashes($member, "\0..\37"), 0, 40) => [$member];
        }
    }

    #[DataProvider('members')]
    public function testTheFastPathGivesTheParsersWords(string $member): void
    {
        self::assertSame(self::viaParser($member), self::viaFastPath($member));
    }

    public function testRandomMembersGiveTheParsersWords(): void
    {
        mt_srand(20261026);
        $pieces = ['a', 'bc', 'Tv', 'AND', 'OR', 'NOT', 'ANDROID', 'and', 'é', 'Ő', '7', 'x-y', "it's", ' ', ' ', '  ', '*', '-', '"', '(', ')', ':', 'k:v', "\t", str_repeat('z', 40)];
        for ($n = 0; $n < 3000; ++$n) {
            $member = '';
            for ($i = mt_rand(1, 40); $i > 0; --$i) {
                $member .= $pieces[mt_rand(0, count($pieces) - 1)] . (mt_rand(0, 3) > 0 ? ' ' : '');
            }
            self::assertSame(self::viaParser($member), self::viaFastPath($member), (string) json_encode($member, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testViolationsAreWorkedOutOnceForAnObject(): void
    {
        $entries = [];
        for ($i = 0; $i < 20_000; ++$i) {
            $entries[] = ["w{$i}a", "multi word {$i}"];
        }
        $synonyms = Synonyms::fromEntries($entries);

        $start = hrtime(true);
        $first = $synonyms->violations();
        $firstTook = hrtime(true) - $start;
        $start = hrtime(true);
        $second = $synonyms->violations();
        $secondTook = hrtime(true) - $start;

        self::assertSame([], $first);
        self::assertSame($first, $second);
        self::assertLessThan($firstTook / 5, $secondTook, 'the second ask is a lookup, not a second check');
        $bad = Synonyms::fromEntries([['tv']]);
        self::assertNotSame([], $bad->violations(), 'another object is checked on its own');
        self::assertSame($bad->violations(), $bad->violations());
    }

    public function testAPreparedObjectCarriesTheTableTheExpanderUses(): void
    {
        $synonyms = new Synonyms([['tv', 'television']]);
        $stems = ['tv' => 'tv', 'television' => 'televis'];
        $table = SynonymExpander::tableOf($synonyms, $stems);
        self::assertSame(['tv' => [['television']], 'televis' => [['tv']]], $table);

        // a sentinel table proves the expander takes the carried one instead of building its own
        $prepared = $synonyms->withStems('english', $stems)->withTable(['tv' => [['carried']]]);
        $expanded = (new SynonymExpander($prepared, $stems))->expand(new Term('tv'));
        self::assertStringContainsString('carried', (string) $expanded);

        // another set of member stems (an engine that stems differently) never uses it
        $rebuilt = (new SynonymExpander($prepared, ['tv' => 'tv']))->expand(new Term('tv'));
        self::assertStringContainsString('television', (string) $rebuilt);
        self::assertStringNotContainsString('carried', (string) $rebuilt);

        // an unprepared object has no table, and withStems drops a table made for other stems
        self::assertSame([], $synonyms->table);
        self::assertSame([], $prepared->withStems('english', ['tv' => 'x'])->table);
        self::assertSame($table, $synonyms->withStems('english', $stems)->withTable($table)->table);
    }
}

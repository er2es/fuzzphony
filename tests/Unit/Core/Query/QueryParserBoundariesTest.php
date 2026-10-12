<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\QueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryParserBoundariesTest extends TestCase
{
    public function testTruncationCountsCharactersNotBytes(): void
    {
        $multibyte = (new QueryParser(maxLength: 5))->parse(str_repeat('é', 9));
        self::assertSame('ééééé', (string) $multibyte->root);

        $parsed = (new QueryParser(maxLength: 5))->parse('abcdefghij');

        self::assertSame('abcde', (string) $parsed->root);
        self::assertSame(['Search text was truncated to 5 characters.'], $parsed->warnings);
    }

    public function testATextOfExactlyTheMaximumLengthIsNotTruncated(): void
    {
        $parsed = (new QueryParser(maxLength: 5))->parse('abcde');

        self::assertSame('abcde', (string) $parsed->root);
        self::assertSame([], $parsed->warnings);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function danglingOperators(): iterable
    {
        yield 'a repeated OR still joins both sides' => ['a OR OR b', '(a OR b)'];
        yield 'OR binds looser than an explicit AND' => ['x OR a AND b', '(x OR (a AND b))'];
        yield 'an explicit AND inside a group' => ['(a AND b OR c)', '((a AND b) OR c)'];
        yield 'AND at the start is skipped' => ['AND a', 'a'];
        yield 'several ANDs in a row' => ['a AND AND b', '(a AND b)'];
    }

    #[DataProvider('danglingOperators')]
    public function testOperatorsAtOddPositionsKeepTheRestOfTheQuery(string $input, string $expected): void
    {
        self::assertSame($expected, (string) (new QueryParser())->parse($input)->root);
    }

    public function testParenthesesMayNestEightDeepAndNoDeeper(): void
    {
        $eight = (new QueryParser())->parse(str_repeat('(', 8) . 'a' . str_repeat(')', 8));
        self::assertSame('a', (string) $eight->root);
        self::assertSame([], $eight->warnings);

        $nine = (new QueryParser())->parse(str_repeat('(', 9) . 'a' . str_repeat(')', 9));
        self::assertSame('a', (string) $nine->root);
        self::assertSame(['Parentheses nested deeper than 8 were flattened.', 'Ignored an unmatched ")".'], $nine->warnings);
    }

    public function testSiblingGroupsDoNotAddUpToTheDepthLimit(): void
    {
        $parsed = (new QueryParser())->parse(implode(' ', array_map(static fn(int $i): string => '(w' . $i . ' OR x' . $i . ')', range(1, 6))));

        self::assertSame([], $parsed->warnings);
        self::assertStringContainsString('(w6 OR x6)', (string) $parsed->root);
    }

    public function testWordsWithoutALetterOrDigitAreDroppedFromAPhrase(): void
    {
        self::assertSame('"wireless mouse"', (string) (new QueryParser())->parse('"wireless - mouse"')->root);
        self::assertSame('mouse', (string) (new QueryParser())->parse('"- mouse -"')->root, 'one word left: a term');
    }

    public function testAPhraseOfSymbolsOnlyIsIgnoredAndCostsNoTerm(): void
    {
        $parsed = (new QueryParser(maxTerms: 1))->parse('"--- !!!" mouse');

        self::assertSame('mouse', (string) $parsed->root);
        self::assertSame([], $parsed->warnings);
        self::assertNull((new QueryParser())->parse('"---"')->root);
    }

    public function testPunctuationOutsideTheLatinAlphabetIsNotAWordInAPhrase(): void
    {
        self::assertSame('"wireless mouse"', (string) (new QueryParser())->parse("\"wireless \u{2013} mouse\"")->root);
    }
}

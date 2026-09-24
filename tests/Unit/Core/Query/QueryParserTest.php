<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\QueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryParserTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function syntax(): iterable
    {
        yield 'single word' => ['mouse', 'mouse'];
        yield 'implicit AND' => ['wireless mouse', '(wireless AND mouse)'];
        yield 'explicit AND is the same' => ['wireless AND mouse', '(wireless AND mouse)'];
        yield 'phrase' => ['"wireless mouse"', '"wireless mouse"'];
        yield 'one-word phrase is a term' => ['"mouse"', 'mouse'];
        yield 'OR keyword' => ['mouse OR trackpad', '(mouse OR trackpad)'];
        yield 'pipe' => ['mouse | trackpad', '(mouse OR trackpad)'];
        yield 'lowercase or is a word' => ['black or white', '(black AND or AND white)'];
        yield 'AND binds tighter than OR' => ['a b OR c', '((a AND b) OR c)'];
        yield 'minus excludes' => ['mouse -cable', '(mouse AND NOT cable)'];
        yield 'NOT keyword' => ['mouse NOT cable', '(mouse AND NOT cable)'];
        yield 'double negation cancels' => ['mouse NOT -cable', '(mouse AND cable)'];
        yield 'hyphen inside word is kept' => ['e-mail', 'e-mail'];
        yield 'prefix' => ['keyb*', 'keyb*'];
        yield 'field term' => ['brand:logitech', 'brand:logitech'];
        yield 'field phrase' => ['brand:"logitech g"', 'brand:"logitech g"'];
        yield 'groups' => ['(mouse OR trackpad) -cable', '((mouse OR trackpad) AND NOT cable)'];
        yield 'nested groups flatten' => ['((a))', 'a'];
        yield 'unicode' => ['egér fülhallgató', '(egér AND fülhallgató)'];
        yield 'plus prefix ignored' => ['+mouse', 'mouse'];
        yield 'empty' => ['', null];
        yield 'only punctuation' => ['!!! *** ???', null];
    }

    #[DataProvider('syntax')]
    public function testSyntax(string $input, ?string $expected): void
    {
        $parsed = (new QueryParser())->parse($input);

        self::assertSame($expected, $parsed->root === null ? null : (string) $parsed->root);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        yield 'unclosed parenthesis' => ['(mouse OR trackpad', 'Added a missing ")".'];
        yield 'stray closing parenthesis' => ['mouse) cable', 'Ignored an unmatched ")".'];
        yield 'dangling OR' => ['mouse OR', 'Ignored an "OR" without a right-hand side.'];
        yield 'dangling NOT' => ['mouse NOT', 'Ignored an exclusion ("-" / NOT) without a term.'];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputDegradesWithAWarning(string $input, string $warning): void
    {
        $parsed = (new QueryParser())->parse($input);

        self::assertNotNull($parsed->root);
        self::assertContains($warning, $parsed->warnings);
    }

    public function testUnterminatedQuoteRunsToTheEnd(): void
    {
        self::assertSame('(mouse AND "usb receiver")', (string) (new QueryParser())->parse('mouse "usb receiver')->root);
    }

    public function testLongInputIsTruncated(): void
    {
        $parsed = (new QueryParser(maxLength: 10))->parse(str_repeat('abc ', 20));

        self::assertSame('Search text was truncated to 10 characters.', $parsed->warnings[0]);
    }

    public function testTooManyTermsAreCut(): void
    {
        $parsed = (new QueryParser(maxTerms: 3))->parse('a b c d e');

        self::assertSame('(a AND b AND c)', (string) $parsed->root);
        self::assertSame(['Only the first 3 terms were used.'], $parsed->warnings);
    }

    public function testDeepNestingIsBounded(): void
    {
        $parsed = (new QueryParser())->parse(str_repeat('(', 50) . 'mouse' . str_repeat(')', 50));

        self::assertNotEmpty($parsed->warnings);
    }

    public function testFuzzedInputNeverThrows(): void
    {
        $alphabet = ['a', 'é', ' ', '"', '(', ')', '-', '|', '*', ':', 'OR', 'NOT', 'AND', "\0", '!', 'x:'];
        mt_srand(42);
        for ($i = 0; $i < 2_000; ++$i) {
            $input = '';
            for ($j = mt_rand(0, 25); $j > 0; --$j) {
                $input .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            (new QueryParser())->parse($input);
        }
        $this->addToAssertionCount(1);
    }

    public function testPositiveWordsIgnoreExclusions(): void
    {
        $root = (new QueryParser())->parse('"usb receiver" mouse -cable brand:logi*')->root;

        self::assertTrue(NodeInspector::hasPositive($root));
        self::assertSame(['usb', 'receiver', 'mouse', 'logi'], NodeInspector::positiveWords($root));
    }

    public function testOnlyExclusionsHaveNoPositive(): void
    {
        self::assertFalse(NodeInspector::hasPositive((new QueryParser())->parse('-cable -wire')->root));
        self::assertFalse(NodeInspector::hasPositive((new QueryParser())->parse('mouse OR -cable')->root));
    }
}

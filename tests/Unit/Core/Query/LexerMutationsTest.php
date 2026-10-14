<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Lexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Where a "-" or "!" still means NOT: only at the start of a word. */
final class LexerMutationsTest extends TestCase
{
    /** @return iterable<string, array{string, list<array{string, string}>}> */
    public static function inputs(): iterable
    {
        yield 'a hyphen right after a closing quote is part of the next word' => ['"foo"-bar', [[Lexer::PHRASE, 'foo'], [Lexer::WORD, '-bar']]];
        yield 'an empty pair of quotes is an empty phrase' => ['"" foo', [[Lexer::PHRASE, ''], [Lexer::WORD, 'foo']]];
        yield 'the character right after a closing quote starts the next word' => ['"a"b', [[Lexer::PHRASE, 'a'], [Lexer::WORD, 'b']]];
        yield 'a space after a closing quote still lets the next word be excluded' => ['"a" -b', [[Lexer::PHRASE, 'a'], [Lexer::NOT, '-'], [Lexer::WORD, 'b']]];
        yield 'an unterminated quote runs to the end' => ['x "ab', [[Lexer::WORD, 'x'], [Lexer::PHRASE, 'ab']]];
        yield 'a hyphen right after a bar is an exclusion' => ['a|-b', [[Lexer::WORD, 'a'], [Lexer::OR, '|'], [Lexer::NOT, '-'], [Lexer::WORD, 'b']]];
    }

    /** @param list<array{string, string}> $expected */
    #[DataProvider('inputs')]
    public function testTokens(string $input, array $expected): void
    {
        self::assertSame($expected, Lexer::tokenize($input));
    }
}

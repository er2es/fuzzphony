<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

/** @internal Splits user search text into tokens. Never fails. */
final class Lexer
{
    public const string WORD = 'word';
    public const string PHRASE = 'phrase';
    public const string FIELD = 'field';
    public const string LPAREN = '(';
    public const string RPAREN = ')';
    public const string OR = 'or';
    public const string AND = 'and';
    public const string NOT = 'not';

    /** @return list<array{0: string, 1: string}> [type, value] */
    public static function tokenize(string $input): array
    {
        $chars = mb_str_split($input);
        $count = count($chars);
        $tokens = [];
        $i = 0;
        $boundary = true; // at start or after whitespace / "(": a leading "-" means NOT

        while ($i < $count) {
            $c = $chars[$i];

            if (preg_match('/\s/u', $c) === 1) {
                $boundary = true;
                ++$i;
                continue;
            }
            if ($c === '(' || $c === ')') {
                $tokens[] = [$c === '(' ? self::LPAREN : self::RPAREN, $c];
                $boundary = $c === '(';
                ++$i;
                continue;
            }
            if ($c === '|') {
                $tokens[] = [self::OR, '|'];
                $boundary = true;
                ++$i;
                continue;
            }
            if ($c === '"') {
                $end = $i + 1;
                while ($end < $count && $chars[$end] !== '"') {
                    ++$end;
                }
                $tokens[] = [self::PHRASE, implode('', array_slice($chars, $i + 1, $end - $i - 1))];
                $i = $end + 1; // an unterminated quote simply runs to the end
                $boundary = false;
                continue;
            }
            if (($c === '-' || $c === '!') && $boundary && $i + 1 < $count && preg_match('/\s/u', $chars[$i + 1]) !== 1) {
                $tokens[] = [self::NOT, $c];
                ++$i;
                continue;
            }

            $start = $i;
            while ($i < $count && preg_match('/[\s()"|]/u', $chars[$i]) !== 1) {
                ++$i;
            }
            $word = ltrim(implode('', array_slice($chars, $start, $i - $start)), '+');
            $boundary = false;

            if (in_array($word, ['OR', 'AND', 'NOT'], true)) {
                $tokens[] = [strtolower($word), $word];
                $boundary = true;
                continue;
            }
            if (preg_match('/^([a-z_][a-z0-9_]*):(.*)$/u', $word, $m) === 1) {
                $tokens[] = [self::FIELD, $m[1]];
                if ($m[2] !== '') {
                    $tokens[] = [self::WORD, $m[2]];
                }
                continue;
            }
            if ($word !== '') {
                $tokens[] = [self::WORD, $word];
            }
        }

        return $tokens;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

/**
 * @internal Search-as-you-type: the word being typed is not finished, so its last word matches as the word
 * itself (with typo tolerance, synonyms and "did you mean") or as the beginning of a longer one:
 *
 *   "wireless hea"   ->  "wireless (hea | hea*)"
 *   "brand:son"      ->  "brand:son*"        a word scoped to a field, or excluded ("-ca"), is only a prefix
 *
 * The text is left alone when it does not end with a word (a space, a symbol: the visitor has finished it),
 * when it ends inside an open quote (a phrase), or when the last word is an operator (AND, OR, NOT).
 */
final class Typeahead
{
    /**
     * A "did you mean" text of a search-as-you-type query without the prefix alternative the engine saw:
     * `wireless (mouse | mouse*)` is `wireless mouse` to the visitor.
     */
    public static function withoutPrefixAlternative(string $suggestion): string
    {
        return preg_replace('/\(?([^\s()|*"]+) \| \1\*\)?/u', '$1', $suggestion) ?? $suggestion;
    }

    public static function lastWordAsPrefix(string $text): string
    {
        if (substr_count($text, '"') % 2 === 1 || preg_match('/^(.*?)([\p{L}\p{N}]+)$/su', $text, $m) !== 1) {
            return $text;
        }
        [, $head, $word] = $m;
        if (in_array($word, ['AND', 'OR', 'NOT'], true)) {
            return $text;
        }
        $before = mb_substr($head, -1);
        if ($before === '-' || $before === '!') {
            // an exclusion at the start of a word ("-ca"); inside a word it is a hyphen ("wi-fi"), left as typed
            return preg_match('/(^|\s)[-!]$/u', $head) === 1 ? $head . $word . '*' : $text;
        }

        return $before === ':'
            ? $head . $word . '*'
            : $head . '(' . $word . ' | ' . $word . '*)';
    }
}

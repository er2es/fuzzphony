<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** @internal Read-only helpers over the AST that every engine needs. */
final class NodeInspector
{
    /** True when the expression can match something on its own (not only exclusions). */
    public static function hasPositive(?Node $node): bool
    {
        return match (true) {
            $node === null => false,
            $node instanceof Term, $node instanceof Phrase, $node instanceof FieldScoped => true,
            $node instanceof Not => false,
            $node instanceof AllOf => array_any($node->nodes, static fn(Node $n): bool => self::hasPositive($n)),
            $node instanceof AnyOf => array_all($node->nodes, static fn(Node $n): bool => self::hasPositive($n)),
            default => false,
        };
    }

    /**
     * The words of a query a spelling suggestion may correct: the whole words it looks for (not the
     * negated ones, prefixes or the alternatives a synonym added), in order, with their case.
     *
     * @return list<string>
     */
    public static function suggestibleWords(?Node $node): array
    {
        return match (true) {
            $node instanceof Term => $node->prefix || $node->synonym ? [] : [$node->text],
            $node instanceof Phrase => $node->synonym ? [] : $node->words,
            $node instanceof FieldScoped => self::suggestibleWords($node->node),
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map(self::suggestibleWords(...), $node->nodes)),
            default => [],
        };
    }

    /**
     * Positive words, in order, of a query: they feed the exact-match and prefix bonuses (typo-tolerant
     * matching compiles the AST itself, see FuzzyQueryCompiler). The alternatives a synonym added are
     * not words of the query.
     *
     * @param (callable(string): bool)|null $fieldFilter only include field-scoped words when this returns true
     * @param bool                          $typed       the words as the visitor typed them: a search-as-you-type text ends with
     *                                                   `(hea | hea*)` (see Typeahead), which counts as the one word `hea`
     *
     * @return list<string>
     */
    public static function positiveWords(?Node $node, ?callable $fieldFilter = null, bool $typed = false): array
    {
        return match (true) {
            $node === null, $node instanceof Not => [],
            $node instanceof Term => $node->synonym ? [] : [$node->text],
            $node instanceof Phrase => $node->synonym ? [] : $node->words,
            $node instanceof FieldScoped => $fieldFilter === null || $fieldFilter($node->field) ? self::positiveWords($node->node) : [],
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map(
                static fn(Node $n): array => self::positiveWords($n, $fieldFilter, $typed),
                $typed && $node instanceof AnyOf && self::isTypeahead($node) ? [$node->nodes[0]] : $node->nodes,
            )),
            default => [],
        };
    }

    /** The pair search-as-you-type makes of the last word: the word (or its synonym expansion) and the same word as a prefix. */
    private static function isTypeahead(AnyOf $node): bool
    {
        $word = $node->nodes[0] ?? null;
        $prefix = $node->nodes[1] ?? null;
        if ($word instanceof AnyOf && $word->expansion) {
            $word = $word->nodes[0];
        }

        return !$node->expansion && count($node->nodes) === 2 && $prefix instanceof Term && $prefix->prefix
            && $word instanceof Term && !$word->prefix && $word->text === $prefix->text;
    }
}

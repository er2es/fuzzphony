<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** Read-only helpers over the AST that every engine needs. */
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
     * Positive words, in order, of a query: they feed the exact-match and prefix bonuses (typo-tolerant
     * matching compiles the AST itself, see FuzzyQueryCompiler).
     *
     * @param (callable(string): bool)|null $fieldFilter only include field-scoped words when this returns true
     *
     * @return list<string>
     */
    public static function positiveWords(?Node $node, ?callable $fieldFilter = null): array
    {
        return match (true) {
            $node === null, $node instanceof Not => [],
            $node instanceof Term => [$node->text],
            $node instanceof Phrase => $node->words,
            $node instanceof FieldScoped => $fieldFilter === null || $fieldFilter($node->field) ? self::positiveWords($node->node) : [],
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map(
                static fn(Node $n): array => self::positiveWords($n, $fieldFilter),
                $node->nodes,
            )),
            default => [],
        };
    }
}

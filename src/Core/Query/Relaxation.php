<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;

/**
 * Empty-result relaxation, the engine-agnostic part: when a multi-word query finds nothing, an
 * engine may drop the words that match nothing on their own (Thresholds::$relaxWhenEmpty) and
 * search once more. A removed word behaves like a dropped stop word: it disappears from its
 * AND / OR group; negations are left alone.
 */
final class Relaxation
{
    /**
     * The words a query looks for (not the negated ones), in order.
     *
     * @return list<Term|Phrase|FieldScoped>
     */
    public static function positiveLeaves(Node $node): array
    {
        return match (true) {
            $node instanceof Term, $node instanceof Phrase, $node instanceof FieldScoped => [$node],
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map(self::positiveLeaves(...), $node->nodes)),
            default => [],
        };
    }

    /**
     * The query without the given leaves (compared by instance); null when nothing is left.
     *
     * @param list<Node> $remove
     */
    public static function without(Node $node, array $remove): ?Node
    {
        if (in_array($node, $remove, true)) {
            return null;
        }
        if (!$node instanceof AllOf && !$node instanceof AnyOf) {
            return $node;
        }
        $kept = [];
        foreach ($node->nodes as $child) {
            $child = self::without($child, $remove);
            if ($child !== null) {
                $kept[] = $child;
            }
        }

        return match (count($kept)) {
            0 => null,
            1 => $kept[0],
            default => $node instanceof AllOf ? new AllOf($kept) : new AnyOf($kept),
        };
    }

    /**
     * The message for SearchResult::$warnings; safe to show to users.
     *
     * @param list<Term|Phrase|FieldScoped> $ignored
     */
    public static function warning(array $ignored): string
    {
        return sprintf(
            'No results for all words; ignored words that match nothing: %s.',
            implode(', ', array_map(static fn(Node $leaf): string => '"' . self::label($leaf) . '"', $ignored)),
        );
    }

    /** The words of a leaf as typed: alu*, usb receiver, name:foo. */
    private static function label(Term|Phrase|FieldScoped $leaf): string
    {
        return match (true) {
            $leaf instanceof Term => (string) $leaf,
            $leaf instanceof Phrase => implode(' ', $leaf->words),
            default => $leaf->field . ':' . self::label($leaf->node),
        };
    }
}

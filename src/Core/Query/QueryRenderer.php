<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;

/**
 * @internal Writes a parsed query back as search text QueryParser reads to the same query, with some
 * words replaced: the operators, quotes, prefixes, field scopes and exclusions stay where the user put
 * them ("did you mean"). Words are replaced as whole words, never by text search.
 */
final class QueryRenderer
{
    /** @param array<string, string> $replace lowercase word => its replacement */
    public static function render(Node $node, array $replace = []): string
    {
        return match (true) {
            $node instanceof Term => self::word($node->text, $replace) . ($node->prefix ? '*' : ''),
            $node instanceof Phrase => '"' . implode(' ', array_map(static fn(string $word): string => self::word($word, $replace), $node->words)) . '"',
            $node instanceof FieldScoped => $node->field . ':' . self::render($node->node, $replace),
            $node instanceof Not => '-' . self::group($node->node, $replace),
            $node instanceof AllOf => implode(' ', array_map(static fn(Node $child): string => self::group($child, $replace), $node->nodes)),
            $node instanceof AnyOf => implode(' | ', array_map(static fn(Node $child): string => self::group($child, $replace), $node->nodes)),
            default => '',
        };
    }

    /**
     * A child group is written in parentheses, so the operators bind as they did in the parsed query.
     *
     * @param array<string, string> $replace
     */
    private static function group(Node $child, array $replace): string
    {
        $text = self::render($child, $replace);

        return $child instanceof AllOf || $child instanceof AnyOf ? '(' . $text . ')' : $text;
    }

    /** @param array<string, string> $replace */
    private static function word(string $word, array $replace): string
    {
        return $replace[mb_strtolower($word)] ?? $word;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;

/**
 * Compiles the AST into to_tsquery() input. Every lexeme is reduced to letters and digits,
 * so the result is always syntactically valid and injection-free; it is still bound as a
 * parameter. PostgreSQL then applies the language configuration (stemming, accents).
 *
 *   wireless "usb receiver" -cable   ->   ('wireless' & ('usb' <-> 'receiver') & !'cable')
 *   name:mouse keyb*                 ->   ('mouse':A & 'keyb':*)
 */
final class TsQueryCompiler
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly IndexDefinition $index) {}

    public function compile(Node $node): ?string
    {
        $this->warnings = [];

        return $this->node($node, '');
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public static function lexemes(string $text): array
    {
        return array_map(
            mb_strtolower(...),
            preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    private function node(Node $node, string $weights): ?string
    {
        return match (true) {
            $node instanceof Term => $this->term($node, $weights),
            $node instanceof Phrase => $this->sequence(array_merge(...array_map(self::lexemes(...), $node->words)), $weights, false),
            $node instanceof FieldScoped => $this->scoped($node),
            $node instanceof AllOf => $this->group($node->nodes, ' & ', $weights),
            $node instanceof AnyOf => $this->group($node->nodes, ' | ', $weights),
            $node instanceof Not => ($inner = $this->node($node->node, $weights)) === null ? null : '!' . $inner,
            default => null,
        };
    }

    private function term(Term $term, string $weights): ?string
    {
        return $this->sequence(self::lexemes($term->text), $weights, $term->prefix);
    }

    private function scoped(FieldScoped $node): ?string
    {
        $field = $this->index->field($node->field);
        if ($field === null) {
            $this->warnings[] = sprintf('Unknown field "%s"; searched in all fields instead.', $node->field);

            return $this->node($node->node, '');
        }

        return $this->node($node->node, $field->weight->value);
    }

    /** @param list<string> $lexemes */
    private function sequence(array $lexemes, string $weights, bool $prefix): ?string
    {
        if ($lexemes === []) {
            return null;
        }
        $last = count($lexemes) - 1;
        $parts = [];
        foreach ($lexemes as $i => $lexeme) {
            $label = ($prefix && $i === $last ? '*' : '') . $weights;
            $parts[] = "'" . $lexeme . "'" . ($label !== '' ? ':' . $label : '');
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' <-> ', $parts) . ')';
    }

    /** @param list<Node> $nodes */
    private function group(array $nodes, string $glue, string $weights): ?string
    {
        $parts = array_values(array_filter(
            array_map(fn (Node $n): ?string => $this->node($n, $weights), $nodes),
            static fn (?string $p): bool => $p !== null,
        ));

        return match (count($parts)) {
            0 => null,
            1 => $parts[0],
            default => '(' . implode($glue, $parts) . ')',
        };
    }
}

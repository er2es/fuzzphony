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
 * @internal Compiles the AST into to_tsquery() input. Every lexeme is reduced to letters and digits,
 * so the result is always syntactically valid and injection-free; it is still bound as a
 * parameter. PostgreSQL then applies the language configuration (stemming, accents).
 *
 *   wireless "usb receiver" -cable   ->   ('wireless' & ('usb' <-> 'receiver') & !'cable')
 *   name:mouse keyb*                 ->   ('mouse':A & 'keyb':*)
 *
 * A word scoped to a field the index has keeps that field's weight label here; the search also
 * checks it against the field's own column (FuzzyQueryCompiler::scope()). An excluded one, or an
 * excluded group with one, is left out of this tsquery entirely: "!'sony':B" would also exclude
 * every other field of weight B. Leaving it out of an AND widens the AND; an OR with such a branch
 * sets no condition at all (dropping the branch would narrow the OR):
 *
 *   wireless (mouse | -brand:logitech)   ->   'wireless'
 */
final class TsQueryCompiler
{
    /** A part left to the field check: no condition here (see the class comment). */
    private const string UNCONSTRAINED = '';

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param bool $positiveOnly every excluded part is left out (as an excluded field-scoped one is): the candidates of a
     *                           restricted viewer's strict branch, where "!'cable'" on the whole tsv would also exclude
     *                           by the hidden fields (SearchSqlBuilder; the allowed-field recheck decides)
     * @param bool $lookupForm       the form for finding candidates on the whole `tsv` for a restricted viewer: every phrase (and every
     *                               word that splits into several, "usb-c") is an AND of its words, not a sequence, and the weight
     *                               labels are left out. A superset, and one a hidden field cannot change: `tsv` is the fields' vectors
     *                               joined, whose positions are capped at 16 383, so after a long hidden field a phrase there does not
     *                               match and a lexeme at a clamped position keeps only the highest weight of its fields. The allowed
     *                               fields' own columns decide (FuzzyQueryCompiler::matches(), SearchSqlBuilder)
     */
    public function __construct(
        private readonly IndexDefinition $index,
        private readonly bool $positiveOnly = false,
        private readonly bool $lookupForm = false,
    ) {}

    public function compile(Node $node): ?string
    {
        $this->warnings = [];
        $tsquery = $this->node($node, '');

        return $tsquery === self::UNCONSTRAINED ? null : $tsquery;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** True when the query has a word (included or excluded) scoped to a field the index has. */
    public function hasFieldScope(Node $node): bool
    {
        return match (true) {
            $node instanceof FieldScoped => $this->knownScope($node),
            $node instanceof AllOf, $node instanceof AnyOf => array_any($node->nodes, $this->hasFieldScope(...)),
            $node instanceof Not => $this->hasFieldScope($node->node),
            default => false,
        };
    }

    /** True when a position or a weight on `tsv` decides something: a word scoped to a known field, or a phrase. */
    public function dependsOnPositions(Node $node): bool
    {
        return $this->hasFieldScope($node) || $this->hasSequence($node);
    }

    /**
     * True when the query has a phrase, or a word that splits into several ("usb-c"), anywhere (excluded ones too): it is a
     * sequence on `tsv`, whose positions are capped (see $lookupForm).
     */
    public function hasSequence(Node $node): bool
    {
        return match (true) {
            $node instanceof Term => count(self::lexemes($node->text)) > 1,
            $node instanceof Phrase => count(array_merge(...array_map(self::lexemes(...), $node->words))) > 1,
            $node instanceof FieldScoped, $node instanceof Not => $this->hasSequence($node->node),
            $node instanceof AllOf, $node instanceof AnyOf => array_any($node->nodes, $this->hasSequence(...)),
            default => false,
        };
    }

    private function knownScope(Node $node): bool
    {
        return $node instanceof FieldScoped && $this->index->field($node->field) !== null;
    }

    /** @return list<string> */
    public static function lexemes(string $text): array
    {
        $split = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(mb_strtolower(...), $split !== false ? $split : []);
    }

    private function node(Node $node, string $weights): ?string
    {
        return match (true) {
            $node instanceof Term => $this->term($node, $weights),
            $node instanceof Phrase => $this->sequence(array_merge(...array_map(self::lexemes(...), $node->words)), $weights, false),
            $node instanceof FieldScoped => $this->scoped($node),
            $node instanceof AllOf => $this->group($node->nodes, ' & ', $weights),
            $node instanceof AnyOf => $this->group($node->nodes, ' | ', $weights),
            $node instanceof Not && ($this->positiveOnly || $this->hasFieldScope($node->node)) => self::UNCONSTRAINED,
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
            $label = ($prefix && $i === $last ? '*' : '') . ($this->lookupForm ? '' : $weights);
            $parts[] = "'" . $lexeme . "'" . ($label !== '' ? ':' . $label : '');
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode($this->lookupForm ? ' & ' : ' <-> ', $parts) . ')';
    }

    /** @param list<Node> $nodes */
    private function group(array $nodes, string $glue, string $weights): ?string
    {
        $compiled = array_map(fn(Node $n): ?string => $this->node($n, $weights), $nodes);
        $unconstrained = in_array(self::UNCONSTRAINED, $compiled, true);
        if ($unconstrained && $glue === ' | ') {
            return self::UNCONSTRAINED;
        }
        $parts = array_values(array_filter($compiled, static fn(?string $p): bool => $p !== null && $p !== self::UNCONSTRAINED));

        return match (count($parts)) {
            0 => $unconstrained ? self::UNCONSTRAINED : null,
            1 => $parts[0],
            default => '(' . implode($glue, $parts) . ')',
        };
    }
}

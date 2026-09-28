<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;

/**
 * @internal Compiles the AST into the predicate and score of the typo-tolerant (fuzzy) branch.
 * Every word must be satisfied on its own, exactly (full text) or fuzzily (trigram), combined
 * through the query's real AND / OR / NOT structure:
 *
 *   wireles mice   ->   (s.tsv @@ q.ft0 OR q.fn1 <% s.fz) AND (s.tsv @@ q.ft2 OR q.fn3 <% s.fz)
 *
 *   with q.ft0 = to_tsquery(cfg, :p2), q.fn1 = fuzzphony_norm(:p3), ...
 *
 * The values live in the materialized q CTE on purpose: when the planner sees them as literals
 * it estimates the (large) number of trigram matches correctly and then prefers a LIMIT
 * early-exit sequential scan that evaluates "<%" row by row, which is several times slower
 * than the BitmapAnd / BitmapOr over the GIN(tsv) and GIN(fz) indexes (measured at 1M rows).
 *
 * The exact side of a leaf is TsQueryCompiler's output for that leaf (lexemes, weight labels,
 * prefix). Negated parts stay exact-only. Words shorter than fuzzyMinLength stay exact-only.
 * A word scoped to a field the index has is checked against that field's own columns on the
 * candidates GIN(tsv) / GIN(fz) find: "s.t_<field> @@" on the exact side, "<% s.z_<field>" on the
 * fuzzy side (a field that is not fuzzy stays exact-only). An unknown field searches every field.
 * An excluded part with such a word is compiled word by word, so each scoped word excludes only
 * by its own field: -(brand:sony | cable) -> NOT ((s.tsv @@ q.ft0 AND s.t_brand @@ q.ft0) OR s.tsv @@ q.ft1).
 *
 * Score: a leaf scores max(word similarity, 1.0 when it matches exactly); AND = mean of the
 * scored children, OR = maximum over the branches that actually match, NOT does not score.
 * Exact hits score 1.0 / 0.0 (numeric, never integers) so that a mean is never an integer division.
 * An AND branch of an OR is guarded ("CASE WHEN <its predicate> THEN <its score> ELSE 0.0 END"):
 * its score is partial credit for the words that matched, which must not lift a row that matched
 * through another branch. Plain leaves need no guard: a leaf that does not match scores below
 * the similarity threshold, under any leaf that does.
 */
final class FuzzyQueryCompiler
{
    private readonly TsQueryCompiler $tsquery;
    /** @var list<string> */
    private array $columns = [];
    /** scope(): every leaf exact only. */
    private bool $exactOnly = false;
    /** Prefix of the q column names, so scope()'s columns never clash with compile()'s. */
    private string $prefix = '';

    public function __construct(
        private readonly IndexDefinition $index,
        private readonly Thresholds $thresholds,
        private readonly Names $names = new Names(),
    ) {
        $this->tsquery = new TsQueryCompiler($index);
    }

    /**
     * True when at least one positive word can match fuzzily. Otherwise a fuzzy statement
     * cannot find anything the strict one does not, and the engine skips it.
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     */
    public function hasFuzzyLeaf(Node $node, array $emptyQueries = []): bool
    {
        return match (true) {
            $node instanceof Not => false,
            $node instanceof AllOf, $node instanceof AnyOf => array_any($node->nodes, fn(Node $n): bool => $this->hasFuzzyLeaf($n, $emptyQueries)),
            default => $this->exact($node, $emptyQueries) !== null && $this->needle($node) !== null,
        };
    }

    /**
     * The tsquery of every unit compile() may drop as a stop word (each leaf and each negated
     * operand), without duplicates. The engine asks PostgreSQL which of them are empty.
     *
     * @return list<string>
     */
    public function leafQueries(Node $node): array
    {
        if ($node instanceof Not && $this->tsquery->hasFieldScope($node->node)) {
            return $this->leafQueries($node->node); // compiled word by word, see excluded()
        }
        if ($node instanceof AllOf || $node instanceof AnyOf) {
            return array_values(array_unique(array_merge(...array_map($this->leafQueries(...), $node->nodes))));
        }
        $tsquery = $this->tsquery->compile($node instanceof Not ? $node->node : $node);

        return $tsquery === null ? [] : [$tsquery];
    }

    /**
     * Registers every user-derived value in $params (the statement's own bag, so placeholder
     * names stay unique). Null when nothing positive is left to match.
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     */
    public function compile(Node $node, ParameterBag $params, array $emptyQueries = []): ?FuzzyMatch
    {
        $this->columns = [];
        $compiled = $this->node($node, $params, $emptyQueries);

        return $compiled === null || $compiled['score'] === null ? null : new FuzzyMatch($compiled['predicate'], $compiled['score'], $this->columns);
    }

    /**
     * The exact-only condition of a query with a field-scoped word, for the strict (full-text)
     * branch: the query's AND / OR / NOT over its words, each matched exactly, a known field's
     * word against that field's own tsvector, stop words dropped. SearchSqlBuilder puts it next to
     * "s.tsv @@ q.tsq", which still finds the candidates through GIN(tsv). Its q columns are named
     * sft<n>. Null when nothing is left to check (every word a stop word).
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return array{predicate: string, columns: list<string>}|null
     */
    public function scope(Node $node, ParameterBag $params, array $emptyQueries = []): ?array
    {
        $this->columns = [];
        $this->exactOnly = true;
        $this->prefix = 's';
        $compiled = $this->node($node, $params, $emptyQueries);
        $this->exactOnly = false;
        $this->prefix = '';

        return $compiled === null ? null : ['predicate' => $compiled['predicate'], 'columns' => $this->columns];
    }

    /**
     * The condition each leaf has in the search, one per leaf and in order, for the empty-result
     * relaxation probe: exact or trigram (as in the fuzzy branch) when $fuzzy is true and the
     * word is long enough, else exact only; null for a leaf reduced to nothing (a stop word).
     * The values are q columns, like compile()'s.
     *
     * @param list<Node>   $leaves
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return array{predicates: list<string|null>, columns: list<string>}
     */
    public function leafConditions(array $leaves, ParameterBag $params, array $emptyQueries, bool $fuzzy): array
    {
        $this->columns = [];
        $predicates = [];
        foreach ($leaves as $leaf) {
            $tsquery = $this->exact($leaf, $emptyQueries);
            $predicates[] = match (true) {
                $tsquery === null => null,
                $fuzzy => $this->leaf($leaf, $params, $emptyQueries)['predicate'] ?? null,
                default => $this->matches($leaf, $tsquery, $params),
            };
        }

        return ['predicates' => $predicates, 'columns' => $this->columns];
    }

    /**
     * @param list<string> $empty
     *
     * "partial" marks a score that can be positive while the predicate is false (an AND of which
     * some words matched).
     *
     * @return array{predicate: string, score: string|null, partial: bool}|null
     */
    private function node(Node $node, ParameterBag $params, array $empty): ?array
    {
        return match (true) {
            $node instanceof AllOf => $this->group($node->nodes, true, $params, $empty),
            $node instanceof AnyOf => $this->group($node->nodes, false, $params, $empty),
            $node instanceof Not && $this->tsquery->hasFieldScope($node->node) => $this->excluded($node->node, $params, $empty),
            $node instanceof Not => ($tsquery = $this->exact($node->node, $empty)) === null
                ? null
                : ['predicate' => sprintf('NOT (%s)', $this->matches($node->node, $tsquery, $params)), 'score' => null, 'partial' => false],
            default => $this->leaf($node, $params, $empty),
        };
    }

    /**
     * An excluded part with a word scoped to a known field: compiled word by word (exact only), so
     * each scoped word is checked against its own field's column, then negated.
     *
     * @param list<string> $empty
     *
     * @return array{predicate: string, score: null, partial: false}|null
     */
    private function excluded(Node $node, ParameterBag $params, array $empty): ?array
    {
        $exactOnly = $this->exactOnly;
        $this->exactOnly = true;
        $compiled = $this->node($node, $params, $empty);
        $this->exactOnly = $exactOnly;

        return $compiled === null ? null : ['predicate' => sprintf('NOT (%s)', $compiled['predicate']), 'score' => null, 'partial' => false];
    }

    /**
     * @param list<string> $empty
     *
     * @return array{predicate: string, score: string, partial: bool}|null
     */
    private function leaf(Node $node, ParameterBag $params, array $empty): ?array
    {
        $tsquery = $this->exact($node, $empty);
        if ($tsquery === null) {
            return null;
        }
        $exact = $this->matches($node, $tsquery, $params);
        $needle = $this->exactOnly ? null : $this->needle($node);
        if ($needle === null) {
            return ['predicate' => $exact, 'score' => sprintf('CASE WHEN %s THEN 1.0 ELSE 0.0 END', $exact), 'partial' => false];
        }
        $norm = $this->column('fn', sprintf('%s(%s)', $this->names->normFunction(), $params->add($needle)));
        $schema = $this->names->extension();
        $field = $this->scopedField($node);
        if ($field === null) {
            return [
                'predicate' => sprintf('(%s OR %s OPERATOR(%s.<%%) s.fz)', $exact, $norm, $schema),
                'score' => sprintf('GREATEST(%s.word_similarity(%s, s.fz), CASE WHEN %s THEN 1.0 ELSE 0.0 END)', $schema, $norm, $exact),
                'partial' => false,
            ];
        }
        $column = 's.' . $this->names->fieldFuzzy($field->name);

        return [
            'predicate' => sprintf('(%1$s OR (%2$s OPERATOR(%3$s.<%%) s.fz AND %2$s OPERATOR(%3$s.<%%) %4$s))', $exact, $norm, $schema, $column),
            'score' => sprintf('GREATEST(%s.word_similarity(%s, %s), CASE WHEN %s THEN 1.0 ELSE 0.0 END)', $schema, $norm, $column, $exact),
            'partial' => false,
        ];
    }

    /**
     * @param list<Node>   $nodes
     * @param list<string> $empty
     *
     * @return array{predicate: string, score: string|null, partial: bool}|null
     */
    private function group(array $nodes, bool $all, ParameterBag $params, array $empty): ?array
    {
        $children = [];
        foreach ($nodes as $child) {
            $compiled = $this->node($child, $params, $empty);
            if ($compiled !== null) {
                $children[] = $compiled;
            }
        }
        if (count($children) <= 1) {
            return $children[0] ?? null;
        }

        $predicates = array_column($children, 'predicate');
        // an OR branch counts only while its own predicate holds (see the class comment)
        $scores = [];
        foreach ($children as $child) {
            if ($child['score'] !== null) {
                $scores[] = !$all && $child['partial']
                    ? sprintf('CASE WHEN %s THEN %s ELSE 0.0 END', $child['predicate'], $child['score'])
                    : $child['score'];
            }
        }

        return [
            'predicate' => '(' . implode($all ? ' AND ' : ' OR ', $predicates) . ')',
            'score' => match (true) {
                $scores === [] => null,
                count($scores) === 1 => $scores[0],
                $all => sprintf('((%s) / %d)', implode(' + ', $scores), count($scores)),
                default => sprintf('GREATEST(%s)', implode(', ', $scores)),
            },
            'partial' => $all,
        ];
    }

    /** @param list<string> $empty */
    private function exact(Node $node, array $empty): ?string
    {
        $tsquery = $this->tsquery->compile($node);

        return $tsquery === null || in_array($tsquery, $empty, true) ? null : $tsquery;
    }

    /** The normalised words of a leaf when it may match fuzzily, else null. */
    private function needle(Node $node): ?string
    {
        $inner = $node instanceof FieldScoped ? $node->node : $node;
        if ($inner instanceof Phrase) {
            $text = implode(' ', $inner->words);
        } else {
            assert($inner instanceof Term, 'node() reaches a leaf only for Term, Phrase and a field-scoped one of them');
            $text = $inner->text;
        }
        $needle = implode(' ', TsQueryCompiler::lexemes($text));

        $field = $this->scopedField($node);

        return $this->index->hasFuzzy() && ($field === null || $field->fuzzy) && mb_strlen(str_replace(' ', '', $needle)) >= $this->thresholds->fuzzyMinLength ? $needle : null;
    }

    private function matches(Node $node, string $tsquery, ParameterBag $params): string
    {
        $query = $this->column('ft', sprintf('to_tsquery(%s, %s)', $this->names->regconfig($this->index->text), $params->add($tsquery)));
        $field = $this->scopedField($node);

        return $field === null
            ? 's.tsv @@ ' . $query
            : sprintf('(s.tsv @@ %1$s AND s.%2$s @@ %1$s)', $query, $this->names->fieldVector($field->name));
    }

    /** Adds a q column (ft<n> = tsquery, fn<n> = needle; scope(): sft<n>) and returns the reference to it. */
    private function column(string $prefix, string $expression): string
    {
        $name = $this->prefix . $prefix . count($this->columns);
        $this->columns[] = $expression . ' AS ' . $name;

        return 'q.' . $name;
    }

    /** The field a leaf is scoped to, when the index has it. */
    private function scopedField(Node $node): ?FieldDefinition
    {
        return $node instanceof FieldScoped ? $this->index->field($node->field) : null;
    }
}

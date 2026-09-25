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
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;

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
 * A field-scoped word matches fuzzily against the whole fz column (every fuzzy field): fz is
 * one string, so the field cannot be enforced on the fuzzy side (known limitation).
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

    public function __construct(
        private readonly IndexDefinition $index,
        private readonly Thresholds $thresholds,
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
                default => $this->matches($tsquery, $params),
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
            $node instanceof Not => ($tsquery = $this->exact($node->node, $empty)) === null
                ? null
                : ['predicate' => sprintf('NOT (%s)', $this->matches($tsquery, $params)), 'score' => null, 'partial' => false],
            default => $this->leaf($node, $params, $empty),
        };
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
        $exact = $this->matches($tsquery, $params);
        $needle = $this->needle($node);
        if ($needle === null) {
            return ['predicate' => $exact, 'score' => sprintf('CASE WHEN %s THEN 1.0 ELSE 0.0 END', $exact), 'partial' => false];
        }
        $norm = $this->column('fn', sprintf('%s(%s)', PostgresSchemaGenerator::NORM_FUNCTION, $params->add($needle)));

        return [
            'predicate' => sprintf('(%s OR %s <%% s.fz)', $exact, $norm),
            'score' => sprintf('GREATEST(word_similarity(%s, s.fz), CASE WHEN %s THEN 1.0 ELSE 0.0 END)', $norm, $exact),
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
        $text = match (true) {
            $inner instanceof Term => $inner->text,
            $inner instanceof Phrase => implode(' ', $inner->words),
            default => '',
        };
        $needle = implode(' ', TsQueryCompiler::lexemes($text));

        return $this->index->hasFuzzy() && mb_strlen(str_replace(' ', '', $needle)) >= $this->thresholds->fuzzyMinLength ? $needle : null;
    }

    private function matches(string $tsquery, ParameterBag $params): string
    {
        return 's.tsv @@ ' . $this->column('ft', sprintf('to_tsquery(%s::regconfig, %s)', Sql::string($this->index->text->configName()), $params->add($tsquery)));
    }

    /** Adds a q column (ft<n> = tsquery, fn<n> = needle) and returns the reference to it. */
    private function column(string $prefix, string $expression): string
    {
        $name = $prefix . count($this->columns);
        $this->columns[] = $expression . ' AS ' . $name;

        return 'q.' . $name;
    }
}

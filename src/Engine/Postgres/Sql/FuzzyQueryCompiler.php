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
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Ranking\TypoCurve;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\TypoTerm;

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
 *
 * Candidates: the session's pg_trgm threshold is the lowest any word of the statement needs, and for a word that needs
 * more the trigram index returns far more rows than match it (every word that starts alike); PostgreSQL reads each of
 * them, a second or more per 100 000 rows. So the engine first asks for each word's matches alone, at that word's own
 * similarity (probes()), and hands the ids back ($candidates): the word's trigram condition becomes "s.id = ANY(ids)",
 * the same rows without the detour. A word with more matches than the candidate limit keeps the trigram condition.
 *
 * A restricted viewer ($allowedFields, the fields it may see): GIN(tsv) / GIN(fz) still find the candidates, and every
 * word is rechecked against the allowed fields' own columns, as a scoped word is against its field's:
 *
 *   mouse   ->   ((s.tsv @@ q.ft0 AND (s.t_name @@ q.ft0 OR s.t_brand @@ q.ft0))
 *                 OR (q.fn1 <% s.fz AND GREATEST(word_similarity(q.fn1, s.z_name), word_similarity(q.fn1, s.z_brand)) >= <its similarity>))
 *
 * and scores by those columns only. A word scoped to a hidden field, or any word when no field is allowed, is "false";
 * a word is typo-tolerant only when an allowed field is (its own field, for a scoped word); an excluded part is
 * always compiled word by word (excluded()). Null keeps every statement of the unrestricted path as it was, except for what
 * tsv cannot hold reliably: it is every field's vector joined, and its positions are capped at 16 383, so after a field of more
 * than about 16 000 words a lexeme of a later field keeps only the highest weight of its fields and a phrase there does not match.
 * An unrestricted viewer's phrase, or word scoped to a field, on an index with several fields is therefore looked up by its words
 * alone (the AND of them, no weight labels: the same GIN lookup) and decided by the fields' own columns (matches()); a word
 * without a weight and a single field's index keep their statements.
 */
final class FuzzyQueryCompiler
{
    /** Most stop words of a query of stop words only that are searched (see keepingStopWords()); a longer query finds nothing, as it always did. */
    public const int MAX_STOP_WORDS = 3;

    private readonly TsQueryCompiler $tsquery;
    /** @var list<string> */
    private array $columns = [];
    /** scope(): every leaf exact only. */
    private bool $exactOnly = false;
    /** Prefix of the q column names, so scope()'s columns never clash with compile()'s. */
    private string $prefix = '';
    /** @var list<FieldDefinition>|null the fields a restricted viewer may match against; null = every field (the unrestricted path) */
    private readonly ?array $allowed;
    /** @var array<string, list<string>> the swapped-letter variants of each word (by candidateKey()), set for the statement by allocateVariants() */
    private array $variants = [];
    /** @var bool whether a condition compiled since the last compile()/leafConditions() holds `<%` (a word without a candidate list) */
    private bool $readsWordThreshold = false;

    /**
     * @param list<string>|null $allowedFields the fields a restricted viewer may see (SearchQuery::$allowedFields): every
     *                                         word is then rechecked against their own columns, see the class comment
     * @param bool              $transpositions a typed word of 4 to 15 letters also matches, as a whole word of a typo-tolerant
     *                                          field, with two neighbouring letters swapped (see Transpositions); off keeps
     *                                          every statement as it was
     * @param (\Closure(): bool)|null $cappedColumn asked (only when a phrase or a scoped word of an unrestricted viewer on an index with several fields
     *                                          is compiled) whether the sidecar has the tsv_capped column; null or false: it has not, and
     *                                          the later fields' vectors are read for every document that `tsv` does not match
     */
    public function __construct(
        private readonly IndexDefinition $index,
        private readonly Thresholds $thresholds,
        private readonly Names $names = new Names(),
        /** @var array<string, list<int|string>> the ids each trigram word matches, by candidateKey() (see probes()) */
        private readonly array $candidates = [],
        ?array $allowedFields = null,
        private readonly bool $transpositions = false,
        private readonly ?\Closure $cappedColumn = null,
    ) {
        $this->tsquery = new TsQueryCompiler($index);
        $this->allowed = $allowedFields === null ? null : array_values(array_filter($index->fields, static fn(FieldDefinition $f): bool => in_array($f->name, $allowedFields, true)));
    }

    /**
     * One statement per word the trigram condition applies to (each once): the ids of the documents it matches
     * alone, one more than $cap so that a word with too many matches is recognised. Each runs with pg_trgm's
     * threshold set to its `similarity`, the lowest the word itself needs.
     *
     * Each applies the search's own conditions (the tenant and the filters), so it probes, counts and returns only
     * documents the search could find: an id of another tenant never reaches a statement (explain() shows them bound).
     *
     * @param list<string>         $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     * @param list<Condition>      $conditions   the search's conditions, tenant included; none: no extra predicate
     *
     * @return array<string, array{sql: string, params: array<string, scalar|null>, similarity: float}> by candidateKey()
     */
    public function probes(Node $node, int $cap, array $emptyQueries = [], array $conditions = []): array
    {
        $probes = [];
        $leaves = $this->trigramLeaves($node, $emptyQueries);
        $this->allocateVariants($leaves);
        foreach ($leaves as $leaf) {
            $needle = $this->needle($leaf);
            assert($needle !== null, 'trigramLeaves() keeps only the leaves that have a needle');
            $key = $this->candidateKey($leaf);
            if (isset($probes[$key])) {
                continue;
            }
            $params = new ParameterBag();
            $norm = sprintf('%s(%s)', $this->names->normFunction(), $params->add($needle));
            $threshold = $this->thresholds->fuzzySimilarity === null ? self::similaritySql($norm, $this->isPrefix($leaf)) : null;
            $where = $this->trigram($leaf, $norm, $threshold)['condition'];
            if (($this->variants[$key] ?? []) !== []) {
                // the swapped-letter variants are found in the same probe: one more way for a document to be a candidate of this word
                $variants = $this->variantsTsquery($this->variants[$key]);
                $where = sprintf('(%s OR %s)', $where, $this->variantMatch($leaf, fn(): string => $this->variantQuery($params->add($variants))));
            }
            if ($conditions !== []) {
                $where .= ' AND ' . (new FilterCompiler($this->index))->compile($conditions, $params);
            }
            $probes[$key] = [
                'sql' => sprintf("SELECT s.id\nFROM %s AS s\nWHERE %s\nLIMIT %d", $this->names->sidecar($this->index), $where, $cap + 1),
                'params' => $params->all(),
                'similarity' => $this->thresholds->fuzzySimilarity ?? TypoCurve::lowest(self::length($needle), preg_match('/^[ -~]*$/', $needle) === 1),
            ];
        }

        return $probes;
    }

    /**
     * The words of the positive part of the query that match fuzzily, for the highlighter: one term per leaf, in order
     * (the same leaves the trigram branch has, so a negated word, a stop word, a word too short for typo tolerance, a
     * word scoped to a field that is not typo-tolerant, and for a restricted viewer a word with no allowed typo-tolerant
     * field, are not among them). A term carries the swapped-letter variants the search matched for its word.
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return list<TypoTerm>
     */
    public function typoTerms(Node $node, array $emptyQueries = []): array
    {
        $leaves = $this->trigramLeaves($node, $emptyQueries);
        $this->allocateVariants($leaves);

        return array_map(function (Node $leaf): TypoTerm {
            $needle = $this->needle($leaf);
            assert($needle !== null, 'trigramLeaves() keeps only the leaves that have a needle');

            return new TypoTerm($needle, $this->isPrefix($leaf), $this->scopedField($leaf)?->name, $this->variants[$this->candidateKey($leaf)] ?? []);
        }, $leaves);
    }

    /**
     * The leaves of the positive part of the query that match fuzzily, in order (a negated part is exact only).
     *
     * @param list<string> $emptyQueries
     *
     * @return list<Node>
     */
    private function trigramLeaves(Node $node, array $emptyQueries): array
    {
        return match (true) {
            $node instanceof Not => [],
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map(fn(Node $n): array => $this->trigramLeaves($n, $emptyQueries), $node->nodes)),
            default => $this->exact($node, $emptyQueries) !== null && $this->needle($node) !== null ? [$node] : [],
        };
    }

    /** What a word's trigram condition depends on: the same key, the same matching documents. */
    private function candidateKey(Node $leaf): string
    {
        return implode("\0", [$this->needle($leaf), $this->scopedField($leaf)->name ?? '', $this->isPrefix($leaf) ? 'prefix' : '', ...($this->transposable($leaf) ? ['swap'] : [])]);
    }

    private function isPrefix(Node $leaf): bool
    {
        $inner = $leaf instanceof FieldScoped ? $leaf->node : $leaf;

        return $inner instanceof Term && $inner->prefix;
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
     * The lowest similarity a positive word of the query needs: the value to set pg_trgm's session
     * threshold to for the statement (null when no positive word can match fuzzily). A lower value
     * would only make the trigram index return candidates the recheck throws away.
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     */
    public function lowestSimilarity(Node $node, array $emptyQueries = []): ?float
    {
        if ($node instanceof Not) {
            return null;
        }
        if ($node instanceof AllOf || $node instanceof AnyOf) {
            $lowest = array_filter(array_map(fn(Node $n): ?float => $this->lowestSimilarity($n, $emptyQueries), $node->nodes), static fn(?float $s): bool => $s !== null);

            return $lowest === [] ? null : min($lowest);
        }
        $needle = $this->exact($node, $emptyQueries) === null ? null : $this->needle($node);

        return $needle === null ? null : ($this->thresholds->fuzzySimilarity ?? TypoCurve::lowest(self::length($needle), preg_match('/^[ -~]*$/', $needle) === 1));
    }

    /**
     * The tsquery of every unit compile() may drop as a stop word (each leaf and each negated
     * operand), without duplicates. The engine asks PostgreSQL which of them are empty.
     *
     * @return list<string>
     */
    public function leafQueries(Node $node): array
    {
        if ($node instanceof Not && $this->wordByWord($node->node)) {
            return $this->leafQueries($node->node); // compiled word by word, see excluded()
        }
        if ($node instanceof AllOf || $node instanceof AnyOf) {
            return array_values(array_unique(array_merge(...array_map($this->leafQueries(...), $node->nodes))));
        }
        $tsquery = $this->tsquery->compile($node instanceof Not ? $node->node : $node);

        return $tsquery === null ? [] : [$tsquery];
    }

    /**
     * The stop-word tsqueries of the typo-tolerant branch for a query of stop words only ("the who"): the strict branch
     * has nothing to match then (the text search configuration drops the words from the query and from the documents),
     * but the trigram side works on the normalised text, stop words included, so the positive words stay ordinary leaves
     * there. Two shapes: every word of the query, excluded ones too, is a stop word; or the query is a top-level AND of
     * stop words with top-level exclusions of anything ("the who -rock", see exclusionStopWords()). A real word to look
     * for makes the strict branch find what it finds, the stop words around it ignored. Only when every positive word is long
     * enough for typo tolerance (a shorter one must match exactly, which a stop word never does: the search would find
     * nothing, so it does not run), and only for at most MAX_STOP_WORDS words: every kept word is a trigram condition
     * that, for a word in most documents, keeps all of its matches (it is over the candidate limit), and several of them
     * in one statement cost seconds on a large index (measured: five common stop words took 15 s for a restricted viewer
     * on 100 000 rows, under 1 s for an unrestricted one; three take under half a second for both). Otherwise $emptyQueries
     * come back unchanged; the negated stop words stay dropped (excluding one excludes nothing).
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return list<string>
     */
    public function keepingStopWords(Node $root, array $emptyQueries): array
    {
        $queries = $this->leafQueries($root);
        $positive = $this->positiveLeaves($root);
        if ($queries === [] || count($positive) > self::MAX_STOP_WORDS || array_any($positive, fn(Node $leaf): bool => $this->needle($leaf) === null)) {
            return $emptyQueries;
        }
        if (array_diff($queries, $emptyQueries) !== [] && $this->exclusionStopWords($root, $emptyQueries) === null) {
            return $emptyQueries;
        }

        return array_values(array_diff($emptyQueries, array_map($this->tsquery->compile(...), $positive)));
    }

    /**
     * "the who -rock": a top-level AND whose positive parts are plain words (a word, a phrase, a field-scoped one: no group, no
     * OR, no synonym expansion) and that has at least one top-level exclusion, of anything. PostgreSQL drops the stop words
     * from the strict tsquery and what is left is a pure exclusion, "everything but rock". When every positive word is a
     * stop word (and the shape of keepingStopWords() allows them) this returns the query without its exclusions, the strict
     * branch's tsquery for it (nothing matches, as for "the who"); the typo-tolerant branch searches the stop words with the
     * exclusion applied. Null otherwise, also when the query is the other shape (every word a stop word: it is as it was)
     * and for any query with an OR at the top level or an OR or an exclusion inside a positive group: those are as they were.
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     */
    public function exclusionStopWords(Node $root, array $emptyQueries): ?Node
    {
        if (!$root instanceof AllOf) {
            return null;
        }
        $positive = array_values(array_filter($root->nodes, static fn(Node $n): bool => !$n instanceof Not));
        if (
            count($positive) === count($root->nodes) || $positive === [] || count($positive) > self::MAX_STOP_WORDS
            || array_any($positive, fn(Node $n): bool => !$this->plainWord($n) || $this->needle($n) === null)
            || array_diff($this->leafQueries($root), $emptyQueries) === []
        ) {
            return null;
        }
        $tsqueries = array_map($this->tsquery->compile(...), $positive);
        if (array_any($tsqueries, static fn(?string $query): bool => $query === null || !in_array($query, $emptyQueries, true))) {
            return null;
        }

        return count($positive) === 1 ? $positive[0] : new AllOf($positive);
    }

    private function plainWord(Node $node): bool
    {
        return $node instanceof Term || $node instanceof Phrase || $node instanceof FieldScoped;
    }

    /** @return list<Node> the leaves of the positive part of the query, in order */
    private function positiveLeaves(Node $node): array
    {
        return match (true) {
            $node instanceof Not => [],
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map($this->positiveLeaves(...), $node->nodes)),
            default => [$node],
        };
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
        $this->readsWordThreshold = false;
        $this->allocateVariants($this->trigramLeaves($node, $emptyQueries));
        $compiled = $this->node($node, $params, $emptyQueries);

        return $compiled === null || $compiled['score'] === null ? null : new FuzzyMatch($compiled['predicate'], $compiled['score'], $this->columns, max(1, count($this->positiveLeaves($node))), $this->readsWordThreshold);
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
        $this->readsWordThreshold = false;
        $this->allocateVariants($fuzzy ? array_merge([], ...array_map(fn(Node $leaf): array => $this->trigramLeaves($leaf, $emptyQueries), $leaves)) : []);
        $predicates = [];
        foreach ($leaves as $leaf) {
            if ($leaf instanceof AnyOf) {
                // a word and the alternatives its synonyms added: the unit matches when any of them does
                $this->exactOnly = !$fuzzy;
                $predicates[] = $this->node($leaf, $params, $emptyQueries)['predicate'] ?? null;
                $this->exactOnly = false;

                continue;
            }
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
            $node instanceof Not && $this->wordByWord($node->node) => $this->excluded($node->node, $params, $empty),
            $node instanceof Not => ($tsquery = $this->exact($node->node, $empty)) === null
                ? null
                : ['predicate' => sprintf('NOT (%s)', $this->matches($node->node, $tsquery, $params)), 'score' => null, 'partial' => false],
            default => $this->leaf($node, $params, $empty),
        };
    }

    /**
     * Whether an excluded part is compiled word by word: when it has a word scoped to a known field, and always for a
     * restricted viewer (one tsquery over the group, "s.tsv @@ ('cable' | !'usb')", would read the hidden fields: its
     * own negation is no longer a superset the allowed-field recheck can narrow).
     */
    private function wordByWord(Node $negated): bool
    {
        return $this->allowed !== null || $this->tsquery->hasFieldScope($negated);
    }

    /**
     * An excluded part with a word scoped to a known field (or any excluded part of a restricted viewer): compiled
     * word by word (exact only), so each word is checked against its own field's column (or the allowed fields'), then negated.
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
        $exactScore = sprintf('CASE WHEN %s THEN 1.0 ELSE 0.0 END', $exact);
        // "<%" uses the session's similarity (the lowest any word of the statement needs, so GIN finds every
        // candidate). By default each word is also rechecked against the similarity of its own length, which
        // PostgreSQL measures on the normalised word (q.th<n>); a word below it does not score either.
        $threshold = $this->thresholds->fuzzySimilarity === null
            ? $this->column('th', self::similaritySql(sprintf('%s(%s)', $this->names->normFunction(), $params->add($needle)), $this->isPrefix($node)))
            : null;
        $trigram = $this->trigram($node, $norm, $threshold);
        $score = $threshold === null ? $trigram['similarity'] : sprintf('CASE WHEN %1$s >= %2$s THEN %1$s ELSE 0.0 END', $trigram['similarity'], $threshold);
        $key = $this->candidateKey($node);
        $ids = $this->candidates[$key] ?? null;
        $this->readsWordThreshold = $this->readsWordThreshold || $ids === null;
        $condition = $ids === null
            ? $trigram['condition']
            : sprintf('s.id = ANY(%s)', $this->column('id', sprintf('CAST(%s AS %s[])', $params->add(Sql::arrayLiteral($ids)), Types::id($this->index->idType))));
        $predicates = [$exact, $condition];
        $scores = [$score, $exactScore];
        if (($this->variants[$key] ?? []) !== []) {
            // A swapped-letter variant of the word, found as a whole word. It scores the least a typo of this word may score
            // (the word's own similarity threshold, or the fixed one), so it ranks below an exact match and never above any
            // ordinary typo match of the word; a candidate id list (probes()) already holds the documents it finds.
            $variants = $this->column('tv', $this->variantQuery($params->add($this->variantsTsquery($this->variants[$key]))));
            $match = $this->variantMatch($node, static fn(): string => $variants);
            $scores[] = sprintf('CASE WHEN %s THEN %s ELSE 0.0 END', $match, $threshold ?? Sql::float((float) $this->thresholds->fuzzySimilarity));
            if ($ids === null) {
                $predicates[] = $match;
            }
        }

        return [
            'predicate' => '(' . implode(' OR ', $predicates) . ')',
            'score' => sprintf('GREATEST(%s)', implode(', ', $scores)),
            'partial' => false,
        ];
    }

    /**
     * Whether the last compile() or leafConditions() put a `<%` comparison into its predicate, which reads
     * pg_trgm.word_similarity_threshold: a word whose candidates were probed (a candidate list) holds none. The statement that runs
     * the predicate declares it (Statement::$readsWordThreshold), so the engine never searches SQL text for the operator.
     */
    public function readsWordThreshold(): bool
    {
        return $this->readsWordThreshold;
    }

    /**
     * Sets the swapped-letter variants of the words of one statement, by candidateKey(): the words in order, each once, as
     * long as the query's budget lasts (Transpositions::MAX_PER_QUERY; a word whose variants do not fit gets none). Done by
     * every entry point that compiles words (probes(), compile(), leafConditions()) over the same leaves, so the probe of a
     * word and the statement that uses its candidates agree.
     *
     * @param list<Node> $leaves the leaves that match fuzzily (trigramLeaves())
     */
    private function allocateVariants(array $leaves): void
    {
        $this->variants = [];
        if (!$this->transpositions) {
            return;
        }
        $budget = Transpositions::MAX_PER_QUERY;
        foreach ($leaves as $leaf) {
            $key = $this->candidateKey($leaf);
            if (isset($this->variants[$key])) {
                continue;
            }
            $this->variants[$key] = [];
            $needle = $this->transposable($leaf) ? $this->needle($leaf) : null;
            $found = $needle === null ? [] : Transpositions::of($needle);
            if ($found !== [] && count($found) <= $budget) {
                $this->variants[$key] = $found;
                $budget -= count($found);
            }
        }
    }

    /** A word the user typed (not a prefix, a phrase, nor an alternative a synonym added) may be matched with two letters swapped. */
    private function transposable(Node $leaf): bool
    {
        $inner = $leaf instanceof FieldScoped ? $leaf->node : $leaf;

        return $this->transpositions && $inner instanceof Term && !$inner->prefix && !$inner->synonym;
    }

    /** @param list<string> $variants */
    private function variantsTsquery(array $variants): string
    {
        return implode(' | ', array_map(static fn(string $variant): string => "'" . $variant . "'", $variants));
    }

    private function variantQuery(string $placeholder): string
    {
        return sprintf('to_tsquery(%s, %s)', $this->names->regconfig($this->index->text), $placeholder);
    }

    /**
     * Whether a document has one of the variants as a word of a typo-tolerant field, like typo tolerance itself reaches only
     * those: GIN(tsv) finds the candidates, and the words must be in a fuzzy field's own column (the allowed ones for a
     * restricted viewer, the field of a scoped word). Without a restriction and when every field is typo-tolerant, tsv alone says it.
     *
     * @param \Closure(): string $query the tsquery of the variants; called once per use (a probe binds each use as its own parameter)
     */
    private function variantMatch(Node $leaf, \Closure $query): string
    {
        $field = $this->scopedField($leaf);
        $targets = $this->allowed !== null
            ? $this->fuzzyAllowed($field)
            : array_values(array_filter($field === null ? $this->index->fields : [$field], static fn(FieldDefinition $f): bool => $f->fuzzy));
        if ($this->allowed === null && $field === null && count($targets) === count($this->index->fields)) {
            return 's.tsv @@ ' . $query();
        }
        $checks = array_map(fn(FieldDefinition $f): string => sprintf('s.%s @@ %s', $this->names->fieldVector($f->name), $query()), $targets);

        return sprintf('(s.tsv @@ %s AND %s)', $query(), count($checks) === 1 ? $checks[0] : '(' . implode(' OR ', $checks) . ')');
    }

    /**
     * The trigram side of a word: its condition (the index can serve "<%"; a word recheck against its own
     * similarity when $threshold is given; a field-scoped word on that field's column too) and its similarity.
     *
     * @return array{condition: string, similarity: string}
     */
    private function trigram(Node $node, string $norm, ?string $threshold): array
    {
        $schema = $this->names->extension();
        $field = $this->scopedField($node);
        if ($field === null && $this->allowed !== null) {
            // A restricted viewer: "<%" on fz (every fuzzy field) only finds the candidates; the similarity, and so the
            // match and the score, is the best of the allowed fuzzy fields' own columns. needle() made sure there is one.
            // A null $threshold means the thresholds fix fuzzySimilarity (both callers pass the per-length recheck otherwise).
            $similarities = array_map(
                fn(FieldDefinition $f): string => sprintf('%s.word_similarity(%s, s.%s)', $schema, $norm, $this->names->fieldFuzzy($f->name)),
                $this->fuzzyAllowed(null),
            );
            $similarity = count($similarities) === 1 ? $similarities[0] : 'GREATEST(' . implode(', ', $similarities) . ')';

            return [
                'condition' => sprintf('(%s OPERATOR(%s.<%%) s.fz AND %s >= %s)', $norm, $schema, $similarity, $threshold ?? Sql::float((float) $this->thresholds->fuzzySimilarity)),
                'similarity' => $similarity,
            ];
        }
        // a scoped word of a restricted viewer is one of the allowed fields (needle()): its own column, as below
        $column = $field === null ? 's.fz' : 's.' . $this->names->fieldFuzzy($field->name);
        $similarity = sprintf('%s.word_similarity(%s, %s)', $schema, $norm, $column);
        $recheck = $threshold === null ? '' : sprintf(' AND %s >= %s', $similarity, $threshold);
        $trigram = $field === null
            ? sprintf('%s OPERATOR(%s.<%%) s.fz', $norm, $schema)
            : sprintf('%1$s OPERATOR(%2$s.<%%) s.fz AND %1$s OPERATOR(%2$s.<%%) %3$s', $norm, $schema, $column);

        return [
            'condition' => $field === null && $recheck === '' ? $trigram : '(' . $trigram . $recheck . ')',
            'similarity' => $similarity,
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
        if ($this->allowed !== null && $this->fuzzyAllowed($field) === []) {
            return null; // no field the viewer may see is typo-tolerant (for a scoped word: its own field)
        }

        return $this->index->hasFuzzy() && ($field === null || $field->fuzzy) && self::length($needle) >= $this->thresholds->fuzzyMinLength ? $needle : null;
    }

    /**
     * The typo-tolerant fields a restricted viewer may see, for a word scoped to $field or (null) any word.
     *
     * @return list<FieldDefinition>
     */
    private function fuzzyAllowed(?FieldDefinition $field): array
    {
        return array_values(array_filter($this->allowed ?? [], static fn(FieldDefinition $f): bool => $f->fuzzy && ($field === null || $f->name === $field->name)));
    }

    /**
     * TypoCurve::similarity() as a SQL expression of the normalised word $norm (a phrase counts its
     * letters without the spaces between its words). A prefix gets the allowance of one letter: its
     * last trigram, padded with a space, can never match inside a longer word.
     */
    public static function similaritySql(string $norm, bool $prefix): string
    {
        $length = sprintf("char_length(replace(%s, ' ', ''))%s", $norm, $prefix ? ' - 1' : '');
        $whens = '';
        foreach (TypoCurve::TYPOS_BY_LENGTH as [$minLength, $typos]) {
            $whens .= sprintf(' WHEN %1$s >= %2$d THEN (%1$s + 1 - %3$d)::float8 / (%1$s + 1 + %3$d) + %4$s', $length, $minLength, 3 * $typos, TypoCurve::SLACK);
        }

        return sprintf('(CASE%s ELSE %s END)', $whens, TypoCurve::SHORT_SIMILARITY);
    }

    /** The length of a needle that similarity is judged by: its letters, without the spaces between words. */
    private static function length(string $needle): int
    {
        return mb_strlen(str_replace(' ', '', $needle));
    }

    private function matches(Node $node, string $tsquery, ParameterBag $params): string
    {
        $field = $this->scopedField($node);
        if ($this->allowed !== null) {
            // GIN(tsv) finds the candidates; the word must be in a field the viewer may see (for a scoped word: its own)
            $targets = $field === null ? $this->allowed : array_values(array_filter($this->allowed, static fn(FieldDefinition $f): bool => $f->name === $field->name));
            if ($targets === []) {
                return 'false'; // nothing the viewer may see can match
            }
            $config = $this->names->regconfig($this->index->text);
            $query = $this->column('ft', sprintf('to_tsquery(%s, %s)', $config, $params->add($tsquery)));
            $checks = array_map(fn(FieldDefinition $f): string => sprintf('s.%s @@ %s', $this->names->fieldVector($f->name), $query), $targets);
            $lookup = $query;
            // The candidates come from the words alone (phrases ANDed, no weight labels), never from the phrase or the weights on
            // tsv: its positions are capped (16 383) after the fields joined before it, so a long hidden field in front of an
            // allowed one would decide whether the phrase is found there, or keep only the highest weight of a lexeme at a clamped
            // position. Every allowed match has the words; the allowed fields' own columns decide.
            $loose = (new TsQueryCompiler($this->index, false, true))->compile($node) ?? $tsquery;
            if ($loose !== $tsquery) {
                $lookup = $this->column('fl', sprintf('to_tsquery(%s, %s)', $config, $params->add($loose)));
            }
            if (str_contains($tsquery, '<->')) {
                if ($field === null && count($targets) > 1) {
                    // A phrase may span two allowed fields, as it does in the whole tsv: it is also read from the allowed fields'
                    // vectors joined in the index's order, as if the hidden fields did not exist (what a hidden field holds, or
                    // lacks, never decides).
                    $checks[] = sprintf('(%s) @@ %s', implode(' || ', array_map(fn(FieldDefinition $f): string => 's.' . $this->names->fieldVector($f->name), $targets)), $query);
                }
            }

            return sprintf('(s.tsv @@ %s AND %s)', $lookup, count($checks) === 1 ? $checks[0] : '(' . implode(' OR ', $checks) . ')');
        }
        $config = $this->names->regconfig($this->index->text);
        $query = $this->column('ft', sprintf('to_tsquery(%s, %s)', $config, $params->add($tsquery)));
        if (count($this->index->fields) > 1 && ($field !== null || str_contains($tsquery, '<->'))) {
            // A word with a weight, or a phrase, on tsv: its positions are capped at 16 383 after the fields joined before the
            // word's own, so after a field of more than about 16 000 words a lexeme there keeps only the highest weight of its
            // fields and a phrase does not match. The candidates come from the words alone (phrases ANDed, no weight labels),
            // which GIN(tsv) serves as it serves the phrase; the field's own column decides a scoped word (positions inside
            // the field), a phrase is read from tsv or from the fields' vectors that start after the first (Names::laterVectors()).
            $loose = (new TsQueryCompiler($this->index, false, true))->compile($node) ?? $tsquery;
            $lookup = $loose !== $tsquery ? $this->column('fl', sprintf('to_tsquery(%s, %s)', $config, $params->add($loose))) : $query;
            if ($field !== null) {
                return sprintf('(s.tsv @@ %s AND s.%s @@ %s)', $lookup, $this->names->fieldVector($field->name), $query);
            }
            $later = array_map(static fn(string $vector): string => sprintf('(%s) @@ %s', $vector, $query), $this->names->laterVectors($this->index));
            if ($this->cappedColumn !== null && ($this->cappedColumn)()) {
                // The later fields' vectors are read only for a document whose tsv was capped (or not known to be: NULL), which is
                // rare: the phrase on tsv, the statement's old cost, decides for every other. They are read by a subquery on the
                // document's own row, not as columns of the scanned one: PostgreSQL fetches every column an expression names from
                // the scanned row before it evaluates anything, which for the last fields of a wide index costs more than the phrase.
                $own = array_map(fn(string $vector): string => sprintf('(%s) @@ %s', $vector, $query), $this->names->laterVectors($this->index, 'c'));

                return sprintf(
                    '(s.tsv @@ %s AND (s.tsv @@ %s OR (s.%s IS NOT FALSE AND EXISTS (SELECT 1 FROM %s AS c WHERE c.id = s.id AND (%s)))))',
                    $lookup,
                    $query,
                    PostgresSchemaGenerator::CAPPED_COLUMN,
                    $this->names->sidecar($this->index),
                    implode(' OR ', $own),
                );
            }

            return sprintf('(s.tsv @@ %s AND (%s))', $lookup, implode(' OR ', ['s.tsv @@ ' . $query, ...$later]));
        }

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

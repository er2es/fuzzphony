<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Engine\Postgres\Schema\Names;

/**
 * @internal The text rank of a document with a `many: { rank: best }` field: the field counts through its BEST single value. For each
 * such field F the viewer may see, R_F = the highest ts_rank_cd of the viewer's own vector with F's joined values replaced by one value
 * (`V[F:=v.t]`: the visible fields' t_ columns in the index's order, F's own swapped for the value's vector from the values table), over
 * the values that hold a word of the query (`v.t @@ q.tsany`); the rank is then
 *
 *   coalesce(nullif(greatest(R_F1, R_F2, ...), 0), <the 0.11 rank>)
 *
 * Same function, normalisation and weights as the whole-field rank, so the score scale is unchanged; a document none of whose values holds a
 * query word, or whose words are spread over several values (no single value gives a cover: R_F = 0), keeps its whole-field rank. A field the
 * viewer may not see never takes part (no reference to the values table at all), so the statement of such a viewer is the whole-field one.
 */
final readonly class BestValueRank
{
    /**
     * What one rank: best field weighs in the scan budget, in words of the query (SearchSqlBuilder::scanLimit()): ranking a candidate
     * reads its matching values (a primary-key probe of the values table, one vector concatenation and one ts_rank_cd per value), which
     * measured on 200 000 customers with 1.5 addresses each about as much as ranking two more words (docs/benchmarks.md).
     */
    public const int OPERANDS = 2;

    /** @var list<string> the rank: best fields the viewer may see, in the index's order */
    public array $fields;

    /** @param list<string>|null $allowed the fields the viewer may see; null = every field */
    public function __construct(
        private IndexDefinition $index,
        private Names $names,
        private ?array $allowed,
    ) {
        $this->fields = array_values(array_filter(
            array_map(static fn(FieldDefinition $f): string => $f->name, $index->manyBestFields()),
            fn(string $name): bool => $this->visible($name),
        ));
    }

    /**
     * The positive words of a rank tsquery (in the form TsQueryCompiler writes) OR-ed: a value takes part only when it holds one of
     * them, so a query that does not touch the field keeps the whole-field rank. Null when there is none.
     */
    public static function anyQuery(string $rankQuery): ?string
    {
        if (preg_match_all("/(!?)('[^']*'(?::[*A-D]+)?)/", $rankQuery, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        $words = [];
        foreach ($m as $match) {
            if ($match[1] === '') {
                $words[$match[2]] = true;
            }
        }

        return $words === [] ? null : implode(' | ', array_keys($words));
    }

    /**
     * The rank expression: the best-value ranks of the visible fields, falling back to `$fallback` (the whole-field rank).
     *
     * @param string $weights   the ts_rank_cd weights literal ({D,C,B,A})
     * @param string $rankQuery the q column of the rank tsquery
     */
    public function rank(string $fallback, string $weights, string $rankQuery): string
    {
        // the field's own vector holds every lexeme of its values: a document none of whose values holds a query word is not looked up
        $parts = array_map(fn(string $field): string => sprintf(
            'CASE WHEN s.%s @@ q.tsany THEN (SELECT max(%s) FROM %s AS v WHERE v.doc = s.id AND v.field = %s AND v.t @@ q.tsany) END',
            $this->names->fieldVector($field),
            $this->valueRank($field, $weights, $rankQuery),
            $this->names->values($this->index),
            Sql::string($field),
        ), $this->fields);

        return sprintf('coalesce(nullif(%s, 0), %s)', count($parts) === 1 ? $parts[0] : 'greatest(' . implode(', ', $parts) . ')', $fallback);
    }

    /**
     * The position (`ord`) of the field's best value for the sidecar row `s`: the first of the highest rank among the values that hold a
     * query word, NULL when no value gives a rank (the document is ranked on the whole field).
     */
    public function bestOrd(string $field, string $weights, string $rankQuery): string
    {
        return sprintf(
            '(SELECT b.ord FROM (SELECT v.ord, %s AS r FROM %s AS v WHERE v.doc = s.id AND v.field = %s AND v.t @@ q.tsany) AS b WHERE b.r > 0 ORDER BY b.r DESC, b.ord LIMIT 1)',
            $this->valueRank($field, $weights, $rankQuery),
            $this->names->values($this->index),
            Sql::string($field),
        );
    }

    /** ts_rank_cd of `V[F:=v.t]`: the visible fields' vectors in order, the field's own replaced by the value's. */
    private function valueRank(string $field, string $weights, string $rankQuery): string
    {
        $parts = [];
        foreach ($this->index->fieldNames() as $name) {
            if ($this->visible($name)) {
                $parts[] = $name === $field ? 'v.t' : 's.' . $this->names->fieldVector($name);
            }
        }

        return sprintf("ts_rank_cd('%s'::real[], %s, %s, 32)", $weights, implode(' || ', $parts), $rankQuery);
    }

    private function visible(string $field): bool
    {
        return $this->allowed === null || in_array($field, $this->allowed, true);
    }
}

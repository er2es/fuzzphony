<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;

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
        $parts = array_map(fn(string $field): string => $this->part($field, $fallback, $weights, $rankQuery), $this->fields);

        return sprintf('coalesce(nullif(%s, 0), %s)', count($parts) === 1 ? $parts[0] : 'greatest(' . implode(', ', $parts) . ')', $fallback);
    }

    /**
     * The position (`ord`) of the field's best value for the sidecar row `s`, only when the field's best-value rank is the one that
     * produced the document's text rank: the first field (in the index's order) whose rank equals the greatest and is above 0, through a
     * real lookup of its values (not the whole-field rank of a one-value document or a stale count). NULL otherwise. Only the fields the
     * viewer may see take part, like in rank().
     *
     * @param string $fallback the whole-field rank, as passed to rank()
     */
    public function bestOrd(string $field, string $fallback, string $weights, string $rankQuery): string
    {
        $mine = $this->part($field, $fallback, $weights, $rankQuery);
        $parts = array_map(fn(string $name): string => $this->part($name, $fallback, $weights, $rankQuery), $this->fields);
        $all = count($parts) === 1 ? $mine : 'greatest(' . implode(', ', $parts) . ')';
        $earlier = [];
        foreach ($this->fields as $i => $name) {
            if ($name === $field) {
                break;
            }
            $earlier[] = sprintf('(%s) IS DISTINCT FROM (%s)', $parts[$i], $mine);
        }

        return sprintf(
            'CASE WHEN s.%s @@ q.tsany AND NOT coalesce(%s, false) AND coalesce((%s) = %s AND (%s) > 0, false)%s THEN (SELECT b.ord FROM (SELECT v.ord, %s AS r FROM %s AS v WHERE v.doc = s.id AND v.field = %s AND v.t @@ q.tsany) AS b WHERE b.r > 0 ORDER BY b.r DESC, b.ord LIMIT 1) END',
            $this->names->fieldVector($field),
            $this->oneValue($field),
            $mine,
            $all,
            $mine,
            $earlier === [] ? '' : ' AND ' . implode(' AND ', $earlier),
            $this->valueRank($field, $weights, $rankQuery),
            $this->names->values($this->index),
            Sql::string($field),
        );
    }

    /** The rank of one field in greatest(): NULL when no value holds a query word, the whole-field rank of a one-value document, else its best value's. */
    private function part(string $field, string $fallback, string $weights, string $rankQuery): string
    {
        // The field's own vector holds every lexeme of its values: a document none of whose values holds a query word is not looked up.
        // A document with ONE value (its count, Names::valueCountName()) is not looked up either: the value's vector is the field's, so the
        // substituted vector is the viewer's own and its rank is the whole-field one (the fallback; for an unrestricted viewer only when
        // `tsv` is not capped, where the fallback may read other vectors). With one field that is the fallback itself (NULL), with several
        // it takes part in greatest() as what it is.
        return sprintf(
            'CASE WHEN NOT s.%1$s @@ q.tsany THEN NULL WHEN %2$s THEN %3$s ELSE (SELECT max(%4$s) FROM %5$s AS v WHERE v.doc = s.id AND v.field = %6$s AND v.t @@ q.tsany) END',
            $this->names->fieldVector($field),
            $this->oneValue($field),
            count($this->fields) === 1 ? 'NULL' : $fallback,
            $this->valueRank($field, $weights, $rankQuery),
            $this->names->values($this->index),
            Sql::string($field),
        );
    }

    /** The condition under which a document counts as having one value of the field (no lookup of its values). */
    private function oneValue(string $field): string
    {
        return sprintf(
            's.%s = 1%s',
            Sql::ident($this->names->valueCountName($field)),
            $this->allowed === null ? sprintf(' AND s.%s IS FALSE', PostgresSchemaGenerator::CAPPED_COLUMN) : '',
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

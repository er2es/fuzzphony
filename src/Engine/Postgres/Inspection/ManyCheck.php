<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The doctor's "Values" checks of an index with several values per document (many): the values table exists, each child table
 * can be read, no document has more values than the limit (they are not indexed: no silent truncation) and the values table holds the
 * rows the child table says it should. Messages count documents and rows; they never name an id or print a value.
 */
final readonly class ManyCheck
{
    /** Above this many child rows (estimated) the full count waits for --deep. */
    private const int CHEAP_ROWS = 1_000_000;

    public function __construct(private Connection $connection, private Names $names) {}

    /** @return list<Check> */
    public function check(IndexDefinition $index, bool $sidecarExists, bool $deep): array
    {
        $checks = [];
        $valuesExists = $this->connection->fetchValue('SELECT to_regclass(:t) IS NOT NULL', ['t' => $this->names->values($index)]) === true;
        if ($index->manyFields() !== [] && !$valuesExists) {
            $checks[] = Check::error('Values table', sprintf('Table %s does not exist: documents cannot be rewritten with their several values.', $this->names->values($index)), 'bin/console fuzzphony:schema --apply');
        }
        foreach ([...$index->manyFields(), ...$index->manyFilters()] as $one) {
            $many = $one->many;
            if (!$many instanceof ManyValues) {
                continue;
            }
            $isField = $one instanceof FieldDefinition;
            $label = sprintf('Values %s "%s"', $isField ? 'field' : 'filter', $one->name);
            try {
                $this->connection->execute(sprintf('SELECT %s, %s, %s FROM %s LIMIT 0', Sql::ident($many->key), Sql::ident($many->column), Sql::ident($many->id), Sql::ident($many->from)));
            } catch (\Throwable) {
                $checks[] = Check::error($label, sprintf('The child table "%s" or one of its columns (%s, %s, %s) cannot be read.', $many->from, $many->key, $many->column, $many->id));

                continue;
            }
            if (!$this->hasKeyIndex($many)) {
                $checks[] = Check::warning($label, sprintf('The child table "%1$s" has no index that starts with "%2$s": every refresh of a document (and every reindex batch) scans the whole table.', $many->from, $many->key), sprintf('CREATE INDEX ON %s (%s);', Sql::ident($many->from), Sql::ident($many->key)));
            }
            if (!$sidecarExists) {
                continue;
            }
            $estimate = Coerce::int($this->connection->fetchValue('SELECT greatest(reltuples, 0)::bigint FROM pg_class WHERE oid = to_regclass(:t)', ['t' => $many->from]));
            if (!$deep && $estimate > self::CHEAP_ROWS) {
                $checks[] = Check::skipped($label, 'The child table is big; run with --deep to count the documents over the limit and the rows of the values table.');

                continue;
            }
            $row = $this->connection->fetchAll(sprintf(
                'SELECT count(*) FILTER (WHERE g.n > %1$d) AS over, coalesce(sum(least(g.n, %1$d)), 0)::bigint AS expected FROM (SELECT c.%2$s AS k, count(*) AS n FROM %3$s AS c WHERE c.%4$s IS NOT NULL GROUP BY c.%2$s) AS g WHERE EXISTS (SELECT 1 FROM %5$s AS s WHERE s.id = g.k::%6$s)',
                $many->limit,
                Sql::ident($many->key),
                Sql::ident($many->from),
                Sql::ident($many->column),
                $this->names->sidecar($index),
                Types::id($index->idType),
            ))[0] ?? ['over' => 0, 'expected' => 0];
            $over = Coerce::int($row['over']);
            if ($over > 0) {
                $checks[] = Check::warning($label, sprintf('%d document(s) have more than %d values in "%s": only the first %d (by value, then "%s") are indexed.', $over, $many->limit, $many->from, $many->limit, $many->id), 'Raise the limit (many: { limit: N }) and reindex, or accept the cut.');
            }
            if ($isField && $valuesExists) {
                $actual = Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s WHERE field = :field', $this->names->values($index)), ['field' => $one->name]));
                $expected = Coerce::int($row['expected']);
                if ($actual !== $expected) {
                    $checks[] = Check::warning($label, sprintf('The values table holds %d row(s) for it, the child table says %d: the values are out of date.', $actual, $expected), sprintf('bin/console fuzzphony:reindex %s', $index->name));
                } elseif ($over === 0) {
                    $checks[] = Check::ok($label, sprintf('%d value(s), none over the limit of %d', $actual, $many->limit));
                }
            } elseif ($over === 0) {
                $checks[] = Check::ok($label, sprintf('none over the limit of %d', $many->limit));
            }
        }

        return $checks;
    }

    /** Whether a valid index of the child table starts with the key column (the lookup of one document's values). */
    private function hasKeyIndex(ManyValues $many): bool
    {
        return $this->connection->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM pg_index AS i JOIN pg_attribute AS a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0] WHERE i.indrelid = to_regclass(:t) AND a.attname = :k AND i.indisvalid)',
            ['t' => Sql::ident($many->from), 'k' => $many->key],
        ) === true;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Wizard;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\ForeignKey;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Fuzzphony\Core\Wizard\TableProfile;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * Reads catalog + planner statistics (pg_stats). Tables that were never analysed are
 * sampled (first 1000 rows) instead, so the wizard also works on fresh databases.
 * Read-only.
 */
final readonly class PostgresIntrospector implements SourceIntrospector
{
    private const int SAMPLE = 1000;

    public function __construct(private Connection $connection) {}

    public function tables(): array
    {
        $rows = $this->connection->fetchAll(<<<'SQL'
            SELECT CASE WHEN n.nspname = 'public' THEN c.relname ELSE n.nspname || '.' || c.relname END AS table_name,
                   greatest(c.reltuples, 0)::bigint AS rows
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ('r', 'p')
              AND n.nspname NOT IN ('pg_catalog', 'information_schema')
              AND n.nspname NOT LIKE 'pg_toast%'
              AND c.relname NOT LIKE 'fuzzphony\_%'
              AND NOT c.relispartition
            ORDER BY c.reltuples DESC, 1
            SQL);

        return array_map(static fn (array $r): array => ['table' => (string) $r['table_name'], 'rows' => (int) $r['rows']], $rows);
    }

    public function describe(string $table, bool $withRelations = true): TableProfile
    {
        if (!Identifier::isTable($table) || $this->connection->fetchValue('SELECT to_regclass(:t)', ['t' => $table]) === null) {
            throw new \InvalidArgumentException(sprintf('Table "%s" does not exist (or is not visible on the search_path).', $table));
        }

        $columns = $this->connection->fetchAll(
            <<<'SQL'
                SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, NOT a.attnotnull AS nullable,
                       s.avg_width, s.n_distinct, s.histogram_bounds::text AS bounds, s.most_common_vals::text AS common
                FROM pg_attribute a
                JOIN pg_class c ON c.oid = a.attrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                LEFT JOIN pg_stats s ON s.schemaname = n.nspname AND s.tablename = c.relname AND s.attname = a.attname
                WHERE a.attrelid = to_regclass(:t) AND a.attnum > 0 AND NOT a.attisdropped
                ORDER BY a.attnum
                SQL,
            ['t' => $table],
        );
        $rows = (int) $this->connection->fetchValue('SELECT greatest(reltuples, 0)::bigint FROM pg_class WHERE oid = to_regclass(:t)', ['t' => $table]);

        $notes = [];
        $hasStats = array_filter($columns, static fn (array $c): bool => $c['n_distinct'] !== null) !== [];
        $sample = [];
        if (!$hasStats) {
            $sample = $this->sample($table, array_column($columns, 'name'));
            $rows = max($rows, (int) ($sample['__rows'] ?? 0));
            $notes[] = sprintf('No planner statistics for "%s" yet; sampled up to %d rows. Run ANALYZE for better suggestions.', $table, self::SAMPLE);
        }

        $profiles = [];
        foreach ($columns as $c) {
            $name = (string) $c['name'];
            $kind = $this->kind((string) $c['type']);
            $distinct = $c['n_distinct'] !== null ? (float) $c['n_distinct'] : null;
            if ($distinct !== null && $distinct < 0) {
                $distinct = -$distinct * max($rows, 1); // negative = fraction of rows
            }
            $profiles[] = new ColumnProfile(
                name: $name,
                kind: $kind,
                sqlType: (string) $c['type'],
                nullable: (bool) $c['nullable'],
                averageLength: $c['avg_width'] !== null ? (float) $c['avg_width'] : (isset($sample['l_' . $name]) ? (float) $sample['l_' . $name] : null),
                distinct: $distinct ?? (isset($sample['d_' . $name]) ? (float) $sample['d_' . $name] : null),
                approximateMax: in_array($kind, [ColumnKind::Int, ColumnKind::Float], true) ? $this->max([$c['bounds'], $c['common']], $sample['m_' . $name] ?? null) : null,
            );
        }

        return new TableProfile(
            table: $table,
            columns: $profiles,
            primaryKey: $this->primaryKey($table),
            estimatedRows: $rows,
            foreignKeys: $withRelations ? $this->foreignKeys($table) : [],
            notes: $notes,
        );
    }

    private function primaryKey(string $table): ?string
    {
        $key = $this->connection->fetchValue(
            <<<'SQL'
                SELECT a.attname
                FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                WHERE i.indrelid = to_regclass(:t) AND i.indisprimary AND i.indnkeyatts = 1
                SQL,
            ['t' => $table],
        );

        return is_string($key) ? $key : null;
    }

    /** @return list<ForeignKey> single-column foreign keys, with the referenced table described (no deeper) */
    private function foreignKeys(string $table): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
                SELECT a.attname AS column_name,
                       CASE WHEN n.nspname = 'public' THEN rc.relname ELSE n.nspname || '.' || rc.relname END AS ref_table,
                       ra.attname AS ref_column
                FROM pg_constraint con
                JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[1]
                JOIN pg_class rc ON rc.oid = con.confrelid
                JOIN pg_namespace n ON n.oid = rc.relnamespace
                JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = con.confkey[1]
                WHERE con.contype = 'f' AND con.conrelid = to_regclass(:t) AND cardinality(con.conkey) = 1
                ORDER BY a.attnum
                SQL,
            ['t' => $table],
        );

        return array_map(fn (array $r): ForeignKey => new ForeignKey(
            (string) $r['column_name'],
            (string) $r['ref_table'],
            (string) $r['ref_column'],
            $this->describe((string) $r['ref_table'], withRelations: false),
        ), $rows);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>
     */
    private function sample(string $table, array $columns): array
    {
        $select = ['count(*) AS "__rows"'];
        foreach ($columns as $column) {
            $q = 's.' . Sql::ident($column);
            $select[] = sprintf('avg(length(%s::text)) AS %s', $q, Sql::ident('l_' . $column));
            $select[] = sprintf('count(DISTINCT %s::text) AS %s', $q, Sql::ident('d_' . $column));
            $select[] = sprintf("max(CASE WHEN %1\$s::text ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN %1\$s::text::numeric END) AS %2\$s", $q, Sql::ident('m_' . $column));
        }

        return $this->connection->fetchAll(sprintf('SELECT %s FROM (SELECT * FROM %s LIMIT %d) AS s', implode(', ', $select), Sql::ident($table), self::SAMPLE))[0] ?? [];
    }

    /**
     * Largest value seen in the histogram / most-common-values arrays (numeric columns).
     *
     * @param list<mixed> $arrays
     */
    private function max(array $arrays, mixed $sampled): ?float
    {
        $max = null;
        foreach ($arrays as $array) {
            if (is_string($array) && preg_match_all('/-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', $array, $m) > 0) {
                $max = max($max ?? -INF, ...array_map('floatval', $m[0]));
            }
        }

        return $max ?? (is_numeric($sampled) ? (float) $sampled : null);
    }

    private function kind(string $type): ColumnKind
    {
        $type = (string) preg_replace('/\(.*\)/', '', $type);

        return match (true) {
            in_array($type, ['text', 'character varying', 'character', 'citext', 'name'], true) => ColumnKind::Text,
            in_array($type, ['smallint', 'integer', 'bigint'], true) => ColumnKind::Int,
            in_array($type, ['real', 'double precision', 'numeric'], true) => ColumnKind::Float,
            $type === 'boolean' => ColumnKind::Bool,
            $type === 'date' => ColumnKind::Date,
            str_starts_with($type, 'timestamp') => ColumnKind::DateTime,
            $type === 'uuid' => ColumnKind::Uuid,
            default => ColumnKind::Other,
        };
    }
}

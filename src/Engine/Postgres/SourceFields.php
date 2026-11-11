<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The returnable columns of a page (SearchBuilder::with()): which of them the actor may ask for, and ONE statement for the
 * page (a SELECT per source on it, joined with UNION ALL) that reads them from the source query by the ids of the page. Every value is
 * bound, the column names come from the validated allow-list (quoted identifiers), a tenant-scoped index repeats its tenant condition
 * on every source SELECT (defence in depth: the ids are already the tenant's). The values come back as one jsonb object per row, so
 * PostgreSQL types them: integers, floats and booleans stay what they are, text and dates (ISO 8601) are strings, json and arrays
 * become JSON strings; a many field comes back as a list of strings (its first `limit` values, read from the child table).
 */
final readonly class SourceFields
{
    public function __construct(private SearchSession $session) {}

    /**
     * The returnable names this search asks for (a name is a column, an alias of one, or a many field's name). A name whose COLUMN (or
     * name) is a `granted` field the viewer does not hold is not returnable to him: the fields of SearchQuery::$allowedFields, after
     * the guards and restrictedToFields(), decide, by the field's name and by the column it reads, so no alias reaches a granted
     * column; the others get the same InvalidQuery for it as for an unknown name, listing only what they may ask for.
     *
     * @return list<string>
     *
     * @throws InvalidQuery
     */
    public static function resolve(IndexDefinition $index, SearchQuery $query): array
    {
        $visible = array_values(array_filter($index->returnableNames(), static fn(string $name): bool => self::mayRead($index, $query, $name)));
        if ($visible === []) {
            throw new InvalidQuery(sprintf('Index "%s" has no returnable columns for this search: declare `returnable: [title, slug]` in its definition to use with().', $index->name));
        }
        $asked = $query->returning ?? [];
        if ($asked === []) {
            return $visible;
        }
        $unknown = array_values(array_diff($asked, $visible));
        if ($unknown !== []) {
            throw new InvalidQuery(sprintf('Index "%s" cannot return "%s". Returnable columns: %s.', $index->name, $unknown[0], implode(', ', $visible)));
        }

        return array_values(array_filter($visible, static fn(string $name): bool => in_array($name, $asked, true)));
    }

    /** Whether this viewer may read a returnable name: no granted field he lacks, and no many field outside his visible fields, is called that or reads a column it maps to (in any source). */
    private static function mayRead(IndexDefinition $index, SearchQuery $query, string $name): bool
    {
        if ($query->allowedFields === null) {
            return true;
        }
        $keys = [$name, ...$index->returnableColumnsOf($name)];
        foreach ($index->fields as $field) {
            // a granted field the viewer lacks, and a many field he cannot search (restrictedToFields() included): its values are a data path of their own, so with() follows the search
            if (($field->granted !== null || $field->many !== null) && (in_array($field->name, $keys, true) || in_array($field->column(), $keys, true)) && !in_array($field->name, $query->allowedFields, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ONE statement for the hits of a page: a SELECT per source on it (its own bound ids and tenant condition) joined with UNION ALL.
     * A many field is read from its child table in the same statement (its first `limit` values, in the order of the index).
     *
     * @param list<Hit>    $hits
     * @param list<string> $fields the resolved names
     */
    public function statement(IndexDefinition $index, SearchQuery $query, array $hits, array $fields): ?Statement
    {
        $ids = [];
        foreach ($hits as $hit) {
            $ids[$hit->kind][] = $hit->id;
        }
        $params = new ParameterBag();
        $branches = [];
        foreach ($ids as $kind => $list) {
            $kind = (string) $kind;
            $source = $index->isMultiSource() ? $index->sourceNamed($kind) : null;
            $part = $source === null ? $index : $index->forSource($source);
            $pairs = [];
            foreach ($fields as $name) {
                $column = $index->returnableColumn($kind, $name);
                if ($column === null || !self::mayRead($index, $query, $name)) {
                    continue; // not this source's, or (defence in depth: resolve() said so already) not this viewer's
                }
                $many = $part->field($column)?->many;
                $pairs[] = Sql::string($name) . ', ' . ($many === null
                    ? 'd.' . Sql::ident($column)
                    : sprintf("coalesce((SELECT jsonb_agg(m.v ORDER BY m.v, m.pk) FROM (%s) AS m), '[]'::jsonb)", DocumentSql::manyRows($part, $many)));
            }
            if ($pairs === []) {
                continue;
            }
            $id = 'd.' . Sql::ident($part->source->idColumn);
            $where = sprintf('%s = ANY (CAST(%s AS %s[]))', $id, $params->add(Sql::arrayLiteral(array_values(array_unique($list)))), Types::id($part->idType));
            if ($index->tenant !== null) {
                $filter = $index->filter($index->tenant);
                $where .= sprintf(' AND d.%s::%s = CAST(%s AS %s)', Sql::ident($filter->column()), Types::filter($filter->type), $params->add($filter->type->normalize($query->tenant, $filter->name)), Types::filter($filter->type));
            }
            $branches[] = sprintf("SELECT %s::text AS fz_kind, %s::text AS fz_id, jsonb_build_object(%s) AS fz_data\nFROM (%s) AS d\nWHERE %s", Sql::string($kind), $id, implode(', ', $pairs), DocumentSql::raw($part), $where);
        }
        if ($branches === []) {
            return null;
        }
        $sql = count($branches) === 1 ? $branches[0] : implode("\nUNION ALL\n", array_map(static fn(string $b): string => '(' . $b . ')', $branches));

        return new Statement(StatementKind::Fields, 'returned fields: ' . $index->name, $sql, $params->all());
    }

    /**
     * The hits with their data: every requested name for a hit (null for one its source does not return), none for a hit whose row is gone.
     *
     * @param list<Hit>    $hits
     * @param list<string> $fields
     *
     * @return array{hits: list<Hit>, statements: list<Statement>}
     */
    public function attach(IndexDefinition $index, SearchQuery $query, array $hits, array $fields): array
    {
        $rows = [];
        $statements = [];
        $statement = $this->statement($index, $query, $hits, $fields);
        if ($statement !== null) {
            $statements[] = $statement;
            foreach ($this->session->run($statement, null) as $row) {
                $decoded = json_decode(Coerce::str($row['fz_data']), true, 8, JSON_THROW_ON_ERROR);
                $rows[Coerce::str($row['fz_kind'])][Coerce::str($row['fz_id'])] = is_array($decoded) ? $decoded : [];
            }
        }
        $out = [];
        foreach ($hits as $hit) {
            $row = $rows[$hit->kind][(string) $hit->id] ?? null;
            $returnable = $index->returnableOf($hit->kind);
            if ($row === null && array_intersect($fields, $returnable) !== []) {
                $out[] = $hit; // the source row is gone: no data
                continue;
            }
            $data = [];
            foreach ($fields as $name) {
                $value = $row[$name] ?? null;
                $column = $index->returnableColumn($hit->kind, $name);
                if (is_array($value) && $column !== null && $index->field($column)?->many !== null) {
                    $data[$name] = array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
                } else {
                    $data[$name] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_scalar($value) ? $value : null);
                }
            }
            $out[] = $hit->withData($data);
        }

        return ['hits' => $out, 'statements' => $statements];
    }
}

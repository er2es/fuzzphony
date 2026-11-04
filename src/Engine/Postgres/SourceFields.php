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
 * @internal The returnable columns of a page (SearchBuilder::with()): which of them the actor may ask for, and ONE statement per source
 * that reads them from the source query by the ids of the page. Every value is bound, the column names come from the validated
 * allow-list (quoted identifiers), a tenant-scoped index repeats its tenant condition on the source (defence in depth: the ids are
 * already the tenant's). The values come back as one jsonb object per row, so PostgreSQL types them: integers, floats and booleans
 * stay what they are, text and dates (ISO 8601) are strings, json and arrays become JSON strings.
 */
final readonly class SourceFields
{
    public function __construct(private SearchSession $session) {}

    /**
     * The returnable columns this search asks for. A `granted` field is returnable only to a viewer who may see it (the fields of
     * SearchQuery::$allowedFields, after the guards and restrictedToFields()); a returnable column is gated by the granted field of that name or that reads that column; the others get the same InvalidQuery for it as for an
     * unknown name, listing only what they may ask for.
     *
     * @return list<string>
     *
     * @throws InvalidQuery
     */
    public static function resolve(IndexDefinition $index, SearchQuery $query): array
    {
        $visible = array_values(array_filter($index->returnableNames(), static function (string $name) use ($index, $query): bool {
            if ($query->allowedFields === null) {
                return true;
            }
            // a returnable name is a COLUMN: any granted field that is called that or reads that column gates it (a field may rename its column)
            foreach ($index->fields as $field) {
                if ($field->granted !== null && ($field->name === $name || $field->column() === $name) && !in_array($field->name, $query->allowedFields, true)) {
                    return false;
                }
            }

            return true;
        }));
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

    /**
     * The statements for the hits of one page, one per source on it.
     *
     * @param list<Hit>    $hits
     * @param list<string> $fields the resolved names
     *
     * @return list<array{kind: string, statement: Statement}>
     */
    public function statements(IndexDefinition $index, SearchQuery $query, array $hits, array $fields): array
    {
        $ids = [];
        foreach ($hits as $hit) {
            $ids[$hit->kind][] = $hit->id;
        }
        $out = [];
        foreach ($ids as $kind => $list) {
            $columns = array_values(array_filter($fields, static fn(string $f): bool => in_array($f, $index->returnableOf((string) $kind), true)));
            if ($columns === []) {
                continue;
            }
            $source = $index->isMultiSource() ? $index->sourceNamed((string) $kind) : null;
            $part = $source === null ? $index : $index->forSource($source);
            $params = new ParameterBag();
            $pairs = [];
            foreach ($columns as $column) {
                $pairs[] = Sql::string($column) . ', d.' . Sql::ident($column);
            }
            $id = 'd.' . Sql::ident($part->source->idColumn);
            $where = sprintf('%s = ANY (CAST(%s AS %s[]))', $id, $params->add(Sql::arrayLiteral(array_values(array_unique($list)))), Types::id($part->idType));
            if ($index->tenant !== null) {
                $filter = $index->filter($index->tenant);
                $where .= sprintf(' AND d.%s::%s = CAST(%s AS %s)', Sql::ident($filter->column()), Types::filter($filter->type), $params->add($filter->type->normalize($query->tenant, $filter->name)), Types::filter($filter->type));
            }
            $sql = sprintf("SELECT %s::text AS fz_id, jsonb_build_object(%s) AS fz_data\nFROM (%s) AS d\nWHERE %s", $id, implode(', ', $pairs), DocumentSql::raw($part), $where);
            $out[] = ['kind' => (string) $kind, 'statement' => new Statement(StatementKind::Fields, 'returned fields: ' . $kind, $sql, $params->all())];
        }

        return $out;
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
        foreach ($this->statements($index, $query, $hits, $fields) as $one) {
            $statements[] = $one['statement'];
            foreach ($this->session->run($one['statement'], null) as $row) {
                $decoded = json_decode(Coerce::str($row['fz_data']), true, 8, JSON_THROW_ON_ERROR);
                $rows[$one['kind']][Coerce::str($row['fz_id'])] = is_array($decoded) ? $decoded : [];
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
                $data[$name] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_scalar($value) ? $value : null);
            }
            $out[] = $hit->withData($data);
        }

        return ['hits' => $out, 'statements' => $statements];
    }
}

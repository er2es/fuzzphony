<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Identifier;

/** @internal Collects ALL problems of a definition at once, each with a concrete fix. */
final class DefinitionValidator
{
    /**
     * Dollar-quote tag the schema generator wraps generated function bodies in. The source query
     * and every watch's affectedIds SQL are embedded in such bodies, so they must never contain
     * it (PostgreSQL matches the tag case-sensitively, and so does this check).
     */
    public const string DOLLAR_QUOTE_TAG = '$fuzzphony$';

    public static function assertValid(IndexDefinition $index): void
    {
        $violations = self::validate($index);
        if ($violations !== []) {
            throw new InvalidDefinition($index->name, $violations);
        }
    }

    /** @return list<string> */
    public static function validate(IndexDefinition $index): array
    {
        $v = [];

        if (!Identifier::isName($index->name)) {
            $v[] = sprintf('Index name "%s" must match [a-z_][a-z0-9_]* (max 48 chars), e.g. "products".', $index->name);
        }
        // "products__next" would name the rebuild table, change log and function of "products"
        if (str_contains($index->name, '__')) {
            $v[] = sprintf('Index name "%s" must not contain "__" (two underscores): it is reserved for the internal "__next" and "__changes" objects of a rebuild. Use single underscores.', $index->name);
        }

        if ($index->sources === []) {
            array_push($v, ...self::validateSource($index, $index->source, $index->watches, ''));
        } else {
            $names = [];
            foreach ($index->sources as $one) {
                $label = sprintf('Source "%s": ', $one->name);
                if (!Identifier::isName($one->name)) {
                    $v[] = sprintf('Source name "%s" must match [a-z_][a-z0-9_]* (max 48 chars), e.g. "product": it is the kind of the hits and is stored in the key.', $one->name);
                }
                if (isset($names[$one->name])) {
                    $v[] = sprintf('Source "%s" is defined twice.', $one->name);
                }
                $names[$one->name] = true;
                array_push($v, ...self::validateSource($index, $one->source, $one->watches, $label));
                array_push($v, ...self::validateReturnable($one->returnable, $label, $one->returnableAs));
            }
        }
        array_push($v, ...self::validateReturnable($index->returnable, '', $index->returnableAs));

        if ($index->fields === []) {
            $v[] = 'At least one searchable field is required, e.g. ->field("name", "A").';
        }
        $seen = [];
        foreach ($index->fields as $field) {
            if (!Identifier::isName($field->name)) {
                $v[] = sprintf('Field name "%s" must match [a-z_][a-z0-9_]*.', $field->name);
            }
            if (!Identifier::isColumn($field->column())) {
                $v[] = sprintf('Field "%s" maps to invalid column "%s".', $field->name, $field->column());
            }
            if (isset($seen[$field->name])) {
                $v[] = sprintf('Field "%s" is defined twice.', $field->name);
            }
            $seen[$field->name] = true;
        }

        $seen = [];
        foreach ($index->filters as $filter) {
            if (!Identifier::isName($filter->name)) {
                $v[] = sprintf('Filter name "%s" must match [a-z_][a-z0-9_]*.', $filter->name);
            }
            if (!Identifier::isColumn($filter->column())) {
                $v[] = sprintf('Filter "%s" maps to invalid column "%s".', $filter->name, $filter->column());
            }
            if (isset($seen[$filter->name])) {
                $v[] = sprintf('Filter "%s" is defined twice.', $filter->name);
            }
            $seen[$filter->name] = true;
        }

        if ($index->tenant !== null) {
            $known = array_map(static fn(FilterDefinition $f): string => $f->name, $index->filters);
            if (!in_array($index->tenant, $known, true)) {
                $v[] = sprintf(
                    'tenant("%s") must reference a declared filter. Known filters: %s.',
                    $index->tenant,
                    $known === [] ? '(none)' : implode(', ', $known),
                );
            }
        }

        array_push($v, ...self::validateMany($index));

        array_push($v, ...$index->synonyms->violations());

        foreach (['boost' => $index->boostColumn, 'recency' => $index->recencyColumn] as $what => $column) {
            if ($column !== null && !Identifier::isColumn($column)) {
                $v[] = sprintf('The %s column "%s" is not a valid column name.', $what, $column);
            }
        }

        if (!Identifier::isName($index->text->language)) {
            $v[] = sprintf('Language "%s" must be a text search configuration name such as english, hungarian, german or simple.', $index->text->language);
        }

        if (!isset($index->profiles['default'])) {
            $v[] = 'A ranking profile named "default" is required.';
        }
        foreach ($index->profiles as $name => $profile) {
            if ($profile->boost > 0.0 && $index->boostColumn === null) {
                $v[] = sprintf('Profile "%s" uses boost, but the index has no boost column. Call boostBy("popularity") or set boost to 0.', $name);
            }
            if ($profile->recency > 0.0 && $index->recencyColumn === null) {
                $v[] = sprintf('Profile "%s" uses recency, but the index has no recency column. Call recencyBy("published_at") or set recency to 0.', $name);
            }
        }

        return $v;
    }

    /** @return list<string> */
    private static function validateMany(IndexDefinition $index): array
    {
        $v = [];
        if (!$index->hasMany()) {
            return $v;
        }
        if ($index->isMultiSource()) {
            $v[] = 'Several values per document (many) are not supported in a multi-source index: declare the field without "many" in a single-source index, or fold the values into one column in each source (string_agg).';
        }
        $primary = $index->fields === [] ? null : $index->primaryField()->name;
        $keys = [];
        foreach ($index->fields as $field) {
            if ($field->many === null) {
                continue;
            }
            $what = sprintf('Field "%s"', $field->name);
            array_push($v, ...$field->many->violations($what));
            if ($field->column !== null) {
                $v[] = $what . ': "column" and "many" are exclusive: the column of the values is many.column.';
            }
            if ($field->code) {
                $v[] = $what . ': "code" cannot be combined with "many" (a code field is read as one value).';
            }
            if ($field->name === $primary) {
                $v[] = $what . sprintf(' is the primary field (the first A-weighted field, else the first one; the exact and prefix bonuses read one value) and cannot be "many": give another field weight A%s.', $index->fields[0]->name === $field->name ? ' or put a single-valued field first' : '');
            }
            $keys[$field->many->from][$field->many->key] = true;
        }
        foreach ($index->filters as $filter) {
            if ($filter->many === null) {
                continue;
            }
            $what = sprintf('Filter "%s"', $filter->name);
            array_push($v, ...$filter->many->violations($what));
            if ($filter->many->rank !== ManyRank::Whole) {
                $v[] = sprintf('%s: many "rank" applies to fields only (a filter does not rank).', $what);
            }
            if ($filter->column !== null) {
                $v[] = $what . ': "column" and "many" are exclusive: the column of the values is many.column.';
            }
            if ($filter->type !== FilterType::String) {
                $v[] = $what . sprintf(' is a %s filter: only string filters can take several values (many).', $filter->type->value);
            }
            if ($filter->name === $index->tenant) {
                $v[] = $what . ' is the tenant filter and cannot take several values (a document belongs to one tenant).';
            }
            $keys[$filter->many->from][$filter->many->key] = true;
        }
        foreach ($keys as $table => $columns) {
            if (count($columns) > 1) {
                $v[] = sprintf('The child table "%s" is linked to the documents by different key columns (%s): one table has one key.', $table, implode(', ', array_keys($columns)));
            }
        }

        return $v;
    }

    /**
     * @param list<string>          $returnable
     * @param array<string, string> $aliases    alias => column
     *
     * @return list<string>
     */
    private static function validateReturnable(array $returnable, string $label, array $aliases = []): array
    {
        $v = [];
        if (count($returnable) + count($aliases) > 50) {
            $v[] = $label . sprintf('At most 50 returnable columns are allowed, got %d.', count($returnable) + count($aliases));
        }
        $seen = [];
        foreach ($aliases as $alias => $column) {
            if (!Identifier::isColumn((string) $alias)) {
                $v[] = $label . sprintf('Returnable alias "%s" is not a valid column name.', $alias);
            }
            if (!Identifier::isColumn($column)) {
                $v[] = $label . sprintf('Returnable column "%s" (alias "%s") is not a valid column name.', $column, $alias);
            }
            if (in_array((string) $alias, $returnable, true)) {
                $v[] = $label . sprintf('Returnable name "%s" is listed twice.', $alias);
            }
        }
        foreach ($returnable as $column) {
            if (!Identifier::isColumn($column)) {
                $v[] = $label . sprintf('Returnable column "%s" is not a valid column name.', $column);
            }
            if (isset($seen[$column])) {
                $v[] = $label . sprintf('Returnable column "%s" is listed twice.', $column);
            }
            $seen[$column] = true;
        }

        return $v;
    }

    /**
     * The table / query / id column of one source and its watches (a multi-source index: each source, messages prefixed with its name).
     *
     * @param list<Watch> $watches
     *
     * @return list<string>
     */
    private static function validateSource(IndexDefinition $index, Source $source, array $watches, string $label): array
    {
        $v = [];
        if ($source->table !== null && !Identifier::isTable($source->table)) {
            $v[] = $label . sprintf('Source table "%s" is not a valid identifier (use "table" or "schema.table").', $source->table);
        }
        if ($source->query !== null && preg_match('/^\s*(select|with)\b/i', $source->query) !== 1) {
            $v[] = $label . 'Source query must be a SELECT (or WITH ... SELECT) statement.';
        }
        if ($source->query !== null && str_contains($source->query, self::DOLLAR_QUOTE_TAG)) {
            $v[] = $label . sprintf('Source query must not contain "%s": it is embedded in generated function bodies quoted with that tag. Use another dollar-quote tag or a plain string literal.', self::DOLLAR_QUOTE_TAG);
        }
        if (!Identifier::isColumn($source->idColumn)) {
            $v[] = $label . sprintf('Id column "%s" is not a valid column name.', $source->idColumn);
        }
        if ($source->isQuery() && $index->sync->usesTriggers() && $watches === []) {
            $v[] = $label . sprintf(
                'Sync mode "%s" needs to know which tables to watch, but a query source cannot be watched automatically. '
                . 'Add watch("main_table") plus one watch per joined table, e.g. watch("brand", "SELECT id FROM product WHERE brand_id = :id").',
                $index->sync->value,
            );
        }
        foreach ($watches as $watch) {
            if (!Identifier::isTable($watch->table)) {
                $v[] = $label . sprintf('Watched table "%s" is not a valid identifier.', $watch->table);
            }
            if (!Identifier::isColumn($watch->keyColumn)) {
                $v[] = $label . sprintf('Watch key column "%s" is not a valid column name.', $watch->keyColumn);
            }
            if (preg_match_all('/:id\b/', $watch->affectedIds) !== 1 || preg_match('/^\s*select\b/i', $watch->affectedIds) !== 1) {
                $v[] = $label . sprintf('Watch on "%s": affectedIds must be a SELECT containing ":id" exactly once, e.g. "SELECT id FROM product WHERE brand_id = :id".', $watch->table);
            }
            if (str_contains($watch->affectedIds, self::DOLLAR_QUOTE_TAG)) {
                $v[] = $label . sprintf('Watch on "%s": affectedIds must not contain "%s": it is embedded in generated trigger functions quoted with that tag. Use another dollar-quote tag or a plain string literal.', $watch->table, self::DOLLAR_QUOTE_TAG);
            }
            foreach ($watch->columns ?? [] as $column) {
                if (!Identifier::isColumn($column)) {
                    $v[] = $label . sprintf('Watch on "%s": column "%s" is not a valid column name.', $watch->table, $column);
                }
            }
        }

        return $v;
    }
}

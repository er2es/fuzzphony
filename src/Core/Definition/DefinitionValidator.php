<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Identifier;

/** Collects ALL problems of a definition at once, each with a concrete fix. */
final class DefinitionValidator
{
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

        $source = $index->source;
        if ($source->table !== null && !Identifier::isTable($source->table)) {
            $v[] = sprintf('Source table "%s" is not a valid identifier (use "table" or "schema.table").', $source->table);
        }
        if ($source->query !== null && preg_match('/^\s*(select|with)\b/i', $source->query) !== 1) {
            $v[] = 'Source query must be a SELECT (or WITH ... SELECT) statement.';
        }
        if (!Identifier::isColumn($source->idColumn)) {
            $v[] = sprintf('Id column "%s" is not a valid column name.', $source->idColumn);
        }

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

        foreach (['boost' => $index->boostColumn, 'recency' => $index->recencyColumn] as $what => $column) {
            if ($column !== null && !Identifier::isColumn($column)) {
                $v[] = sprintf('The %s column "%s" is not a valid column name.', $what, $column);
            }
        }

        if ($source->isQuery() && $index->sync->usesTriggers() && $index->watches === []) {
            $v[] = sprintf(
                'Sync mode "%s" needs to know which tables to watch, but a query source cannot be watched automatically. '
                . 'Add watch("main_table") plus one watch per joined table, e.g. watch("brand", "SELECT id FROM product WHERE brand_id = :id").',
                $index->sync->value,
            );
        }
        foreach ($index->watches as $watch) {
            if (!Identifier::isTable($watch->table)) {
                $v[] = sprintf('Watched table "%s" is not a valid identifier.', $watch->table);
            }
            if (!Identifier::isColumn($watch->keyColumn)) {
                $v[] = sprintf('Watch key column "%s" is not a valid column name.', $watch->keyColumn);
            }
            if (preg_match_all('/:id\b/', $watch->affectedIds) !== 1 || preg_match('/^\s*select\b/i', $watch->affectedIds) !== 1) {
                $v[] = sprintf('Watch on "%s": affectedIds must be a SELECT containing ":id" exactly once, e.g. "SELECT id FROM product WHERE brand_id = :id".', $watch->table);
            }
            foreach ($watch->columns ?? [] as $column) {
                if (!Identifier::isColumn($column)) {
                    $v[] = sprintf('Watch on "%s": column "%s" is not a valid column name.', $watch->table, $column);
                }
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
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * @internal The open class of an index that has at least one `granted` field: the fields nobody needs a role for. A viewer who
 * holds none of the granted fields (a restricted one, see SearchQuery::$allowedFields) can only match against the open fields,
 * so its candidates are looked up in two indexes over the open fields alone, a trigram GIN over their `z_` columns joined and a
 * GIN over their `t_` columns joined, instead of in `fz` and `tsv` that hold the granted text too. A common word that sits in a
 * large granted field then costs the viewer nothing (it is not in these indexes), and nothing about a granted field's content
 * decides what the viewer finds: the lookup only has to find a superset of the allowed fields' own matches, which the per-field
 * recheck then decides, exactly as it does on `fz` / `tsv`.
 *
 * An index without a granted field has none of this: no extra index, no changed statement.
 */
final readonly class OpenClass
{
    public const string TRIGRAM = 'open_fz';
    public const string VECTOR = 'open_tsv';

    /** @return list<FieldDefinition> the fields that are not granted, when the index has a granted field; else none */
    public static function fields(IndexDefinition $index): array
    {
        if (!array_any($index->fields, static fn(FieldDefinition $f): bool => $f->granted !== null)) {
            return [];
        }

        return array_values(array_filter($index->fields, static fn(FieldDefinition $f): bool => $f->granted === null));
    }

    /**
     * The expression the trigram index is over (`s.z_name || ' ' || s.z_category` with the alias `s.`, bare in the DDL), or null
     * when the index has no granted field or the open fields have no typo-tolerant one.
     */
    public static function trigram(IndexDefinition $index, Names $names, string $alias = ''): ?string
    {
        $columns = array_map(
            static fn(FieldDefinition $f): string => $alias . $names->fieldFuzzy($f->name),
            array_values(array_filter(self::fields($index), static fn(FieldDefinition $f): bool => $f->fuzzy)),
        );

        return self::joined($columns, " || ' ' || ");
    }

    /** The expression the vector index is over (the open fields' `t_` columns joined in the index's order), or null: see trigram(). */
    public static function vector(IndexDefinition $index, Names $names, string $alias = ''): ?string
    {
        return self::joined(array_map(static fn(FieldDefinition $f): string => $alias . $names->fieldVector($f->name), self::fields($index)), ' || ');
    }

    /** @return array<string, string> index name suffix => "USING ..." (what PostgresSchemaGenerator::indexes() adds) */
    public static function indexes(IndexDefinition $index, Names $names): array
    {
        $indexes = [];
        $trigram = self::trigram($index, $names);
        if ($trigram !== null) {
            $indexes[self::TRIGRAM] = sprintf('USING gin ((%s) %s.gin_trgm_ops)', $trigram, $names->extension());
        }
        $vector = self::vector($index, $names);
        if ($vector !== null) {
            $indexes[self::VECTOR] = sprintf('USING gin ((%s))', $vector);
        }

        return $indexes;
    }

    /** @param list<string> $columns */
    private static function joined(array $columns, string $glue): ?string
    {
        return $columns === [] ? null : implode($glue, $columns);
    }
}

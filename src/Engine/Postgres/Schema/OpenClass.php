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

    /**
     * @return array<string, string> index name suffix => "USING ..." (what PostgresSchemaGenerator::indexes() adds). The suffix ends
     *                               with a short hash of the definition: when the granted fields change, the expected index has a new
     *                               name, so `CREATE INDEX ... IF NOT EXISTS` builds it instead of keeping an old body, and the old
     *                               one is stale (see PostgresSchemaGenerator::staleOpenClassIndexes())
     */
    public static function indexes(IndexDefinition $index, Names $names): array
    {
        $indexes = [];
        $trigram = self::trigram($index, $names);
        if ($trigram !== null) {
            $definition = sprintf('USING gin ((%s) %s.gin_trgm_ops)', $trigram, $names->extension());
            $indexes[self::TRIGRAM . '_' . hash('crc32b', $definition)] = $definition;
        }
        $vector = self::vector($index, $names);
        if ($vector !== null) {
            $definition = sprintf('USING gin ((%s))', $vector);
            $indexes[self::VECTOR . '_' . hash('crc32b', $definition)] = $definition;
        }

        return $indexes;
    }

    /**
     * Prefixes of the names an open-class index of this sidecar can have (then any hash), for finding the stale ones: an index that
     * is one of these plus 8 characters (the hash) and is not among the expected ones was built for other granted fields. Empty when a name would be
     * shortened by the identifier limit (the hash is not at the end then, and a prefix could match other indexes).
     *
     * @return list<string>
     */
    public static function stalePrefixes(IndexDefinition $index, Names $names): array
    {
        $prefixes = [];
        foreach ([self::TRIGRAM, self::VECTOR] as $kind) {
            $prefix = $names->sidecarName($index) . '_' . $kind . '_';
            if (strlen($prefix) + 8 <= Names::MAX_IDENTIFIER_BYTES) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /** @return list<string> the names the open-class indexes of this definition have now (none without a granted field) */
    public static function expectedNames(IndexDefinition $index, Names $names): array
    {
        return array_map(static fn(string $suffix): string => $names->indexName($index, $suffix), array_keys(self::indexes($index, $names)));
    }

    /** @param list<string> $columns */
    private static function joined(array $columns, string $glue): ?string
    {
        return $columns === [] ? null : implode($glue, $columns);
    }
}

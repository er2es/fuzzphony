<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;

/**
 * @internal What fuzzphony_meta records about a definition. The definition hash covers what shapes
 * the DDL (written by schema --apply, compared by the doctor); the documents hash covers what shapes
 * the stored documents (written by a full reindex). Ranking settings shape neither.
 */
final class Fingerprint
{
    public static function definition(IndexDefinition $index): string
    {
        return self::hash([
            'source' => self::source($index),
            'fields' => self::fields($index),
            'filters' => self::filters($index),
            'id_type' => $index->idType->value,
            'watches' => array_map(static fn(Watch $w): array => [$w->table, $w->affectedIds, $w->keyColumn, $w->columns], $index->watches),
            'sync' => $index->sync->value,
            'trigger_level' => $index->triggerLevel->value,
            'text' => [$index->text->language, $index->text->unaccent],
            'boost' => $index->boostColumn,
            'recency' => $index->recencyColumn,
            'tenant' => $index->tenant,
        ]);
    }

    public static function documents(IndexDefinition $index): string
    {
        return self::hash([
            'source' => self::source($index),
            'fields' => self::fields($index),
            'filters' => self::filters($index),
            'text' => [$index->text->language, $index->text->unaccent],
            'boost' => $index->boostColumn,
            'recency' => $index->recencyColumn,
            // documents built for an older sidecar layout never match
            'layout' => PostgresSchemaGenerator::LAYOUT_VERSION,
        ]);
    }

    /** The shared objects (queue, normaliser, text configurations) depend only on where they live. */
    public static function shared(Names $names): string
    {
        return self::hash(['schema' => $names->schema, 'extension_schema' => $names->extensionSchema]);
    }

    /** @return array{string|null, string|null, string} */
    private static function source(IndexDefinition $index): array
    {
        return [$index->source->table, $index->source->query, $index->source->idColumn];
    }

    /** @return list<array{string, string, string, bool}> */
    private static function fields(IndexDefinition $index): array
    {
        return array_map(static fn(FieldDefinition $f): array => [$f->name, $f->column(), $f->weight->value, $f->fuzzy], $index->fields);
    }

    /** @return list<array{string, string, string}> */
    private static function filters(IndexDefinition $index): array
    {
        return array_map(static fn(FilterDefinition $f): array => [$f->name, $f->column(), $f->type->value], $index->filters);
    }

    /** @param array<string, mixed> $parts */
    private static function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}

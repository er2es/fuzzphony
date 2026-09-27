<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

/**
 * @internal The DBAL schema_filter that keeps Fuzzphony's objects out of Doctrine's schema tools,
 * so "doctrine:migrations:diff" never proposes dropping them. DBAL passes table names as
 * "table" (current schema) or "schema.table". A schema name is a plain identifier (Names rejects
 * anything else when the container is built), so it needs no regex quoting.
 */
final class SchemaAssetFilter
{
    public static function regex(string $schema): string
    {
        return $schema === 'public'
            ? '~^(?!(public\.)?fuzzphony_)~'
            : sprintf('~^(?!%s\.)~', $schema);
    }

    /** Shared table names to probe: the sync queue, the sidecar-layout marker, and a generic sidecar shape. */
    private const array TABLES = ['fuzzphony_queue', 'fuzzphony_meta', 'fuzzphony_x'];

    /**
     * Whether an application's own schema_filter keeps any of Fuzzphony's shared tables (as DBAL
     * would name them) visible to Doctrine's schema tools, i.e. has not merged regex($schema).
     */
    public static function letsThrough(string $applicationFilter, string $schema): bool
    {
        $names = $schema === 'public'
            ? array_merge(self::TABLES, array_map(static fn(string $t): string => 'public.' . $t, self::TABLES))
            : array_map(static fn(string $t): string => $schema . '.' . $t, self::TABLES);

        return array_any($names, static fn(string $name): bool => preg_match($applicationFilter, $name) === 1);
    }
}

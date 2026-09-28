<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The zero-downtime reindex of PostgresEngine (ADR 0008). A full rebuild fills a second
 * table (Names::shadow()) through its own refresh function while searches keep reading the live
 * one. A row trigger logs every change to the live table meanwhile (Names::changes()). finish()
 * refreshes the logged ids into the rebuild in batches, then takes ACCESS EXCLUSIVE on the live
 * table, which waits for every transaction that wrote it (so the log is complete), refreshes the
 * rest and swaps the tables in the same transaction. One rebuild per index at a time: a
 * session-level advisory lock, held from begin() until finish() or abort().
 */
final class ShadowRebuild
{
    /** Logged ids refreshed per statement while catching up outside the swap lock. */
    private const int CATCH_UP_BATCH = 5_000;

    private readonly Names $names;

    public function __construct(
        private readonly Connection $connection,
        private readonly PostgresSchemaGenerator $schema,
    ) {
        $this->names = $schema->names();
    }

    /**
     * Takes the lock and starts (or, with $resume, continues) the rebuild; false, with the lock
     * released, when the run must write the live index in place.
     */
    public function begin(IndexDefinition $index, bool $resume): bool
    {
        if (!(bool) $this->connection->fetchValue('SELECT pg_try_advisory_lock(hashtext(:key))', ['key' => $this->names->rebuildLockKey($index)])) {
            throw new InvalidArgument(sprintf('A rebuild of "%s" is already running.', $index->name));
        }
        try {
            $shadow = $resume ? $this->leftOver($index) : $this->possible($index);
            if ($shadow && !$resume) {
                $this->connection->execute($this->schema->beginRebuild($index));
            }
        } catch (\Throwable $e) {
            $this->unlock($index);

            throw $e;
        }
        if (!$shadow) {
            $this->unlock($index);
        }

        return $shadow;
    }

    /** @param list<int|string> $ids */
    public function refresh(IndexDefinition $index, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Coerce::int($this->connection->fetchValue(
            sprintf('SELECT %s(CAST(:ids AS %s[]))', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
            ['ids' => Sql::arrayLiteral($ids)],
        ));
    }

    public function finish(IndexDefinition $index): void
    {
        foreach ($this->schema->shadowIndexes($index) as $sql) {
            $this->connection->execute($sql);
        }
        do {
            $taken = $this->connection->transactional(fn(Connection $c): int => $this->catchUp($c, $index, self::CATCH_UP_BATCH));
        } while ($taken === self::CATCH_UP_BATCH);
        $this->connection->transactional(function (Connection $c) use ($index): void {
            // waits for every transaction that wrote the live table: after it, the log is complete
            $c->execute(sprintf('LOCK TABLE %s, %s IN ACCESS EXCLUSIVE MODE', $this->names->sidecar($index), $this->names->shadow($index)));
            $this->catchUp($c, $index, null);
            $c->execute($this->schema->swap($index));
        });
        $this->unlock($index);
    }

    public function abort(IndexDefinition $index, bool $keepShadow): void
    {
        if (!$keepShadow) {
            $this->connection->execute($this->schema->discardRebuild($index));
        }
        $this->unlock($index);
    }

    /** A resumed run continues the rebuild a failed run left behind. */
    private function leftOver(IndexDefinition $index): bool
    {
        return (bool) $this->connection->fetchValue(
            'SELECT to_regclass(:shadow) IS NOT NULL AND to_regclass(:changes) IS NOT NULL',
            ['shadow' => $this->names->shadow($index), 'changes' => $this->names->changes($index)],
        );
    }

    /**
     * Whether this role can build next to the live table (create tables in Fuzzphony's schema;
     * drop and replace the live one: its owner or a member of the owning role) and schema --apply
     * created the rebuild's functions. NULL (no live table) counts as no.
     */
    private function possible(IndexDefinition $index): bool
    {
        return (bool) $this->connection->fetchValue(
            <<<'SQL'
                SELECT has_schema_privilege(:schema, 'CREATE')
                   AND pg_has_role(c.relowner, 'USAGE')
                   AND to_regprocedure(:refresh) IS NOT NULL
                   AND to_regprocedure(:track) IS NOT NULL
                FROM pg_class AS c
                WHERE c.oid = to_regclass(:sidecar)
                SQL,
            [
                'schema' => $this->names->schema,
                'refresh' => sprintf('%s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
                'track' => $this->names->trackFunction($index) . '()',
                'sidecar' => $this->names->sidecar($index),
            ],
        );
    }

    /** Refreshes up to $limit logged ids (null: all) into the rebuild, in one statement: a failed refresh keeps them logged. */
    private function catchUp(Connection $c, IndexDefinition $index, ?int $limit): int
    {
        $changes = $this->names->changes($index);
        $sql = sprintf(
            <<<'SQL'
                WITH batch AS (
                    DELETE FROM %1$s%2$s
                    RETURNING id
                ), refreshed AS (
                    SELECT %3$s(ARRAY(SELECT id FROM batch)) AS written
                )
                SELECT (SELECT count(*) FROM batch) FROM refreshed
                SQL,
            $changes,
            $limit === null ? '' : sprintf(' WHERE id IN (SELECT id FROM %s ORDER BY id LIMIT :limit)', $changes),
            $this->names->shadowRefreshFunction($index),
        );

        return Coerce::int($c->fetchValue($sql, $limit === null ? [] : ['limit' => $limit]));
    }

    private function unlock(IndexDefinition $index): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock(hashtext(:key))', ['key' => $this->names->rebuildLockKey($index)]);
    }
}

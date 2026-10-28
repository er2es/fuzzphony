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
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal Keeping the index current: refreshing documents, listing the source's ids, pruning orphans, the work queue and the
 * records of a (shadow) rebuild. Every statement is guarded with the remedy for the failure it most likely meets.
 */
final readonly class IndexMaintenance
{
    public function __construct(
        private Connection $connection,
        private Names $names,
        private PostgresSchemaGenerator $schema,
        private ShadowRebuild $rebuild,
        private EngineGuard $guard,
    ) {}

    /** @param list<int|string> $ids */
    public function refresh(IndexDefinition $index, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->guard->run('refresh', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT %s(CAST(:ids AS %s[]))', $this->names->refreshFunction($index), Types::id($index->idType)),
            ['ids' => Sql::arrayLiteral(array_values($ids))],
        )), 'Run "fuzzphony:schema --apply" to create the refresh function, then "fuzzphony:doctor".');
    }

    /** @return list<int|string> */
    public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array
    {
        $params = ['limit' => $limit];
        $where = '';
        if ($after !== null) {
            $where = sprintf('WHERE doc.fz_id > CAST(:after AS %s)', Types::id($index->idType));
            $params['after'] = (string) $after;
        }
        $rows = $this->guard->run('source ids', fn(): array => $this->connection->fetchAll(sprintf(
            'SELECT doc.fz_id::text AS id FROM (%s) AS doc %s ORDER BY doc.fz_id LIMIT :limit',
            DocumentSql::select($index),
            $where,
        ), $params), 'Run "bin/console fuzzphony:doctor": it checks that the source can be queried.');

        return array_map(static fn(array $row): int|string => $index->idType->cast(Coerce::str($row['id'])), $rows);
    }

    public function pruneOrphans(IndexDefinition $index, int $batchSize): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgument('Batch size must be >= 1.');
        }
        // Keyset pagination over the sidecar: each statement checks (and locks) at most one batch,
        // and the whole run reads every indexed id once. Same anti-join as the refresh function.
        $sidecar = $this->names->sidecar($index);
        $sql = static fn(bool $first): string => sprintf(
            <<<'SQL'
                WITH batch AS (
                    SELECT s.id FROM %1$s AS s %2$s ORDER BY s.id LIMIT :limit
                ), removed AS (
                    DELETE FROM %1$s AS s
                    USING batch AS b
                    WHERE s.id = b.id
                      AND NOT EXISTS (SELECT 1 FROM (%3$s) AS doc WHERE doc.fz_id = b.id)
                    RETURNING 1
                )
                SELECT (SELECT count(*) FROM batch) AS scanned,
                       (SELECT b.id::text FROM batch AS b ORDER BY b.id DESC LIMIT 1) AS last,
                       (SELECT count(*) FROM removed) AS removed
                SQL,
            $sidecar,
            $first ? '' : sprintf('WHERE s.id > CAST(:after AS %s)', Types::id($index->idType)),
            DocumentSql::select($index),
        );

        return $this->guard->run('orphan pruning', function () use ($index, $sql, $batchSize): int {
            $removed = 0;
            $after = null;
            do {
                $params = ['limit' => $batchSize];
                if ($after !== null) {
                    $params['after'] = $after;
                }
                $row = $this->connection->fetchAll($sql($after === null), $params)[0];
                $removed += Coerce::int($row['removed']);
                $after = $row['last'] === null ? null : Coerce::str($row['last']);
            } while ($after !== null && Coerce::int($row['scanned']) === $batchSize);
            // the end of a full in-place run
            $this->rebuild->complete($index);

            return $removed;
        }, Hints::SCHEMA_AND_DOCTOR);
    }

    public function recordReindex(IndexDefinition $index): void
    {
        // a missing table is a no-op (the statement checks), so a failure is almost always a missing privilege
        $this->guard->run(
            'reindex record',
            fn(): int => $this->connection->execute($this->schema->reindexed($index)),
            sprintf('The role running the reindex needs SELECT and UPDATE on %s.', $this->names->meta()),
        );
    }

    public function processQueue(IndexDefinition $index, int $limit): int
    {
        // Taking the batch and refreshing it happen in ONE statement and transaction:
        // if the refresh fails, the ids stay in the queue. SKIP LOCKED lets workers run in parallel.
        $sql = sprintf(
            <<<'SQL'
                WITH batch AS (
                    DELETE FROM %1$s
                    WHERE (index_name, doc_id) IN (
                        SELECT index_name, doc_id FROM %1$s
                        WHERE index_name = :index AND doc_id <> '*'
                        ORDER BY queued_at
                        LIMIT :limit
                        FOR UPDATE SKIP LOCKED
                    )
                    RETURNING doc_id
                ), refreshed AS (
                    SELECT %2$s(ARRAY(SELECT doc_id::%3$s FROM batch)) AS written
                )
                SELECT (SELECT count(*) FROM batch) FROM refreshed
                SQL,
            $this->names->queue(),
            $this->names->refreshFunction($index),
            Types::id($index->idType),
        );

        return $this->guard->run(
            'queue processing',
            fn(): int => Coerce::int($this->connection->transactional(
                static fn(Connection $c): mixed => $c->fetchValue($sql, ['index' => $index->name, 'limit' => $limit]),
            )),
            Hints::SCHEMA_AND_DOCTOR,
        );
    }

    public function queueSize(IndexDefinition $index): int
    {
        return $this->guard->run('queue size', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT count(*) FROM %s WHERE index_name = :index', $this->names->queue()),
            ['index' => $index->name],
        )), 'Run "fuzzphony:schema --apply" to create the queue table.');
    }

    public function rebuildRequested(IndexDefinition $index): bool
    {
        return $this->guard->run('queue processing', fn(): bool => (bool) $this->connection->fetchValue(
            sprintf("SELECT EXISTS (SELECT 1 FROM %s WHERE index_name = :index AND doc_id = '*')", $this->names->queue()),
            ['index' => $index->name],
        ), Hints::SCHEMA_AND_DOCTOR);
    }

    public function recordRebuildFailure(IndexDefinition $index, string $message): void
    {
        $this->guard->run('rebuild failure record', fn(): int => $this->connection->execute(
            sprintf('UPDATE %s SET rebuild_failed_at = now(), rebuild_failures = coalesce(rebuild_failures, 0) + 1, rebuild_error = :message WHERE index_name = :index', $this->names->meta()),
            ['index' => $index->name, 'message' => $message],
        ), sprintf('Run "fuzzphony:schema --apply" (it adds the failure columns); the worker role needs SELECT and UPDATE on %s.', $this->names->meta()));
    }

    public function beginRebuild(IndexDefinition $index, bool $resume): bool
    {
        return $this->guard->run('rebuild', fn(): bool => $this->rebuild->begin($index, $resume), Hints::REBUILD);
    }

    /** @param list<int|string> $ids */
    public function refreshShadow(IndexDefinition $index, array $ids): int
    {
        return $this->guard->run('rebuild', fn(): int => $this->rebuild->refresh($index, $ids), Hints::REBUILD);
    }

    public function finishRebuild(IndexDefinition $index): void
    {
        $this->guard->run('rebuild', function () use ($index): null {
            $this->rebuild->finish($index);

            return null;
        }, Hints::REBUILD);
    }

    public function abortRebuild(IndexDefinition $index, bool $keepShadow): void
    {
        $this->guard->run('rebuild', function () use ($index, $keepShadow): null {
            $this->rebuild->abort($index, $keepShadow);

            return null;
        }, Hints::REBUILD);
    }

    public function discardLeftoverRebuild(IndexDefinition $index): bool
    {
        return $this->guard->run('rebuild', fn(): bool => $this->rebuild->discardLeftover($index), Hints::REBUILD);
    }
}

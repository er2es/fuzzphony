<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Support\ErrorSummary;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\SharedWatch;
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
        if ($index->isMultiSource()) {
            return $this->multiSourceIds($index, $after === null ? null : (string) $after, $limit);
        }
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

    /**
     * The stored keys of a multi-source index, source by source (in declaration order) and by the source's own key inside it, so a
     * batch reads one source's index in order instead of sorting the union. `$after` is the last key of the previous batch.
     *
     * @return list<string>
     */
    private function multiSourceIds(IndexDefinition $index, ?string $after, int $limit): array
    {
        $sources = $index->activeSources();
        $from = 0;
        $afterId = null;
        if ($after !== null) {
            $kind = explode(':', $after, 2)[0];
            foreach ($sources as $i => $source) {
                if ($source->name === $kind) {
                    $from = $i;
                    $afterId = substr($after, strlen($kind) + 1);
                }
            }
        }
        $keys = [];
        foreach (array_slice($sources, $from) as $i => $source) {
            $params = ['limit' => $limit - count($keys)];
            $where = '';
            if ($i === 0 && $afterId !== null) {
                $where = sprintf('WHERE d.%s > CAST(:after AS %s)', Sql::ident($source->source->idColumn), Types::id($source->idType));
                $params['after'] = $afterId;
            }
            $rows = $this->guard->run('source ids', fn(): array => $this->connection->fetchAll(sprintf(
                'SELECT d.%1$s::text AS id FROM (%2$s) AS d %3$s ORDER BY d.%1$s LIMIT :limit',
                Sql::ident($source->source->idColumn),
                $source->source->query ?? 'SELECT * FROM ' . Sql::ident((string) $source->source->table),
                $where,
            ), $params), 'Run "bin/console fuzzphony:doctor": it checks that the source can be queried.');
            foreach ($rows as $row) {
                $keys[] = $source->key(Coerce::str($row['id']));
            }
            if (count($keys) >= $limit) {
                break;
            }
        }

        return $keys;
    }

    public function pruneOrphans(IndexDefinition $index, int $batchSize): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgument('Batch size must be >= 1.');
        }
        // Keyset pagination over the sidecar: each statement checks (and locks) at most one batch,
        // and the whole run reads every indexed id once. Same anti-join as the refresh function.
        $sidecar = $this->names->sidecar($index);
        if ($index->isMultiSource()) {
            // the documents of the batch only are looked up (each kind's part in its own source); a narrowed run touches one kind
            $kind = $index->onlySource;
            $sql = fn(bool $first): string => sprintf(
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
                self::where($first ? null : 's.id > CAST(:after AS text)', $kind === null ? null : 's.kind = ' . Sql::string($kind)),
                DocumentSql::select($index, '(SELECT array_agg(x.id) FROM batch AS x)'),
            );

            return $this->pruneBatches($index, $sql, $batchSize);
        }
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

        $removed = $this->pruneBatches($index, $sql, $batchSize);
        if ($index->manyFields() !== []) {
            // the values rows of the documents just removed (and of any that lost theirs some other way)
            $this->guard->run('orphan pruning', fn(): int => $this->connection->execute(sprintf(
                'DELETE FROM %1$s AS v WHERE NOT EXISTS (SELECT 1 FROM %2$s AS s WHERE s.id = v.doc)',
                $this->names->values($index),
                $sidecar,
            )), Hints::SCHEMA_AND_DOCTOR);
        }

        return $removed;
    }

    /** @param \Closure(bool): string $sql the batch statement ($first: no keyset condition yet) */
    private function pruneBatches(IndexDefinition $index, \Closure $sql, int $batchSize): int
    {
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

    private static function where(?string ...$conditions): string
    {
        $conditions = array_filter($conditions, static fn(?string $c): bool => $c !== null);

        return $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
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

    /**
     * The condition under which a queued item is taken: one that failed waits for its back-off (60 s, doubling to an hour per
     * failure in a row). Unqualified columns, so it fits the batch statement and the claim of the split alike.
     */
    private const string DUE = "(failed_at IS NULL OR failed_at + least(3600, 60 * power(2, least(attempts, 10) - 1)) * interval '1 second' <= clock_timestamp())";

    /**
     * Moves what the shared trigger logged for the index into its queue (queue sync, statement-level triggers); a no-op for the
     * other modes. Several workers may call it at once.
     */
    public function drainChanges(IndexDefinition $index): int
    {
        if (!SharedWatch::applies($index)) {
            return 0;
        }

        return $this->guard->run('queue processing', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT %s()', $this->names->drainFunction($index)),
        )), Hints::SCHEMA_AND_DOCTOR);
    }

    public function processQueue(IndexDefinition $index, int $limit): int
    {
        $this->drainChanges($index);
        // Taking the batch and refreshing it happen in ONE statement and transaction:
        // if the refresh fails, the ids stay in the queue. SKIP LOCKED lets workers run in parallel.
        $sql = sprintf(
            <<<'SQL'
                WITH batch AS (
                    DELETE FROM %1$s
                    WHERE (index_name, doc_id) IN (
                        SELECT index_name, doc_id FROM %1$s
                        WHERE index_name = :index AND %5$s AND %4$s
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
            self::DUE,
            self::notARequest($index),
        );

        return $this->guard->run(
            'queue processing',
            function () use ($index, $limit, $sql): int {
                try {
                    return Coerce::int($this->connection->transactional(
                        static fn(Connection $c): mixed => $c->fetchValue($sql, ['index' => $index->name, 'limit' => $limit]),
                    ));
                } catch (\Throwable $e) {
                    if (!ErrorSummary::isDataError($e)) {
                        throw $e;
                    }

                    // one document the refresh cannot build must not hold the others back
                    return $this->processSplit($index, $limit);
                }
            },
            Hints::SCHEMA_AND_DOCTOR,
        );
    }

    /**
     * A batch whose refresh failed on the data: takes it again, refreshes it in halves down to single ids, removes what was
     * refreshed from the queue and keeps only the ids that fail on their own (with the error class and SQLSTATE, never the
     * driver's message: it can carry the source row). Those wait for a back-off before they are tried again.
     *
     * @return int the ids refreshed
     */
    private function processSplit(IndexDefinition $index, int $limit): int
    {
        return Coerce::int($this->connection->transactional(function (Connection $c) use ($index, $limit): int {
            $rows = $c->fetchAll(sprintf(
                'SELECT doc_id FROM %s WHERE index_name = :index AND %s AND %s ORDER BY queued_at LIMIT :limit FOR UPDATE SKIP LOCKED',
                $this->names->queue(),
                self::notARequest($index),
                self::DUE,
            ), ['index' => $index->name, 'limit' => $limit]);
            $ids = array_map(static fn(array $row): string => Coerce::str($row['doc_id']), $rows);
            $failed = [];
            $done = $this->refreshHalves($c, $index, $ids, $failed);
            if ($done !== []) {
                $c->execute(sprintf('DELETE FROM %s WHERE index_name = :index AND doc_id = ANY (CAST(:ids AS text[]))', $this->names->queue()), ['index' => $index->name, 'ids' => Sql::arrayLiteral($done)]);
            }
            foreach ($failed as $id => $error) {
                $c->execute(
                    sprintf('UPDATE %s SET attempts = attempts + 1, last_error = :error, failed_at = clock_timestamp() WHERE index_name = :index AND doc_id = :id', $this->names->queue()),
                    ['index' => $index->name, 'id' => (string) $id, 'error' => $error],
                );
            }

            return count($done);
        }));
    }

    /**
     * @param list<string>          $ids
     * @param array<string, string> $failed the ids that fail on their own, with the error class and SQLSTATE
     *
     * @return list<string> the ids refreshed (inside the open transaction)
     */
    private function refreshHalves(Connection $c, IndexDefinition $index, array $ids, array &$failed): array
    {
        if ($ids === []) {
            return [];
        }
        $c->execute('SAVEPOINT fz_split');
        try {
            $c->fetchValue(
                sprintf('SELECT %s(CAST(:ids AS %s[]))', $this->names->refreshFunction($index), Types::id($index->idType)),
                ['ids' => Sql::arrayLiteral($ids)],
            );
            $c->execute('RELEASE SAVEPOINT fz_split');

            return $ids;
        } catch (\Throwable $e) {
            $c->execute('ROLLBACK TO SAVEPOINT fz_split');
            $c->execute('RELEASE SAVEPOINT fz_split');
            if (!ErrorSummary::isDataError($e)) {
                throw $e;
            }
            if (count($ids) === 1) {
                $failed[$ids[0]] = ErrorSummary::describe($e);

                return [];
            }
        }
        $half = intdiv(count($ids), 2);

        return [
            ...$this->refreshHalves($c, $index, array_slice($ids, 0, $half), $failed),
            ...$this->refreshHalves($c, $index, array_slice($ids, $half), $failed),
        ];
    }

    /** The items waiting: the queue plus what the shared trigger logged and the index has not drained yet. */
    public function queueSize(IndexDefinition $index): int
    {
        return $this->guard->run('queue size', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT count(*) FROM %s WHERE index_name = :index', $this->names->queue()),
            ['index' => $index->name],
        )) + $this->pendingChanges($index), 'Run "fuzzphony:schema --apply" to create the queue table.');
    }

    /**
     * The gauges of `OperationalStats`: one statement each, on the small queue, watches and change-log tables (the queue by its
     * (index_name, queued_at) index, the log by the entries past the index's cursor). A statement that fails (a table or column of an
     * older schema) leaves its gauges out; the worker never stops over a metric.
     *
     * @return array<string, float>
     */
    public function operationalStats(IndexDefinition $index): array
    {
        $stats = [];
        try {
            $row = $this->connection->fetchAll(
                sprintf("SELECT count(*) FILTER (WHERE attempts > 0 AND doc_id <> '*') AS failing, coalesce(extract(epoch FROM now() - min(queued_at)), 0)::bigint AS oldest FROM %s WHERE index_name = :index", $this->names->queue()),
                ['index' => $index->name],
            )[0] ?? null;
            if ($row !== null) {
                $stats['fuzzphony.queue.failing_items'] = (float) Coerce::int($row['failing']);
                $stats['fuzzphony.queue.oldest_age_seconds'] = (float) Coerce::int($row['oldest']);
            }
        } catch (\Throwable) {
        }
        if (!SharedWatch::applies($index)) {
            return $stats;
        }
        try {
            $row = $this->connection->fetchAll(
                sprintf('SELECT count(l.tbl) AS entries, coalesce(extract(epoch FROM now() - min(l.at)), 0)::bigint AS oldest FROM %s AS w LEFT JOIN %s AS l ON l.tbl = w.tbl AND l.xid >= w.cur_xid WHERE w.index_name = :index', $this->names->watches(), $this->names->watchLog()),
                ['index' => $index->name],
            )[0] ?? null;
            if ($row !== null) {
                $stats['fuzzphony.watch_log.entries'] = (float) Coerce::int($row['entries']);
                $stats['fuzzphony.watch_log.oldest_age_seconds'] = (float) Coerce::int($row['oldest']);
            }
        } catch (\Throwable) {
        }
        try {
            $stats['fuzzphony.watch.bad_rows'] = (float) Coerce::int($this->connection->fetchValue(
                sprintf('SELECT coalesce(sum(bad_rows), 0)::bigint FROM %s WHERE index_name = :index', $this->names->watches()),
                ['index' => $index->name],
            ));
        } catch (\Throwable) {
        }

        return $stats;
    }

    /** The entries of the shared log the index has not drained yet (they become queue rows at its next drain). */
    public function pendingChanges(IndexDefinition $index): int
    {
        if (!SharedWatch::applies($index)) {
            return 0;
        }

        return Coerce::int($this->connection->fetchValue(
            sprintf('SELECT count(*) FROM %s AS w JOIN %s AS l ON l.tbl = w.tbl AND l.xid >= w.cur_xid WHERE w.index_name = :index', $this->names->watches(), $this->names->watchLog()),
            ['index' => $index->name],
        ));
    }

    /** The queue rows that are documents: not the rebuild requests (`*`, and for a multi-source index `*:<kind>`). */
    private static function notARequest(IndexDefinition $index): string
    {
        return $index->isMultiSource() ? "doc_id <> '*' AND NOT starts_with(doc_id, '*:')" : "doc_id <> '*'";
    }

    /**
     * The sources of a multi-source index a TRUNCATE asked a rebuild of (queue rows `*:<kind>`); empty for the other indexes, and
     * when the whole index is to be rebuilt (the `*` row, see rebuildRequested()).
     *
     * @return list<string>
     */
    public function rebuildRequestedSources(IndexDefinition $index): array
    {
        if (!$index->isMultiSource()) {
            return [];
        }
        try {
            $this->drainChanges($index);
        } catch (\Throwable) {
            // the queue as it is decides
        }
        $rows = $this->guard->run('queue processing', fn(): array => $this->connection->fetchAll(
            sprintf("SELECT substr(doc_id, 3) AS kind FROM %s WHERE index_name = :index AND starts_with(doc_id, '*:')", $this->names->queue()),
            ['index' => $index->name],
        ), Hints::SCHEMA_AND_DOCTOR);
        $requested = array_map(static fn(array $row): string => Coerce::str($row['kind']), $rows);

        return array_values(array_filter(array_map(static fn($source): string => $source->name, $index->sources), static fn(string $name): bool => in_array($name, $requested, true)));
    }

    public function rebuildRequested(IndexDefinition $index): bool
    {
        // a TRUNCATE the shared trigger logged is a queue row only after the drain (a drain that fails is reported by processQueue(), which drains again)
        try {
            $this->drainChanges($index);
        } catch (\Throwable) {
            // the queue as it is decides
        }

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

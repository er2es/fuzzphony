<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\EngineFailure;
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
 * session-level advisory lock, held from begin() until finish() or abort(). A logged id whose
 * writer has not committed yet is locked (the log's ON CONFLICT DO UPDATE): the batches skip it,
 * the final catch-up under the lock takes it.
 */
final class ShadowRebuild
{
    /** Logged ids refreshed per statement while catching up outside the swap lock. */
    private const int CATCH_UP_BATCH = 5_000;

    /** At most this many batches before the swap: the rest (a write rate above the batch rate) is refreshed under the lock. */
    private const int CATCH_UP_MAX_BATCHES = 20;

    /**
     * How long the swap waits for the live table's lock. Above deadlock_timeout (1s by default):
     * PostgreSQL cancels an autovacuum that blocks the lock only after that.
     */
    private const string LOCK_TIMEOUT = '3s';

    /** Swap attempts after the first one that timed out on the lock. */
    private const int SWAP_RETRIES = 5;

    private const string LOCK_NOT_AVAILABLE = '55P03';

    private readonly Names $names;

    /** @var \Closure(int): void */
    private readonly \Closure $pause;

    /** @param (\Closure(int): void)|null $pause sleeps the given microseconds between swap attempts */
    public function __construct(
        private readonly Connection $connection,
        private readonly PostgresSchemaGenerator $schema,
        private readonly string $lockTimeout = self::LOCK_TIMEOUT,
        ?\Closure $pause = null,
    ) {
        $this->names = $schema->names();
        $this->pause = $pause ?? usleep(...);
    }

    /**
     * Takes the lock and starts (or, with $resume, continues) the rebuild; false, with the lock
     * released, when the run must write the live index in place. Inside a caller transaction it
     * is always false (nothing is locked or created): the batches and the swap would join that
     * transaction, which would hold the swap's ACCESS EXCLUSIVE lock until the caller commits.
     */
    public function begin(IndexDefinition $index, bool $resume): bool
    {
        if ($this->inTransaction()) {
            return false;
        }
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

    /**
     * Throws, keeping the rebuild and the lock (the caller aborts with $keepShadow, or calls this
     * again), when the swap fails; on a lock timeout only after SWAP_RETRIES more attempts, each
     * after one more batch and a back-off.
     */
    public function finish(IndexDefinition $index): void
    {
        foreach ($this->schema->shadowIndexes($index) as $sql) {
            $this->connection->execute($sql);
        }
        $batches = 0;
        do {
            $taken = $this->batch($index);
        } while ($taken === self::CATCH_UP_BATCH && ++$batches < self::CATCH_UP_MAX_BATCHES);
        for ($attempt = 0; ($timedOut = $this->swap($index)) !== null; ++$attempt) {
            if ($attempt === self::SWAP_RETRIES) {
                throw new EngineFailure(sprintf(
                    'Fuzzphony rebuild of "%1$s" failed: the swap could not lock %2$s within %3$s, %4$d times (long transactions or autovacuum hold it). The rebuild was kept: call finishRebuild() again, or resume the reindex ("fuzzphony:reindex %1$s --from …").',
                    $index->name,
                    $this->names->sidecar($index),
                    $this->lockTimeout,
                    self::SWAP_RETRIES + 1,
                ), previous: $timedOut);
            }
            $this->batch($index);
            ($this->pause)(self::backoff($attempt, mt_rand() / mt_getrandmax()));
        }
        $this->unlock($index);
    }

    /** Microseconds to wait before swap attempt $attempt + 2: 0.1 s doubling, plus up to as much jitter ($random: 0 to 1). */
    public static function backoff(int $attempt, float $random): int
    {
        return (int) ((100_000 << $attempt) * (1 + $random));
    }

    public function abort(IndexDefinition $index, bool $keepShadow): void
    {
        if (!$keepShadow) {
            $this->connection->execute($this->schema->discardRebuild($index));
        }
        $this->unlock($index);
    }

    /**
     * Whether this session is inside a transaction block, from SQL (the Connection port cannot
     * tell): a transaction-local setting outlives its statement only inside one.
     */
    private function inTransaction(): bool
    {
        $this->connection->fetchValue("SELECT set_config('fuzzphony.transaction_probe', 'on', true)");

        return $this->connection->fetchValue("SELECT current_setting('fuzzphony.transaction_probe', true)") === 'on';
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
     * drop and replace the live one: its owner or a member of the owning role), hand the rebuild
     * to the live table's owner (ALTER TABLE ... OWNER TO: SET membership, CREATE on the schema for
     * the owner; SET membership is PostgreSQL 16+, plain membership before) and schema --apply
     * created the rebuild's functions. NULL (no live table) counts as no.
     */
    private function possible(IndexDefinition $index): bool
    {
        return (bool) $this->connection->fetchValue(
            <<<'SQL'
                SELECT has_schema_privilege(:schema, 'CREATE')
                   AND has_schema_privilege(c.relowner, :owner_schema, 'CREATE')
                   AND pg_has_role(c.relowner, 'USAGE')
                   AND pg_has_role(c.relowner, CASE WHEN current_setting('server_version_num')::integer >= 160000 THEN 'SET' ELSE 'MEMBER' END)
                   AND to_regprocedure(:refresh) IS NOT NULL
                   AND to_regprocedure(:track) IS NOT NULL
                FROM pg_class AS c
                WHERE c.oid = to_regclass(:sidecar)
                SQL,
            [
                'schema' => $this->names->schema,
                'owner_schema' => $this->names->schema,
                'refresh' => sprintf('%s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
                'track' => $this->names->trackFunction($index) . '()',
                'sidecar' => $this->names->sidecar($index),
            ],
        );
    }

    private function batch(IndexDefinition $index): int
    {
        return $this->connection->transactional(fn(Connection $c): int => $this->catchUp($c, $index, self::CATCH_UP_BATCH));
    }

    /** One swap attempt; the lock timeout when it could not take the lock (nothing changed), null when it swapped. */
    private function swap(IndexDefinition $index): ?\Throwable
    {
        try {
            $this->connection->transactional(function (Connection $c) use ($index): void {
                $c->execute(sprintf('SET LOCAL lock_timeout = %s', Sql::string($this->lockTimeout)));
                // waits for every transaction that wrote the live table: after it, the log is complete
                $c->execute(sprintf('LOCK TABLE %s, %s IN ACCESS EXCLUSIVE MODE', $this->names->sidecar($index), $this->names->shadow($index)));
                $this->catchUp($c, $index, null);
                $c->execute($this->schema->swap($index));
            });
        } catch (\Throwable $e) {
            if (!self::lockNotAvailable($e)) {
                throw $e;
            }
            return $e;
        }

        return null;
    }

    /** SQLSTATE 55P03, from PDO (the code) or DBAL (getSQLState()), anywhere in the chain. */
    private static function lockNotAvailable(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $state = $cause instanceof \PDOException ? $cause->getCode() : (method_exists($cause, 'getSQLState') ? $cause->getSQLState() : null);
            if ($state === self::LOCK_NOT_AVAILABLE) {
                return true;
            }
        }

        return false;
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
            $limit === null ? '' : sprintf(' WHERE id IN (SELECT id FROM %s ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED)', $changes),
            $this->names->shadowRefreshFunction($index),
        );

        return Coerce::int($c->fetchValue($sql, $limit === null ? [] : ['limit' => $limit]));
    }

    private function unlock(IndexDefinition $index): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock(hashtext(:key))', ['key' => $this->names->rebuildLockKey($index)]);
    }
}

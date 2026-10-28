<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Support\Coerce;

/**
 * @internal The connection of the searches of one engine and the pg_trgm settings they need.
 *
 * Runs the statements of a search in ONE transaction when this engine may own it (inTransaction()), instead of one transaction
 * per statement that sets a setting (BEGIN, the setting, the statement, COMMIT: four round trips for one SELECT). Each statement
 * still sets what it needs (settle()), so none sees another's value, and the settings are transaction-local, so COMMIT leaves the
 * session as it was. When the caller has a transaction open, or the connection cannot say, nothing is opened here: each statement
 * does what it always did (its own setting, and the previous value put back).
 */
final class SearchSession
{
    public const string WORD_SIMILARITY = 'pg_trgm.word_similarity_threshold';
    public const string SIMILARITY = 'pg_trgm.similarity_threshold';

    /**
     * Set while this session itself holds the transaction of a search (see inTransaction()): the pg_trgm settings it has set
     * in it, by name, with the value the session had before (original) and the one in force now (current). Null outside one.
     *
     * @var array<string, array{original: string|null, current: string|null}>|null
     */
    private ?array $settings = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EngineGuard $guard,
    ) {}

    /** Whether this session holds the transaction of a search right now. */
    public function ownsTransaction(): bool
    {
        return $this->settings !== null;
    }

    /**
     * Whether the caller may have a transaction open (or the connection cannot say): a setting made inside it must be put back, and
     * a catalog answer is not to be trusted to stay true.
     */
    public function callerMayHoldTransaction(): bool
    {
        return !($this->connection instanceof TransactionAware) || $this->connection->inTransaction();
    }

    /**
     * Runs $work, the statements of one search, in ONE transaction when this session may own it (see the class comment).
     *
     * A statement that fails ends the search with its EngineFailure and rolls the transaction back, as it did when the
     * transaction was its own; no statement of a search is allowed to fail and be ignored, so nothing is left to poison.
     * A failing BEGIN or COMMIT is an EngineFailure of the search too.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function inTransaction(\Closure $work): mixed
    {
        if ($this->settings !== null || !$this->connection instanceof TransactionAware || $this->connection->inTransaction()) {
            return $work();
        }
        $state = new \stdClass();
        $state->entered = false;
        $state->thrown = null;
        try {
            return $this->connection->transactional(function () use ($work, $state): mixed {
                $state->entered = true;
                $this->settings = [];
                try {
                    return $work();
                } catch (\Throwable $e) {
                    $state->thrown = $e;

                    throw $e;
                } finally {
                    $this->settings = null;
                }
            });
        } catch (FuzzphonyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($state->entered === true && $e === $state->thrown) {
                throw $e; // not the database's: it passes as it would without a transaction
            }
            // BEGIN, COMMIT or ROLLBACK failed
            $this->guard->metrics()->increment('fuzzphony.search.errors');

            throw EngineFailure::wrap('search', $e, Hints::INDEX);
        }
    }

    /**
     * One statement of a search, at the word similarity it needs (null: it needs none).
     *
     * @return list<array<string, mixed>>
     */
    public function run(Statement $statement, ?float $wordSimilarity): array
    {
        return $this->guard->run('search', fn(): array => $this->fetch($statement->sql, $statement->params, $wordSimilarity, self::WORD_SIMILARITY, $statement->readsWordThreshold), Hints::INDEX);
    }

    /**
     * A candidate probe, which is not a Statement of the search: it holds a `<%` and sets its own similarity.
     *
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function runProbe(string $sql, array $params, float $wordSimilarity): array
    {
        return $this->guard->run('search', fn(): array => $this->fetch($sql, $params, $wordSimilarity, self::WORD_SIMILARITY, true), Hints::INDEX);
    }

    /**
     * One statement, with the pg_trgm setting $name at $value (null: it needs none).
     *
     * Inside the search transaction the setting is made there; otherwise a statement with a setting is wrapped in a transaction of its
     * own (and joins the caller's, putting the previous value back).
     *
     * @param array<string, scalar|null> $params
     * @param bool                       $readsWordThreshold whether the statement compares words with `<%` (the only operator that reads
     *                                                       pg_trgm.word_similarity_threshold), declared by its Statement
     *
     * @return list<array<string, mixed>>
     */
    public function fetch(string $sql, array $params, ?float $value, string $name, bool $readsWordThreshold = false): array
    {
        if ($this->settings !== null) {
            $this->settle($value, $name, $readsWordThreshold);

            return $this->connection->fetchAll($sql, $params);
        }
        if ($value === null) {
            // nothing to set, so nothing to scope to a transaction: a single SELECT needs no BEGIN and COMMIT (two round trips)
            return $this->connection->fetchAll($sql, $params);
        }

        // Computed before transactional() opens (or joins) a transaction, so it reflects whether
        // the *caller* already had one open, not the one this call is about to start itself.
        $restore = $this->callerMayHoldTransaction();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->transactional(
            static fn(Connection $c) => self::withSimilarityThreshold($c, $value, $restore, static fn() => $c->fetchAll($sql, $params), $name),
        );

        return $rows;
    }

    /**
     * The rows of EXPLAIN [ANALYZE] of a statement, at the word similarity it ran with.
     *
     * @return list<string>
     */
    public function plan(Statement $statement, ?float $wordSimilarity, bool $analyze): array
    {
        $restore = $this->callerMayHoldTransaction();

        /** @var list<string> $plan */
        $plan = $this->connection->transactional(fn(Connection $c) => self::withSimilarityThreshold(
            $c,
            $wordSimilarity,
            $restore,
            static function () use ($c, $statement, $analyze) {
                $rows = $c->fetchAll(($analyze ? 'EXPLAIN (ANALYZE, BUFFERS) ' : 'EXPLAIN ') . $statement->sql, $statement->params);

                return array_map(static fn(array $row): string => Coerce::str(reset($row)), $rows);
            },
        ));

        return $plan;
    }

    /**
     * Inside the search transaction: makes the setting what the next statement needs, and nothing more.
     *
     * A value that is already in force is not set again (a transaction keeps it until it ends). The first time a setting is
     * set, the value the session had is read in the same round trip, as withSimilarityThreshold() reads it. A statement that
     * sets nothing must see the session's own value, as it did outside a transaction: the one that declares it reads the word
     * similarity (`<%`) gets it back when this transaction has changed it; the others (the strict full-text statements, the
     * facets over strict candidates) do not read the setting and cost no round trip.
     */
    private function settle(?float $value, string $name, bool $readsWordThreshold): void
    {
        $setting = $this->settings[$name] ?? null;
        if ($value === null) {
            if ($setting !== null && $setting['current'] !== $setting['original'] && $name === self::WORD_SIMILARITY && $readsWordThreshold) {
                self::put($this->connection, $name, $setting['original']);
                $this->settings[$name]['current'] = $setting['original'];
            }

            return;
        }
        $text = (string) $value;
        if ($setting === null) {
            $previous = self::readAndSet($this->connection, $name, $text);
            $this->settings[$name] = ['original' => is_string($previous) ? $previous : null, 'current' => $text];
        } elseif ($setting['current'] !== $text) {
            self::put($this->connection, $name, $text);
            $this->settings[$name]['current'] = $text;
        }
    }

    /**
     * Runs $work with pg_trgm.word_similarity_threshold set, which lets "<%" use the trigram
     * index, and puts the previous value back afterwards. The setting is transaction-local, but
     * when the caller already has a transaction open (or a savepoint is released) it would
     * otherwise stay on until the caller commits. A search leaves no session state behind.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private static function withSimilarityThreshold(Connection $c, ?float $similarityThreshold, bool $restore, \Closure $work, string $name = self::WORD_SIMILARITY): mixed
    {
        if ($similarityThreshold === null) {
            return $work();
        }
        $previous = self::readAndSet($c, $name, (string) $similarityThreshold);
        $result = $work();
        if ($restore) {
            self::put($c, $name, is_string($previous) ? $previous : null);
        }

        return $result;
    }

    /**
     * Sets the transaction-local setting and returns the value it had; the previous value is read before the new one is set (the
     * CTE is evaluated first). NULL (the extension is not loaded yet) is restored as the default.
     */
    private static function readAndSet(Connection $c, string $name, string $text): mixed
    {
        return $c->fetchValue(
            sprintf("WITH old AS MATERIALIZED (SELECT current_setting('%1\$s', true) AS v) SELECT v, set_config('%1\$s', :t, true) FROM old", $name),
            ['t' => $text],
        );
    }

    private static function put(Connection $c, string $name, ?string $value): void
    {
        $c->fetchValue(sprintf("SELECT set_config('%s', :v, true)", $name), ['v' => $value]);
    }
}

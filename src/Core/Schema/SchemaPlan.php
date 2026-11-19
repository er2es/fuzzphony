<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Schema;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Support\ErrorSummary;

/** Ordered DDL. Idempotent: every statement can be re-run safely. */
final readonly class SchemaPlan
{
    /** @param list<Statement> $statements */
    public function __construct(public array $statements = []) {}

    public function merge(self $other): self
    {
        return new self([...$this->statements, ...$other->statements]);
    }

    /**
     * Transactional statements run in one transaction; the rest (concurrent index builds) run after it.
     *
     * @return list<string> warnings: the runs of a statement with `forEach` that failed (the rest of the plan ran)
     */
    public function apply(Connection $connection, ?callable $onStatement = null): array
    {
        $transactional = array_filter($this->statements, static fn(Statement $s): bool => $s->transactional);
        $separate = array_filter($this->statements, static fn(Statement $s): bool => !$s->transactional);

        $connection->transactional(static function (Connection $c) use ($transactional, $onStatement): void {
            foreach ($transactional as $statement) {
                self::run($c, $statement, $onStatement);
            }
        });
        $warnings = [];
        foreach ($separate as $statement) {
            if ($statement->forEach === null) {
                $warnings = [...$warnings, ...self::run($connection, $statement, $onStatement)];

                continue;
            }
            if ($onStatement !== null) {
                $onStatement($statement);
            }
            $warnings = [...$warnings, ...self::each($connection, $statement, false)];
        }

        return array_values(array_unique($warnings)); // a repair after a wait can fail on the same name as the step before it
    }

    /**
     * Runs a statement with `forEach` once for each name.
     *
     * @return list<string> its failed runs
     */
    private static function each(Connection $connection, Statement $statement, bool $retryDeadlock): array
    {
        $warnings = [];
        foreach ($connection->fetchAll((string) $statement->forEach) as $row) {
            $name = Coerce::str($row['name']);
            $sql = str_replace('{name}', $name, $statement->sql);
            try {
                $connection->execute($sql);
            } catch (\Throwable $e) {
                if ($retryDeadlock && ErrorSummary::sqlState($e) === '40P01') {
                    throw $e;
                }
                // the statement itself, not "re-run the apply": a run that depends on what an earlier statement did is not repeated by a second apply
                $warnings[] = sprintf('%s: %s failed (%s). Run it yourself: %s', $statement->description, $name, $e->getMessage(), $sql);
            }
        }

        return $warnings;
    }

    /** How often a statement with a wait is tried again after it lost a deadlock (two concurrent builds on one table). */
    private const int DEADLOCK_RETRIES = 5;

    /** @return list<string> the failed runs of its repair */
    private static function run(Connection $connection, Statement $statement, ?callable $onStatement): array
    {
        if ($onStatement !== null) {
            $onStatement($statement);
        }
        for ($attempt = 0; ; ++$attempt) {
            try {
                return self::attempt($connection, $statement);
            } catch (\Throwable $e) {
                // the wait is a poll: another apply can start its build between our poll and our build, and one of the two
                // concurrent builds is cancelled as a deadlock. The victim waits again, drops the index it left INVALID and checks
                // whether the other apply built it meanwhile.
                if ($statement->waitWhile !== null && $attempt < self::DEADLOCK_RETRIES && ErrorSummary::sqlState($e) === '40P01') {
                    usleep(20_000 * ($attempt + 1) + random_int(0, 30_000));

                    continue;
                }

                throw EngineFailure::wrap(
                    sprintf('schema statement "%s"', $statement->description),
                    $e,
                    'Review the SQL with "bin/console fuzzphony:schema" (without --apply); every statement is safe to re-run.',
                    driverMessage: true,
                );
            }
        }
    }

    /** @return list<string> the failed runs of its repair */
    private static function attempt(Connection $connection, Statement $statement): array
    {
        while ($statement->waitWhile !== null && $connection->fetchValue($statement->waitWhile) === true) {
            usleep(100_000); // short statements: a long-running wait would hold a snapshot the other build waits for
        }
        $warnings = $statement->repair?->forEach !== null ? self::each($connection, $statement->repair, true) : [];
        if ($statement->skipIf === null || $connection->fetchValue($statement->skipIf) !== true) {
            $connection->execute($statement->sql);
        }

        return $warnings;
    }

    public function toSql(): string
    {
        $out = [];
        foreach ($this->statements as $statement) {
            $out[] = $statement->forEach === null
                ? sprintf("-- %s%s\n%s;", $statement->description, $statement->transactional ? '' : ' (run outside a transaction)', rtrim($statement->sql, "; \n"))
                : sprintf("-- %s (run outside a transaction, once for each name this returns when the plan is applied)\n-- %s\n%s;", $statement->description, $statement->forEach, rtrim($statement->sql, "; \n"));
        }

        return implode("\n\n", $out) . "\n";
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Schema;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Support\Coerce;

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
                self::run($connection, $statement, $onStatement);

                continue;
            }
            if ($onStatement !== null) {
                $onStatement($statement);
            }
            foreach ($connection->fetchAll($statement->forEach) as $row) {
                $name = Coerce::str($row['name']);
                try {
                    $connection->execute(str_replace('{name}', $name, $statement->sql));
                } catch (\Throwable $e) {
                    $warnings[] = sprintf('%s: %s failed (%s). Re-run the apply, or run the statement yourself.', $statement->description, $name, $e->getMessage());
                }
            }
        }

        return $warnings;
    }

    private static function run(Connection $connection, Statement $statement, ?callable $onStatement): void
    {
        if ($onStatement !== null) {
            $onStatement($statement);
        }
        try {
            $connection->execute($statement->sql);
        } catch (\Throwable $e) {
            throw EngineFailure::wrap(
                sprintf('schema statement "%s"', $statement->description),
                $e,
                'Review the SQL with "bin/console fuzzphony:schema" (without --apply); every statement is safe to re-run.',
            );
        }
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

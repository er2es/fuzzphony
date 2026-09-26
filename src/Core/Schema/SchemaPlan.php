<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Schema;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;

/** Ordered DDL. Idempotent: every statement can be re-run safely. */
final readonly class SchemaPlan
{
    /** @param list<Statement> $statements */
    public function __construct(public array $statements = []) {}

    public function merge(self $other): self
    {
        return new self([...$this->statements, ...$other->statements]);
    }

    /** Transactional statements run in one transaction; the rest (concurrent index builds) run after it. */
    public function apply(Connection $connection, ?callable $onStatement = null): void
    {
        $transactional = array_filter($this->statements, static fn(Statement $s): bool => $s->transactional);
        $separate = array_filter($this->statements, static fn(Statement $s): bool => !$s->transactional);

        $connection->transactional(static function (Connection $c) use ($transactional, $onStatement): void {
            foreach ($transactional as $statement) {
                self::run($c, $statement, $onStatement);
            }
        });
        foreach ($separate as $statement) {
            self::run($connection, $statement, $onStatement);
        }
    }

    private static function run(Connection $connection, Statement $statement, ?callable $onStatement): void
    {
        $onStatement !== null && $onStatement($statement);
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
            $out[] = sprintf("-- %s%s\n%s;", $statement->description, $statement->transactional ? '' : ' (run outside a transaction)', rtrim($statement->sql, "; \n"));
        }

        return implode("\n\n", $out) . "\n";
    }
}

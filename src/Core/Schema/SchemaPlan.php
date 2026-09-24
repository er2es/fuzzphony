<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Schema;

use Fuzzphony\Core\Database\Connection;

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
                $onStatement !== null && $onStatement($statement);
                $c->execute($statement->sql);
            }
        });
        foreach ($separate as $statement) {
            $onStatement !== null && $onStatement($statement);
            $connection->execute($statement->sql);
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

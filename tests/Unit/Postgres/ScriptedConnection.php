<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;

/**
 * @internal A scripted connection that knows about transactions: it records every statement and BEGIN / COMMIT / ROLLBACK,
 * joins a transaction the "caller" has open ($callerTransaction) the way PdoConnection does, and answers statements from
 * closures (which may throw). Without a script every query returns no rows and every value is null.
 */
final class ScriptedConnection implements Connection, TransactionAware
{
    /** @var list<string> the SQL of every statement, and BEGIN / COMMIT / ROLLBACK */
    public array $log = [];
    /** @var list<array<string, scalar|null>> the parameters of each statement of $log (empty for BEGIN / COMMIT / ROLLBACK) */
    public array $params = [];
    private bool $open;

    /**
     * @param (\Closure(string, array<string, scalar|null>): ?list<array<string, mixed>>)|null $rows  null falls through to "no rows"
     * @param (\Closure(string, array<string, scalar|null>): mixed)|null                       $value the answer of fetchValue()
     */
    public function __construct(
        private readonly bool $callerTransaction = false,
        private readonly ?\Closure $rows = null,
        private readonly ?\Closure $value = null,
    ) {
        $this->open = $callerTransaction;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->record($sql, $params);

        return $this->rows === null ? [] : (($this->rows)($sql, $params) ?? []);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $this->record($sql, $params);

        return $this->value === null ? null : ($this->value)($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->record($sql, $params);

        return 0;
    }

    public function transactional(callable $callback): mixed
    {
        if ($this->open) {
            return $callback($this);
        }
        $this->record('BEGIN', []);
        $this->open = true;
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->open = false;
            $this->record('ROLLBACK', []);

            throw $e;
        }
        $this->open = false;
        $this->record('COMMIT', []);

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    /** @return list<string> the log with every SQL text shortened to its kind: set_config, vocabulary check, lookup, statement */
    public function kinds(): array
    {
        return array_map(static fn(string $sql): string => match (true) {
            in_array($sql, ['BEGIN', 'COMMIT', 'ROLLBACK'], true) => $sql,
            str_contains($sql, 'set_config') => 'set_config',
            str_contains($sql, 'to_regclass') => 'vocabulary check',
            str_contains($sql, 'LEFT JOIN LATERAL') => 'did you mean',
            str_contains($sql, 'unnest(CAST(') => 'stop words',
            default => 'statement',
        }, $this->log);
    }

    /** @param array<string, scalar|null> $params */
    private function record(string $sql, array $params): void
    {
        $this->log[] = $sql;
        $this->params[] = $params;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;

/**
 * @internal A real connection whose answers a test can replace (fetchAll / fetchValue / execute
 * hooks return null to let the statement through) and whose statements it records.
 */
final class InterceptingConnection implements Connection, TransactionAware
{
    /** @var list<array{string, array<string, scalar|null>}> */
    public array $log = [];

    /**
     * @param (\Closure(string, array<string, scalar|null>): ?list<array<string, mixed>>)|null $fetchAll
     * @param (\Closure(string, array<string, scalar|null>): mixed)|null                       $fetchValue returns [value] to answer, null to pass through
     * @param (\Closure(string, array<string, scalar|null>): void)|null                        $execute    may throw
     */
    public function __construct(
        private readonly Connection $inner,
        private readonly ?\Closure $fetchAll = null,
        private readonly ?\Closure $fetchValue = null,
        private readonly ?\Closure $execute = null,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->log[] = [$sql, $params];
        $answer = $this->fetchAll === null ? null : ($this->fetchAll)($sql, $params);

        return $answer ?? $this->inner->fetchAll($sql, $params);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $this->log[] = [$sql, $params];
        $answer = $this->fetchValue === null ? null : ($this->fetchValue)($sql, $params);

        return is_array($answer) ? $answer[0] : $this->inner->fetchValue($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->log[] = [$sql, $params];
        if ($this->execute !== null) {
            ($this->execute)($sql, $params);
        }

        return $this->inner->execute($sql, $params);
    }

    public function transactional(callable $callback): mixed
    {
        return $this->inner->transactional(fn(): mixed => $callback($this));
    }

    public function inTransaction(): bool
    {
        return $this->inner instanceof TransactionAware && $this->inner->inTransaction();
    }

    public function reset(): void
    {
        $this->log = [];
    }

    /** @return list<array<string, scalar|null>> the parameters of the statements that start with $prefix */
    public function params(string $prefix): array
    {
        return array_values(array_map(
            static fn(array $entry): array => $entry[1],
            array_filter($this->log, static fn(array $entry): bool => str_starts_with($entry[0], $prefix)),
        ));
    }

    /** @return list<string> the statements that start with $prefix */
    public function statements(string $prefix): array
    {
        return array_values(array_map(
            static fn(array $entry): string => $entry[0],
            array_filter($this->log, static fn(array $entry): bool => str_starts_with($entry[0], $prefix)),
        ));
    }
}

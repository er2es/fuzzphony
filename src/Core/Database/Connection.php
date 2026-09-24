<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Database;

/**
 * Minimal database port used by engines. Implemented with plain PDO in core
 * and with Doctrine DBAL in the doctrine bridge.
 *
 * Parameters are always named (":p0") and every placeholder is used exactly once.
 */
interface Connection
{
    /**
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /** @param array<string, scalar|null> $params */
    public function fetchValue(string $sql, array $params = []): mixed;

    /** @param array<string, scalar|null> $params */
    public function execute(string $sql, array $params = []): int;

    /**
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;
}

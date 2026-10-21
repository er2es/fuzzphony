<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;

/**
 * @internal A connection that cannot say whether a transaction is open (it is not TransactionAware): the engine must take the
 * per-statement path with it, one transaction per statement that sets a pg_trgm setting, the previous value put back.
 * Everything is delegated to a real connection; the statements are recorded.
 */
final class NotTransactionAwareConnection implements Connection
{
    /** @var list<string> */
    public array $sql = [];

    public function __construct(private readonly Connection $delegate) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->sql[] = $sql;

        return $this->delegate->fetchAll($sql, $params);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $this->sql[] = $sql;

        return $this->delegate->fetchValue($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->sql[] = $sql;

        return $this->delegate->execute($sql, $params);
    }

    public function transactional(callable $callback): mixed
    {
        $this->sql[] = 'BEGIN';

        return $this->delegate->transactional(fn(Connection $c): mixed => $callback($this));
    }

    /** @return int how many recorded statements contain $needle */
    public function count(string $needle): int
    {
        return count(array_filter($this->sql, static fn(string $s): bool => str_contains($s, $needle)));
    }
}

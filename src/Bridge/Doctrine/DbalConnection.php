<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\ParameterType;
use Fuzzphony\Core\Database\Connection;

/** Runs Fuzzphony on the application's existing DBAL connection (same transaction, same pool). */
final readonly class DbalConnection implements Connection
{
    public function __construct(private DoctrineConnection $connection) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        return array_values($this->connection->fetchAllAssociative($sql, ...$this->bind($params)));
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->connection->fetchOne($sql, ...$this->bind($params));

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        return (int) $this->connection->executeStatement($sql, ...$this->bind($params));
    }

    public function transactional(callable $callback): mixed
    {
        return $this->connection->transactional(fn(): mixed => $callback($this));
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return array{0: array<string, scalar|null>, 1: array<string, ParameterType>}
     */
    private function bind(array $params): array
    {
        $values = [];
        $types = [];
        foreach ($params as $name => $value) {
            $name = ltrim($name, ':');
            $values[$name] = $value;
            $types[$name] = match (true) {
                is_bool($value) => ParameterType::BOOLEAN,
                is_int($value) => ParameterType::INTEGER,
                $value === null => ParameterType::NULL,
                default => ParameterType::STRING,
            };
        }

        return [$values, $types];
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;

/** @internal Records every fetchAll() and answers it with the next scripted set of rows; one statement more than scripted fails. */
final class ScriptedConnection implements Connection
{
    /** @var list<array{string, array<string, scalar|null>}> */
    public array $log = [];

    /** @param list<list<array<string, mixed>>> $answers the rows of each statement, in order */
    public function __construct(private array $answers) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->log[] = [$sql, $params];

        return array_shift($this->answers) ?? throw new \LogicException('one statement too many');
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        throw new \LogicException('not used');
    }

    public function execute(string $sql, array $params = []): int
    {
        throw new \LogicException('not used');
    }

    public function transactional(callable $callback): mixed
    {
        throw new \LogicException('not used');
    }
}

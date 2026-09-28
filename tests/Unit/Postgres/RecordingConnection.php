<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;

/** @internal Records every statement and transaction boundary; fetchValue() / execute() answer from a script (which may throw). */
final class RecordingConnection implements Connection
{
    /** @var list<array{string, array<string, scalar|null>}> */
    public array $log = [];

    /** @param \Closure(string, array<string, scalar|null>): mixed $answer */
    public function __construct(private readonly \Closure $answer) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->log[] = [$sql, $params];

        return [];
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $this->log[] = [$sql, $params];

        return ($this->answer)($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->log[] = [$sql, $params];
        ($this->answer)($sql, $params);

        return 0;
    }

    public function transactional(callable $callback): mixed
    {
        $this->log[] = ['BEGIN', []];
        $result = $callback($this);
        $this->log[] = ['COMMIT', []];

        return $result;
    }
}

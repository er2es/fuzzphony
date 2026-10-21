<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Psr\Log\AbstractLogger;

/**
 * @internal The logger of Doctrine's logging middleware: every statement (its SQL) and every transaction boundary the driver
 * sees (the pdo_pgsql driver's BEGIN, COMMIT and ROLLBACK are calls, not statements; savepoints are statements).
 */
final class StatementLog extends AbstractLogger
{
    /** @var list<string> */
    public array $events = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->events[] = trim($context['sql']);
        } elseif ($message === 'Beginning transaction') {
            $this->events[] = 'BEGIN';
        } elseif ($message === 'Committing transaction') {
            $this->events[] = 'COMMIT';
        } elseif ($message === 'Rolling back transaction') {
            $this->events[] = 'ROLLBACK';
        }
    }

    public function reset(): void
    {
        $this->events = [];
    }

    /** @return list<string> BEGIN, COMMIT, ROLLBACK and the savepoint statements, in order */
    public function boundaries(): array
    {
        return array_values(array_filter($this->events, static fn(string $e): bool => in_array($e, ['BEGIN', 'COMMIT', 'ROLLBACK'], true) || preg_match('/^(SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT) /', $e) === 1));
    }

    /** @return list<int> the positions of the events that contain $needle */
    public function positions(string $needle): array
    {
        return array_keys(array_filter($this->events, static fn(string $e): bool => str_contains($e, $needle)));
    }
}

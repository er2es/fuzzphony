<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;

/**
 * @internal What may be shown of a database failure: its exception class and SQLSTATE. The driver's message can carry
 * the values of the source row that failed, so it never reaches a log, a metric or the queue.
 */
final class ErrorSummary
{
    /** The SQLSTATE of the failure or of the first failure it wraps; null when there is none. */
    public static function sqlState(\Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $info = $current instanceof \PDOException ? $current->errorInfo : null;
            if (is_array($info) && is_string($info[0] ?? null) && preg_match('/^[0-9A-Z]{5}$/', $info[0]) === 1) {
                return $info[0];
            }
            if (method_exists($current, 'getSQLState')) {
                $state = $current->getSQLState();
                if (is_string($state) && preg_match('/^[0-9A-Z]{5}$/', $state) === 1) {
                    return $state;
                }
            }
        }

        return null;
    }

    /** `PDOException (SQLSTATE 22P02)`: the class of the innermost failure and its SQLSTATE. */
    public static function describe(\Throwable $e): string
    {
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }
        $state = self::sqlState($e);

        return $root::class . ($state === null ? '' : ' (SQLSTATE ' . $state . ')');
    }

    /** What an operator may be shown on a console: the message of Fuzzphony's own errors, the summary of a wrapped database failure. */
    public static function forOperator(\Throwable $e, bool $withHint = true): string
    {
        if ($e->getPrevious() === null && $e instanceof FuzzphonyException) {
            return $e->getMessage();
        }
        if ($e instanceof EngineFailure && $e->operation !== null && $withHint) {
            return sprintf('Fuzzphony %s failed: %s%s', $e->operation, self::describe($e), $e->hint !== null ? "
Hint: " . $e->hint : '');
        }

        return self::describe($e);
    }

    /** Whether the failure is a problem of the data one document was built from (data exception, integrity, program limit, a raised error), not of the database or the schema. */
    public static function isDataError(\Throwable $e): bool
    {
        $state = self::sqlState($e);

        return $state !== null && in_array(substr($state, 0, 2), ['22', '23', '54', 'P0'], true);
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

use Fuzzphony\Core\Support\SanitizedError;

final class EngineFailure extends \RuntimeException implements FuzzphonyException
{
    /** @param bool $driverMessage the message of the previous exception instead of its class and SQLSTATE: only for SQL Fuzzphony generated itself (never a row value in it) */
    public static function wrap(string $operation, \Throwable $previous, ?string $hint = null, bool $driverMessage = false): self
    {
        return new self(
            sprintf('Fuzzphony %s failed: %s%s', $operation, $driverMessage ? $previous->getMessage() : \Fuzzphony\Core\Support\ErrorSummary::describe($previous), $hint !== null ? "\nHint: " . $hint : ''),
            0,
            $driverMessage ? $previous : SanitizedError::of($previous),
            $operation,
            $hint,
        );
    }

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly ?string $operation = null, public readonly ?string $hint = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

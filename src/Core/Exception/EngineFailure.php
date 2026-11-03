<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

final class EngineFailure extends \RuntimeException implements FuzzphonyException
{
    public static function wrap(string $operation, \Throwable $previous, ?string $hint = null): self
    {
        return new self(
            sprintf('Fuzzphony %s failed: %s%s', $operation, $previous->getMessage(), $hint !== null ? "\nHint: " . $hint : ''),
            0,
            $previous,
            $operation,
            $hint,
        );
    }

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly ?string $operation = null, public readonly ?string $hint = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

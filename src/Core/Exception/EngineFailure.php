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
        );
    }
}

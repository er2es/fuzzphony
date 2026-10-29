<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** The actor may not search this index (the HTTP layer shows 403). */
final class AccessDenied extends \RuntimeException implements SearchRefused
{
    public static function forIndex(string $index): self
    {
        return new self(sprintf('Searching index "%s" is not granted to this actor.', $index));
    }
}

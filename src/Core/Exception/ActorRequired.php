<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** A search made as an actor reached a tenant-scoped index without a tenant: refused (the HTTP layer shows 403). */
final class ActorRequired extends InvalidQuery
{
    public static function forIndex(string $index): self
    {
        return new self(sprintf('Index "%s" is tenant-scoped, but the actor of this search has no tenant.', $index));
    }
}

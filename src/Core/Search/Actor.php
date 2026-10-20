<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/**
 * Who is searching: the tenant whose documents the search may see and the user who asks. Both are optional.
 * The tenant scopes a tenant-scoped index (SearchBuilder::asActor()); the user is carried for the search log
 * and the guards (rate limit, authorization). The client IP is optional too: the Core knows nothing of HTTP, so the
 * caller gives it (the Symfony bundle does, from the request); it only reaches the search log, and only when
 * search_log.ip is not off (see IpMode).
 */
final readonly class Actor
{
    public function __construct(
        public int|string|null $tenant = null,
        public ?string $user = null,
        public ?string $ip = null,
    ) {}

    public static function anonymous(): self
    {
        return new self();
    }

    public function isAnonymous(): bool
    {
        return $this->tenant === null && $this->user === null;
    }
}

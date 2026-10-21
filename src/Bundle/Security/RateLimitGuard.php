<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGuard;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * @internal Spends one token of a Symfony rate limiter per search, keyed by the actor's tenant, user or client IP (the
 * address, or its /64 for IPv6: see ClientIpKey). An actor without that key is not limited.
 */
final readonly class RateLimitGuard implements SearchGuard
{
    public function __construct(private RateLimiterFactoryInterface $limiter, private string $per)
    {
        if (!in_array($per, ['tenant', 'user', 'ip'], true)) {
            throw new InvalidConfiguration(sprintf('fuzzphony.rate_limit.per must be "tenant", "user" or "ip", got "%s".', $per));
        }
    }

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        $key = match ($this->per) {
            'tenant' => $actor->tenant,
            'user' => $actor->user,
            default => ClientIpKey::of($actor->ip),
        };
        if ($this->per === 'ip' && $key !== null) {
            $key = hash('sha256', (string) $key); // the limiter keeps its key in its cache pool: not the address itself
        }
        if ($key === null || !$charge) {
            return Access::all(); // no key, no bucket (an actor without an IP is not limited by IP: the library sees no request)
        }
        $limit = $this->limiter->create((string) $key)->consume(1);
        if (!$limit->isAccepted()) {
            throw new RateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        return Access::all();
    }
}

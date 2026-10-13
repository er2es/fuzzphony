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

/** @internal Spends one token of a Symfony rate limiter per search, keyed by the actor's tenant or user. */
final readonly class RateLimitGuard implements SearchGuard
{
    public function __construct(private RateLimiterFactoryInterface $limiter, private string $per)
    {
        if (!in_array($per, ['tenant', 'user'], true)) {
            throw new InvalidConfiguration(sprintf('fuzzphony.rate_limit.per must be "tenant" or "user", got "%s".', $per));
        }
    }

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        $key = $this->per === 'tenant' ? $actor->tenant : $actor->user;
        if ($key === null || !$charge) {
            return Access::all(); // no key, no bucket: an IP limit is Symfony's own business
        }
        $limit = $this->limiter->create((string) $key)->consume(1);
        if (!$limit->isAccepted()) {
            throw new RateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        return Access::all();
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * @internal Spends one token of a Symfony rate limiter per search, keyed by the actor's tenant, user or client IP (the
 * address, or its /64 for IPv6: see ClientIpKey). An actor without that key is not limited.
 *
 * For `per: ip` the limiter is handed an HMAC (SHA-256) of the bucket key, with $secret as the key (the bundle passes
 * kernel.secret), never the address: the limiter's cache pool holds its key, and an unkeyed hash of an IPv4 address
 * is recovered by trying every address. The HMAC is stable across requests and across servers that share the secret;
 * changing the secret starts every bucket afresh. Without the bundle $secret has a fixed, public default, which keeps
 * the address out of the pool in clear but is no protection against enumeration: pass your own secret.
 */
final class RateLimitGuard implements SearchGuard
{
    /** The HMAC key when none is given (not secret). */
    public const string DEFAULT_SECRET = 'fuzzphony-rate-limit-ip';

    /** Whether this process has already reported an address it could not key (once per process, as the counter is no rate). */
    private static bool $unkeyedReported = false;

    private readonly string $secret;

    public function __construct(
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly string $per,
        string $secret = self::DEFAULT_SECRET,
        private readonly MetricsCollector $metrics = new NullMetricsCollector(),
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (!in_array($per, ['tenant', 'user', 'ip'], true)) {
            throw new InvalidConfiguration(sprintf('fuzzphony.rate_limit.per must be "tenant", "user" or "ip", got "%s".', $per));
        }
        $this->secret = $secret !== '' ? $secret : self::DEFAULT_SECRET;
    }

    /** Lets the next unkeyable address be reported again (a long-running test process; an application never needs it). */
    public static function forgetReports(): void
    {
        self::$unkeyedReported = false;
    }

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        $key = match ($this->per) {
            'tenant' => $actor->tenant,
            'user' => $actor->user,
            default => $this->ipKey($actor),
        };
        if ($key === null || !$charge) {
            return Access::all(); // no key, no bucket (an actor without an IP is not limited by IP: the library sees no request)
        }
        $limit = $this->limiter->create((string) $key)->consume(1);
        if (!$limit->isAccepted()) {
            throw new RateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        return Access::all();
    }

    private function ipKey(Actor $actor): ?string
    {
        $key = ClientIpKey::of($actor->ip);
        if ($key === null) {
            if (ClientIpKey::isUnparseable($actor->ip)) {
                $this->reportUnkeyed();
            }

            return null;
        }

        return hash_hmac('sha256', $key, $this->secret);
    }

    /** Fail open, but say so once: the actor's address is no IP address (only an application's own resolver can do that). Never the value. */
    private function reportUnkeyed(): void
    {
        if (self::$unkeyedReported) {
            return;
        }
        self::$unkeyedReported = true;
        $this->metrics->increment('fuzzphony.search.rate_limit_unkeyed');
        $this->logger?->warning('Fuzzphony cannot limit searches by IP: an actor carries an address that is not an IP address, so its searches are not limited (reported once per process; the value is never logged). Check your ActorResolver.');
    }
}

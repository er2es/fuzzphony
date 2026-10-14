<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class RateLimitGuardMutationsTest extends TestCase
{
    public function testARefusalThatMayBeRetriedAtOnceStillAsksToWaitOneSecond(): void
    {
        // the limiter says "try again right now" (the window rolls over this second): a Retry-After of 0 would be no wait at all
        $retryAt = new \DateTimeImmutable();
        $limiter = new class (new RateLimit(0, $retryAt, false, 1)) implements LimiterInterface {
            public function __construct(private readonly RateLimit $limit) {}

            public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
            {
                throw new \LogicException('not used');
            }

            public function consume(int $tokens = 1): RateLimit
            {
                return $this->limit;
            }

            public function reset(): void {}
        };
        $factory = new class ($limiter) implements RateLimiterFactoryInterface {
            public function __construct(private readonly LimiterInterface $limiter) {}

            public function create(?string $key = null): LimiterInterface
            {
                return $this->limiter;
            }
        };

        try {
            (new RateLimitGuard($factory, 'tenant'))->check(Indexes::products(), new Actor(tenant: 1));
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertSame(1, $e->retryAfter);
        }
    }
}

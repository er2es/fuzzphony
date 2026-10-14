<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Exception;

use Fuzzphony\Core\Exception\RateLimited;
use PHPUnit\Framework\TestCase;

final class RateLimitedMutationsTest extends TestCase
{
    public function testTheExceptionCarriesNoErrorCodeAndItsPrevious(): void
    {
        $previous = new \RuntimeException('limiter');

        $e = new RateLimited(30, $previous);

        self::assertSame(0, $e->getCode());
        self::assertSame($previous, $e->getPrevious());
        self::assertSame('Too many searches: try again in 30 seconds.', $e->getMessage());
        self::assertSame(30, $e->retryAfter);
    }
}

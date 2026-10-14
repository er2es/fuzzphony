<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Search\Access;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase
{
    public function testAllMeansNoRestriction(): void
    {
        self::assertTrue(Access::all()->isAll());
        self::assertNull(Access::all()->fields);
        self::assertFalse(Access::only(['name'])->isAll());
    }

    public function testIntersectionKeepsOnlyWhatBothAllow(): void
    {
        self::assertSame(['name'], Access::only(['name', 'brand'])->intersect(Access::only(['name', 'description']))->fields);
        self::assertSame(['name', 'brand'], Access::all()->intersect(Access::only(['name', 'brand']))->fields);
        self::assertSame(['name', 'brand'], Access::only(['name', 'brand'])->intersect(Access::all())->fields);
        self::assertTrue(Access::all()->intersect(Access::all())->isAll());
        self::assertSame([], Access::only(['name'])->intersect(Access::only(['brand']))->fields);
    }

    public function testOnlyDeduplicatesAndKeepsOrder(): void
    {
        self::assertSame(['name', 'brand'], Access::only(['name', 'brand', 'name'])->fields);
    }

    public function testAnIntersectionResultIsAListAgain(): void
    {
        self::assertSame(['brand'], Access::only(['name', 'brand'])->intersect(Access::only(['brand']))->fields);
    }

    public function testAccessDeniedNamesTheIndex(): void
    {
        $e = AccessDenied::forIndex('products');

        self::assertInstanceOf(FuzzphonyException::class, $e);
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('Searching index "products" is not granted to this actor.', $e->getMessage());
    }

    public function testRateLimitedCarriesTheWait(): void
    {
        $previous = new \LogicException('inner');
        $e = new RateLimited(7, $previous);

        self::assertInstanceOf(FuzzphonyException::class, $e);
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame(7, $e->retryAfter);
        self::assertSame('Too many searches: try again in 7 seconds.', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
        self::assertNull((new RateLimited(1))->getPrevious());
    }
}

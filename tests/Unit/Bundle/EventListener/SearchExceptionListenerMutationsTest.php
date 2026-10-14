<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\EventListener;

use Fuzzphony\Bundle\EventListener\SearchExceptionListener;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\RateLimited;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** The first refusal in the previous-chain decides the status: the walk stops there. */
final class SearchExceptionListenerMutationsTest extends TestCase
{
    public function testADenialThatWrapsARateLimitStaysA403(): void
    {
        $denied = new AccessDenied('denied', 0, new RateLimited(5));

        $thrown = $this->handle($denied);

        self::assertInstanceOf(AccessDeniedHttpException::class, $thrown);
        self::assertSame($denied, $thrown->getPrevious());
    }

    public function testARateLimitThatWrapsADenialStaysA429(): void
    {
        $limited = new RateLimited(5, new AccessDenied('denied'));

        $thrown = $this->handle($limited);

        self::assertInstanceOf(TooManyRequestsHttpException::class, $thrown);
        self::assertSame($limited, $thrown->getPrevious());
    }

    private function handle(\Throwable $throwable): \Throwable
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);

        (new SearchExceptionListener())($event);

        return $event->getThrowable();
    }
}

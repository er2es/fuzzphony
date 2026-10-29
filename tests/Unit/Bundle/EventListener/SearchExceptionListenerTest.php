<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\EventListener;

use Fuzzphony\Bundle\EventListener\SearchExceptionListener;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Exception\RateLimited;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Error\RuntimeError;

final class SearchExceptionListenerTest extends TestCase
{
    public function testActorRequiredBecomesA403(): void
    {
        $original = ActorRequired::forIndex('products');
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $original);

        (new SearchExceptionListener())($event);

        self::assertInstanceOf(AccessDeniedHttpException::class, $event->getThrowable());
        self::assertSame($original, $event->getThrowable()->getPrevious());
        self::assertSame('Searching is not allowed for this caller.', $event->getThrowable()->getMessage(), 'no index name in the response');
    }

    public function testActorRequiredWrappedByTwigBecomesA403(): void
    {
        // the Live Component searches inside its template: Twig wraps the throwable in a RuntimeError
        $original = ActorRequired::forIndex('products');
        $wrapped = new RuntimeError('An exception has been thrown during the rendering of a template.', -1, null, $original);
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $wrapped);

        (new SearchExceptionListener())($event);

        self::assertInstanceOf(AccessDeniedHttpException::class, $event->getThrowable());
        self::assertSame($original, $event->getThrowable()->getPrevious());
        self::assertSame('Searching is not allowed for this caller.', $event->getThrowable()->getMessage(), 'no index name in the response');
    }

    public function testAccessDeniedBecomesA403(): void
    {
        $original = AccessDenied::forIndex('products');

        $thrown = $this->handle($original);

        self::assertInstanceOf(AccessDeniedHttpException::class, $thrown);
        self::assertSame(403, $thrown->getStatusCode());
        self::assertSame($original, $thrown->getPrevious());
        self::assertSame('Searching is not allowed for this caller.', $thrown->getMessage(), 'no index name in the response');
        self::assertStringContainsString('products', $original->getMessage(), 'the log has it');
    }

    public function testAccessDeniedWrappedByTwigBecomesA403(): void
    {
        $original = AccessDenied::forIndex('products');

        $thrown = $this->handle(new RuntimeError('An exception has been thrown during the rendering of a template.', -1, null, $original));

        self::assertInstanceOf(AccessDeniedHttpException::class, $thrown);
        self::assertSame($original, $thrown->getPrevious());
    }

    public function testRateLimitedBecomesA429WithRetryAfter(): void
    {
        $original = new RateLimited(42);

        $thrown = $this->handle($original);

        self::assertInstanceOf(TooManyRequestsHttpException::class, $thrown);
        self::assertSame(429, $thrown->getStatusCode());
        self::assertSame(['Retry-After' => 42], $thrown->getHeaders());
        self::assertSame($original, $thrown->getPrevious());
        self::assertSame($original->getMessage(), $thrown->getMessage());
    }

    public function testRateLimitedWrappedByTwigBecomesA429(): void
    {
        $original = new RateLimited(7);

        $thrown = $this->handle(new RuntimeError('An exception has been thrown during the rendering of a template.', -1, null, $original));

        self::assertInstanceOf(TooManyRequestsHttpException::class, $thrown);
        self::assertSame(['Retry-After' => 7], $thrown->getHeaders());
        self::assertSame($original, $thrown->getPrevious());
    }

    private function handle(\Throwable $throwable): \Throwable
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);

        (new SearchExceptionListener())($event);

        return $event->getThrowable();
    }

    public function testAWrappedExceptionThatIsNotARefusalIsLeftAlone(): void
    {
        $wrapped = new RuntimeError('boom', -1, null, InvalidQuery::missingTenant('products'));
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $wrapped);

        (new SearchExceptionListener())($event);

        self::assertSame($wrapped, $event->getThrowable());
    }

    public function testOtherExceptionsAreLeftAlone(): void
    {
        $original = InvalidQuery::missingTenant('products');
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $original);

        (new SearchExceptionListener())($event);

        self::assertSame($original, $event->getThrowable());
    }
}

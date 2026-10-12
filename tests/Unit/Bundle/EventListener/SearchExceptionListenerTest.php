<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\EventListener;

use Fuzzphony\Bundle\EventListener\SearchExceptionListener;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SearchExceptionListenerTest extends TestCase
{
    public function testActorRequiredBecomesA403(): void
    {
        $original = ActorRequired::forIndex('products');
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $original);

        (new SearchExceptionListener())($event);

        self::assertInstanceOf(AccessDeniedHttpException::class, $event->getThrowable());
        self::assertSame($original, $event->getThrowable()->getPrevious());
        self::assertSame($original->getMessage(), $event->getThrowable()->getMessage());
    }

    public function testOtherExceptionsAreLeftAlone(): void
    {
        $original = InvalidQuery::missingTenant('products');
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $original);

        (new SearchExceptionListener())($event);

        self::assertSame($original, $event->getThrowable());
    }
}

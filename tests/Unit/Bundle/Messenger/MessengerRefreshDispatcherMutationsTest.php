<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Messenger;

use Fuzzphony\Bundle\Messenger\MessengerRefreshDispatcher;
use Fuzzphony\Bundle\Messenger\RefreshDocuments;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerRefreshDispatcherMutationsTest extends TestCase
{
    public function testTheDefaultChunkIsFiveHundredIds(): void
    {
        $chunks = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (RefreshDocuments $message) use (&$chunks): Envelope {
            $chunks[] = $message->ids;

            return new Envelope($message);
        });

        (new MessengerRefreshDispatcher($bus))->dispatch(Indexes::products(), range(1, 1001));

        self::assertSame([500, 500, 1], array_map(count(...), $chunks));
        self::assertSame(501, $chunks[1][0]);
        self::assertSame([1001], $chunks[2]);
    }
}

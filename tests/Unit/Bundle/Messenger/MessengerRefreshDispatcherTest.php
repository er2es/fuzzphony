<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Messenger;

use Fuzzphony\Bundle\Messenger\MessengerRefreshDispatcher;
use Fuzzphony\Bundle\Messenger\RefreshDocuments;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerRefreshDispatcherTest extends TestCase
{
    public function testIdsAreDispatchedInChunksOfTheConfiguredSize(): void
    {
        $index = Indexes::products();
        $bus = $this->createMock(MessageBusInterface::class);

        $dispatched = [];
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (RefreshDocuments $message) use (&$dispatched, $index): Envelope {
                self::assertSame($index->name, $message->index);
                $dispatched[] = $message->ids;

                return new Envelope($message);
            },
        );

        (new MessengerRefreshDispatcher($bus, chunkSize: 2))->dispatch($index, [1, 2, 3]);

        self::assertSame([[1, 2], [3]], $dispatched);
    }

    public function testANonPositiveChunkSizeIsTreatedAsOne(): void
    {
        $index = Indexes::products();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static fn(RefreshDocuments $message): Envelope => new Envelope($message),
        );

        (new MessengerRefreshDispatcher($bus, chunkSize: 0))->dispatch($index, [1, 2]);
    }
}

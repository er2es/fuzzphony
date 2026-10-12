<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\EventListener;

use Fuzzphony\Bundle\Analytics\BufferedSearchLog;
use Fuzzphony\Bundle\EventListener\SearchLogFlushListener;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Tests\Unit\Bundle\Analytics\FakeSearchLogStore;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SearchLogFlushListenerTest extends TestCase
{
    private function buffered(FakeSearchLogStore $store): BufferedSearchLog
    {
        $log = new BufferedSearchLog($store, new RecordingMetricsCollector());
        $log->record(new SearchRecord(new \DateTimeImmutable(), 'products', null, null, 'q', 1, 1.0, false));

        return $log;
    }

    public function testTheHttpTerminateFlushesTheLog(): void
    {
        $store = new FakeSearchLogStore();

        (new SearchLogFlushListener($this->buffered($store)))(new TerminateEvent(self::createStub(HttpKernelInterface::class), new Request(), new Response()));

        self::assertCount(1, $store->batches);
    }

    public function testTheConsoleTerminateFlushesTheLog(): void
    {
        $store = new FakeSearchLogStore();

        (new SearchLogFlushListener($this->buffered($store)))(new ConsoleTerminateEvent(new Command('x'), new ArrayInput([]), new NullOutput(), 0));

        self::assertCount(1, $store->batches);
    }
}

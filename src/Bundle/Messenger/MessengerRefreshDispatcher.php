<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Messenger;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Sync\RefreshDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerRefreshDispatcher implements RefreshDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private int $chunkSize = 500,
    ) {}

    public function dispatch(IndexDefinition $index, array $ids): void
    {
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $this->bus->dispatch(new RefreshDocuments($index->name, $chunk));
        }
    }
}

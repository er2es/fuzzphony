<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Messenger;

/** Route it to an async transport: framework.messenger.routing: { Fuzzphony\Bundle\Messenger\RefreshDocuments: async } */
final readonly class RefreshDocuments
{
    /** @param list<int|string> $ids */
    public function __construct(
        public string $index,
        public array $ids,
    ) {}
}

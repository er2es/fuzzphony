<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Messenger;

use Fuzzphony\Core\Fuzzphony;

final readonly class RefreshDocumentsHandler
{
    public function __construct(private Fuzzphony $fuzzphony) {}

    public function __invoke(RefreshDocuments $message): void
    {
        // Idempotent: a retried message simply rebuilds the same documents again.
        $this->fuzzphony->refresh($message->index, $message->ids);
    }
}

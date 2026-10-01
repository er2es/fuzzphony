<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Observability;

use Fuzzphony\Core\Support\Coerce;
use Psr\Log\AbstractLogger;

/** @internal Records every log() call's level and context, in order. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, array<array-key, mixed>}> */
    public array $calls = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->calls[] = [Coerce::str($level), $context];
    }
}

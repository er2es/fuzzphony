<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\IpLimit;

use Psr\Log\AbstractLogger;

/** @internal Keeps every log record of the process (message and context) so a test can look for what must never be in it. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public static array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        self::$lines[] = (string) json_encode([(string) $level, (string) $message, $context], \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

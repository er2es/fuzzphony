<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Inspection;

final readonly class InspectOptions
{
    public function __construct(
        /** Run exact (potentially slow) counts instead of planner estimates. */
        public bool $deep = false,
        /** Queue backlog above this is reported as a warning. */
        public int $maxQueueBacklog = 10_000,
        /** Oldest queued item older than this (seconds) means the worker is probably not running. */
        public int $maxQueueAgeSeconds = 300,
    ) {}
}

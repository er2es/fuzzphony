<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** One search text in a report: how often it was searched, with how many hits on average, and when last. */
final readonly class QueryStat
{
    public function __construct(
        public string $query,
        public int $searches,
        public float $averageHits,
        public \DateTimeImmutable $lastSeen,
    ) {}
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

final readonly class Hit
{
    /** @param array<string, string> $highlights field => HTML-safe snippet with <mark> tags */
    public function __construct(
        public int|string $id,
        public float $score,
        public ScoreBreakdown $breakdown,
        public array $highlights = [],
    ) {}
}

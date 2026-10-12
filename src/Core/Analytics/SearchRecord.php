<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** One line of the search log: who searched what, where, and how it went. */
final readonly class SearchRecord
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $index,
        public int|string|null $tenant,
        public ?string $user,
        public string $query,
        public int $hits,
        public float $tookMs,
        public bool $usedFuzzy,
        public RecordStatus $status = RecordStatus::Ok,
    ) {}

    /** The text the reports group by: lower-cased, whitespace collapsed, trimmed. */
    public function normalised(): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->query)));
    }
}

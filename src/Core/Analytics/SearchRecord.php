<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** One line of the search log: who searched what, where, and how it went. The ip is what the log is to store (already masked), or null. */
final readonly class SearchRecord
{
    /** @internal */
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
        public ?string $ip = null,
        /** Whether the zero-result rescue answered the search (SearchResult::$correctedFrom): `hits` then counts the corrected search's hits. */
        public bool $rescued = false,
    ) {}

    /** The text the reports group by: lower-cased, whitespace collapsed, trimmed. */
    public function normalised(): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->query)));
    }
}

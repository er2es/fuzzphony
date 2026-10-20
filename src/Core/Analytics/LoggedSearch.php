<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/**
 * One search read back from the search log (SearchAnalytics::recentSearches()): who searched what, where and how it
 * went. The text is the stored one, already redacted and cut. This is personal data: see SECURITY.md.
 */
final readonly class LoggedSearch
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $index,
        public ?string $tenant,
        public ?string $user,
        public ?string $ip,
        public string $query,
        public int $hits,
        public float $tookMs,
        public bool $usedFuzzy,
        public RecordStatus $status,
    ) {}
}

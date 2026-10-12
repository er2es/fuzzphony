<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Analytics;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\SearchLogStore;

/** @internal Records the batches it is asked to write; can be told to fail. */
final class FakeSearchLogStore implements SearchLogStore
{
    /** @var list<list<\Fuzzphony\Core\Analytics\SearchRecord>> */
    public array $batches = [];

    public function __construct(public bool $fail = false) {}

    public function writeSearchLog(array $records): int
    {
        if ($this->fail) {
            throw new \RuntimeException('disk full');
        }
        $this->batches[] = $records;

        return count($records);
    }

    public function purgeSearchLog(\DateTimeInterface $before): int
    {
        return 0;
    }

    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        return [];
    }

    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        return [];
    }
}

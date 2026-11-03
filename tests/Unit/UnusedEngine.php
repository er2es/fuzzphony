<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Wizard\SourceIntrospector;

/** @internal An engine whose every method throws: a test double extends it and overrides what the test uses. */
abstract class UnusedEngine implements Engine
{
    public function resetCaches(): void
    {
        throw new \LogicException('not used by this test');
    }

    public function name(): string
    {
        throw new \LogicException('not used by this test');
    }

    public function introspector(): SourceIntrospector
    {
        throw new \LogicException('not used by this test');
    }

    public function globalSchema(IndexDefinition ...$indexes): SchemaPlan
    {
        throw new \LogicException('not used by this test');
    }

    public function indexSchema(IndexDefinition $index): SchemaPlan
    {
        throw new \LogicException('not used by this test');
    }

    public function dropSchema(IndexDefinition $index): SchemaPlan
    {
        throw new \LogicException('not used by this test');
    }

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        throw new \LogicException('not used by this test');
    }

    public function explain(IndexDefinition $index, SearchQuery $query, bool $analyze = false): Explanation
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @param list<int|string> $ids
     * @return int number of documents written
     */
    public function refresh(IndexDefinition $index, array $ids): int
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return list<int|string>
     */
    public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return int number of documents removed
     */
    public function pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int
    {
        throw new \LogicException('not used by this test');
    }

    public function recordReindex(IndexDefinition $index): void
    {
        throw new \LogicException('not used by this test');
    }

    public function beginRebuild(IndexDefinition $index, bool $resume = false): bool
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @param list<int|string> $ids
     * @return int number of documents written
     */
    public function refreshShadow(IndexDefinition $index, array $ids): int
    {
        throw new \LogicException('not used by this test');
    }

    public function finishRebuild(IndexDefinition $index): void
    {
        throw new \LogicException('not used by this test');
    }

    public function abortRebuild(IndexDefinition $index, bool $keepShadow = false): void
    {
        throw new \LogicException('not used by this test');
    }

    public function discardLeftoverRebuild(IndexDefinition $index): bool
    {
        throw new \LogicException('not used by this test');
    }

    public function processQueue(IndexDefinition $index, int $limit): int
    {
        throw new \LogicException('not used by this test');
    }

    public function queueSize(IndexDefinition $index): int
    {
        throw new \LogicException('not used by this test');
    }

    public function rebuildRequested(IndexDefinition $index): bool
    {
        throw new \LogicException('not used by this test');
    }

    public function rebuildRequestedSources(IndexDefinition $index): array
    {
        throw new \LogicException('not used by this test');
    }

    public function recordRebuildFailure(IndexDefinition $index, string $message): void
    {
        throw new \LogicException('not used by this test');
    }

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return int the number of words
     */
    public function rebuildVocabulary(IndexDefinition $index): int
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return list<string>
     */
    public function suggestWords(IndexDefinition $index, string $prefix, int $limit): array
    {
        throw new \LogicException('not used by this test');
    }

    public function stemSynonyms(IndexDefinition $index, Synonyms $synonyms): Synonyms
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @param list<SearchRecord> $records
     * @return int the number of records written
     */
    public function writeSearchLog(array $records): int
    {
        throw new \LogicException('not used by this test');
    }

    /** @return int the number of records deleted */
    public function purgeSearchLog(\DateTimeInterface $before): int
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return list<QueryStat>
     */
    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return list<QueryStat>
     */
    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        throw new \LogicException('not used by this test');
    }

    /**
     * @return list<LoggedSearch>
     */
    public function recentSearches(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array
    {
        throw new \LogicException('not used by this test');
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Search\SearchResult;

/** @internal What two searches must agree on: everything but the timing, the scores to the sixth decimal (the recency bonus reads the clock). */
final class ResultSnapshot
{
    /** @return array<string, mixed> */
    public static function of(SearchResult $result): array
    {
        $round = static fn(float $f): float => round($f, 6);

        return [
            'hits' => array_map(static fn($hit): array => ['id' => $hit->id, 'score' => $round($hit->score), 'breakdown' => array_map($round, $hit->breakdown->toArray()), 'highlights' => $hit->highlights], $result->hits),
            'total' => $result->total,
            'lowerBound' => $result->totalIsLowerBound,
            'usedFuzzy' => $result->usedFuzzy,
            'warnings' => $result->warnings,
            'interpretedAs' => $result->interpretedAs,
            'didYouMean' => $result->didYouMean,
            'facets' => array_map(static fn(array $values): array => array_map(static fn($v): array => [$v->value, $v->count], $values), $result->facets),
        ];
    }
}

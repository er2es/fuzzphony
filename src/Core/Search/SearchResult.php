<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Ranking\Thresholds;

/** @implements \IteratorAggregate<int, Hit> */
final readonly class SearchResult implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @param list<Hit>    $hits
     * @param array<string, list<FacetValue>> $facets filter => its values, the most frequent first (see SearchBuilder::facets())
     * @param list<string> $warnings Corrections applied to the search text. Plain text, not HTML: a warning may quote the user's own words (invisible format characters removed, long words cut), so escape it when rendering it as HTML.
     *
     * @internal
     */
    public function __construct(
        public array $hits,
        public int $total,
        /** True when the candidate limit was reached: display "$total+" instead of an exact number. */
        public bool $totalIsLowerBound,
        public float $tookMs,
        public bool $usedFuzzy,
        public array $warnings,
        public int $limit,
        public int $offset,
        /** The canonical form of the parsed query, e.g. "(wireless AND NOT cable)". */
        public ?string $interpretedAs = null,
        /**
         * The query with the spelling it probably meant, when it found few hits and a word is not in the
         * index's vocabulary (`hedphones` -> `headphones`); null otherwise. Plain text, not HTML: it
         * contains the user's own words, so escape it when you render it. Search it by passing it to query().
         */
        public ?string $didYouMean = null,
        /**
         * Counts per value of the filters asked for with facets(): `$result->facets['category']` is a list of
         * FacetValue (value, count). Counted among the candidates, so they are lower bounds when
         * `totalIsLowerBound` is true, unless the search asked for exactCounts(). A facet does not count
         * the conditions on its own filter, so it shows what choosing another value would find.
         */
        public array $facets = [],
        /**
         * The search text as the visitor wrote it, when the search found nothing and this result is the one of the
         * corrected query instead (`mpuse` found nothing, `mouse` did): `interpretedAs` then shows the corrected query and a
         * warning says so. Null when nothing was corrected. Plain text, not HTML: it is the visitor's own text, so
         * escape it when you render it. See RescueThresholds::$correctWhenEmpty.
         */
        public ?string $correctedFrom = null,
        /**
         * The text the zero-result rescue actually searched, when it did (`mouse` for `mpuse`); null otherwise. Set exactly when
         * `correctedFrom` is. Plain text, not HTML: escape it when you render it.
         */
        public ?string $correctedTo = null,
    ) {}

    /**
     * The result as plain data, with these keys (the same ones `json_encode($result)` writes; they are stable):
     *
     * - `hits`: list of Hit::toArray() (kind, id, score, breakdown, highlights, mergedScore, matches)
     * - `total`, `totalIsLowerBound`: the number of matches, and whether it is a lower bound ("$total+")
     * - `facets`: filter => list of {value, count}
     * - `didYouMean`, `correctedFrom`, `correctedTo`: the spelling hints (null when there are none); plain text
     * - `warnings`: list of plain-text corrections applied to the search text
     * - `tookMs`: the time the search took
     *
     * Every string is plain text except the `highlights` of the hits, which are already HTML-escaped with <mark> tags: put those
     * into HTML as they are, and escape everything else when you render it.
     *
     * @return array{hits: list<array<string, mixed>>, total: int, totalIsLowerBound: bool, facets: array<string, list<array{value: bool|int|string|null, count: int}>>, didYouMean: string|null, correctedFrom: string|null, correctedTo: string|null, warnings: list<string>, tookMs: float}
     */
    public function toArray(): array
    {
        return [
            'hits' => array_map(static fn(Hit $hit): array => $hit->toArray(), $this->hits),
            'total' => $this->total,
            'totalIsLowerBound' => $this->totalIsLowerBound,
            'facets' => array_map(static fn(array $values): array => array_map(static fn(FacetValue $v): array => $v->toArray(), $values), $this->facets),
            'didYouMean' => $this->didYouMean,
            'correctedFrom' => $this->correctedFrom,
            'correctedTo' => $this->correctedTo,
            'warnings' => $this->warnings,
            'tookMs' => $this->tookMs,
        ];
    }

    /** @return array{hits: list<array<string, mixed>>, total: int, totalIsLowerBound: bool, facets: array<string, list<array{value: bool|int|string|null, count: int}>>, didYouMean: string|null, correctedFrom: string|null, correctedTo: string|null, warnings: list<string>, tookMs: float} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** The same result with another "did you mean" text. */
    public function withDidYouMean(?string $didYouMean): self
    {
        return new self($this->hits, $this->total, $this->totalIsLowerBound, $this->tookMs, $this->usedFuzzy, $this->warnings, $this->limit, $this->offset, $this->interpretedAs, $didYouMean, $this->facets, $this->correctedFrom, $this->correctedTo);
    }

    /** @param list<string> $warnings */
    public static function empty(int $limit, int $offset, array $warnings = [], float $tookMs = 0.0): self
    {
        return new self([], 0, false, $tookMs, false, array_values($warnings), $limit, $offset);
    }

    /** @return list<int|string> */
    public function ids(): array
    {
        return array_map(static fn(Hit $hit): int|string => $hit->id, $this->hits);
    }

    /** @return \ArrayIterator<int, Hit> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->hits);
    }

    public function count(): int
    {
        return count($this->hits);
    }

    public function page(): int
    {
        return intdiv($this->offset, $this->limit) + 1;
    }

    /**
     * Whether hits follow this page among the ones the search ranked: false at the last page of the candidates, even when
     * `totalIsLowerBound` says there are more matches than candidates (a page asked for directly past them is served by a wider selection, see
     * LimitThresholds::$candidates). With exactCounts() the total counts every match, but the hits that can be read end at
     * Thresholds::MAX_CANDIDATE_LIMIT (10 000): there `hasMore()` is false too, and it is false for an empty page.
     */
    public function hasMore(): bool
    {
        $end = $this->offset + count($this->hits);

        return $this->hits !== [] && $end < $this->total && $end < Thresholds::MAX_CANDIDATE_LIMIT;
    }
}

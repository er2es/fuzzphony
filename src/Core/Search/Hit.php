<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/**
 * One hit of a search. `$index` says which index it comes from (a federated search mixes them), `$kind` which source inside that
 * index (the index name for a single-source index, the source name for a multi-source one), `$id` is the raw key of the document typed
 * by the source's id type (an int for an integer key, a string for a uuid or string key): index and kind tell which entity to load for it.
 */
final readonly class Hit implements \JsonSerializable
{
    /**
     * @param string                    $kind        the source inside the index the hit comes from (the index name for a single-source index)
     * @param array<string, string>     $highlights  field => HTML-safe snippet with <mark> tags (the text is escaped: put it into HTML as it is)
     * @param float|null                $mergedScore the reciprocal-rank-fusion score a federated search orders its merged list by (weight / (60 + rank in its index)), comparable across indexes, unlike `$score`; null outside a federated search
     * @param list<MatchedWord>|null    $matches     which word matched in which field and how: only with SearchBuilder::explainMatches(), null otherwise
     * @param string|null               $index       the index the hit comes from; null = the same as `$kind` (a single-source index)
     * @param array<string, scalar|null> $data       field => value of the hit's source row, only with SearchBuilder::with() (empty otherwise, or when the row vanished)
     */
    public function __construct(
        public string $kind,
        public int|string $id,
        public float $score,
        public ScoreBreakdown $breakdown,
        public array $highlights = [],
        public ?float $mergedScore = null,
        public ?array $matches = null,
        ?string $index = null,
        public array $data = [],
    ) {
        $this->index = $index ?? $kind;
    }

    /** The index the hit comes from. */
    public string $index;

    /** @internal */
    public function withMergedScore(float $mergedScore): self
    {
        return new self($this->kind, $this->id, $this->score, $this->breakdown, $this->highlights, $mergedScore, $this->matches, $this->index, $this->data);
    }

    /**
     * @param list<MatchedWord> $matches
     *
     * @internal
     */
    public function withMatches(array $matches): self
    {
        return new self($this->kind, $this->id, $this->score, $this->breakdown, $this->highlights, $this->mergedScore, $matches, $this->index, $this->data);
    }

    /**
     * @param array<string, scalar|null> $data
     *
     * @internal
     */
    public function withData(array $data): self
    {
        return new self($this->kind, $this->id, $this->score, $this->breakdown, $this->highlights, $this->mergedScore, $this->matches, $this->index, $data);
    }

    /**
     * Stable keys: index, kind, id, score, breakdown (see ScoreBreakdown::toArray()), highlights (HTML-escaped, with <mark>), mergedScore
     * (null outside a federated search) and matches (null unless explainMatches() was asked; else a list of field, word, via, similarity), and data (field => value, only when SearchBuilder::with() returned something).
     *
     * @return array{index: string, kind: string, id: int|string, score: float, breakdown: array<string, float>, highlights: array<string, string>, mergedScore: float|null, matches: list<array{field: string, word: string, via: string, similarity: float|null}>|null, data?: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [...[
            'index' => $this->index,
            'kind' => $this->kind,
            'id' => $this->id,
            'score' => $this->score,
            'breakdown' => $this->breakdown->toArray(),
            'highlights' => $this->highlights,
            'mergedScore' => $this->mergedScore,
            'matches' => $this->matches === null ? null : array_map(static fn(MatchedWord $m): array => $m->toArray(), $this->matches),
        ], ...($this->data === [] ? [] : ['data' => $this->data])];
    }

    /** @return array{index: string, kind: string, id: int|string, score: float, breakdown: array<string, float>, highlights: array<string, string>, mergedScore: float|null, matches: list<array{field: string, word: string, via: string, similarity: float|null}>|null, data?: array<string, scalar|null>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

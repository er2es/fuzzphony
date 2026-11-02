<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/**
 * One hit of a search. `$kind` says which index it comes from (the index name; every hit of a single search has the same one,
 * a federated search mixes them), `$id` is the raw key of the document typed by the index's id_type (an int for an integer key, a
 * string for a uuid or string key): the kind tells which entity to load for it.
 */
final readonly class Hit implements \JsonSerializable
{
    /**
     * @param string                    $kind        the index the hit comes from
     * @param array<string, string>     $highlights  field => HTML-safe snippet with <mark> tags (the text is escaped: put it into HTML as it is)
     * @param float|null                $mergedScore the reciprocal-rank-fusion score a federated search orders its merged list by (weight / (60 + rank in its index)), comparable across indexes, unlike `$score`; null outside a federated search
     * @param list<MatchedWord>|null    $matches     which word matched in which field and how: only with SearchBuilder::explainMatches(), null otherwise
     */
    public function __construct(
        public string $kind,
        public int|string $id,
        public float $score,
        public ScoreBreakdown $breakdown,
        public array $highlights = [],
        public ?float $mergedScore = null,
        public ?array $matches = null,
    ) {}

    /** @internal */
    public function withMergedScore(float $mergedScore): self
    {
        return new self($this->kind, $this->id, $this->score, $this->breakdown, $this->highlights, $mergedScore, $this->matches);
    }

    /**
     * @param list<MatchedWord> $matches
     *
     * @internal
     */
    public function withMatches(array $matches): self
    {
        return new self($this->kind, $this->id, $this->score, $this->breakdown, $this->highlights, $this->mergedScore, $matches);
    }

    /**
     * Stable keys: kind, id, score, breakdown (see ScoreBreakdown::toArray()), highlights (HTML-escaped, with <mark>), mergedScore
     * (null outside a federated search) and matches (null unless explainMatches() was asked; else a list of field, word, via, similarity).
     *
     * @return array{kind: string, id: int|string, score: float, breakdown: array<string, float>, highlights: array<string, string>, mergedScore: float|null, matches: list<array{field: string, word: string, via: string, similarity: float|null}>|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'score' => $this->score,
            'breakdown' => $this->breakdown->toArray(),
            'highlights' => $this->highlights,
            'mergedScore' => $this->mergedScore,
            'matches' => $this->matches === null ? null : array_map(static fn(MatchedWord $m): array => $m->toArray(), $this->matches),
        ];
    }

    /** @return array{kind: string, id: int|string, score: float, breakdown: array<string, float>, highlights: array<string, string>, mergedScore: float|null, matches: list<array{field: string, word: string, via: string, similarity: float|null}>|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

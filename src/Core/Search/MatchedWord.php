<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/**
 * One word of a document that a search matched (Hit::$matches, SearchBuilder::explainMatches()): the field it is in, the word as the index
 * stores it (normalised: lower case, accents folded), and how it matched the query. Plain text, not HTML.
 */
final readonly class MatchedWord implements \JsonSerializable
{
    public const string EXACT = 'exact';
    public const string STEM = 'stem';
    public const string PREFIX = 'prefix';
    public const string TYPO = 'typo';
    public const string SYNONYM = 'synonym';

    /**
     * @param string     $via        exact (the word as typed), stem (another form of it), prefix (the typed word starts it), typo (a close spelling) or synonym
     * @param float|null $similarity for a typo: how close it is (1.0 = the same), else null
     */
    public function __construct(
        public string $field,
        public string $word,
        public string $via,
        public ?float $similarity = null,
    ) {}

    /** @return array{field: string, word: string, via: string, similarity: float|null} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'word' => $this->word, 'via' => $this->via, 'similarity' => $this->similarity];
    }

    /** @return array{field: string, word: string, via: string, similarity: float|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

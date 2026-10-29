<?php

declare(strict_types=1);

namespace App\Service;

use Fuzzphony\Core\Search\Hit;

/**
 * "Why was this found?" in plain words, for the rows of the Relevance page. What the library reports is used as it is: the fields whose
 * highlight carries a mark, the text rank (0 = no word matched exactly, only typo tolerance) and `interpretedAs` (the query after its
 * synonyms). For a typo-found row the library only gives the similarity, not the word pair, so Catalog::closestWords() asks PostgreSQL
 * for the pair of the displayed rows (the same measure, pg_trgm word_similarity).
 */
final readonly class MatchReason
{
    public function __construct(private Catalog $catalog) {}

    /** The lower-case words of a query: operators, quotes and excluded (-word) terms left out. */
    public static function words(string $query): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (str_starts_with($part, '-')) {
                continue;
            }
            $words = [...$words, ...(preg_split('/[^\p{L}\p{N}]+/u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [])];
        }

        return $words;
    }

    /**
     * @param list<Hit> $hits
     *
     * @return array<int|string, string> the sentence of each hit id
     */
    public function explain(array $hits, string $query, ?string $interpretedAs): array
    {
        $words = self::words($query);
        $typoIds = [];
        foreach ($hits as $hit) {
            if ($hit->breakdown->textRank <= 0.0) {
                $typoIds[] = $hit->id;
            }
        }
        $pairs = $this->catalog->closestWords($typoIds, $words);
        $reasons = [];
        foreach ($hits as $hit) {
            $reasons[$hit->id] = $hit->breakdown->textRank > 0.0
                ? $this->exact($hit, $words, $interpretedAs)
                : $this->typo($hit, $pairs[$hit->id] ?? null);
        }

        return $reasons;
    }

    /** A word of the query (or its stem) is in the field; or a synonym of it. */
    private function exact(Hit $hit, array $words, ?string $interpretedAs): string
    {
        $found = [];
        foreach (['name', 'brand', 'category'] as $field) {
            if (preg_match_all('~<mark>(.*?)</mark>~su', $hit->highlights[$field] ?? '', $m) > 0) {
                $found[$field] = array_unique(array_map(static fn(string $w): string => mb_strtolower(html_entity_decode($w)), $m[1]));
            }
        }
        if ($found === []) {
            return 'Matched in the description: a word of the query is there.';
        }
        $parts = [];
        $synonym = false;
        foreach ($found as $field => $marked) {
            $parts[] = $field . ' (' . implode(', ', array_map(static fn(string $w): string => '“' . $w . '”', $marked)) . ')';
            foreach ($marked as $w) {
                $synonym = $synonym || (!in_array($w, $words, true) && $interpretedAs !== null && preg_match('~\b' . preg_quote($w, '~') . '\b~iu', $interpretedAs) === 1);
            }
        }

        return 'Matched in ' . implode(' and ', $parts) . ($synonym ? ': a synonym of a word of the query (the catalogue\'s synonyms were expanded on it).' : ': the word itself or its stem.');
    }

    /** @param array{field: string, word: string, token: string, similarity: float, shared: int}|null $pair */
    private function typo(Hit $hit, ?array $pair): string
    {
        if ($pair === null) {
            return sprintf('Matched by typo tolerance (similarity %.2f).', $hit->breakdown->fuzzySimilarity);
        }
        $trigrams = $pair['shared'] === 1 ? 'they share one trigram' : sprintf('they share %d trigrams', $pair['shared']);

        return sprintf('Matched in %s by typo tolerance: “%s” ~ “%s”, similarity %.2f (%s).', $pair['field'], $pair['token'], $pair['word'], $pair['similarity'], $trigrams);
    }
}

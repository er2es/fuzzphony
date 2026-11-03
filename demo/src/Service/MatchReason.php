<?php

declare(strict_types=1);

namespace App\Service;

use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\MatchedWord;

/**
 * "Why was this found?" in plain words, for the rows of the Relevance page. The words come from the library: the hits carry
 * `Hit::$matches` (SearchBuilder::explainMatches()): which stored word of which field matched, and how (exact, stem, prefix,
 * synonym, typo with its similarity). The sentence is built from those; for a typo only the typed word it came from and the
 * trigrams they share are worked out here (pg_trgm's trigram sets).
 */
final readonly class MatchReason
{
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
     * @param list<Hit> $hits hits of a search made with explainMatches()
     *
     * @return array<int|string, string> the sentence of each hit id
     */
    public function explain(array $hits, string $query): array
    {
        $words = self::words($query);
        $reasons = [];
        foreach ($hits as $hit) {
            $reasons[$hit->id] = $hit->breakdown->textRank > 0.0
                ? $this->exact($hit)
                : $this->typo($hit, $words);
        }

        return $reasons;
    }

    /** A word of the query (or its stem) is in the field; or a synonym of it. */
    private function exact(Hit $hit): string
    {
        $found = [];
        $synonym = false;
        foreach ($hit->matches ?? [] as $match) {
            if (!in_array($match->field, ['name', 'brand', 'category'], true) || $match->via === MatchedWord::TYPO) {
                continue;
            }
            $found[$match->field][$match->word] = true;
            $synonym = $synonym || $match->via === MatchedWord::SYNONYM;
        }
        if ($found === []) {
            return 'Matched in the description: a word of the query is there.';
        }
        $parts = [];
        foreach ($found as $field => $marked) {
            $parts[] = $field . ' (' . implode(', ', array_map(static fn(string $w): string => '“' . $w . '”', array_keys($marked))) . ')';
        }

        return 'Matched in ' . implode(' and ', $parts) . ($synonym ? ': a synonym of a word of the query (the catalogue\'s synonyms were expanded on it).' : ': the word itself or its stem.');
    }

    /** @param list<string> $words the words of the query */
    private function typo(Hit $hit, array $words): string
    {
        $best = null;
        foreach ($hit->matches ?? [] as $match) {
            if ($match->via === MatchedWord::TYPO && ($best === null || ($match->similarity ?? 0.0) > ($best->similarity ?? 0.0))) {
                $best = $match;
            }
        }
        $word = $best?->word;
        $field = $best?->field;
        $similarity = $best?->similarity ?? $hit->breakdown->fuzzySimilarity;
        if ($best === null) {
            // explainMatches() names no typo word when the query word is shorter than typo.min_length (the search still found the row by
            // its own rule): the highlighted word of the row is the one that matched
            foreach (['name', 'brand', 'category'] as $candidate) {
                if (preg_match('~<mark>(.*?)</mark>~su', $hit->highlights[$candidate] ?? '', $m) === 1) {
                    [$field, $word] = [$candidate, mb_strtolower(html_entity_decode($m[1]))];
                    break;
                }
            }
            if ($word === null) {
                return sprintf('Matched by typo tolerance (similarity %.2f).', $hit->breakdown->fuzzySimilarity);
            }
        }
        $typed = null;
        $shared = 0;
        foreach ($words as $word) {
            $common = count(array_intersect(self::trigrams($word), self::trigrams($word)));
            if ($typed === null || $common > $shared) {
                [$typed, $shared] = [$word, $common];
            }
        }
        $trigrams = $shared === 1 ? 'they share one trigram' : sprintf('they share %d trigrams', $shared);

        return sprintf('Matched in %s by typo tolerance: “%s” ~ “%s”, similarity %.2f (%s).', $field, $word, $typed ?? '', $similarity, $trigrams);
    }

    /** @return list<string> the distinct trigrams of a word, as pg_trgm sees them (two spaces before, one after) */
    private static function trigrams(string $word): array
    {
        $padded = '  ' . $word . ' ';
        $set = [];
        for ($i = 0, $n = mb_strlen($padded) - 2; $i < $n; ++$i) {
            $set[mb_substr($padded, $i, 3)] = true;
        }

        return array_keys($set);
    }
}

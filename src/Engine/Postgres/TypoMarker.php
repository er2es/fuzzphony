<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal Marks, in a field's text, the words that are the typo-tolerant match of the typed words (the Highlighter
 * uses it for the hits the strict full-text branch did not find, where ts_headline marks nothing or only part).
 * It does the text work only; whether a word matches is PostgreSQL's answer (the same word_similarity rule the search
 * used), handed in as $similarities: lower-cased candidate string => term index => similarity, for the candidates that
 * reached their term's similarity.
 *
 * A word is a run of letters, digits and combining marks (the search's normaliser turns every other character into a
 * space), so an apostrophe or a hyphen separates words, as it does in the search. A term of one word is compared with
 * every word of the text; a term of n words (a phrase) with every run of n consecutive words, and where such runs
 * overlap the most similar one wins. Marked output uses the Highlighter's private-use markers, never HTML.
 */
final class TypoMarker
{
    /** The words a text is looked at in: a bound on the work for a very long document. */
    public const int MAX_WORDS_SCANNED = 2000;
    /** A phrase of more words than this is not looked for (a query can hold a very long one; the work grows with its length). */
    public const int MAX_PHRASE_WORDS = 8;
    /** A text of at most this many words is returned whole; a longer one as fragments. */
    private const int WHOLE_TEXT_WORDS = 30;
    private const int FRAGMENT_WORDS = 15;
    private const int FRAGMENT_LEAD = 3;
    private const int FRAGMENTS = 2;
    private const string DELIMITER = ' … ';

    /** @param list<TypoTerm> $terms */
    public function __construct(private readonly array $terms) {}

    /**
     * The work marking $text costs, in runs of words to compare (what candidates() and the marking each go through): the
     * highlighter spends a budget of them on a page.
     */
    public function cost(string $text, string $field): int
    {
        $count = count(self::tokens($text));
        $cost = 0;
        foreach ($this->applicable($field) as $i) {
            $cost += max(0, $count - $this->terms[$i]->words + 1);
        }

        return $cost;
    }

    /**
     * What to ask PostgreSQL about: the lower-cased words, or runs of words, of $text a term of this field compares with.
     *
     * @return list<string>
     */
    public function candidates(string $text, string $field): array
    {
        $tokens = self::tokens($text);
        $out = [];
        foreach ($this->applicable($field) as $i) {
            foreach (self::runs($tokens, $text, $this->terms[$i]->words) as [, $key]) {
                $out[$key] = true;
            }
        }

        return array_map(strval(...), array_keys($out));
    }

    /**
     * $headline (ts_headline's output, with its markers) with the typo matches marked too. Words ts_headline marked stay as
     * they are; nothing else changes.
     *
     * @param array<string, array<int, float>> $similarities
     */
    public function extend(string $headline, string $field, array $similarities): string
    {
        $tokens = self::tokens($headline);
        $hit = $this->matched($tokens, $headline, $field, $similarities);

        return $hit === [] ? $headline : self::rebuild($headline, $tokens, 0, count($tokens) - 1, $hit, true);
    }

    /**
     * A snippet of $text built around its typo matches, marked like ts_headline's (a short text whole, a long one as up to two
     * fragments of about fifteen words joined by " … "). Null when no word of it matches.
     *
     * @param array<string, array<int, float>> $similarities
     */
    public function snippet(string $text, string $field, array $similarities): ?string
    {
        $text = str_replace([Highlighter::START, Highlighter::STOP], '', $text);
        $tokens = self::tokens($text);
        $hit = $this->matched($tokens, $text, $field, $similarities);
        if ($hit === []) {
            return null;
        }
        $count = count($tokens);
        if ($count <= self::WHOLE_TEXT_WORDS) {
            return self::rebuild($text, $tokens, 0, $count - 1, $hit, true);
        }
        $matches = array_keys($hit);
        sort($matches);
        $fragments = [];
        $from = 0;
        foreach ($matches as $match) {
            if ($match < $from) {
                continue;
            }
            $start = max($from, min($match - self::FRAGMENT_LEAD, $count - self::FRAGMENT_WORDS));
            $end = min($count - 1, $start + self::FRAGMENT_WORDS - 1);
            $fragments[] = self::rebuild($text, $tokens, $start, $end, $hit, false);
            $from = $end + 1;
            if (count($fragments) === self::FRAGMENTS) {
                break;
            }
        }

        return implode(self::DELIMITER, $fragments);
    }

    /** @return list<int> the terms that apply to the field's text: unscoped, or scoped to this field, and not a very long phrase */
    private function applicable(string $field): array
    {
        return array_keys(array_filter($this->terms, static fn(TypoTerm $term): bool => $term->words <= self::MAX_PHRASE_WORDS && ($term->field === null || $term->field === $field)));
    }

    /**
     * The token indexes some term matches.
     *
     * @param list<array{int, int, bool, string}> $tokens
     * @param array<string, array<int, float>>    $similarities
     *
     * @return array<int, true>
     */
    private function matched(array $tokens, string $text, string $field, array $similarities): array
    {
        $hit = [];
        foreach ($this->applicable($field) as $i) {
            $length = $this->terms[$i]->words;
            $found = [];
            foreach (self::runs($tokens, $text, $length) as [$start, $key]) {
                $similarity = $similarities[$key][$i] ?? null;
                if ($similarity !== null) {
                    $found[] = [$start, $similarity];
                }
            }
            if ($length > 1) {
                // overlapping runs of a phrase: the most similar first, the earlier on a tie
                usort($found, static fn(array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);
            }
            $taken = [];
            foreach ($found as [$start]) {
                $range = range($start, $start + $length - 1);
                if ($length > 1 && array_intersect_key(array_flip($range), $taken) !== []) {
                    continue;
                }
                foreach ($range as $token) {
                    $taken[$token] = true;
                    $hit[$token] = true;
                }
            }
        }

        return $hit;
    }

    /**
     * The runs of $length consecutive words that have a word not yet marked, as [index of the first, the lower-cased words
     * joined by a space]. A run of several words may include a marked word (ts_headline marked "red" of "red shoes" for the
     * typed "red shoas"); none crosses a fragment delimiter.
     *
     * @param list<array{int, int, bool, string}> $tokens
     *
     * @return \Generator<int, array{int, string}>
     */
    private static function runs(array $tokens, string $text, int $length): \Generator
    {
        for ($start = 0, $last = count($tokens) - $length; $start <= $last; ++$start) {
            $words = [];
            $open = false;
            for ($k = 0; $k < $length; ++$k) {
                [$from, , $marked, $word] = $tokens[$start + $k];
                if ($k > 0 && !self::contiguous($text, $tokens[$start + $k - 1][1], $from)) {
                    continue 2;
                }
                $open = $open || !$marked;
                $words[] = $word;
            }
            if ($open) {
                yield [$start, implode(' ', $words)];
            }
        }
    }

    /** Whether the text between two words, [$from, $to), holds no fragment delimiter (the words are neighbours in the document). */
    private static function contiguous(string $text, int $from, int $to): bool
    {
        return !str_contains(substr($text, $from, $to - $from), '…');
    }

    /**
     * [start byte, end byte, inside a marked span, lower-cased word], at most MAX_WORDS_SCANNED of them. Offsets are those of a
     * UTF-8 match, so they never fall inside a character. A text that is not valid UTF-8 has no words.
     *
     * @return list<array{int, int, bool, string}>
     */
    private static function tokens(string $text): array
    {
        if (preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $text, $words, PREG_OFFSET_CAPTURE) === false || $words[0] === []) {
            return [];
        }
        $spans = [];
        if (str_contains($text, Highlighter::START)) {
            preg_match_all('/' . Highlighter::START . '.*?' . Highlighter::STOP . '/su', $text, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as [$span, $at]) {
                $spans[] = [$at, $at + strlen($span)];
            }
        }
        $tokens = [];
        foreach (array_slice($words[0], 0, self::MAX_WORDS_SCANNED) as [$word, $at]) {
            $end = $at + strlen($word);
            $marked = array_any($spans, static fn(array $span): bool => $at < $span[1] && $end > $span[0]);
            $tokens[] = [$at, $end, $marked, mb_strtolower($word)];
        }

        return $tokens;
    }

    /**
     * The text from word $first to word $last (the whole text, with what is around the words, when $whole), the
     * words of $hit wrapped in the markers.
     *
     * @param list<array{int, int, bool, string}> $tokens
     * @param array<int, true>                    $hit
     */
    private static function rebuild(string $text, array $tokens, int $first, int $last, array $hit, bool $whole): string
    {
        $out = '';
        $at = $whole ? 0 : $tokens[$first][0];
        for ($i = $first; $i <= $last; ++$i) {
            [$start, $end] = $tokens[$i];
            $word = substr($text, $start, $end - $start);
            $out .= substr($text, $at, $start - $at) . (isset($hit[$i]) && !$tokens[$i][2] ? Highlighter::START . $word . Highlighter::STOP : $word);
            $at = $end;
        }

        return $out . ($whole ? substr($text, $at) : '');
    }
}

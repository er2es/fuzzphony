<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

/**
 * @internal The words a typed word becomes when two neighbouring letters are swapped (`mosue` -> `mouse`), for the
 * typo-tolerant branch (FuzzyQueryCompiler). Trigrams cannot see a swap in a short word: it changes four of the
 * word's n + 1 trigrams, and a word of 4 to 7 letters needs more of them than that leaves. The variants are looked
 * up as whole words, so no vocabulary is needed.
 *
 * Bounded: a word of 4 to 15 letters has at most 14 variants (a longer word keeps most of its trigrams under a swap
 * and is found by similarity anyway), and a query gets at most MAX_PER_QUERY variants in all.
 */
final class Transpositions
{
    /** The shortest word that gets variants. */
    public const int MIN_LENGTH = 4;
    /** The longest word that gets variants: at most MAX_LENGTH - 1 of them. */
    public const int MAX_LENGTH = 15;
    /** The most variants one query adds, over all of its words (a word whose variants do not fit gets none). */
    public const int MAX_PER_QUERY = 48;

    /**
     * The variants of a normalised (lowercase) word, each once, never the word itself. Only a word made of letters
     * has any: a word with a digit is a code (`rtx4090`), and the code with two digits swapped is another product.
     *
     * @return list<string>
     */
    public static function of(string $word): array
    {
        if (preg_match('/^\p{L}+$/u', $word) !== 1) {
            return [];
        }
        $letters = mb_str_split($word);
        $count = count($letters);
        if ($count < self::MIN_LENGTH || $count > self::MAX_LENGTH) {
            return [];
        }
        $variants = [];
        for ($i = 0; $i < $count - 1; ++$i) {
            if ($letters[$i] === $letters[$i + 1]) {
                continue; // the same word
            }
            $swapped = $letters;
            [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
            $variants[implode('', $swapped)] = true;
        }

        return array_map(strval(...), array_keys($variants));
    }
}

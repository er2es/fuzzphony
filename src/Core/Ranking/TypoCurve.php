<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

/**
 * @internal The default typo tolerance: how similar (trigram word similarity) a word must be, by its
 * length, when no explicit fuzzy_similarity is set. One typo is tolerated from 4 letters and two
 * from 8. A typo changes at most three of a word's n + 1 trigrams, so t typos leave a similarity of
 * (n + 1 - 3t) / (n + 1 + 3t) in the worst case; a word needs that plus SLACK, which keeps a
 * different word at exactly the worst case out (`mouse` / `monitor`). The PostgreSQL engine
 * evaluates the same rule on the normalised word (FuzzyQueryCompiler); a test keeps the two equal.
 */
final class TypoCurve
{
    /** [minimum length of the normalised word, typos tolerated], longest first. */
    public const array TYPOS_BY_LENGTH = [[8, 2], [4, 1]];
    /** The similarity a word shorter than the last entry of TYPOS_BY_LENGTH needs. */
    public const float SHORT_SIMILARITY = 0.6;
    public const float SLACK = 0.03;
    /**
     * PostgreSQL measures the length after normalisation (accents folded, `ß` -> `ss`, ligatures
     * split), which for text outside ASCII can differ from the length PHP sees; lowest() allows for it.
     */
    private const int SHRINKS = 1;
    private const int GROWS = 2;

    public static function similarity(int $length): float
    {
        foreach (self::TYPOS_BY_LENGTH as [$minLength, $typos]) {
            if ($length >= $minLength) {
                return ($length + 1 - 3 * $typos) / ($length + 1 + 3 * $typos) + self::SLACK;
            }
        }

        return self::SHORT_SIMILARITY;
    }

    /**
     * The lowest similarity a word of $length letters may need: what the session's pg_trgm threshold
     * is set to for it, so the trigram index returns no more candidates than necessary. An ASCII word
     * keeps its length through normalisation; any other may change it, so the neighbouring lengths count.
     */
    public static function lowest(int $length, bool $ascii): float
    {
        return $ascii
            ? self::similarity($length)
            : min(array_map(self::similarity(...), range(max(1, $length - self::SHRINKS), $length + self::GROWS)));
    }
}

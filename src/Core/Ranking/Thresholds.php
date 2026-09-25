<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;

/**
 * Tolerance and cost limits. Every value has a safe default; override per index or per query.
 */
final readonly class Thresholds
{
    /**
     * Hard caps of the cost limits. They hold even when an application forwards request parameters
     * into ->thresholds(), so an override can tighten the limits but never switch them off.
     */
    public const int MAX_CANDIDATE_LIMIT = 10_000;
    public const int MAX_QUERY_LENGTH = 1_024;
    public const int MAX_TERMS = 64;

    public function __construct(
        /** Minimum relevance (0..~1.5) a hit needs; bonuses are not counted. */
        public float $minScore = 0.0,
        /** Minimum trigram word similarity (0..1) for a typo-tolerant match. Lower = more tolerant. */
        public float $fuzzySimilarity = 0.3,
        /** Typo tolerance is skipped for shorter queries: trigrams of 1-2 letters are noise. */
        public int $fuzzyMinLength = 3,
        public FuzzyMode $fuzzyMode = FuzzyMode::Fallback,
        /** In fallback mode fuzzy matching kicks in when exact matching found fewer hits than this. */
        public int $fallbackBelow = 5,
        /** Upper bound of candidates ranked per branch; protects against "match half the table" queries. At most MAX_CANDIDATE_LIMIT. */
        public int $candidateLimit = 2000,
        /** Longer search text is truncated (with a warning). At most MAX_QUERY_LENGTH. */
        public int $maxQueryLength = 256,
        /** Queries with more terms are truncated (with a warning). At most MAX_TERMS. */
        public int $maxTerms = 16,
        /**
         * A multi-word query that finds nothing is searched once more without the words that match
         * nothing on their own, and the result names them in a warning.
         */
        public bool $relaxWhenEmpty = true,
    ) {
        $violations = [];
        if ($minScore < 0.0) {
            $violations[] = '"minScore" must be >= 0.';
        }
        if ($fuzzySimilarity <= 0.0 || $fuzzySimilarity > 1.0) {
            $violations[] = '"fuzzySimilarity" must be in (0, 1]. Typical values: 0.3 (tolerant) .. 0.6 (strict).';
        }
        if ($fuzzyMinLength < 1) {
            $violations[] = '"fuzzyMinLength" must be >= 1.';
        }
        if ($fallbackBelow < 1) {
            $violations[] = '"fallbackBelow" must be >= 1.';
        }
        if ($candidateLimit < 10) {
            $violations[] = '"candidateLimit" must be >= 10.';
        }
        if ($candidateLimit > self::MAX_CANDIDATE_LIMIT) {
            $violations[] = sprintf('"candidateLimit" must be <= %d.', self::MAX_CANDIDATE_LIMIT);
        }
        if ($maxQueryLength < 1 || $maxTerms < 1) {
            $violations[] = '"maxQueryLength" and "maxTerms" must be >= 1.';
        }
        if ($maxQueryLength > self::MAX_QUERY_LENGTH) {
            $violations[] = sprintf('"maxQueryLength" must be <= %d.', self::MAX_QUERY_LENGTH);
        }
        if ($maxTerms > self::MAX_TERMS) {
            $violations[] = sprintf('"maxTerms" must be <= %d.', self::MAX_TERMS);
        }
        if ($violations !== []) {
            throw new InvalidDefinition('thresholds', $violations);
        }
    }

    /** @param array<string, mixed> $overrides snake_case keys, e.g. ['min_score' => 0.1, 'fuzzy_mode' => 'always'] */
    public function with(array $overrides): self
    {
        $map = [
            'min_score' => 'minScore',
            'fuzzy_similarity' => 'fuzzySimilarity',
            'fuzzy_min_length' => 'fuzzyMinLength',
            'fuzzy_mode' => 'fuzzyMode',
            'fallback_below' => 'fallbackBelow',
            'candidate_limit' => 'candidateLimit',
            'max_query_length' => 'maxQueryLength',
            'max_terms' => 'maxTerms',
            'relax_when_empty' => 'relaxWhenEmpty',
        ];
        $unknown = array_diff(array_keys($overrides), array_keys($map));
        if ($unknown !== []) {
            throw new InvalidDefinition('thresholds', [sprintf('Unknown threshold option(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', array_keys($map)))]);
        }

        $minScore = $this->minScore;
        $fuzzySimilarity = $this->fuzzySimilarity;
        $fuzzyMinLength = $this->fuzzyMinLength;
        $fuzzyMode = $this->fuzzyMode;
        $fallbackBelow = $this->fallbackBelow;
        $candidateLimit = $this->candidateLimit;
        $maxQueryLength = $this->maxQueryLength;
        $maxTerms = $this->maxTerms;
        $relaxWhenEmpty = $this->relaxWhenEmpty;

        foreach ($overrides as $key => $value) {
            $property = $map[$key];
            match ($property) {
                'minScore' => $minScore = is_numeric($value) ? (float) $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be a number.', $key)]),
                'fuzzySimilarity' => $fuzzySimilarity = is_numeric($value) ? (float) $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be a number.', $key)]),
                'fuzzyMinLength' => $fuzzyMinLength = is_int($value) ? $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be an integer.', $key)]),
                'fuzzyMode' => $fuzzyMode = $value instanceof FuzzyMode ? $value : (FuzzyMode::tryFrom(is_string($value) ? $value : '') ?? throw new InvalidDefinition('thresholds', [sprintf('"%s" must be one of: %s.', $key, implode(', ', array_map(static fn(FuzzyMode $m): string => $m->value, FuzzyMode::cases())))])),
                'fallbackBelow' => $fallbackBelow = is_int($value) ? $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be an integer.', $key)]),
                'candidateLimit' => $candidateLimit = is_int($value) ? $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be an integer.', $key)]),
                'maxQueryLength' => $maxQueryLength = is_int($value) ? $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be an integer.', $key)]),
                'relaxWhenEmpty' => $relaxWhenEmpty = self::bool($value) ?? throw new InvalidDefinition('thresholds', [sprintf('"%s" must be a boolean.', $key)]),
                default => $maxTerms = is_int($value) ? $value : throw new InvalidDefinition('thresholds', [sprintf('"%s" must be an integer.', $key)]),
            };
        }

        return new self(
            minScore: $minScore,
            fuzzySimilarity: $fuzzySimilarity,
            fuzzyMinLength: $fuzzyMinLength,
            fuzzyMode: $fuzzyMode,
            fallbackBelow: $fallbackBelow,
            candidateLimit: $candidateLimit,
            maxQueryLength: $maxQueryLength,
            maxTerms: $maxTerms,
            relaxWhenEmpty: $relaxWhenEmpty,
        );
    }

    /** true / false, also as 1 / 0 or "true" / "false" / "yes" / "no" (command-line overrides are strings). */
    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === '' => null, // filter_var() reads an empty string as false
            is_int($value), is_string($value) => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            default => null,
        };
    }
}

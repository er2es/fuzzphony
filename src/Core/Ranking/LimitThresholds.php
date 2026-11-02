<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;

/**
 * The `limits` group of the thresholds: cost caps. They hold even when an application forwards request parameters
 * into ->thresholds(), so an override can tighten the limits but never switch them off.
 */
final readonly class LimitThresholds
{
    private const array TYPES = ['candidates' => 'int', 'max_query_length' => 'int', 'max_terms' => 'int'];

    public function __construct(
        /**
         * Candidates ranked per branch; protects against "match half the table" queries. At most Thresholds::MAX_CANDIDATE_LIMIT. A word with
         * more matches than this is ranked over its best matches in the full-text branch (and in a restricted viewer's typo-tolerant one, within a smaller work
         * budget); the typo-tolerant branch of everyone else takes the first matches of its trigram index, a lower bound. A page past it selects `offset + limit`
         * candidates in both, up to Thresholds::MAX_CANDIDATE_LIMIT.
         */
        public int $candidates = 2000,
        /** Longer search text is truncated (with a warning). At most Thresholds::MAX_QUERY_LENGTH. */
        public int $maxQueryLength = Thresholds::DEFAULT_MAX_QUERY_LENGTH,
        /** Queries with more terms are truncated (with a warning). At most Thresholds::MAX_TERMS. */
        public int $maxTerms = 16,
    ) {
        $violations = [];
        if ($candidates < 10) {
            $violations[] = '"candidates" must be >= 10.';
        }
        if ($candidates > Thresholds::MAX_CANDIDATE_LIMIT) {
            $violations[] = sprintf('"candidates" must be <= %d.', Thresholds::MAX_CANDIDATE_LIMIT);
        }
        if ($maxQueryLength < 1 || $maxTerms < 1) {
            $violations[] = '"max_query_length" and "max_terms" must be >= 1.';
        }
        if ($maxQueryLength > Thresholds::MAX_QUERY_LENGTH) {
            $violations[] = sprintf('"max_query_length" must be <= %d.', Thresholds::MAX_QUERY_LENGTH);
        }
        if ($maxTerms > Thresholds::MAX_TERMS) {
            $violations[] = sprintf('"max_terms" must be <= %d.', Thresholds::MAX_TERMS);
        }
        if ($violations !== []) {
            throw new InvalidDefinition('thresholds.limits', $violations);
        }
    }

    /** @param array<array-key, mixed> $overrides e.g. ['candidates' => 1000] */
    public function with(array $overrides): self
    {
        $v = ThresholdOptions::apply('limits', self::TYPES, $overrides, [
            'candidates' => $this->candidates, 'max_query_length' => $this->maxQueryLength, 'max_terms' => $this->maxTerms,
        ]);

        return new self(
            candidates: is_int($v['candidates']) ? $v['candidates'] : 2000,
            maxQueryLength: is_int($v['max_query_length']) ? $v['max_query_length'] : Thresholds::DEFAULT_MAX_QUERY_LENGTH,
            maxTerms: is_int($v['max_terms']) ? $v['max_terms'] : 16,
        );
    }
}

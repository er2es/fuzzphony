<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;

/** The `relevance` group of the thresholds. */
final readonly class RelevanceThresholds
{
    private const array TYPES = ['min_score' => 'float'];

    public function __construct(
        /** Minimum relevance (0..~1.5) a hit needs; bonuses are not counted. */
        public float $minScore = 0.0,
    ) {
        if ($minScore < 0.0) {
            throw new InvalidDefinition('thresholds.relevance', ['"min_score" must be >= 0.']);
        }
    }

    /** @param array<array-key, mixed> $overrides e.g. ['min_score' => 0.1] */
    public function with(array $overrides): self
    {
        $v = ThresholdOptions::apply('relevance', self::TYPES, $overrides, ['min_score' => $this->minScore]);

        return new self(is_float($v['min_score']) ? $v['min_score'] : 0.0);
    }
}

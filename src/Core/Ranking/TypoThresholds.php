<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;

/** The `typo` group of the thresholds: how typo tolerance behaves. */
final readonly class TypoThresholds
{
    private const array TYPES = ['similarity' => '?float', 'min_length' => 'int', 'mode' => 'mode', 'fallback_below' => 'int'];

    public function __construct(
        /**
         * Minimum trigram word similarity (0..1) for a typo-tolerant match. Lower = more tolerant.
         * Null (the default): by word length (see TypoCurve). A number applies to every word.
         */
        public ?float $similarity = null,
        /** Typo tolerance is skipped for shorter queries: trigrams of 1-2 letters are noise. */
        public int $minLength = 3,
        public FuzzyMode $mode = FuzzyMode::Fallback,
        /** In fallback mode fuzzy matching kicks in when exact matching found fewer hits than this. Also the default of RescueThresholds::$didYouMeanBelow. */
        public int $fallbackBelow = 5,
    ) {
        $violations = [];
        if ($similarity !== null && ($similarity <= 0.0 || $similarity > 1.0)) {
            $violations[] = '"similarity" must be in (0, 1]. Typical values: 0.3 (tolerant) .. 0.6 (strict).';
        }
        if ($minLength < 1) {
            $violations[] = '"min_length" must be >= 1.';
        }
        if ($fallbackBelow < 1) {
            $violations[] = '"fallback_below" must be >= 1.';
        }
        if ($violations !== []) {
            throw new InvalidDefinition('thresholds.typo', $violations);
        }
    }

    /** @param array<array-key, mixed> $overrides e.g. ['mode' => 'always', 'similarity' => 0.4] */
    public function with(array $overrides): self
    {
        $v = ThresholdOptions::apply('typo', self::TYPES, $overrides, [
            'similarity' => $this->similarity, 'min_length' => $this->minLength, 'mode' => $this->mode, 'fallback_below' => $this->fallbackBelow,
        ]);

        return new self(
            similarity: is_float($v['similarity']) ? $v['similarity'] : null,
            minLength: is_int($v['min_length']) ? $v['min_length'] : 3,
            mode: $v['mode'] instanceof FuzzyMode ? $v['mode'] : FuzzyMode::Fallback,
            fallbackBelow: is_int($v['fallback_below']) ? $v['fallback_below'] : 5,
        );
    }
}

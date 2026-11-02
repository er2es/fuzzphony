<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;

/** The `rescue` group of the thresholds: what happens when a search finds little or nothing. */
final readonly class RescueThresholds
{
    private const array TYPES = ['drop_unmatched' => 'bool', 'did_you_mean' => 'bool', 'correct_when_empty' => 'bool', 'did_you_mean_below' => '?int'];

    public function __construct(
        /**
         * A multi-word query that finds nothing is searched once more without the words that match
         * nothing on their own, and the result names them in a warning.
         */
        public bool $dropUnmatched = true,
        /**
         * A search that finds fewer hits than didYouMeanBelow, or had to use typo tolerance, with a word that
         * the index does not know, suggests the spelling it probably meant (SearchResult::$didYouMean).
         */
        public bool $didYouMean = true,
        /**
         * A search that found nothing, and has a word with a unique one-edit spelling in the did-you-mean vocabulary
         * (`mpuse` -> `mouse`), runs once more with the corrected words and returns that result, flagged
         * (SearchResult::$correctedFrom). It uses the did-you-mean lookup, so it needs didYouMean too.
         */
        public bool $correctWhenEmpty = true,
        /** The did-you-mean trigger: fewer hits than this. Null (the default): TypoThresholds::$fallbackBelow. */
        public ?int $didYouMeanBelow = null,
    ) {
        $violations = [];
        if ($correctWhenEmpty && !$didYouMean) {
            $violations[] = '"correct_when_empty" needs "did_you_mean" (the correction reads the did-you-mean vocabulary): set "correct_when_empty: false" together with "did_you_mean: false".';
        }
        if ($didYouMeanBelow !== null && $didYouMeanBelow < 1) {
            $violations[] = '"did_you_mean_below" must be >= 1 (or null: the same as typo.fallback_below).';
        }
        if ($violations !== []) {
            throw new InvalidDefinition('thresholds.rescue', $violations);
        }
    }

    /** @param array<array-key, mixed> $overrides e.g. ['correct_when_empty' => false] */
    public function with(array $overrides): self
    {
        $v = ThresholdOptions::apply('rescue', self::TYPES, $overrides, [
            'drop_unmatched' => $this->dropUnmatched, 'did_you_mean' => $this->didYouMean,
            'correct_when_empty' => $this->correctWhenEmpty, 'did_you_mean_below' => $this->didYouMeanBelow,
        ]);

        return new self(
            dropUnmatched: $v['drop_unmatched'] === true,
            didYouMean: $v['did_you_mean'] === true,
            correctWhenEmpty: $v['correct_when_empty'] === true,
            didYouMeanBelow: is_int($v['did_you_mean_below']) ? $v['did_you_mean_below'] : null,
        );
    }
}

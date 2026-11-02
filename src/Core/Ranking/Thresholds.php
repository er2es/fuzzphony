<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Closest;

/**
 * Tolerance and cost limits in four named groups: relevance, typo, rescue and limits. Every value has a safe default; override per index or per query.
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

    /** The default of maxQueryLength; the query parser and the synonym expander cut a text at the same length. */
    public const int DEFAULT_MAX_QUERY_LENGTH = 256;

    /** The flat names of 0.9: refused with the key that replaces them. */
    private const array RENAMED = [
        'min_score' => 'relevance.min_score',
        'fuzzy_similarity' => 'typo.similarity',
        'fuzzy_min_length' => 'typo.min_length',
        'fuzzy_mode' => 'typo.mode',
        'fallback_below' => 'typo.fallback_below (the did-you-mean trigger: rescue.did_you_mean_below)',
        'relax_when_empty' => 'rescue.drop_unmatched',
        'did_you_mean' => 'rescue.did_you_mean',
        'rescue_empty' => 'rescue.correct_when_empty',
        'candidate_limit' => 'limits.candidates',
        'max_query_length' => 'limits.max_query_length',
        'max_terms' => 'limits.max_terms',
    ];

    public function __construct(
        public RelevanceThresholds $relevance = new RelevanceThresholds(),
        public TypoThresholds $typo = new TypoThresholds(),
        public RescueThresholds $rescue = new RescueThresholds(),
        public LimitThresholds $limits = new LimitThresholds(),
    ) {
    }

    /**
     * Applies overrides given per group.
     *
     * @param array<array-key, mixed> $overrides e.g. ['typo' => ['mode' => 'always'], 'limits' => ['candidates' => 1000]]
     */
    public function with(array $overrides): self
    {
        $problems = [];
        foreach ($overrides as $key => $value) {
            $key = (string) $key;
            if (isset(self::RENAMED[$key])) {
                $problems[] = sprintf('"%s" was renamed: use "%s" (thresholds are grouped: relevance, typo, rescue, limits).', $key, self::RENAMED[$key]);
            } elseif (!in_array($key, ['relevance', 'typo', 'rescue', 'limits'], true)) {
                $suggestion = Closest::of($key, ['relevance', 'typo', 'rescue', 'limits']);
                $problems[] = sprintf('Unknown threshold group "%s".%s Allowed: relevance, typo, rescue, limits.', $key, $suggestion !== null ? sprintf(' Did you mean "%s"?', $suggestion) : '');
            } elseif (!is_array($value)) {
                $problems[] = sprintf('"%s" must be a map of options, e.g. %s: { %s }.', $key, $key, ['relevance' => 'min_score: 0.1', 'typo' => 'mode: always', 'rescue' => 'did_you_mean: false, correct_when_empty: false', 'limits' => 'candidates: 1000'][$key]);
            }
        }
        if ($problems !== []) {
            throw new InvalidDefinition('thresholds', $problems);
        }

        return new self(
            relevance: isset($overrides['relevance']) && is_array($overrides['relevance']) ? $this->relevance->with($overrides['relevance']) : $this->relevance,
            typo: isset($overrides['typo']) && is_array($overrides['typo']) ? $this->typo->with($overrides['typo']) : $this->typo,
            rescue: isset($overrides['rescue']) && is_array($overrides['rescue']) ? $this->rescue->with($overrides['rescue']) : $this->rescue,
            limits: isset($overrides['limits']) && is_array($overrides['limits']) ? $this->limits->with($overrides['limits']) : $this->limits,
        );
    }

    /** @internal Fewer hits than this trigger the did-you-mean lookup. */
    public function didYouMeanBelow(): int
    {
        return $this->rescue->didYouMeanBelow ?? $this->typo->fallbackBelow;
    }
}

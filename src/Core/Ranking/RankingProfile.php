<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;

/**
 * How a hit's score is composed:
 *
 *   relevance = text * fullTextRank + fuzzy * trigramSimilarity        (both normalised to 0..1)
 *   score     = relevance + exactBonus + prefixBonus + boost * boostColumn + recency * decay(age)
 *
 * Thresholds::$minScore is applied to "relevance" only, so boosts can reorder relevant
 * documents but can never pull an irrelevant document into the result set.
 */
final readonly class RankingProfile
{
    /** @var array{A: float, B: float, C: float, D: float} */
    public array $labelWeights;

    /** @param array<string, float|int> $labelWeights Relative importance of weight labels A..D inside full-text ranking. */
    public function __construct(
        public float $text = 1.0,
        public float $fuzzy = 0.5,
        public float $exactBonus = 0.5,
        public float $prefixBonus = 0.2,
        public float $boost = 0.0,
        public float $recency = 0.0,
        public float $recencyHalfLifeDays = 30.0,
        array $labelWeights = ['A' => 1.0, 'B' => 0.4, 'C' => 0.2, 'D' => 0.1],
    ) {
        $weights = ['A' => 1.0, 'B' => 0.4, 'C' => 0.2, 'D' => 0.1];
        foreach ($labelWeights as $label => $weight) {
            $label = strtoupper((string) $label);
            if (!array_key_exists($label, $weights)) {
                throw new InvalidDefinition('ranking', [sprintf('Unknown weight label "%s"; use A, B, C or D.', $label)]);
            }
            $weights[$label] = (float) $weight;
        }
        $this->labelWeights = $weights;

        $violations = [];
        foreach (['text' => $text, 'fuzzy' => $fuzzy, 'exactBonus' => $exactBonus, 'prefixBonus' => $prefixBonus, 'boost' => $boost, 'recency' => $recency] as $name => $value) {
            if ($value < 0.0 || !is_finite($value)) {
                $violations[] = sprintf('Ranking weight "%s" must be a finite number >= 0, got %s.', $name, $value);
            }
        }
        foreach ($weights as $label => $weight) {
            if ($weight < 0.0 || $weight > 1.0) {
                $violations[] = sprintf('Label weight %s must be between 0 and 1, got %s.', $label, $weight);
            }
        }
        if ($text === 0.0 && $fuzzy === 0.0) {
            $violations[] = 'At least one of "text" or "fuzzy" must be greater than 0, otherwise nothing is relevant.';
        }
        if ($recencyHalfLifeDays <= 0.0) {
            $violations[] = '"recencyHalfLifeDays" must be greater than 0.';
        }
        if ($violations !== []) {
            throw new InvalidDefinition('ranking', $violations);
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $allowed = ['text', 'fuzzy', 'exact_bonus', 'prefix_bonus', 'boost', 'recency', 'recency_half_life_days', 'label_weights'];
        $unknown = array_diff(array_keys($config), $allowed);
        if ($unknown !== []) {
            throw new InvalidDefinition('ranking', [sprintf('Unknown ranking option(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', $allowed))]);
        }
        $default = new self();
        /** @var array<string, float|int> $labels */
        $labels = $config['label_weights'] ?? $default->labelWeights;

        return new self(
            text: self::float($config, 'text', $default->text),
            fuzzy: self::float($config, 'fuzzy', $default->fuzzy),
            exactBonus: self::float($config, 'exact_bonus', $default->exactBonus),
            prefixBonus: self::float($config, 'prefix_bonus', $default->prefixBonus),
            boost: self::float($config, 'boost', $default->boost),
            recency: self::float($config, 'recency', $default->recency),
            recencyHalfLifeDays: self::float($config, 'recency_half_life_days', $default->recencyHalfLifeDays),
            labelWeights: $labels,
        );
    }

    /**
     * Returns a copy with some weights replaced; used for per-query tuning (playground, A/B tests).
     *
     * @param array<string, mixed> $overrides snake_case keys, same as fromArray()
     */
    public function with(array $overrides): self
    {
        return self::fromArray([...$this->toArray(), ...$overrides]);
    }

    /** @return array<string, mixed> snake_case, round-trips through fromArray() */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'fuzzy' => $this->fuzzy,
            'exact_bonus' => $this->exactBonus,
            'prefix_bonus' => $this->prefixBonus,
            'boost' => $this->boost,
            'recency' => $this->recency,
            'recency_half_life_days' => $this->recencyHalfLifeDays,
            'label_weights' => $this->labelWeights,
        ];
    }

    /** PostgreSQL ts_rank weights array literal, ordered {D, C, B, A}. */
    public function tsRankWeights(): string
    {
        $w = $this->labelWeights;

        return sprintf('{%s,%s,%s,%s}', self::num($w['D']), self::num($w['C']), self::num($w['B']), self::num($w['A']));
    }

    /** @param array<string, mixed> $config */
    private static function float(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidDefinition('ranking', [sprintf('Ranking option "%s" must be a number.', $key)]);
        }

        return (float) $value;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}

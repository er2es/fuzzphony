<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** Why a hit got its score. Shown by explain() and the demo playground. */
final readonly class ScoreBreakdown
{
    /** @internal */
    public function __construct(
        public float $textRank = 0.0,
        public float $fuzzySimilarity = 0.0,
        public float $relevance = 0.0,
        public float $exactBonus = 0.0,
        public float $prefixBonus = 0.0,
        public float $boostBonus = 0.0,
        public float $recencyBonus = 0.0,
        /**
         * With explainMatches(): for each `many: { rank: best }` field the viewer may see whose best single value gave the text rank, the
         * position of that value (MatchedWord::$valueOrd numbering, from 0); a field whose document kept the whole-field rank is absent.
         *
         * @var array<string, int>
         */
        public array $bestValues = [],
    ) {}

    public function total(): float
    {
        return $this->relevance + $this->exactBonus + $this->prefixBonus + $this->boostBonus + $this->recencyBonus;
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return [
            'textRank' => $this->textRank,
            'fuzzySimilarity' => $this->fuzzySimilarity,
            'relevance' => $this->relevance,
            'exactBonus' => $this->exactBonus,
            'prefixBonus' => $this->prefixBonus,
            'boostBonus' => $this->boostBonus,
            'recencyBonus' => $this->recencyBonus,
        ];
    }
}

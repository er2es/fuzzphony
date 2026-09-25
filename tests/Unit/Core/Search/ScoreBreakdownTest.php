<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Search\ScoreBreakdown;
use PHPUnit\Framework\TestCase;

final class ScoreBreakdownTest extends TestCase
{
    public function testTotalSumsRelevanceAndBonusesButNotFuzzySimilarityOrTextRank(): void
    {
        $breakdown = new ScoreBreakdown(
            textRank: 0.9,
            fuzzySimilarity: 0.8,
            relevance: 1.0,
            exactBonus: 0.5,
            prefixBonus: 0.2,
            boostBonus: 0.1,
            recencyBonus: 0.05,
        );

        self::assertSame(1.85, $breakdown->total());
    }

    public function testToArrayExposesEveryComponentBySnakelessName(): void
    {
        $breakdown = new ScoreBreakdown(0.9, 0.8, 1.0, 0.5, 0.2, 0.1, 0.05);

        self::assertSame([
            'textRank' => 0.9,
            'fuzzySimilarity' => 0.8,
            'relevance' => 1.0,
            'exactBonus' => 0.5,
            'prefixBonus' => 0.2,
            'boostBonus' => 0.1,
            'recencyBonus' => 0.05,
        ], $breakdown->toArray());
    }
}

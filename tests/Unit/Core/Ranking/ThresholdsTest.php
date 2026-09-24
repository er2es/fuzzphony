<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Ranking\Thresholds;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    public function testWithOverridesOnlyTheGivenKeys(): void
    {
        $base = new Thresholds();
        $changed = $base->with(['min_score' => 0.2, 'fuzzy_mode' => 'always', 'candidate_limit' => 500]);

        self::assertSame(0.2, $changed->minScore);
        self::assertSame(FuzzyMode::Always, $changed->fuzzyMode);
        self::assertSame(500, $changed->candidateLimit);
        self::assertSame($base->fuzzySimilarity, $changed->fuzzySimilarity);
        self::assertSame(0.0, $base->minScore, 'the original is untouched');
    }

    public function testUnknownKeysAreReportedWithTheAllowedList(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/min_scor\b.*Allowed: min_score/');
        (new Thresholds())->with(['min_scor' => 1]);
    }

    public function testRangesAreValidated(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/fuzzySimilarity.*0\.3 \(tolerant\)/');
        new Thresholds(fuzzySimilarity: 1.5);
    }

    public function testTypesAreValidated(): void
    {
        $this->expectException(InvalidDefinition::class);
        (new Thresholds())->with(['candidate_limit' => 'lots']);
    }
}

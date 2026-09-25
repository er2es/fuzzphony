<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;
use PHPUnit\Framework\TestCase;

final class RankingProfileTest extends TestCase
{
    public function testDefaultsAreSensible(): void
    {
        $profile = new RankingProfile();

        self::assertSame(1.0, $profile->text);
        self::assertSame('{0.1,0.2,0.4,1}', $profile->tsRankWeights());
    }

    public function testLabelWeightsAreMergedWithDefaults(): void
    {
        self::assertSame('{0.1,0.2,0.8,1}', (new RankingProfile(labelWeights: ['b' => 0.8]))->tsRankWeights());
    }

    public function testCollectsEveryViolation(): void
    {
        try {
            new RankingProfile(text: 0.0, fuzzy: 0.0, boost: -1.0, recencyHalfLifeDays: 0.0);
            self::fail('Expected an exception');
        } catch (InvalidDefinition $e) {
            self::assertCount(3, $e->violations);
            self::assertStringContainsString('"boost"', $e->getMessage());
            self::assertStringContainsString('nothing is relevant', $e->getMessage());
        }
    }

    public function testFromArrayRejectsTypos(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/Unknown ranking option\(s\): boots/');
        RankingProfile::fromArray(['boots' => 1]);
    }

    public function testFromArray(): void
    {
        $profile = RankingProfile::fromArray(['text' => 2, 'recency' => 0.5, 'recency_half_life_days' => 7, 'label_weights' => ['A' => 1, 'B' => 0.5]]);

        self::assertSame(2.0, $profile->text);
        self::assertSame(7.0, $profile->recencyHalfLifeDays);
        self::assertSame(0.5, $profile->labelWeights['B']);
    }

    public function testWithOverridesAndRoundTrips(): void
    {
        $base = new RankingProfile(boost: 0.1);
        $tuned = $base->with(['fuzzy' => 0.9, 'label_weights' => ['A' => 0.8]]);

        self::assertSame(0.9, $tuned->fuzzy);
        self::assertSame(0.1, $tuned->boost, 'untouched values are kept');
        self::assertSame(0.8, $tuned->labelWeights['A']);
        self::assertEquals($base, RankingProfile::fromArray($base->toArray()));
    }

    public function testAnUnknownWeightLabelIsRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Unknown weight label "E"; use A, B, C or D.');
        new RankingProfile(labelWeights: ['E' => 0.5]);
    }

    public function testALabelWeightOutOfRangeIsRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Label weight A must be between 0 and 1, got 1.5.');
        new RankingProfile(labelWeights: ['A' => 1.5]);
    }

    public function testFromArrayRejectsANonNumericOption(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Ranking option "text" must be a number.');
        RankingProfile::fromArray(['text' => 'a lot']);
    }
}

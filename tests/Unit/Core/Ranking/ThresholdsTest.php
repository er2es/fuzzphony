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

    public function testRelaxWhenEmptyIsOnByDefaultAndOverridable(): void
    {
        $base = new Thresholds();

        self::assertTrue($base->relaxWhenEmpty);
        self::assertFalse($base->with(['relax_when_empty' => false])->relaxWhenEmpty);
        self::assertFalse($base->with(['relax_when_empty' => 'false'])->relaxWhenEmpty, 'as passed by --threshold relax_when_empty=false');
        self::assertFalse($base->with(['relax_when_empty' => 0])->relaxWhenEmpty);
        self::assertTrue($base->with(['relax_when_empty' => false])->with(['relax_when_empty' => 'true'])->relaxWhenEmpty);
        self::assertFalse($base->with(['relax_when_empty' => false])->with(['min_score' => 0.1])->relaxWhenEmpty, 'kept by other overrides');
    }

    public function testRelaxWhenEmptyMustBeABoolean(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"relax_when_empty" must be a boolean/');
        (new Thresholds())->with(['relax_when_empty' => 'maybe']);
    }

    public function testRelaxWhenEmptyRejectsAnEmptyString(): void
    {
        // "--threshold relax_when_empty=" must not silently switch the feature off
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"relax_when_empty" must be a boolean/');
        (new Thresholds())->with(['relax_when_empty' => '']);
    }

    public function testCostLimitsHaveHardMaximums(): void
    {
        $max = new Thresholds(
            candidateLimit: Thresholds::MAX_CANDIDATE_LIMIT,
            maxQueryLength: Thresholds::MAX_QUERY_LENGTH,
            maxTerms: Thresholds::MAX_TERMS,
        );
        self::assertSame(10_000, $max->candidateLimit);
        self::assertSame(1_024, $max->maxQueryLength);
        self::assertSame(64, $max->maxTerms);

        foreach ([
            'candidate_limit' => ['"candidateLimit" must be <= 10000', 10_001],
            'max_query_length' => ['"maxQueryLength" must be <= 1024', 1_025],
            'max_terms' => ['"maxTerms" must be <= 64', 65],
        ] as $key => [$message, $value]) {
            try {
                (new Thresholds())->with([$key => $value]);
                self::fail(sprintf('%s = %d must be rejected', $key, $value));
            } catch (InvalidDefinition $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testAllCapViolationsAreReportedTogether(): void
    {
        try {
            new Thresholds(candidateLimit: PHP_INT_MAX, maxQueryLength: PHP_INT_MAX, maxTerms: PHP_INT_MAX);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertCount(3, $e->violations);
        }
    }

    public function testAnUnknownFuzzyModeIsAnInvalidDefinition(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"fuzzy_mode" must be one of: always, fallback, never/');
        (new Thresholds())->with(['fuzzy_mode' => 'bogus']);
    }

    public function testLowerBoundsAreValidated(): void
    {
        foreach ([
            ['minScore' => -0.1, 'message' => '"minScore" must be >= 0.'],
            ['fuzzyMinLength' => 0, 'message' => '"fuzzyMinLength" must be >= 1.'],
            ['fallbackBelow' => 0, 'message' => '"fallbackBelow" must be >= 1.'],
            ['candidateLimit' => 5, 'message' => '"candidateLimit" must be >= 10.'],
            ['maxQueryLength' => 0, 'message' => '"maxQueryLength" and "maxTerms" must be >= 1.'],
            ['maxTerms' => 0, 'message' => '"maxQueryLength" and "maxTerms" must be >= 1.'],
        ] as $case) {
            $message = $case['message'];
            unset($case['message']);
            try {
                new Thresholds(...$case);
                self::fail(sprintf('%s must be rejected', implode(',', array_keys($case))));
            } catch (InvalidDefinition $e) {
                self::assertContains($message, $e->violations);
            }
        }
    }

    public function testFuzzySimilarityOverrideMustBeNumeric(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"fuzzy_similarity" must be a number\./');
        (new Thresholds())->with(['fuzzy_similarity' => 'lots']);
    }

    public function testFuzzyMinLengthOverrideMustBeAnInteger(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"fuzzy_min_length" must be an integer\./');
        (new Thresholds())->with(['fuzzy_min_length' => 'three']);
    }
}

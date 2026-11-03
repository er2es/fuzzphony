<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Ranking\LimitThresholds;
use Fuzzphony\Core\Ranking\RelevanceThresholds;
use Fuzzphony\Core\Ranking\RescueThresholds;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Ranking\TypoThresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    public function testWithOverridesOnlyTheGivenKeys(): void
    {
        $base = new Thresholds();
        $changed = $base->with(['relevance' => ['min_score' => 0.2], 'typo' => ['mode' => 'always'], 'limits' => ['candidates' => 500]]);

        self::assertSame(0.2, $changed->relevance->minScore);
        self::assertSame(FuzzyMode::Always, $changed->typo->mode);
        self::assertSame(500, $changed->limits->candidates);
        self::assertSame($base->typo->similarity, $changed->typo->similarity);
        self::assertSame($base->rescue, $changed->rescue, 'a group that is not named is untouched');
        self::assertSame(0.0, $base->relevance->minScore, 'the original is untouched');
    }

    public function testSimilarityIsByWordLengthByDefaultAndAnExplicitNumberIsKept(): void
    {
        self::assertNull((new Thresholds())->typo->similarity);
        self::assertSame(0.5, (new Thresholds(typo: new TypoThresholds(similarity: 0.5)))->typo->similarity);
    }

    public function testNullReturnsToLengthAwareAndStillRejectsOtherTypes(): void
    {
        $flat = (new Thresholds())->with(['typo' => ['similarity' => 0.5]]);
        self::assertSame(0.5, $flat->typo->similarity);
        self::assertNull($flat->with(['typo' => ['similarity' => null]])->typo->similarity);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"similarity" must be a number\./');
        $flat->with(['typo' => ['similarity' => 'lots']]);
    }

    public function testStringOverridesFromTheCommandLineBecomeNumbers(): void
    {
        $t = (new Thresholds())->with(['typo' => ['similarity' => '0.5', 'min_length' => '5'], 'limits' => ['max_terms' => '8']]);

        self::assertSame(0.5, $t->typo->similarity);
        self::assertSame(5, $t->typo->minLength);
        self::assertSame(8, $t->limits->maxTerms);
        self::assertSame(256, $t->limits->maxQueryLength, 'min_length must not land in another setting');
    }

    public function testAnExplicitSimilarityOfZeroIsStillRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        new TypoThresholds(similarity: 0.0);
    }

    public function testUnknownKeysAreReportedWithAnAdviceAndTheAllowedList(): void
    {
        try {
            (new Thresholds())->with(['relevance' => ['min_scor' => 1]]);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertSame('thresholds.relevance', $e->index);
            self::assertStringContainsString('Unknown option "min_scor". Did you mean "min_score"? Allowed: min_score.', $e->violations[0]);
        }
    }

    public function testAnUnknownGroupIsReportedWithAnAdvice(): void
    {
        try {
            (new Thresholds())->with(['typos' => ['mode' => 'never']]);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertSame('thresholds', $e->index);
            self::assertStringContainsString('Unknown threshold group "typos". Did you mean "typo"?', $e->violations[0]);
        }
    }

    public function testAGroupNeedsAMap(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"typo" must be a map of options/');
        (new Thresholds())->with(['typo' => 'always']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function flatNames(): iterable
    {
        yield 'min_score' => ['min_score', 'relevance.min_score'];
        yield 'fuzzy_similarity' => ['fuzzy_similarity', 'typo.similarity'];
        yield 'fuzzy_min_length' => ['fuzzy_min_length', 'typo.min_length'];
        yield 'fuzzy_mode' => ['fuzzy_mode', 'typo.mode'];
        yield 'fallback_below' => ['fallback_below', 'typo.fallback_below'];
        yield 'relax_when_empty' => ['relax_when_empty', 'rescue.drop_unmatched'];
        yield 'did_you_mean' => ['did_you_mean', 'rescue.did_you_mean'];
        yield 'rescue_empty' => ['rescue_empty', 'rescue.correct_when_empty'];
        yield 'candidate_limit' => ['candidate_limit', 'limits.candidates'];
        yield 'max_query_length' => ['max_query_length', 'limits.max_query_length'];
        yield 'max_terms' => ['max_terms', 'limits.max_terms'];
    }

    #[DataProvider('flatNames')]
    public function testAFlatNameOfBeforeIsRefusedAndNamesTheNewKey(string $old, string $new): void
    {
        try {
            (new Thresholds())->with([$old => 1]);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString(sprintf('"%s" was renamed: use "%s', $old, $new), $e->violations[0]);
        }
    }

    public function testRangesAreValidated(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"similarity".*0\.3 \(tolerant\)/');
        new TypoThresholds(similarity: 1.5);
    }

    public function testTypesAreValidated(): void
    {
        $this->expectException(InvalidDefinition::class);
        (new Thresholds())->with(['limits' => ['candidates' => 'lots']]);
    }

    public function testDropUnmatchedIsOnByDefaultAndOverridable(): void
    {
        $base = new Thresholds();
        $drop = static fn(Thresholds $t, mixed $value): bool => $t->with(['rescue' => ['drop_unmatched' => $value]])->rescue->dropUnmatched;

        self::assertTrue($base->rescue->dropUnmatched);
        self::assertFalse($drop($base, false));
        self::assertFalse($drop($base, 'false'), 'as passed by --threshold rescue.drop_unmatched=false');
        self::assertFalse($drop($base, 0));
        self::assertTrue($drop($base->with(['rescue' => ['drop_unmatched' => false]]), 'true'));
        self::assertFalse($base->with(['rescue' => ['drop_unmatched' => false]])->with(['relevance' => ['min_score' => 0.1]])->rescue->dropUnmatched, 'kept by other overrides');
    }

    public function testDropUnmatchedMustBeABoolean(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"drop_unmatched" must be a boolean/');
        (new Thresholds())->with(['rescue' => ['drop_unmatched' => 'maybe']]);
    }

    public function testDropUnmatchedRejectsAnEmptyString(): void
    {
        // "--threshold rescue.drop_unmatched=" must not silently switch the feature off
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"drop_unmatched" must be a boolean/');
        (new Thresholds())->with(['rescue' => ['drop_unmatched' => '']]);
    }

    public function testCostLimitsHaveHardMaximums(): void
    {
        $max = new LimitThresholds(
            candidates: Thresholds::MAX_CANDIDATE_LIMIT,
            maxQueryLength: Thresholds::MAX_QUERY_LENGTH,
            maxTerms: Thresholds::MAX_TERMS,
        );
        self::assertSame(10_000, $max->candidates);
        self::assertSame(1_024, $max->maxQueryLength);
        self::assertSame(64, $max->maxTerms);

        foreach ([
            'candidates' => ['"candidates" must be <= 10000', 10_001],
            'max_query_length' => ['"max_query_length" must be <= 1024', 1_025],
            'max_terms' => ['"max_terms" must be <= 64', 65],
        ] as $key => [$message, $value]) {
            try {
                (new Thresholds())->with(['limits' => [$key => $value]]);
                self::fail(sprintf('%s = %d must be rejected', $key, $value));
            } catch (InvalidDefinition $e) {
                self::assertSame('thresholds.limits', $e->index);
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testAllCapViolationsOfAGroupAreReportedTogether(): void
    {
        try {
            new LimitThresholds(candidates: PHP_INT_MAX, maxQueryLength: PHP_INT_MAX, maxTerms: PHP_INT_MAX);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertCount(3, $e->violations);
        }
    }

    public function testAnUnknownTypoModeIsAnInvalidDefinition(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"mode" must be one of: always, fallback, never/');
        (new Thresholds())->with(['typo' => ['mode' => 'bogus']]);
    }

    public function testLowerBoundsAreValidated(): void
    {
        foreach ([
            [static fn(): RelevanceThresholds => new RelevanceThresholds(minScore: -0.1), '"min_score" must be >= 0.'],
            [static fn(): TypoThresholds => new TypoThresholds(minLength: 0), '"min_length" must be >= 1.'],
            [static fn(): TypoThresholds => new TypoThresholds(fallbackBelow: 0), '"fallback_below" must be >= 1.'],
            [static fn(): RescueThresholds => new RescueThresholds(didYouMeanBelow: 0), '"did_you_mean_below" must be >= 1 (or null: the same as typo.fallback_below).'],
            [static fn(): LimitThresholds => new LimitThresholds(candidates: 5), '"candidates" must be >= 10.'],
            [static fn(): LimitThresholds => new LimitThresholds(maxQueryLength: 0), '"max_query_length" and "max_terms" must be >= 1.'],
            [static fn(): LimitThresholds => new LimitThresholds(maxTerms: 0), '"max_query_length" and "max_terms" must be >= 1.'],
        ] as [$build, $message]) {
            try {
                $build();
                self::fail($message . ' must be rejected');
            } catch (InvalidDefinition $e) {
                self::assertContains($message, $e->violations);
            }
        }
    }

    public function testSimilarityOverrideMustBeNumeric(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"similarity" must be a number\./');
        (new Thresholds())->with(['typo' => ['similarity' => 'lots']]);
    }

    public function testMinLengthOverrideMustBeAnInteger(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"min_length" must be an integer\./');
        (new Thresholds())->with(['typo' => ['min_length' => 'three']]);
    }

    public function testCorrectWhenEmptyIsOnByDefaultAndCanBeSwitchedOff(): void
    {
        $default = new Thresholds();
        $correct = static fn(mixed $value): bool => $default->with(['rescue' => ['correct_when_empty' => $value]])->rescue->correctWhenEmpty;

        self::assertTrue($default->rescue->correctWhenEmpty);
        self::assertFalse($correct(false));
        self::assertFalse($correct('no'), 'command-line strings count');
        self::assertTrue($default->with(['rescue' => ['correct_when_empty' => false]])->with(['rescue' => ['correct_when_empty' => 1]])->rescue->correctWhenEmpty);
        self::assertTrue($default->with(['rescue' => ['correct_when_empty' => false]])->rescue->didYouMean, 'did-you-mean stays on without the rescue');
        self::assertFalse($default->with(['rescue' => ['correct_when_empty' => false]])->with(['typo' => ['mode' => 'never']])->rescue->correctWhenEmpty, 'other overrides keep it');
    }

    public function testTheRescueNeedsDidYouMeanAndTheCombinationIsRejected(): void
    {
        try {
            (new Thresholds())->with(['rescue' => ['did_you_mean' => false]]);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertSame('thresholds.rescue', $e->index);
            self::assertStringContainsString('"correct_when_empty" needs "did_you_mean"', $e->violations[0]);
        }

        $off = (new Thresholds())->with(['rescue' => ['did_you_mean' => false, 'correct_when_empty' => false]]);
        self::assertFalse($off->rescue->didYouMean);
        self::assertFalse($off->rescue->correctWhenEmpty);
    }

    public function testDidYouMeanIsOnByDefaultAndCanBeSwitchedOff(): void
    {
        $default = new Thresholds();
        $off = static fn(mixed $value): bool => $default->with(['rescue' => ['did_you_mean' => $value, 'correct_when_empty' => false]])->rescue->didYouMean;

        self::assertTrue($default->rescue->didYouMean);
        self::assertFalse($off(false));
        self::assertFalse($off('no'), 'command-line strings count');
        self::assertTrue($off(1));

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/"did_you_mean" must be a boolean\./');
        $default->with(['rescue' => ['did_you_mean' => 'maybe']]);
    }

    public function testTheDidYouMeanTriggerDefaultsToTheFallbackBelowOfTheTypoGroup(): void
    {
        $default = new Thresholds();
        self::assertSame(5, $default->didYouMeanBelow());
        self::assertSame(8, $default->with(['typo' => ['fallback_below' => 8]])->didYouMeanBelow());
        self::assertSame(2, $default->with(['typo' => ['fallback_below' => 8], 'rescue' => ['did_you_mean_below' => 2]])->didYouMeanBelow());
        self::assertSame(8, $default->with(['typo' => ['fallback_below' => 8], 'rescue' => ['did_you_mean_below' => 2]])->with(['rescue' => ['did_you_mean_below' => null]])->didYouMeanBelow());
    }

    public function testEveryGroupReportsItsOwnScope(): void
    {
        foreach (['relevance' => ['min_score' => 'x'], 'typo' => ['mode' => 'x'], 'rescue' => ['did_you_mean' => 'x'], 'limits' => ['candidates' => 'x']] as $group => $options) {
            try {
                (new Thresholds())->with([$group => $options]);
                self::fail($group . ' must be rejected');
            } catch (InvalidDefinition $e) {
                self::assertSame('thresholds.' . $group, $e->index);
            }
        }
    }
}

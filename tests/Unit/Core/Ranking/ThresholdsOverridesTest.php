<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\Thresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThresholdsOverridesTest extends TestCase
{
    /** @return iterable<string, array{string, string, mixed, non-empty-string}> */
    public static function badValues(): iterable
    {
        yield 'min_score text' => ['relevance', 'min_score', 'abc', '"min_score" must be a number.'];
        yield 'min_score array' => ['relevance', 'min_score', [], '"min_score" must be a number.'];
        yield 'similarity text' => ['typo', 'similarity', 'abc', '"similarity" must be a number.'];
        yield 'min_length text' => ['typo', 'min_length', 'abc', '"min_length" must be an integer.'];
        yield 'fallback_below float' => ['typo', 'fallback_below', 5.5, '"fallback_below" must be an integer.'];
        yield 'fallback_below decimal string' => ['typo', 'fallback_below', '5.5', '"fallback_below" must be an integer.'];
        yield 'candidates text' => ['limits', 'candidates', 'many', '"candidates" must be an integer.'];
        yield 'max_query_length float' => ['limits', 'max_query_length', 1.5, '"max_query_length" must be an integer.'];
        yield 'max_terms null' => ['limits', 'max_terms', null, '"max_terms" must be an integer.'];
        yield 'mode unknown' => ['typo', 'mode', 'sometimes', '"mode" must be one of: '];
        yield 'drop_unmatched empty string' => ['rescue', 'drop_unmatched', '', '"drop_unmatched" must be a boolean.'];
        yield 'drop_unmatched float' => ['rescue', 'drop_unmatched', 1.5, '"drop_unmatched" must be a boolean.'];
        yield 'drop_unmatched array' => ['rescue', 'drop_unmatched', [true], '"drop_unmatched" must be a boolean.'];
        yield 'drop_unmatched text' => ['rescue', 'drop_unmatched', 'maybe', '"drop_unmatched" must be a boolean.'];
        yield 'did_you_mean null' => ['rescue', 'did_you_mean', null, '"did_you_mean" must be a boolean.'];
        yield 'correct_when_empty null' => ['rescue', 'correct_when_empty', null, '"correct_when_empty" must be a boolean.'];
        yield 'correct_when_empty text' => ['rescue', 'correct_when_empty', 'maybe', '"correct_when_empty" must be a boolean.'];
        yield 'did_you_mean_below text' => ['rescue', 'did_you_mean_below', 'x', '"did_you_mean_below" must be an integer.'];
    }

    /** @param non-empty-string $message */
    #[DataProvider('badValues')]
    public function testAValueOfTheWrongTypeIsReportedByNameAndGroup(string $group, string $key, mixed $value, string $message): void
    {
        try {
            (new Thresholds())->with([$group => [$key => $value]]);
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertSame('thresholds.' . $group, $e->index);
            self::assertCount(1, $e->violations);
            self::assertStringStartsWith($message, $e->violations[0]);
        }
    }

    public function testAValueThatReadsAsAThresholdIsConverted(): void
    {
        $thresholds = (new Thresholds())->with([
            'relevance' => ['min_score' => '0.25'],
            'typo' => ['similarity' => '0.4'],
            'rescue' => ['drop_unmatched' => 'no', 'did_you_mean' => 0, 'correct_when_empty' => 'false'],
        ]);

        self::assertSame(0.25, $thresholds->relevance->minScore);
        self::assertSame(0.4, $thresholds->typo->similarity);
        self::assertFalse($thresholds->rescue->dropUnmatched);
        self::assertFalse($thresholds->rescue->didYouMean);
        self::assertTrue((new Thresholds())->with(['rescue' => ['drop_unmatched' => 'yes', 'did_you_mean' => 1]])->rescue->didYouMean);
    }

    public function testTheIntegerOverridesAreApplied(): void
    {
        $thresholds = (new Thresholds())->with(['typo' => ['fallback_below' => 9], 'limits' => ['candidates' => '100', 'max_query_length' => 50, 'max_terms' => 4]]);

        self::assertSame([9, 100, 50, 4], [$thresholds->typo->fallbackBelow, $thresholds->limits->candidates, $thresholds->limits->maxQueryLength, $thresholds->limits->maxTerms]);
    }

    public function testOneErrorPerGroup(): void
    {
        try {
            (new Thresholds())->with(['typo' => ['mode' => 'x', 'min_length' => 'y', 'bogus' => 1]]);
            self::fail('must be rejected');
        } catch (InvalidDefinition $e) {
            self::assertSame('thresholds.typo', $e->index);
            self::assertCount(3, $e->violations, 'all the problems of the group in one error');
        }
    }
}

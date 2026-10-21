<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\Thresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThresholdsOverridesTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, non-empty-string}> */
    public static function badValues(): iterable
    {
        yield 'min_score text' => ['min_score', 'abc', '"min_score" must be a number.'];
        yield 'min_score array' => ['min_score', [], '"min_score" must be a number.'];
        yield 'fuzzy_similarity text' => ['fuzzy_similarity', 'abc', '"fuzzy_similarity" must be a number.'];
        yield 'fuzzy_min_length string' => ['fuzzy_min_length', '3', '"fuzzy_min_length" must be an integer.'];
        yield 'fallback_below string' => ['fallback_below', '5', '"fallback_below" must be an integer.'];
        yield 'fallback_below float' => ['fallback_below', 5.5, '"fallback_below" must be an integer.'];
        yield 'candidate_limit string' => ['candidate_limit', '100', '"candidate_limit" must be an integer.'];
        yield 'max_query_length string' => ['max_query_length', '100', '"max_query_length" must be an integer.'];
        yield 'max_terms string' => ['max_terms', '8', '"max_terms" must be an integer.'];
        yield 'max_terms null' => ['max_terms', null, '"max_terms" must be an integer.'];
        yield 'fuzzy_mode unknown' => ['fuzzy_mode', 'sometimes', '"fuzzy_mode" must be one of: '];
        yield 'relax_when_empty empty string' => ['relax_when_empty', '', '"relax_when_empty" must be a boolean.'];
        yield 'relax_when_empty float' => ['relax_when_empty', 1.5, '"relax_when_empty" must be a boolean.'];
        yield 'relax_when_empty array' => ['relax_when_empty', [true], '"relax_when_empty" must be a boolean.'];
        yield 'relax_when_empty text' => ['relax_when_empty', 'maybe', '"relax_when_empty" must be a boolean.'];
        yield 'did_you_mean null' => ['did_you_mean', null, '"did_you_mean" must be a boolean.'];
        yield 'rescue_empty null' => ['rescue_empty', null, '"rescue_empty" must be a boolean.'];
        yield 'rescue_empty text' => ['rescue_empty', 'maybe', '"rescue_empty" must be a boolean.'];
    }

    /** @param non-empty-string $message */
    #[DataProvider('badValues')]
    public function testAValueOfTheWrongTypeIsReportedByName(string $key, mixed $value, string $message): void
    {
        try {
            (new Thresholds())->with([$key => $value]);
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertSame('thresholds', $e->index);
            self::assertCount(1, $e->violations);
            self::assertStringStartsWith($message, $e->violations[0]);
        }
    }

    public function testAnotherTypeOfValueThatReadsAsAThresholdIsConverted(): void
    {
        $thresholds = (new Thresholds())->with([
            'min_score' => '0.25',
            'fuzzy_similarity' => '0.4',
            'relax_when_empty' => 'no',
            'did_you_mean' => 0,
        ]);

        self::assertSame(0.25, $thresholds->minScore);
        self::assertSame(0.4, $thresholds->fuzzySimilarity);
        self::assertFalse($thresholds->relaxWhenEmpty);
        self::assertFalse($thresholds->didYouMean);
        self::assertTrue((new Thresholds())->with(['relax_when_empty' => 'yes', 'did_you_mean' => 1])->didYouMean);
    }

    public function testTheIntegerOverridesAreApplied(): void
    {
        $thresholds = (new Thresholds())->with(['fallback_below' => 9, 'candidate_limit' => 100, 'max_query_length' => 50, 'max_terms' => 4]);

        self::assertSame([9, 100, 50, 4], [$thresholds->fallbackBelow, $thresholds->candidateLimit, $thresholds->maxQueryLength, $thresholds->maxTerms]);
    }
}

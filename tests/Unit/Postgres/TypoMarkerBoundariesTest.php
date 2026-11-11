<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Engine\Postgres\TypoMarker;
use Fuzzphony\Engine\Postgres\TypoTerm;
use PHPUnit\Framework\TestCase;

/** The boundaries of the text work of the typo marks: fragment limits, overlapping phrase runs, marked-span edges, invalid text. */
final class TypoMarkerBoundariesTest extends TestCase
{
    private const string S = "\u{E000}";
    private const string E = "\u{E001}";

    /** @return string a text of $count words w1 .. wN */
    private static function words(int $count): string
    {
        return implode(' ', array_map(static fn(int $i): string => 'w' . $i, range(1, $count)));
    }

    public function testATextOfExactlyThirtyWordsIsReturnedWhole(): void
    {
        $marker = new TypoMarker([new TypoTerm('w1')]);
        $text = self::words(30);

        $out = $marker->snippet($text, 'name', ['w1' => [0 => 0.9]]);

        self::assertSame(self::S . 'w1' . self::E . substr($text, 2), $out, 'thirty words: the whole text, not fragments');
        self::assertStringEndsWith(' w15', (string) $marker->snippet(self::words(31), 'name', ['w1' => [0 => 0.9]]), 'one word more: a fragment');
    }

    public function testFragmentsFollowTheTextOrderWhateverTheOrderOfTheTerms(): void
    {
        // term 0 matches the late word, term 1 the early one: the first fragment is the early one
        $marker = new TypoMarker([new TypoTerm('late'), new TypoTerm('early')]);
        $text = 'a1 a2 a3 early a5 a6 a7 a8 a9 a10 a11 a12 a13 a14 a15 a16 a17 a18 a19 a20 a21 a22 a23 a24 late a26 a27 a28 a29 a30 a31 a32 a33 a34 a35 a36 a37 a38 a39 a40';
        $out = $marker->snippet($text, 'name', ['late' => [0 => 0.9], 'early' => [1 => 0.9]]);

        self::assertSame(
            'a1 a2 a3 ' . self::S . 'early' . self::E . ' a5 a6 a7 a8 a9 a10 a11 a12 a13 a14 a15 … a22 a23 a24 ' . self::S . 'late' . self::E . ' a26 a27 a28 a29 a30 a31 a32 a33 a34 a35 a36',
            $out,
        );
    }

    public function testAMatchInsideTheFirstFragmentDoesNotStopTheNextOnes(): void
    {
        $marker = new TypoMarker([new TypoTerm('w3'), new TypoTerm('w5'), new TypoTerm('w31')]);
        $sims = ['w3' => [0 => 0.9], 'w5' => [1 => 0.9], 'w31' => [2 => 0.9]];

        $out = (string) $marker->snippet(self::words(40), 'name', $sims);

        self::assertSame(2, substr_count($out, ' … ') + 1, 'two fragments: w3 and w5 share the first, w31 starts the second');
        self::assertStringContainsString(self::S . 'w31' . self::E, $out);
        self::assertStringContainsString(self::S . 'w5' . self::E, $out);
    }

    public function testTheSecondFragmentStopsAtTheEndOfTheText(): void
    {
        $marker = new TypoMarker([new TypoTerm('w21'), new TypoTerm('w39')]);

        $out = (string) $marker->snippet(self::words(40), 'name', ['w21' => [0 => 0.9], 'w39' => [1 => 0.9]]);

        self::assertStringEndsWith(' w38 ' . self::S . 'w39' . self::E . ' w40', $out);
    }

    public function testAMatchOnTheLastWordOfAFragmentAndOneOnTheNextWordAreOnTheirOwn(): void
    {
        $text = self::words(40);
        // the first fragment is w1..w15 (words 0..14): a match on its last word belongs to it, a match on the next word starts the second one
        $one = (new TypoMarker([new TypoTerm('w1'), new TypoTerm('w15')]))->snippet($text, 'name', ['w1' => [0 => 0.9], 'w15' => [1 => 0.9]]);
        self::assertSame(1, substr_count((string) $one, self::S . 'w15' . self::E), 'once: w15 is inside the first fragment');
        self::assertStringNotContainsString(' … ', (string) $one);

        $two = (new TypoMarker([new TypoTerm('w1'), new TypoTerm('w16')]))->snippet($text, 'name', ['w1' => [0 => 0.9], 'w16' => [1 => 0.9]]);
        self::assertStringContainsString(' … ' . self::S . 'w16' . self::E, (string) $two, 'the next word starts the second fragment');
    }

    public function testTheMostSimilarOverlappingRunOfAPhraseWinsWhateverItsPlace(): void
    {
        $marker = new TypoMarker([new TypoTerm('red shoes')]);

        // runs: "red red" (0), "red shoes" (1): the later one is more similar
        $out = $marker->extend('red red shoes', 'name', ['red red' => [0 => 0.5], 'red shoes' => [0 => 0.9]]);
        self::assertSame('red ' . self::S . 'red' . self::E . ' ' . self::S . 'shoes' . self::E, $out);

        // the earlier one is more similar
        $out = $marker->extend('red shoes shoes', 'name', ['red shoes' => [0 => 0.9], 'shoes shoes' => [0 => 0.5]]);
        self::assertSame(self::S . 'red' . self::E . ' ' . self::S . 'shoes' . self::E . ' shoes', $out);
    }

    public function testOverlappingRunsOfEqualSimilarityGoToTheEarlierOne(): void
    {
        $marker = new TypoMarker([new TypoTerm('ab ab')]);

        $out = $marker->extend('xx yy zz ab ab ab ab ab tail', 'name', ['ab ab' => [0 => 0.8]]);

        self::assertSame('xx yy zz ' . self::S . 'ab' . self::E . ' ' . self::S . 'ab' . self::E . ' ' . self::S . 'ab' . self::E . ' ' . self::S . 'ab' . self::E . ' ab tail', $out, 'runs 3-4 and 5-6 are taken, the fifth ab is left (its run overlaps)');
    }

    public function testAnOverlappedRunDoesNotHideTheRunsAfterIt(): void
    {
        $marker = new TypoMarker([new TypoTerm('ab cd')]);
        $text = 'ab cd ab cd ab cd';

        $out = $marker->extend($text, 'name', ['ab cd' => [0 => 0.7], 'cd ab' => [0 => 0.7]]);

        self::assertSame(3, substr_count($out, self::S . 'ab' . self::E), 'every non-overlapping run is marked');
    }

    public function testAPhraseWithEveryWordAlreadyMarkedIsNotACandidateButOneWithAFreeWordIs(): void
    {
        $marker = new TypoMarker([new TypoTerm('red shoes')]);

        self::assertSame([], $marker->candidates(self::S . 'red' . self::E . ' ' . self::S . 'shoes' . self::E, 'name'));
        self::assertSame(['red shoes'], $marker->candidates('red ' . self::S . 'shoes' . self::E, 'name'), 'the first word is free');
        self::assertSame(['red shoes'], $marker->candidates(self::S . 'red' . self::E . ' shoes', 'name'), 'the second word is free');
    }

    public function testAFragmentDelimiterOnlyCutsTheRunItIsBetweenTheWordsOf(): void
    {
        $marker = new TypoMarker([new TypoTerm('aa bb')]);

        self::assertSame(['aa bb'], $marker->candidates('aa bb … cc', 'name'), 'the delimiter after the pair does not cut the pair; the pair across it is cut');
        self::assertSame(['aa bb', 'bb dd'], $marker->candidates('aa bb dd … cc', 'name'));
    }

    public function testAWordNextToAMarkedSpanIsNotPartOfIt(): void
    {
        $marker = new TypoMarker([new TypoTerm('x')]);

        self::assertSame(['ab'], $marker->candidates('ab' . self::S . 'cd' . self::E, 'name'), 'the word before the span');
        self::assertSame(['cd'], $marker->candidates(self::S . 'ab' . self::E . 'cd', 'name'), 'the word after the span');
    }

    public function testATextThatIsNotValidUtf8HasNoWords(): void
    {
        $marker = new TypoMarker([new TypoTerm('x')]);

        self::assertSame([], $marker->candidates("ab \xFF cd", 'name'));
        self::assertSame(0, $marker->cost("ab \xFF cd", 'name'));
        self::assertSame("ab \xFF cd", $marker->extend("ab \xFF cd", 'name', ['ab' => [0 => 0.9]]));
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Engine\Postgres\Highlighter;
use Fuzzphony\Engine\Postgres\TypoMarker;
use Fuzzphony\Engine\Postgres\TypoTerm;
use PHPUnit\Framework\TestCase;

/**
 * The text work of the typo marks. Which candidate reaches its term's similarity is PostgreSQL's answer, handed in here
 * as candidate => term index => similarity; the real answers are checked through highlight() in TypoHighlightTest.
 */
final class TypoMarkerTest extends TestCase
{
    private const string S = "\u{E000}";
    private const string E = "\u{E001}";

    public function testCandidatesAreTheLowerCasedWordsOfTheTextWithoutRepeats(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones')]);

        self::assertSame(['wireless', 'headphones', 'and', 'bluetooth'], $marker->candidates('Wireless HEADPHONES, and Headphones; bluetooth!', 'name'));
    }

    public function testWordsSplitOnEveryCharacterThatIsNotALetterDigitOrMark(): void
    {
        $marker = new TypoMarker([new TypoTerm('mens')]);

        self::assertSame(['men', 's', 'e', 'mail', 'a', 'b', '2024', 'x1'], $marker->candidates("Men's e-mail <a>b</a> 2024/x1", 'name'), 'an apostrophe, a hyphen and an HTML tag separate words, as the search\'s normaliser does');
        self::assertSame(['zażółć', 'gęślą', 'jaźń', "cafe\u{0301}"], $marker->candidates("Zażółć gęślą jaźń cafe\u{0301}", 'name'), 'multibyte letters and a combining accent stay in their word');
        self::assertSame(['i', 'a'], $marker->candidates('I a', 'name'), 'very short words are candidates too: the similarity rule decides');
    }

    public function testAnEmptyTextOrOneWithoutWordsHasNoCandidatesAndNoMarks(): void
    {
        $marker = new TypoMarker([new TypoTerm('mouse')]);

        self::assertSame([], $marker->candidates('', 'name'));
        self::assertSame([], $marker->candidates(' -- ... !! ', 'name'));
        self::assertNull($marker->snippet(' -- ', 'name', ['x' => [0 => 1.0]]));
        self::assertSame(' -- ', $marker->extend(' -- ', 'name', ['x' => [0 => 1.0]]));
    }

    public function testATextThatIsNotUtf8HasNoWords(): void
    {
        $marker = new TypoMarker([new TypoTerm('mouse')]);

        self::assertSame([], $marker->candidates("mou\xE9se", 'name'));
        self::assertNull($marker->snippet("mou\xE9se", 'name', ['mouse' => [0 => 1.0]]));
    }

    public function testATermIsAskedOnlyAboutTheFieldsItAppliesTo(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones', field: 'name'), new TypoTerm('mouse')]);

        self::assertSame(['a', 'headphones'], $marker->candidates('a headphones', 'name'));
        self::assertSame(['a', 'headphones'], $marker->candidates('a headphones', 'brand'), 'the unscoped term compares every word');
        $scopedOnly = new TypoMarker([new TypoTerm('hedphones', field: 'name')]);
        self::assertSame([], $scopedOnly->candidates('a headphones', 'brand'));
        self::assertNull($scopedOnly->snippet('a headphones', 'brand', ['headphones' => [0 => 1.0]]));
        self::assertSame('a headphones', $scopedOnly->extend('a headphones', 'brand', ['headphones' => [0 => 1.0]]));
        self::assertSame('a ' . self::S . 'headphones' . self::E, $scopedOnly->snippet('a headphones', 'name', ['headphones' => [0 => 1.0]]));
    }

    public function testTheOriginalCharactersAreMarked(): void
    {
        $marker = new TypoMarker([new TypoTerm('creme')]);
        $similar = ['crème' => [0 => 1.0]];

        self::assertSame('Café ' . self::S . 'CRÈME' . self::E . ', ' . self::S . 'Crème' . self::E . '!', $marker->snippet('Café CRÈME, Crème!', 'name', $similar), 'every occurrence, with the case of the text');
    }

    public function testOnlyTheWordsThatReachedTheirSimilarityAreMarked(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones'), new TypoTerm('wireles')]);
        $marked = $marker->snippet('Wireless Headphones for the ear', 'name', ['wireless' => [1 => 0.8], 'headphones' => [0 => 0.9, 1 => 0.1]]);

        self::assertSame(self::S . 'Wireless' . self::E . ' ' . self::S . 'Headphones' . self::E . ' for the ear', $marked);
        self::assertNull($marker->snippet('for the ear', 'name', ['wireless' => [1 => 0.8]]), 'nothing of the text matched');
    }

    public function testAShortTextIsReturnedWholeWithItsPunctuation(): void
    {
        $marker = new TypoMarker([new TypoTerm('mouse')]);

        self::assertSame('"A ' . self::S . 'mouse' . self::E . '", they said... ', $marker->snippet('"A mouse", they said... ', 'name', ['mouse' => [0 => 1.0]]));
    }

    public function testHtmlInTheTextIsLeftForTheHighlighterToEscape(): void
    {
        $marker = new TypoMarker([new TypoTerm('cofee')]);
        $marked = $marker->snippet('<script>alert(1)</script> coffee & "cream"', 'body', ['coffee' => [0 => 1.0]]);

        self::assertSame('<script>alert(1)</script> ' . self::S . 'coffee' . self::E . ' & "cream"', $marked);
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt; <mark>coffee</mark> &amp; &quot;cream&quot;', Highlighter::toHtml($marked), 'the highlighter escapes it and turns only the markers into tags');
    }

    public function testAMarkerCharacterInTheTextIsDroppedBeforeMarking(): void
    {
        $marker = new TypoMarker([new TypoTerm('mouse')]);

        self::assertSame('a evil ' . self::S . 'mouse' . self::E . ' b', $marker->snippet('a ' . self::S . 'evil' . self::E . ' mouse b', 'name', ['mouse' => [0 => 1.0]]));
    }

    public function testASingleWordTermMarksEveryOccurrence(): void
    {
        $marker = new TypoMarker([new TypoTerm('jackt')]);
        $text = "Joe's jacket, a jacket for jackets";

        self::assertSame(
            "Joe's " . self::S . 'jacket' . self::E . ', a ' . self::S . 'jacket' . self::E . ' for ' . self::S . 'jackets' . self::E,
            $marker->snippet($text, 'name', ['jacket' => [0 => 0.7], 'jackets' => [0 => 0.6]]),
        );
    }

    public function testAPhraseTermComparesRunsOfWordsAndTheMostSimilarOfOverlappingRunsWins(): void
    {
        $marker = new TypoMarker([new TypoTerm('red shoas')]);

        self::assertSame(['blue red', 'red shoes', 'shoes big'], $marker->candidates('blue red shoes big', 'name'));
        $similar = ['blue red' => [0 => 0.4], 'red shoes' => [0 => 0.9], 'shoes big' => [0 => 0.6]];
        self::assertSame('blue ' . self::S . 'red' . self::E . ' ' . self::S . 'shoes' . self::E . ' big', $marker->snippet('blue red shoes big', 'name', $similar));
        self::assertSame(
            self::S . 'red' . self::E . ' ' . self::S . 'shoes' . self::E . ' x ' . self::S . 'red' . self::E . ' ' . self::S . 'shoes' . self::E,
            $marker->snippet('red shoes x red shoes', 'name', ['red shoes' => [0 => 0.9]]),
            'a run that overlaps none is marked as well',
        );
    }

    public function testAPhraseDoesNotSpanAFragmentDelimiterOrJoinWordsAcrossIt(): void
    {
        $marker = new TypoMarker([new TypoTerm('red shoas')]);

        self::assertSame(['a red', 'shoes b'], $marker->candidates('a red … shoes b', 'name'), 'the run "red shoes" would cross the delimiter');
    }

    public function testExtendAddsTheTypoMatchesToATsHeadlineSnippetAndKeepsItsOwnMarks(): void
    {
        $marker = new TypoMarker([new TypoTerm('wireles'), new TypoTerm('headphones')]);
        $headline = 'Wireless ' . self::S . 'Headphones' . self::E . ' for ' . self::S . 'the' . self::E . ' ear';

        self::assertSame(
            self::S . 'Wireless' . self::E . ' ' . self::S . 'Headphones' . self::E . ' for ' . self::S . 'the' . self::E . ' ear',
            $marker->extend($headline, 'name', ['wireless' => [0 => 0.8], 'headphones' => [1 => 1.0], 'the' => [0 => 0.5]]),
            'headphones was marked already (not marked twice), "the" is marked and stays so',
        );
        self::assertSame($headline, $marker->extend($headline, 'name', ['nothing' => [0 => 1.0]]), 'no match: the snippet as it was');
    }

    public function testExtendLetsAPhraseRunIncludeAWordThatWasMarkedAlready(): void
    {
        $marker = new TypoMarker([new TypoTerm('red shoas')]);
        $headline = self::S . 'Red' . self::E . ' shoes for kids';

        self::assertSame(self::S . 'Red' . self::E . ' ' . self::S . 'shoes' . self::E . ' for kids', $marker->extend($headline, 'name', ['red shoes' => [0 => 0.9]]));
        $all = self::S . 'Red shoes' . self::E . ' for kids';
        self::assertSame($all, $marker->extend($all, 'name', ['red shoes' => [0 => 0.9]]), 'a run of words all marked has nothing to add');
    }

    public function testExtendDoesNotJoinWordsAcrossAFragmentDelimiter(): void
    {
        $marker = new TypoMarker([new TypoTerm('red shoas')]);
        $headline = 'big red … shoes for kids';

        self::assertSame($headline, $marker->extend($headline, 'name', ['red shoes' => [0 => 0.9]]));
    }

    public function testALongTextGetsAtMostTwoFragmentsOfFifteenWordsAroundTheFirstMatches(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones')]);
        $words = array_map(static fn(int $i): string => 'w' . $i, range(1, 100));
        $words[39] = 'headphones';
        $words[79] = 'headphones';
        $words[89] = 'headphones';
        $marked = $marker->snippet(implode(' ', $words), 'name', ['headphones' => [0 => 1.0]]);

        self::assertNotNull($marked);
        [$one, $two] = explode(' … ', $marked);
        self::assertSame('w37 w38 w39 ' . self::S . 'headphones' . self::E . ' w41 w42 w43 w44 w45 w46 w47 w48 w49 w50 w51', $one, 'three words of lead, then the match; fifteen in all');
        self::assertSame('w77 w78 w79 ' . self::S . 'headphones' . self::E . ' w81 w82 w83 w84 w85 w86 w87 w88 w89 ' . self::S . 'headphones' . self::E . ' w91', $two, 'the second fragment starts at the next match after the first');
        self::assertSame(3, substr_count($marked, self::S), 'the third match is in the second fragment');
    }

    public function testAMatchNearTheEndOfALongTextKeepsAFullFragment(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones')]);
        $words = array_map(static fn(int $i): string => 'w' . $i, range(1, 40));
        $words[39] = 'headphones';

        self::assertSame(
            'w26 w27 w28 w29 w30 w31 w32 w33 w34 w35 w36 w37 w38 w39 ' . self::S . 'headphones' . self::E,
            $marker->snippet(implode(' ', $words), 'name', ['headphones' => [0 => 1.0]]),
            'fifteen words ending with the last word of the text',
        );
    }

    public function testAMatchAtTheStartOfALongTextStartsAtTheText(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones')]);
        $words = array_map(static fn(int $i): string => 'w' . $i, range(1, 40));
        $words[0] = 'headphones';

        self::assertSame(
            self::S . 'headphones' . self::E . ' w2 w3 w4 w5 w6 w7 w8 w9 w10 w11 w12 w13 w14 w15',
            $marker->snippet(implode(' ', $words), 'name', ['headphones' => [0 => 1.0]]),
        );
    }

    public function testAMatchBeyondTheScannedWordsIsNotLookedFor(): void
    {
        $marker = new TypoMarker([new TypoTerm('hedphones')]);
        $text = str_repeat('word ', TypoMarker::MAX_WORDS_SCANNED) . 'headphones';

        self::assertCount(1, $marker->candidates($text, 'name'), 'the work is bounded: the words after the limit are not even asked about');
        self::assertNull($marker->snippet($text, 'name', ['headphones' => [0 => 1.0]]));
    }

    public function testFragmentsOfMultibyteTextAreNeverCutInsideACharacter(): void
    {
        $marker = new TypoMarker([new TypoTerm('zolc')]);
        $text = 'ą ę ó ł ' . str_repeat('żółć gęślą jaźń ', 30);
        $marked = $marker->snippet($text, 'name', ['żółć' => [0 => 1.0]]);

        self::assertNotNull($marked);
        self::assertTrue(mb_check_encoding($marked, 'UTF-8'));
        self::assertSame(1, preg_match('/^ę ó ł \x{E000}żółć\x{E001}/u', $marked), 'the lead of three words is counted in words, not bytes');
    }

    public function testTwoTermsMarkTheirOwnWords(): void
    {
        $marker = new TypoMarker([new TypoTerm('wireles'), new TypoTerm('mose')]);

        self::assertSame(
            self::S . 'Wireless' . self::E . ' ' . self::S . 'mouse' . self::E . ' pad',
            $marker->snippet('Wireless mouse pad', 'name', ['wireless' => [0 => 0.9], 'mouse' => [1 => 0.8]]),
        );
    }
}

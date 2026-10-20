<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SynonymExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SynonymExpanderMutationsTest extends TestCase
{
    public function testASingleWordMemberIsCutAtTheQueryParsersLimitLikeAnyOtherText(): void
    {
        // the parser cuts a text at its 256-character limit; the fast path of a plain word does the same
        $long = str_repeat('a', 300);

        self::assertSame([str_repeat('a', 256), 'tv'], SynonymExpander::wordsOf(new Synonyms([[$long, 'tv']])));
        self::assertSame([str_repeat('a', 256), 'tv'], SynonymExpander::wordsOf(new Synonyms([[$long . ' ', 'tv']])));
    }

    /** @return iterable<string, array{string}> */
    public static function memberTexts(): iterable
    {
        foreach ([1, 255, 256, 257, 300] as $length) {
            yield "$length letters" => [str_repeat('a', $length)];
            yield "$length capitals" => [str_repeat('Q', $length)];
            yield "$length accented letters" => [str_repeat('Ő', $length)];
            yield "$length digits" => [str_repeat('7', $length)];
            yield "$length letters and a space" => [str_repeat('a', $length) . ' '];
        }
        yield 'mixed case and accents' => ['Árvíztűrő'];
        yield 'letters and digits' => ['RTX4090'];
        yield 'uppercase AND' => ['AND'];
        yield 'uppercase OR' => ['OR'];
        yield 'uppercase NOT' => ['NOT'];
        yield 'lowercase and' => ['and'];
        yield 'lowercase or' => ['or'];
        yield 'lowercase not' => ['not'];
        yield 'mixed case And' => ['And'];
        yield 'the empty string' => [''];
        yield 'a space' => [' '];
        yield 'a hyphenated word' => ['wi-fi'];
        yield 'two words' => ['solid state'];
        yield 'a phrase in quotes' => ['"solid state"'];
    }

    #[DataProvider('memberTexts')]
    public function testThePlainWordFastPathGivesWhatTheParserGives(string $text): void
    {
        $words = new \ReflectionMethod(SynonymExpander::class, 'words');
        $viaParser = array_map(mb_strtolower(...), NodeInspector::positiveWords((new QueryParser())->parse($text)->root));

        self::assertSame($viaParser, $words->invoke(null, $text));
    }

    public function testAMemberThatIsNotValidUtf8ContributesNoWord(): void
    {
        self::assertSame(['tv'], SynonymExpander::wordsOf(new Synonyms([["\xE9", 'tv']])));
    }

    public function testTheWordsOfTheAlternativesOfAnExpansionCountToo(): void
    {
        $inner = new AnyOf([new Term('telly'), new Term('television', false, true)], expansion: true);
        $outer = new AnyOf([new Term('tv'), $inner, new Term('tube', false, true)], expansion: true);

        self::assertSame(['tv', 'telly'], SynonymExpander::expandedWords($outer));
    }
}

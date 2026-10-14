<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\SynonymExpander;
use PHPUnit\Framework\TestCase;

final class SynonymExpanderMutationsTest extends TestCase
{
    public function testASingleWordMemberIsTakenWholeWithoutTheQueryParser(): void
    {
        // the parser would cut a text at its 256-character limit; a plain word never goes through it
        $long = str_repeat('a', 300);

        self::assertSame([$long, 'tv'], SynonymExpander::wordsOf(new Synonyms([[$long, 'tv']])));
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

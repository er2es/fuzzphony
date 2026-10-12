<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SynonymExpander;
use PHPUnit\Framework\TestCase;

final class SynonymExpanderDetailsTest extends TestCase
{
    private static function custom(): Node
    {
        return new class implements Node {
            public function __toString(): string
            {
                return 'custom';
            }
        };
    }

    public function testANodeOfAnUnknownKindIsLeftAlone(): void
    {
        $node = self::custom();

        self::assertSame($node, (new SynonymExpander(new Synonyms([['tv', 'television']]), []))->expand($node));
        self::assertSame([], SynonymExpander::queryWords($node));
        self::assertSame([], SynonymExpander::expandedWords($node));
    }

    public function testAWordThatIsAnOperatorIsNotAMember(): void
    {
        $words = SynonymExpander::wordsOf(Synonyms::fromEntries([['AND', 'plus'], 'OR => either']));

        self::assertEqualsCanonicalizing(['plus', 'either'], $words);
    }

    public function testRepeatedTypedWordsAreListedOnce(): void
    {
        $expander = new SynonymExpander(Synonyms::fromEntries([['tv', 'television'], ['solid solid', 'x']]), []);
        $expand = static function (string $text) use ($expander): Node {
            $root = (new QueryParser())->parse($text)->root;
            self::assertNotNull($root);

            return $expander->expand($root);
        };

        self::assertSame(['tv'], SynonymExpander::expandedWords($expand('tv tv')));
        self::assertSame(['solid'], SynonymExpander::expandedWords($expand('"solid solid"')));
    }

    public function testTheStemOfTheQueryWinsOverTheStemOfTheMember(): void
    {
        $expander = new SynonymExpander(Synonyms::fromEntries([['tvs', 'telly']]), ['tvs' => 'member-stem', 'telly' => 'telly']);
        $term = new Term('tvs');

        self::assertSame('(tvs OR telly)', (string) $expander->expand($term, ['tvs' => 'member-stem']));
        self::assertSame('tvs', (string) $expander->expand($term, ['tvs' => 'query-stem']), 'the typed word has another stem: it is another word');
        self::assertSame('(tvs OR telly)', (string) $expander->expand($term), 'without a query stem the member stem is used');
    }

    public function testTheQueryWordsAreAListWithoutGaps(): void
    {
        $root = (new QueryParser())->parse('tv tv mouse')->root;
        self::assertNotNull($root);

        self::assertSame(['tv', 'mouse'], SynonymExpander::queryWords($root));
    }
}

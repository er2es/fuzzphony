<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SynonymExpander;
use PHPUnit\Framework\TestCase;

final class SynonymExpanderTest extends TestCase
{
    /**
     * @param list<mixed>           $entries
     * @param array<string, string> $stems
     */
    private static function expand(string $query, array $entries, array $stems = []): Node
    {
        $root = (new QueryParser())->parse($query)->root;
        self::assertNotNull($root);

        return (new SynonymExpander(Synonyms::fromEntries($entries), $stems))->expand($root);
    }

    public function testAGroupMemberFindsTheOthers(): void
    {
        self::assertSame('(tv OR television OR telly)', (string) self::expand('tv', [['tv', 'television', 'telly']]));
        self::assertSame('(television OR tv OR telly)', (string) self::expand('television', [['tv', 'television', 'telly']]));
    }

    public function testTheAlternativesAreFlaggedAsImplied(): void
    {
        $expanded = self::expand('tv', [['tv', 'television']]);

        self::assertInstanceOf(AnyOf::class, $expanded);
        self::assertInstanceOf(Term::class, $expanded->nodes[0]);
        self::assertFalse($expanded->nodes[0]->synonym);
        self::assertInstanceOf(Term::class, $expanded->nodes[1]);
        self::assertTrue($expanded->nodes[1]->synonym);
        self::assertSame(['tv'], NodeInspector::positiveWords($expanded), 'the bonuses compare with what the user typed');
    }

    public function testAOneWayRuleOnlyExpandsItsSource(): void
    {
        self::assertSame('(laptop OR notebook OR portable)', (string) self::expand('laptop', ['laptop => notebook | portable']));
        self::assertSame('notebook', (string) self::expand('notebook', ['laptop => notebook | portable']));
    }

    public function testWordsAreComparedByTheirStemAndCase(): void
    {
        $stems = ['tvs' => 'tv', 'tv' => 'tv', 'television' => 'televis', 'televisions' => 'televis'];

        self::assertSame('(TVs OR television)', (string) self::expand('TVs', [['tv', 'television']], $stems));
        self::assertSame('(tvs OR television)', (string) self::expand('tvs', [['tv', 'television']], $stems));
        self::assertSame('(Televisions OR tv)', (string) self::expand('Televisions', [['tv', 'television']], $stems));
        self::assertSame('tvs', (string) self::expand('tvs', [['tv', 'television']]), 'without a stem "tvs" is not "tv"');
    }

    public function testAMultiWordMemberMatchesAQuotedPhraseOnly(): void
    {
        $entries = [['ssd', 'solid state drive']];

        self::assertSame('(ssd OR "solid state drive")', (string) self::expand('ssd', $entries));
        self::assertSame('("solid state drive" OR ssd)', (string) self::expand('"solid state drive"', $entries));
        self::assertSame('(solid AND state AND drive)', (string) self::expand('solid state drive', $entries), 'unquoted words are separate terms');
    }

    public function testAPrefixIsNeverExpanded(): void
    {
        self::assertSame('tv*', (string) self::expand('tv*', [['tv', 'television']]));
    }

    public function testNegationFieldScopeAndGroupsExpandInside(): void
    {
        $entries = [['tv', 'television']];

        $not = self::expand('mouse -tv', $entries);
        self::assertSame('(mouse AND NOT (tv OR television))', (string) $not);
        self::assertInstanceOf(AllOf::class, $not);
        self::assertInstanceOf(Not::class, $not->nodes[1]);

        $scoped = self::expand('name:tv', $entries);
        self::assertSame('(name:tv OR name:television)', (string) $scoped);
        self::assertInstanceOf(AnyOf::class, $scoped);
        self::assertInstanceOf(FieldScoped::class, $scoped->nodes[1]);
        self::assertInstanceOf(Term::class, $scoped->nodes[1]->node);
        self::assertTrue($scoped->nodes[1]->node->synonym);

        self::assertSame('((tv OR television) OR mouse)', (string) self::expand('tv | mouse', $entries));
    }

    public function testASynonymOfASynonymIsNotFollowed(): void
    {
        $entries = ['a => b', 'b => c'];

        self::assertSame('(a OR b)', (string) self::expand('a', $entries));
    }

    public function testACycleAndTheSameWordAreHarmless(): void
    {
        self::assertSame('(a OR b)', (string) self::expand('a', ['a => b', 'b => a']));
        self::assertSame('(a OR b)', (string) self::expand('a', [['a', 'b'], 'a => b']), 'an alternative is added once');
        self::assertSame('a', (string) self::expand('a', ['a => a']), 'a word is not its own synonym');
    }

    public function testAQueryWithoutASynonymWordIsReturnedAsItIs(): void
    {
        $root = (new QueryParser())->parse('mouse -cable')->root;
        self::assertNotNull($root);

        self::assertEquals($root, (new SynonymExpander(Synonyms::fromEntries([['tv', 'television']]), []))->expand($root));
    }

    public function testTheWordsTheEngineHasToStem(): void
    {
        self::assertEqualsCanonicalizing(['tv', 'television', 'solid', 'state', 'drive', 'ssd', 'laptop', 'notebook'], SynonymExpander::wordsOf(Synonyms::fromEntries([['TV', 'Television'], ['ssd', 'solid state drive'], 'laptop => notebook'])));

        $root = (new QueryParser())->parse('TVs name:Mouse -cable "Solid State" tv* keyb*')->root;
        self::assertNotNull($root);
        self::assertEqualsCanonicalizing(['tvs', 'mouse', 'cable', 'solid', 'state'], SynonymExpander::queryWords($root), 'a prefix is never expanded');
        self::assertSame([], SynonymExpander::queryWords(new Term('tv', false, true)), 'an implied word is not asked about');
        self::assertSame([], SynonymExpander::queryWords(new Phrase(['a', 'b'], true)));
    }

    public function testAMemberIsSplitLikeAQuery(): void
    {
        self::assertSame('(wi-fi OR wireless)', (string) self::expand('wi-fi', [['wi-fi', 'wireless']]) === '(wi-fi | wireless)' ? '(wi-fi | wireless)' : (string) self::expand('wi-fi', [['wi-fi', 'wireless']]));
    }
}

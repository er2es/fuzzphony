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

        return (new SynonymExpander(Synonyms::fromEntries($entries), $stems))->expand($root, $stems);
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
        // the engine gets the stems from PostgreSQL: English maps "Televisions" and "television" to "televis"
        $stems = ['television' => 'televis', 'televisions' => 'televis'];

        self::assertSame('(Televisions OR tv)', (string) self::expand('Televisions', [['tv', 'television']], $stems));
        self::assertSame('(TELEVISION OR tv)', (string) self::expand('TELEVISION', [['tv', 'television']], $stems));
        self::assertSame('Televisions', (string) self::expand('Televisions', [['tv', 'television']]), 'without a stem the plural is another word');
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

    public function testAHyphenatedMemberStaysOneWordLikeInAQuery(): void
    {
        self::assertSame('(wi-fi OR wireless)', (string) self::expand('wi-fi', [['wi-fi', 'wireless']]) === '(wi-fi | wireless)' ? '(wi-fi | wireless)' : (string) self::expand('wi-fi', [['wi-fi', 'wireless']]));
    }

    public function testCaseIsFoldedForAccentedLettersToo(): void
    {
        $entries = [['éclair', 'pastry'], ['crème brûlée', 'custard']];

        self::assertSame('(ÉCLAIR OR pastry)', (string) self::expand('ÉCLAIR', $entries));
        self::assertSame('("CRÈME BRÛLÉE" OR custard)', (string) self::expand('"CRÈME BRÛLÉE"', $entries));
        self::assertEqualsCanonicalizing(['éclair', 'pastry', 'crème', 'brûlée', 'custard'], SynonymExpander::wordsOf(Synonyms::fromEntries([['ÉCLAIR', 'Pastry'], ['CRÈME BRÛLÉE', 'Custard']])));

        $root = (new QueryParser())->parse('ÉCLAIR "CRÈME BRÛLÉE"')->root;
        self::assertNotNull($root);
        self::assertEqualsCanonicalizing(['éclair', 'crème', 'brûlée'], SynonymExpander::queryWords($root));
    }

    public function testEachWordIsListedOnceAndOrBranchesAreWalked(): void
    {
        self::assertSame(['tv', 'television', 'telly'], SynonymExpander::wordsOf(Synonyms::fromEntries([['tv', 'television'], 'tv => telly'])));

        $root = (new QueryParser())->parse('tv tv | mouse')->root;
        self::assertNotNull($root);
        self::assertSame(['tv', 'mouse'], SynonymExpander::queryWords($root));
    }

    public function testAnAlternativePhraseIsFlaggedAndANonWordMemberIsIgnored(): void
    {
        $expanded = self::expand('ssd', [['ssd', 'solid state drive']]);

        self::assertInstanceOf(AnyOf::class, $expanded);
        self::assertInstanceOf(Phrase::class, $expanded->nodes[1]);
        self::assertTrue($expanded->nodes[1]->synonym);

        // a member without a letter or digit has no words: it adds nothing (the validator rejects it earlier)
        self::assertSame('tv', (string) (new SynonymExpander(new Synonyms([['tv', '+++']]), []))->expand(new Term('tv')));
    }

    public function testAnImpliedWordOrPhraseIsNotExpandedAgain(): void
    {
        $synonyms = new Synonyms([['solid state', 'ssd'], ['television', 'tv']]);
        $expander = new SynonymExpander($synonyms, []);

        $phrase = new Phrase(['solid', 'state'], true);
        self::assertSame($phrase, $expander->expand($phrase));
        $term = new Term('tv', false, true);
        self::assertSame($term, $expander->expand($term));
    }

    public function testAnExpansionIsFlaggedAsOneUnit(): void
    {
        $expanded = self::expand('tv', [['tv', 'television']]);

        self::assertInstanceOf(AnyOf::class, $expanded);
        self::assertTrue($expanded->expansion);
        $typed = (new QueryParser())->parse('tv | mouse')->root;
        self::assertInstanceOf(AnyOf::class, $typed);
        self::assertFalse($typed->expansion, 'an OR the user typed is not an expansion');
    }

    public function testTheBudgetLimitsTheAlternativesAndLeavesTheRestAsTyped(): void
    {
        $synonyms = Synonyms::fromEntries([['a', 'b', 'c', 'd'], ['x', 'y', 'z']]);
        $expander = new SynonymExpander($synonyms, []);
        $root = (new QueryParser())->parse('a x')->root;
        self::assertNotNull($root);

        $truncated = false;
        self::assertSame('((a OR b OR c OR d) AND (x OR y OR z))', (string) $expander->expand($root, [], 5, $truncated));
        self::assertFalse($truncated);

        // three alternatives for "a", two for "x": 3 fit, then 2 do not
        self::assertSame('((a OR b OR c OR d) AND x)', (string) $expander->expand($root, [], 4, $truncated));
        self::assertTrue($truncated);

        // "a" needs three, which do not fit in two; "x" needs two, which do
        $truncated = false;
        self::assertSame('(a AND (x OR y OR z))', (string) $expander->expand($root, [], 2, $truncated));
        self::assertTrue($truncated);

        $truncated = false;
        self::assertSame('(a AND x)', (string) $expander->expand($root, [], 1, $truncated));
        self::assertTrue($truncated);
        self::assertSame('(a AND x)', (string) $expander->expand($root, [], 0));
    }

    public function testTheStemsOfTheQueryAndOfTheMembersAreBothUsed(): void
    {
        $expander = new SynonymExpander(Synonyms::fromEntries([['television', 'tv']]), ['television' => 'televis']);
        $root = (new QueryParser())->parse('Televisions')->root;
        self::assertNotNull($root);

        self::assertSame('Televisions', (string) $expander->expand($root), 'without the stem of the query word');
        self::assertSame('(Televisions OR tv)', (string) $expander->expand($root, ['televisions' => 'televis']));
    }

    public function testTheTypedWordsASynonymExpandedAreListed(): void
    {
        $synonyms = Synonyms::fromEntries([['tv', 'television'], ['ssd', 'solid state drive']]);
        $expander = new SynonymExpander($synonyms, []);
        $expand = static function (string $text) use ($expander): Node {
            $root = (new QueryParser())->parse($text)->root;
            self::assertNotNull($root);

            return $expander->expand($root);
        };

        self::assertSame(['tv'], SynonymExpander::expandedWords($expand('tv')));
        self::assertSame(['tv'], SynonymExpander::expandedWords($expand('tv mouse')), 'the alternatives and the other words are not listed');
        self::assertSame(['tv', 'ssd'], SynonymExpander::expandedWords($expand('tv | ssd')));
        self::assertSame(['tv'], SynonymExpander::expandedWords($expand('mouse -tv')), 'also inside an exclusion');
        self::assertSame(['television'], SynonymExpander::expandedWords($expand('television')));
        self::assertSame([], SynonymExpander::expandedWords($expand('mouse')));
        self::assertSame(['tv'], SynonymExpander::expandedWords($expand('(tv | mouse) pad')));
        self::assertSame(['ssd'], SynonymExpander::expandedWords($expand('name:ssd')), 'a field-scoped word is listed by its word');
    }
}

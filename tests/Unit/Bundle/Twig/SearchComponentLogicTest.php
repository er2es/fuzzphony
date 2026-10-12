<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Twig;

use Fuzzphony\Bundle\Twig\SearchComponent;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * SearchComponent's own logic (what it asks the engine, when it asks, what it remembers) against an
 * engine that records the queries; the rendered component is in tests/Integration/Twig.
 */
final class SearchComponentLogicTest extends TestCase
{
    /** @var list<SearchQuery> */
    private array $queries = [];
    private ?int $suggestLimit = null;
    private ?string $didYouMean = null;

    public function testABlankQueryIsNotSearchedNorCompleted(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            $component = $this->component(query: $blank);

            self::assertNull($component->getResult(), var_export($blank, true));
            self::assertSame([], $component->getCompletions());
        }
        self::assertSame([], $this->queries);
        self::assertNull($this->suggestLimit);
    }

    public function testABlankQueryIsAnsweredBeforeTheIndexIsLookedUp(): void
    {
        $component = $this->component(query: '  ', index: 'no_such_index');

        self::assertSame([], $component->getCompletions());
        self::assertNull($component->getResult());

        $component->query = 'mouse';
        $this->expectException(\Throwable::class);
        $component->getCompletions();
    }

    public function testTheResultIsSearchedOnceUntilSomethingChanges(): void
    {
        $component = $this->component(query: 'mouse', asYouType: false);

        self::assertSame($component->getResult(), $component->getResult());
        self::assertCount(1, $this->queries);

        $component->useCompletion('keyboard');
        $component->getResult();
        self::assertCount(2, $this->queries);
        self::assertSame('keyboard', $this->queries[1]->text);

        $component->getResult();
        self::assertCount(2, $this->queries, 'cached again');
    }

    public function testTheComponentPassesItsPropsToTheSearch(): void
    {
        $component = $this->component(query: 'mouse', profile: 'popular', highlight: ' name , brand ,, ', facets: ' in_stock , brand_id ,', asYouType: false);

        $component->getResult();

        $query = $this->queries[0];
        self::assertSame('mouse', $query->text);
        self::assertSame('popular', $query->profile);
        self::assertSame(['name', 'brand'], $query->highlight);
        self::assertSame(['in_stock', 'brand_id'], $query->facets);
        self::assertFalse($query->asYouType);
    }

    public function testTheWordBeingTypedIsSearchedAsAPrefixToo(): void
    {
        $this->component(query: 'mouse', asYouType: true)->getResult();

        self::assertTrue($this->queries[0]->asYouType);
    }

    /** @return iterable<string, array{int, int}> */
    public static function limits(): iterable
    {
        yield 'zero is raised to one' => [0, 1];
        yield 'negative is raised to one' => [-4, 1];
        yield 'one' => [1, 1];
        yield 'two' => [2, 2];
        yield 'forty-nine' => [49, 49];
        yield 'fifty' => [50, 50];
        yield 'fifty-one is cut to fifty' => [51, 50];
        yield 'a thousand is cut to fifty' => [1000, 50];
    }

    #[DataProvider('limits')]
    public function testTheHitLimitIsOneToFifty(int $limit, int $expected): void
    {
        $this->component(query: 'mouse', limit: $limit)->getResult();

        self::assertSame($expected, $this->queries[0]->limit);
    }

    /** @return iterable<string, array{int, int|null}> */
    public static function completionLimits(): iterable
    {
        yield 'switched off' => [0, null];
        yield 'negative: switched off' => [-1, null];
        yield 'one' => [1, 1];
        yield 'five' => [5, 5];
        yield 'nineteen' => [19, 19];
        yield 'twenty' => [20, 20];
        yield 'twenty-one is cut to twenty' => [21, 20];
        yield 'a hundred is cut to twenty' => [100, 20];
    }

    #[DataProvider('completionLimits')]
    public function testTheCompletionsAskForOneToTwentyWordsOrNoneWhenSwitchedOff(int $suggestions, ?int $expected): void
    {
        $component = $this->component(query: 'wireless key', suggestions: $suggestions);

        $completions = $component->getCompletions();

        self::assertSame($expected, $this->suggestLimit);
        self::assertSame($expected === null ? [] : ['wireless keyboard'], $completions);
    }

    public function testUseSuggestionSearchesTheSuggestedSpelling(): void
    {
        $this->didYouMean = 'mouse';
        $component = $this->component(query: 'mose', asYouType: false);

        $component->useSuggestion();

        self::assertSame('mouse', $component->query);
        $component->getResult();
        self::assertSame(['mose', 'mouse'], array_map(static fn(SearchQuery $q): string => $q->text, $this->queries), 'the suggestion was found with the first search, then searched itself');
    }

    public function testUseSuggestionDoesNothingWithoutASuggestionOrAQuery(): void
    {
        $blank = $this->component(query: '');
        $blank->useSuggestion();
        self::assertSame('', $blank->query);
        self::assertSame([], $this->queries);

        $none = $this->component(query: 'mouse', asYouType: false);
        $none->useSuggestion();
        self::assertSame('mouse', $none->query);
        self::assertCount(1, $this->queries);
    }

    public function testOnlyTheFiltersListedAsFacetsCanBeChosenAndChoosingTwiceLiftsTheChoice(): void
    {
        $component = $this->component(query: 'mouse', facets: 'in_stock');

        $component->toggleFacet('brand_id', '1');
        self::assertSame([], $component->selected, 'brand_id is no facet of this component');

        $component->toggleFacet('in_stock', '1');
        self::assertSame(['in_stock' => '1'], $component->selected);

        $component->toggleFacet('in_stock', '0');
        self::assertSame(['in_stock' => '0'], $component->selected, 'another value replaces the choice');

        $component->toggleFacet('in_stock', '0');
        self::assertSame([], $component->selected);
    }

    /** @return iterable<string, array{string, string, Operator, mixed}> */
    public static function chosenValues(): iterable
    {
        yield 'bool 1' => ['in_stock', '1', Operator::Eq, true];
        yield 'bool true' => ['in_stock', 'true', Operator::Eq, true];
        yield 'bool 0' => ['in_stock', '0', Operator::Eq, false];
        yield 'bool false' => ['in_stock', 'false', Operator::Eq, false];
        yield 'bool anything else' => ['in_stock', 'yes', Operator::Eq, false];
        yield 'documents without a value (bool)' => ['in_stock', '', Operator::IsNull, null];
        yield 'documents without a value (int)' => ['brand_id', '', Operator::IsNull, null];
        yield 'int' => ['brand_id', '12', Operator::Eq, 12];
        yield 'negative int' => ['brand_id', '-5', Operator::Eq, -5];
        yield 'string' => ['colour', 'red', Operator::Eq, 'red'];
    }

    #[DataProvider('chosenValues')]
    public function testAChosenFacetValueNarrowsTheSearch(string $filter, string $value, Operator $operator, mixed $expected): void
    {
        $component = $this->component(query: 'mouse', facets: $filter, selected: [$filter => $value]);

        $component->getResult();

        self::assertCount(1, $this->queries[0]->conditions);
        $condition = $this->queries[0]->conditions[0];
        self::assertSame($filter, $condition->filter);
        self::assertSame($operator, $condition->operator);
        self::assertSame($expected, $condition->value);
    }

    /** @return iterable<string, array{string}> */
    public static function ignoredValues(): iterable
    {
        yield 'a minus sign alone' => ['-'];
        yield 'not a number' => ['abc'];
        yield 'a decimal' => ['1.5'];
    }

    #[DataProvider('ignoredValues')]
    public function testAnIntegerChoiceThatIsNoIntegerIsIgnored(string $value): void
    {
        $component = $this->component(query: 'mouse', facets: 'brand_id', selected: ['brand_id' => $value]);

        $component->getResult();

        self::assertSame([], $this->queries[0]->conditions);
    }

    public function testAChoiceOfAFilterThatIsNoLongerAFacetIsIgnored(): void
    {
        $component = $this->component(query: 'mouse', facets: 'in_stock', selected: ['brand_id' => '3', 'in_stock' => '1']);

        $component->getResult();

        self::assertCount(1, $this->queries[0]->conditions);
        self::assertSame('in_stock', $this->queries[0]->conditions[0]->filter);
    }

    public function testTheSearchIsMadeAsTheResolvedActor(): void
    {
        $actors = self::createStub(ActorResolver::class);
        $actors->method('resolve')->willReturn(new Actor(tenant: 7, user: 'ada'));

        $this->component(query: 'mouse', actors: $actors)->getResult();

        self::assertSame('ada', $this->queries[0]->actor?->user);
    }

    public function testTheResolvedTenantScopesATenantScopedIndex(): void
    {
        $actors = self::createStub(ActorResolver::class);
        $actors->method('resolve')->willReturn(new Actor(tenant: 7, user: 'ada'));

        $this->component(query: 'mouse', actors: $actors, tenant: true)->getResult();

        self::assertSame(7, $this->queries[0]->tenant, 'the engine is asked for the resolved tenant');
        self::assertSame('ada', $this->queries[0]->actor?->user);
    }

    public function testWithoutAResolverTheSearchHasNoActor(): void
    {
        $this->component(query: 'mouse')->getResult();

        self::assertNull($this->queries[0]->actor);
    }

    /** The tenant and the user come from the server side, never from the browser: a LiveProp is writable state the client can post. */
    public function testNoLivePropCarriesTheTenantTheUserOrTheActor(): void
    {
        $liveProps = [];
        foreach ((new \ReflectionClass(SearchComponent::class))->getProperties() as $property) {
            if ($property->getAttributes(LiveProp::class) !== []) {
                $liveProps[] = $property->getName();
            }
        }

        self::assertContains('query', $liveProps, 'the reflection does see the live props');
        self::assertSame([], array_intersect($liveProps, ['tenant', 'user', 'actor']));
    }

    private function shirts(): IndexDefinition
    {
        return IndexDefinition::builder('shirts')->fromQuery('SELECT id, name, colour FROM fz_shirt')->sync('manual')->field('name', 'A')->filter('colour', 'string')->build();
    }

    /**
     * @param array<string, string> $selected
     */
    private function component(string $query = '', string $index = 'products', string $profile = 'default', string $highlight = '', int $limit = 10, string $facets = '', int $suggestions = 5, bool $asYouType = true, array $selected = [], ?ActorResolver $actors = null, bool $tenant = false): SearchComponent
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
        $engine->method('search')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query): SearchResult {
            $this->queries[] = $query;
            $result = SearchResult::empty($query->limit, $query->offset);

            // the suggestion belongs to the first search only: the searched suggestion is a word the index knows
            return count($this->queries) === 1 ? $result->withDidYouMean($this->didYouMean) : $result;
        });
        $engine->method('suggestWords')->willReturnCallback(function (IndexDefinition $index, string $prefix, int $limit): array {
            $this->suggestLimit = $limit;

            return ['keyboard'];
        });
        assert($engine instanceof Engine);

        $component = new SearchComponent(new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual', tenant: $tenant), $this->shirts()])), $actors);
        $component->query = $query;
        $component->index = $facets === 'colour' ? 'shirts' : $index;
        $component->profile = $profile;
        $component->highlight = $highlight;
        $component->limit = $limit;
        $component->facets = $facets;
        $component->suggestions = $suggestions;
        $component->asYouType = $asYouType;
        $component->selected = $selected;

        return $component;
    }
}

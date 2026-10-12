<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What fuzzphony:search hands to the engine and how it prints what comes back, against an
 * engine that records the query (no database): option parsing, clamping and the output format.
 */
final class SearchCommandOptionsTest extends TestCase
{
    private ?SearchQuery $query = null;
    private ?bool $analyze = null;

    /** @return iterable<string, array{string, int}> */
    public static function suggestLimits(): iterable
    {
        yield 'zero is raised to one' => ['0', 1];
        yield 'negative is raised to one' => ['-3', 1];
        yield 'one' => ['1', 1];
        yield 'two' => ['2', 2];
        yield 'nineteen' => ['19', 19];
        yield 'twenty' => ['20', 20];
        yield 'twenty-one is cut to twenty' => ['21', 20];
        yield 'a hundred is cut to twenty' => ['100', 20];
    }

    #[DataProvider('suggestLimits')]
    public function testSuggestClampsTheLimitToOneToTwenty(string $limit, int $expected): void
    {
        $passed = null;
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
        $engine->method('suggestWords')->willReturnCallback(static function (IndexDefinition $index, string $prefix, int $limit) use (&$passed): array {
            $passed = $limit;

            return ['headphones'];
        });
        assert($engine instanceof Engine);

        $tester = $this->tester($engine);
        $status = $tester->execute(['index' => 'products', 'query' => 'wireless hea', '--suggest' => true, '--limit' => $limit], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($expected, $passed);
        self::assertNull($this->query, 'completing the last word is not a search');
        self::assertStringNotContainsString('hit(s)', $tester->getDisplay(true));
    }

    public function testSuggestionsAreEscapedAndAnEmptyListIsSaid(): void
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
        $engine->method('suggestWords')->willReturnCallback(static fn(IndexDefinition $index, string $prefix): array => $prefix === 'hea' ? ['headphones'] : []);
        assert($engine instanceof Engine);
        $tester = $this->tester($engine);

        $tester->execute(['index' => 'products', 'query' => '<info>x hea', '--suggest' => true], ['interactive' => false]);
        self::assertStringContainsString('<info>x headphones', $tester->getDisplay(true), 'the completion is printed as text, not as console markup');

        $tester->execute(['index' => 'products', 'query' => 'qq', '--suggest' => true], ['interactive' => false]);
        self::assertStringContainsString('No completions.', $tester->getDisplay(true));
    }

    /** @return iterable<string, array{string, int}> */
    public static function searchLimits(): iterable
    {
        yield 'zero is raised to one' => ['0', 1];
        yield 'negative is raised to one' => ['-5', 1];
        yield 'one' => ['1', 1];
        yield 'two' => ['2', 2];
        yield 'default' => ['10', 10];
    }

    #[DataProvider('searchLimits')]
    public function testTheSearchLimitIsAtLeastOne(string $limit, int $expected): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--limit' => $limit], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($expected, $this->query()->limit);
    }

    public function testTheDefaultsOfTheOptions(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        $query = $this->query();
        self::assertSame(10, $query->limit);
        self::assertSame('default', $query->profile);
        self::assertSame([], $query->conditions);
        self::assertSame([], $query->thresholdOverrides);
        self::assertSame([], $query->facets);
        self::assertFalse($query->exactCounts);
        self::assertFalse($query->asYouType);
        self::assertNull($this->analyze, 'no explain unless asked');
    }

    /** @return iterable<string, array{string, string, Operator, mixed}> */
    public static function filters(): iterable
    {
        yield 'equals a number' => ['price=100', 'price', Operator::Eq, '100'];
        yield 'at most' => ['price <= 20000', 'price', Operator::Lte, '20000'];
        yield 'at least' => ['price>=5', 'price', Operator::Gte, '5'];
        yield 'not equal' => ['brand_id!=3', 'brand_id', Operator::Neq, '3'];
        yield 'less than' => ['price<7', 'price', Operator::Lt, '7'];
        yield 'greater than' => ['price>7', 'price', Operator::Gt, '7'];
        yield 'true' => ['in_stock=true', 'in_stock', Operator::Eq, true];
        yield 'TRUE in capitals' => ['in_stock=TRUE', 'in_stock', Operator::Eq, true];
        yield 'false' => ['in_stock=false', 'in_stock', Operator::Eq, false];
        yield 'False mixed' => ['in_stock=False', 'in_stock', Operator::Eq, false];
    }

    #[DataProvider('filters')]
    public function testWhereFiltersAreParsedIntoConditions(string $where, string $filter, Operator $operator, mixed $value): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--where' => [$where]], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $this->query()->conditions);
        $condition = $this->query()->conditions[0];
        self::assertSame($filter, $condition->filter);
        self::assertSame($operator, $condition->operator);
        self::assertSame($value, $condition->value);
    }

    /** @return iterable<string, array{string}> */
    public static function unparsableFilters(): iterable
    {
        yield 'no operator' => ['not a filter'];
        yield 'words before the filter' => ['foo bar=1'];
        yield 'a second line' => ["price=1\nin_stock=true"];
        yield 'no name' => ['=5'];
    }

    #[DataProvider('unparsableFilters')]
    public function testAnUnparsableFilterIsRejectedBeforeSearching(string $where): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--where' => [$where]], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Cannot parse filter', $tester->getDisplay(true));
        self::assertNull($this->query, 'the engine was not asked');
    }

    public function testThresholdOverridesAreTyped(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--threshold' => ['min_score=0.25', 'fuzzy_mode=always', 'fuzzy_similarity=NULL', 'candidate_limit=500']], ['interactive' => false]);

        self::assertSame(
            ['min_score' => 0.25, 'fuzzy_mode' => 'always', 'fuzzy_similarity' => null, 'candidate_limit' => 500],
            $this->query()->thresholdOverrides,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedThresholds(): iterable
    {
        yield 'the value is everything after the first equals sign' => ['fuzzy_similarity=0.5=1', '"fuzzy_similarity" must be a number.'];
        yield 'a missing value is empty' => ['fuzzy_mode', '"fuzzy_mode" must be one of'];
    }

    #[DataProvider('malformedThresholds')]
    public function testAThresholdIsSplitAtTheFirstEqualsSignAndAMissingValueIsEmpty(string $threshold, string $message): void
    {
        $tester = $this->tester();

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage($message);

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--threshold' => [$threshold]], ['interactive' => false]);
    }

    public function testOptionsAreHandedToTheEngine(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--rank-profile' => 'popular', '--facet' => ['in_stock', 'brand_id'], '--exact' => true, '--as-you-type' => true], ['interactive' => false]);

        $query = $this->query();
        self::assertSame('popular', $query->profile);
        self::assertSame(['in_stock', 'brand_id'], $query->facets);
        self::assertTrue($query->exactCounts);
        self::assertTrue($query->asYouType);
    }

    /** @return iterable<string, array{string, int}> */
    public static function tenants(): iterable
    {
        // The option is a string, whatever it looks like: the FilterType of the tenant filter decides what it means
        // (an Int filter accepts "12", a String filter keeps it).
        yield 'digits stay a string here, the Int filter converts them' => ['12', 12];
        yield 'zero' => ['0', 0];
        yield 'a number with a sign' => ['-3', -3];
    }

    /** @return iterable<string, array{string}> */
    public static function stringTenants(): iterable
    {
        yield 'digits' => ['12'];
        yield 'a word' => ['acme'];
        yield 'text with a digit first' => ['1t'];
    }

    #[DataProvider('stringTenants')]
    public function testTheTenantOptionIsHandedOverAsItIsTyped(string $tenant): void
    {
        $tester = $this->tester(tenant: true);

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--tenant' => $tenant, '--user' => 'ada'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $actor = $this->query()->actor;
        self::assertNotNull($actor);
        self::assertSame('ada', $actor->user);
        self::assertSame($tenant, $actor->tenant, 'the raw string, never a guessed integer');
    }

    #[DataProvider('tenants')]
    public function testADigitsTenantStillScopesAnIntegerTenantFilter(string $tenant, int $expected): void
    {
        $tester = $this->tester(tenant: true);

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--tenant' => $tenant], ['interactive' => false]);

        // the query keeps what the user typed; the integer filter normalizes it when the engine compiles the condition
        self::assertSame($tenant, $this->query()->tenant);
        self::assertSame($expected, FilterType::Int->normalize($this->query()->tenant, 'brand_id'));
    }

    /** @return iterable<string, array{string}> */
    public static function emptyOptions(): iterable
    {
        yield 'tenant' => ['--tenant'];
        yield 'user' => ['--user'];
    }

    #[DataProvider('emptyOptions')]
    public function testAnEmptyTenantOrUserIsRejectedBeforeSearching(string $option): void
    {
        $tester = $this->tester(tenant: true);

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', $option => ''], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString(sprintf('%s needs a value', $option), $tester->getDisplay(true));
        self::assertNull($this->query, 'the engine was not asked');
    }

    public function testATenantScopedIndexWithoutTenantOrUserIsAnErrorMessageNotAStackTrace(): void
    {
        $tester = $this->tester(tenant: true, throws: InvalidQuery::missingTenant('products'));

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('requires forTenant()', $tester->getDisplay(true));
        self::assertStringNotContainsString('hit(s)', $tester->getDisplay(true));
    }

    public function testTheSameGoesForExplain(): void
    {
        $tester = $this->tester(tenant: true, throws: InvalidQuery::missingTenant('products'));

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('requires forTenant()', $tester->getDisplay(true));
    }

    public function testTheUserOptionAloneIsAnActorWithoutATenant(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--user' => 'ada'], ['interactive' => false]);

        self::assertNull($this->query()->actor?->tenant);
        self::assertSame('ada', $this->query()->actor?->user);
    }

    public function testWithoutTenantOrUserThereIsNoActor(): void
    {
        $this->tester()->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        self::assertNull($this->query()->actor);
    }

    public function testExplainAsksForAnAnalyzeOnlyWhenToldTo(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true], ['interactive' => false]);
        self::assertFalse($this->analyze);

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true, '--analyze' => true], ['interactive' => false]);
        self::assertTrue($this->analyze);
    }

    public function testTheSummaryLine(): void
    {
        $plain = $this->tester(result: $this->searchResult(interpretedAs: 'wireless mouse', total: 1234, usedFuzzy: false));
        $plain->execute(['index' => 'products', 'query' => 'wireless mouse'], ['interactive' => false]);
        self::assertStringContainsString('Interpreted as wireless mouse · 1,234 hit(s) · 3.5 ms', $plain->getDisplay(true));
        self::assertStringNotContainsString('typo-tolerant', $plain->getDisplay(true));
        self::assertStringNotContainsString('browsing', $plain->getDisplay(true));

        $fuzzy = $this->tester(result: $this->searchResult(interpretedAs: null, total: 2, totalIsLowerBound: true, usedFuzzy: true));
        $fuzzy->execute(['index' => 'products'], ['interactive' => false]);
        self::assertStringContainsString('Interpreted as (no text: browsing) · 2+ hit(s) · 3.5 ms · typo-tolerant', $fuzzy->getDisplay(true));
    }

    public function testWarningsAndTheSpellingSuggestionAreEscapedLines(): void
    {
        $tester = $this->tester(result: $this->searchResult(warnings: ['ignored <comment>zzqqx</comment>'], didYouMean: '<info>mouse</info>'));

        $tester->execute(['index' => 'products', 'query' => 'mose'], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString(" ! ignored <comment>zzqqx</comment>\n", $display);
        self::assertStringContainsString(' Did you mean <info>mouse</info>?', $display);
    }

    public function testTheTablePrintsEveryScoreComponent(): void
    {
        $hit = new Hit('p-7', 1.23456, new ScoreBreakdown(textRank: 0.1234, fuzzySimilarity: 0.5678, exactBonus: 0.5, prefixBonus: 0.25, boostBonus: 0.0123, recencyBonus: 0.0456));
        $tester = $this->tester(result: $this->searchResult(hits: [$hit], total: 1));

        $tester->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        self::assertMatchesRegularExpression('/\bp-7\s+1\.235\s+0\.123\s+0\.568\s+0\.75\s+0\.012\s+0\.046\b/', $tester->getDisplay(true));
    }

    public function testFacetValuesAreListedWithTheirCounts(): void
    {
        $facets = [
            'in_stock' => [new FacetValue(true, 3), new FacetValue(false, 1)],
            'brand_id' => [new FacetValue(7, 2), new FacetValue(null, 4)],
            'category' => [new FacetValue('<b>x</b>', 1)],
            'empty' => [],
        ];
        $tester = $this->tester(result: $this->searchResult(facets: $facets));

        $tester->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString('in_stock: true (3), false (1)', $display);
        self::assertStringContainsString('brand_id: 7 (2), (none) (4)', $display);
        self::assertStringContainsString('category: <b>x</b> (1)', $display);
        self::assertStringContainsString('empty: (no values)', $display);
    }

    public function testExplainPrintsEachStatementItsParametersAndThePlan(): void
    {
        $explanation = new Explanation(
            'mouse',
            [['label' => 'candidates', 'sql' => 'SELECT 1', 'params' => ['q' => 'é', 'n' => 3]], ['label' => 'facet in_stock', 'sql' => 'SELECT 2', 'params' => []]],
            ['Seq Scan on fuzzphony_products', 'Planning Time: 0.1 ms'],
            $this->searchResult(),
        );
        $tester = $this->tester(explanation: $explanation);

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay(true);
        self::assertStringContainsString("SQL: candidates\n", $display);
        self::assertStringContainsString("SELECT 1\n", $display);
        self::assertStringContainsString("params: {\"q\":\"é\",\"n\":3}\n", $display);
        self::assertStringContainsString("SQL: facet in_stock\n", $display);
        self::assertStringContainsString("params: []\n", $display);
        self::assertStringContainsString("\nPlan\n", $display);
        self::assertStringContainsString("Seq Scan on fuzzphony_products\nPlanning Time: 0.1 ms\n", $display);
    }

    private function query(): SearchQuery
    {
        self::assertNotNull($this->query, 'the engine was asked to search');

        return $this->query;
    }

    /** @param (Engine&Stub)|null $engine */
    private function tester(?Engine $engine = null, ?SearchResult $result = null, ?Explanation $explanation = null, bool $tenant = false, ?\Throwable $throws = null): CommandTester
    {
        $result ??= $this->searchResult();
        $engine ??= self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query) use ($result, $throws): SearchResult {
            $this->query = $query;

            return $throws !== null ? throw $throws : $result;
        });
        $engine->method('explain')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query, bool $analyze) use ($explanation, $result, $throws): Explanation {
            $this->query = $query;
            $this->analyze = $analyze;
            if ($throws !== null) {
                throw $throws;
            }

            return $explanation ?? new Explanation('mouse', [], [], $result);
        });

        return new CommandTester(new SearchCommand(new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual', tenant: $tenant)]))));
    }

    /**
     * @param list<Hit>                           $hits
     * @param list<string>                        $warnings
     * @param array<string, list<FacetValue>>     $facets
     */
    private function searchResult(array $hits = [], int $total = 0, bool $totalIsLowerBound = false, bool $usedFuzzy = false, array $warnings = [], ?string $interpretedAs = 'mouse', ?string $didYouMean = null, array $facets = []): SearchResult
    {
        return new SearchResult($hits, $total, $totalIsLowerBound, 3.5, $usedFuzzy, $warnings, 10, 0, $interpretedAs, $didYouMean, $facets);
    }
}

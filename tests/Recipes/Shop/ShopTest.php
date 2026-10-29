<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\Shop;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use Fuzzphony\Tests\Support\RestoresPhpHandlers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Twig\Environment;

/** Everything docs/recipes/shop.md claims, run against PostgreSQL. */
final class ShopTest extends KernelTestCase
{
    use RestoresPhpHandlers;

    use InteractsWithLiveComponents;

    protected static function getKernelClass(): string
    {
        return ShopKernel::class;
    }

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        $connection->execute('DROP TABLE IF EXISTS recipe_product, recipe_category, fuzzphony_products, fuzzphony_products__vocab, fuzzphony_products__next, fuzzphony_products__changes, fuzzphony_categories, fuzzphony_categories__vocab, fuzzphony_categories__next, fuzzphony_categories__changes CASCADE');
        $connection->execute(<<<'SQL'
            CREATE TABLE recipe_product (
                id integer PRIMARY KEY, name text NOT NULL, brand text NOT NULL, description text NOT NULL, category_id integer NOT NULL,
                in_stock boolean NOT NULL, price integer NOT NULL, popularity real NOT NULL, published_at timestamptz NOT NULL
            )
            SQL);
        $connection->execute('CREATE TABLE recipe_category (id integer PRIMARY KEY, name text NOT NULL)');
        foreach ([[1, 'Audio'], [2, 'Peripherals'], [3, 'Displays'], [4, 'Computers'], [5, 'Headphones']] as [$id, $name]) {
            $connection->execute('INSERT INTO recipe_category VALUES (:id, :name)', ['id' => $id, 'name' => $name]);
        }
        // published_at is relative to now: the recency of a profile must not depend on the day the test runs
        foreach ([
            [1, 'Wireless Headphones', 'Sonic', 'Over-ear, 30 hours of battery', 1, true, 129, 0.9, 60],
            [2, 'Wireless Headset', 'Sonic', 'Office headset with a boom microphone', 1, true, 79, 0.4, 300],
            [3, 'Wireless Mouse', 'Logix', 'Ergonomic, for the right hand', 2, true, 39, 0.7, 2],
            [4, 'Gaming Mouse', 'Logix', 'RGB lighting', 2, false, 59, 0.5, 120],
            [5, 'Mechanical Keyboard', 'Logix', 'Wired, tactile switches', 2, true, 99, 0.2, 900],
            [6, 'Television 55 inch', 'Visio', 'A 4K panel', 3, true, 499, 0.3, 200],
            [7, 'Portable Notebook 14', 'Notebooks Inc', 'Lightweight, 12 hours of battery', 4, true, 899, 0.1, 100],
            [8, 'USB-C Charging Lead', 'Voltix', 'Two metres', 2, true, 9, 0.95, 1500],
            [9, 'Studio Headphones', 'Sonic', 'Closed back, for recording', 1, false, 249, 0.1, 700],
        ] as [$id, $name, $brand, $description, $category, $inStock, $price, $popularity, $daysAgo]) {
            $connection->execute(
                'INSERT INTO recipe_product VALUES (:id, :name, :brand, :description, :category, :stock, :price, :popularity, now() - make_interval(days => :days))',
                ['id' => $id, 'name' => $name, 'brand' => $brand, 'description' => $description, 'category' => $category, 'stock' => $inStock, 'price' => $price, 'popularity' => $popularity, 'days' => $daysAgo],
            );
        }

        self::bootKernel();
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');
        $fuzzphony->reindex('categories');
    }

    protected function tearDown(): void
    {
        // the tables of this recipe must not outlive it: a trigger on recipe_product would be counted by other suites
        PostgresTestCase::connect()->execute('DROP TABLE IF EXISTS recipe_product, recipe_category, fuzzphony_products, fuzzphony_products__vocab, fuzzphony_products__next, fuzzphony_products__changes, fuzzphony_categories, fuzzphony_categories__vocab, fuzzphony_categories__next, fuzzphony_categories__changes CASCADE');
        parent::tearDown();
    }

    private function fuzzphony(): Fuzzphony
    {
        $fuzzphony = self::getContainer()->get(Fuzzphony::class);
        self::assertInstanceOf(Fuzzphony::class, $fuzzphony);

        return $fuzzphony;
    }

    private function storefront(): Storefront
    {
        $storefront = self::getContainer()->get(Storefront::class);
        self::assertInstanceOf(Storefront::class, $storefront);

        return $storefront;
    }

    /**
     * @param array<string, list<FacetValue>> $facets
     *
     * @return array<string, array<string, int>> filter => value => count, in the order the sidebar lists them
     */
    private static function counts(array $facets): array
    {
        $counts = [];
        foreach ($facets as $filter => $values) {
            foreach ($values as $facet) {
                $counts[$filter][var_export($facet->value, true)] = $facet->count;
            }
        }

        return $counts;
    }

    public function testFacetsCountTheMatchesAndAChosenValueStillShowsTheOtherValues(): void
    {
        // region: facets
        $all = $this->storefront()->listing('wireless');
        self::assertSame([1, 2, 3], $all->ids());
        self::assertSame(['category_id' => ['1' => 2, '2' => 1], 'in_stock' => ['true' => 3]], self::counts($all->facets));

        // category 1 is chosen: the hits narrow to it, the category facet still lists category 2 (what choosing it
        // would find), and the other facet counts only inside the choice
        $audio = $this->storefront()->listing('wireless', categoryId: 1);
        self::assertSame([1, 2], $audio->ids());
        self::assertSame(['category_id' => ['1' => 2, '2' => 1], 'in_stock' => ['true' => 2]], self::counts($audio->facets));
        // endregion

        self::assertSame([2, 3], $this->fuzzphony()->in('products')->query('wireless')->whereBetween('price', 30, 100)->get()->ids(), 'price is a filter of the index for ranges');
        $browse = $this->storefront()->listing('');
        self::assertSame(9, $browse->total, 'an empty search text is a browse: the same endpoint serves the first page of the shop');
        self::assertSame(['2' => 4, '1' => 3, '3' => 1, '4' => 1], self::counts($browse->facets)['category_id']);
    }

    public function testFacetsCostOneStatementAndAChosenFacetOneMore(): void
    {
        // region: facet-cost
        $facetStatements = static fn(SearchBuilder $search): array => array_values(array_filter(
            array_column($search->explain()->statements, 'label'),
            static fn(string $label): bool => str_starts_with($label, 'facet:'),
        ));
        $search = $this->fuzzphony()->in('products')->query('wireless')->facets('category_id', 'in_stock');

        // no condition on the filters of the facets: they share one statement that evaluates the candidates once
        self::assertSame(['facet: category_id, in_stock'], $facetStatements($search));
        // a condition on category_id: its facet leaves the condition out, so it counts other candidates, in a statement of its own
        self::assertSame(['facet: category_id', 'facet: in_stock'], $facetStatements($search->where('category_id', 1)));
        // endregion
    }

    public function testAResultWithFewHitsAlsoRunsTheTypoTolerantFallbackAndTheDidYouMeanLookup(): void
    {
        // region: fallback-cost
        $labels = array_column($this->fuzzphony()->in('products')->query('wireless')->explain()->statements, 'label');
        self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'did you mean'], $labels);   // 3 hits: fewer than fallback_below (5)
        // endregion
    }

    public function testTheVocabularyIsRebuiltByTheReindexCommand(): void
    {
        $console = new Application(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);

        self::assertSame(0, $tester->run(['command' => 'fuzzphony:reindex', '--vocabulary' => true], ['decorated' => false]), $tester->getDisplay());
        self::assertStringContainsString('words in the vocabulary', $tester->getDisplay());
    }

    public function testSearchAsYouTypeFindsTheWordBeingTypedAndSuggestCompletesIt(): void
    {
        // region: typing
        $typing = $this->fuzzphony()->in('products')->query('wireless mo')->asYouType()->get();
        self::assertSame([3], $typing->ids());                                 // "mo" is the beginning of "mouse"
        self::assertSame('(wireless AND (mo OR mo*))', $typing->interpretedAs);

        $whole = $this->fuzzphony()->in('products')->query('wireless mo')->get();
        self::assertSame([1, 2, 3], $whole->ids());                            // "mo" is no word: it is ignored, and the result says so
        self::assertSame(['No results for all words; ignored words that match nothing: "mo".'], $whole->warnings);

        self::assertSame(['wireless headphones', 'wireless headset'], $this->fuzzphony()->suggest('products', 'wireless hea'));
        // endregion

        $dropdown = $this->storefront()->dropdown('wireless hea');
        self::assertSame(['wireless headphones', 'wireless headset'], $dropdown['completions']);
        self::assertSame([1, 2], array_map(static fn($hit): int|string => $hit->id, $dropdown['products']));
        self::assertSame(['1' => 2], self::counts(['category_id' => $dropdown['categories']])['category_id'] ?? []);
        self::assertSame([], $this->storefront()->dropdown('')['completions'], 'nothing typed, nothing to complete');
    }

    public function testSynonymsAndDidYouMean(): void
    {
        // region: words
        $tv = $this->fuzzphony()->in('products')->query('tv')->get();
        self::assertSame([6], $tv->ids());                                     // "Television 55 inch" never says "tv"
        self::assertSame('(tv OR television)', $tv->interpretedAs);

        $laptop = $this->fuzzphony()->in('products')->query('laptop')->get();
        self::assertSame([7], $laptop->ids());                                 // a one-way rule: laptop => notebook | portable
        self::assertSame('(laptop OR notebook OR portable)', $laptop->interpretedAs);

        $typo = $this->fuzzphony()->in('products')->query('hedphones')->get();
        self::assertSame('headphones', $typo->didYouMean);                     // a suggestion to show as a link: it is not searched for the visitor unless a search finds nothing
        // endregion

        self::assertNull($this->fuzzphony()->in('products')->query('headphones')->get()->didYouMean, 'a word the index knows is never "corrected"');
    }

    public function testAProfileRanksByPopularityAndRecencyAndAnUnknownSortFallsBack(): void
    {
        // region: ranking
        $default = $this->fuzzphony()->in('products')->query('wireless')->get()->ids();
        $popular = $this->fuzzphony()->in('products')->query('wireless')->profile('popular')->get()->ids();
        self::assertSame([1, 2, 3], $default);   // equally relevant: the tie goes to the lower id
        self::assertSame([3, 1, 2], $popular);   // the mouse is new and popular, the headset is old and not
        // endregion

        self::assertSame($default, $this->storefront()->listing('wireless', sort: 'surprise')->ids(), 'a sort from the request is whitelisted, never passed to profile()');
        self::assertSame($popular, $this->storefront()->listing('wireless', sort: 'popular')->ids());
    }

    public function testOneListForProductsAndCategories(): void
    {
        $result = $this->storefront()->everything('headphones');
        $hits = iterator_to_array($result, false);

        // region: federated
        self::assertSame(['products:1', 'products:2', 'categories:5'], array_map(static fn($hit): string => $hit->index . ':' . $hit->hit->id, $hits));
        // product 9 is out of stock and the products index skips those; the headset comes with the typo-tolerant search;
        // the category is last: the products weigh twice
        // endregion

        self::assertSame(2, $result->results['products']->total);
        self::assertGreaterThan($hits[2]->score, $hits[1]->score);
    }

    public function testTheLiveComponentIsTheUiAndAFacetKeepsTheOtherValues(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $page = $twig->render('shop/search.html.twig', ['q' => 'wireless']);
        self::assertStringContainsString('data-facet="category_id"', $page);
        self::assertStringContainsString('data-facet="in_stock"', $page);
        self::assertStringContainsString('data-id="3"', $page);

        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'products', 'query' => 'wireless', 'facets' => 'category_id,in_stock', 'highlight' => 'name']);
        $component->call('toggleFacet', ['filter' => 'category_id', 'value' => '2']);
        $html = (string) $component->render();
        self::assertStringContainsString('data-id="3"', $html);
        self::assertStringNotContainsString('data-id="1"', $html, 'category 2 is chosen: the headphones are gone');
        self::assertStringContainsString('data-live-value-param="1"', $html, 'and category 1 is still offered');
        self::assertMatchesRegularExpression('/aria-pressed="true"[^>]*data-live-value-param="2"/', $html);
        self::assertStringNotContainsString('Audio', $html, 'the facet buttons show the raw values: the category ids, not their names');
    }

    public function testTheDefaultQueueSyncShowsAChangeWhenTheWorkerHasRun(): void
    {
        PostgresTestCase::connect()->execute("INSERT INTO recipe_product VALUES (10, 'Smart Kettle', 'Voltix', 'Qi', 2, true, 29, 0.3, now())");
        self::assertSame([], $this->fuzzphony()->in('products')->query('kettle')->get()->ids(), 'queued, not indexed yet');

        $console = new Application(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);
        self::assertSame(0, $tester->run(['command' => 'fuzzphony:worker', '--once' => true], ['decorated' => false]));

        self::assertStringContainsString('Processed 1 queued item(s).', $tester->getDisplay());
        self::assertSame([10], $this->fuzzphony()->in('products')->query('kettle')->get()->ids());
    }
}

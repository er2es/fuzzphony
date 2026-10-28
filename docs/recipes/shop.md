# Recipe: search for a shop

A product catalogue with a search box that completes while the visitor types, a listing with filters in a sidebar
that show how many products each choice would find, synonyms ("tv" finds the television), popular and new
products ranked higher, and one list for products and categories. Back to the
[documentation](../README.md).

The code is real: it lives in [`tests/Recipes/Shop`](../../tests/Recipes/Shop), in a Symfony kernel with the Live
Component, against PostgreSQL. Every block marked in this page is copied from it and a test fails if they differ.
The tests use nine products and five categories; the numbers below are theirs.

## Setup

```
composer require fuzzphony/fuzzphony symfony/ux-live-component
```

PostgreSQL 15 or later with `pg_trgm` and `unaccent` (see the [README](../../README.md)). The index definitions are
YAML here, so the same file works for any table (an entity with `#[Searchable]` works as well, see
[Index definitions](../configuration.md)). In the builder, `boost:` is `boostBy()` and `recency:` is `recencyBy()`.

<!-- tested: tests/Recipes/Shop/config/fuzzphony.yaml#indexes -->
```yaml
fuzzphony:
  indexes:
    products:
      source: { table: recipe_product }
      language: english
      fields:
        name: { weight: A, fuzzy: true }
        brand: { weight: B, fuzzy: true }
        description: D
      filters:
        category_id: int
        in_stock: bool
        price: int
      boost: popularity                # builder: ->boostBy('popularity')
      recency: published_at            # builder: ->recencyBy('published_at')
      profiles:
        popular: { boost: 0.05, recency: 0.3, recency_half_life_days: 14 }
      synonyms:
        - [tv, television]
        - 'laptop => notebook | portable'
    categories:
      source: { table: recipe_category }
      language: english
      fields:
        name: { weight: A, fuzzy: true }
```

- **Typo tolerance and completions live in the fuzzy fields.** `name` and `brand` are `fuzzy: true`: a misspelt
  word is found there, and the completions of the search box are the words of exactly those fields. A word that is
  only in `description` is matched exactly. There is no separate "suggest list" to configure.
- **Filters are what facets count.** `category_id` and `in_stock` are facets below; `price` is a filter for
  `->whereBetween()` (float and datetime filters cannot be facets).
- The default sync mode is `queue`: a trigger on `recipe_product` queues the id and `bin/console fuzzphony:worker`
  (or `--once` from cron) refreshes the document. The test inserts a product, finds nothing, runs the worker once
  and finds it. If a visitor must see a change at once, use `sync: trigger` (see
  [Keeping the index in sync](../sync.md)).

Create the tables and fill them, once: `bin/console fuzzphony:schema --apply`, then `bin/console fuzzphony:reindex`.
That also builds the vocabulary of the fuzzy fields. It ages as the catalogue changes, so rebuild it at night:
`bin/console fuzzphony:reindex --vocabulary` ([why](../searching.md#did-you-mean)).

## The listing, with facets

<!-- tested: tests/Recipes/Shop/Storefront.php#listing -->
<!-- fragment -->
```php
/** The listing page: hits, the facets of the sidebar and a did-you-mean link. */
public function listing(string $text, ?int $categoryId = null, string $sort = 'default'): SearchResult
{
    $search = $this->fuzzphony->in('products')
        ->query($text)
        ->profile(in_array($sort, ['default', 'popular'], true) ? $sort : 'default')   // never pass raw input to profile()
        ->facets('category_id', 'in_stock')
        ->facetValues(10)
        ->highlight('name')
        ->limit(24);
    if ($categoryId !== null) {
        $search = $search->where('category_id', $categoryId);   // the category facet still lists the other categories
    }

    return $search->get();
}
```

<!-- tested: tests/Recipes/Shop/ShopTest.php#facets -->
```php
$all = $this->storefront()->listing('wireless');
self::assertSame([1, 2, 3], $all->ids());
self::assertSame(['category_id' => ['1' => 2, '2' => 1], 'in_stock' => ['true' => 3]], self::counts($all->facets));

// category 1 is chosen: the hits narrow to it, the category facet still lists category 2 (what choosing it
// would find), and the other facet counts only inside the choice
$audio = $this->storefront()->listing('wireless', categoryId: 1);
self::assertSame([1, 2], $audio->ids());
self::assertSame(['category_id' => ['1' => 2, '2' => 1], 'in_stock' => ['true' => 2]], self::counts($audio->facets));
```

That is the *disjunctive* behaviour of a shop sidebar, and it is the default: **a facet does not count the
conditions on its own filter**. The values are ids; showing "Audio (2)" is your template's job (the facet gives
`value` and `count`). An empty text is a browse (`listing('')` lists the shop and counts every category), so one
endpoint serves the first page and the search results. The page's counts are over the search's candidates (the best 2000
by default, the ones the hits are chosen from): `totalIsLowerBound` says when to print "120+", and `exactCounts()` counts everything, slowly: never
wire that to request input.

### What it costs

<!-- tested: tests/Recipes/Shop/ShopTest.php#facet-cost -->
```php
$facetStatements = static fn(SearchBuilder $search): array => array_values(array_filter(
    array_column($search->explain()->statements, 'label'),
    static fn(string $label): bool => str_starts_with($label, 'facet:'),
));
$search = $this->fuzzphony()->in('products')->query('wireless')->facets('category_id', 'in_stock');

// no condition on the filters of the facets: they share one statement that evaluates the candidates once
self::assertSame(['facet: category_id, in_stock'], $facetStatements($search));
// a condition on category_id: its facet leaves the condition out, so it counts other candidates, in a statement of its own
self::assertSame(['facet: category_id', 'facet: in_stock'], $facetStatements($search->where('category_id', 1)));
```

Since 0.9.5 the facets of a search are not one statement each: all those without a condition of their own share
one, so a search with two facets is three round trips instead of fifteen ([CHANGELOG](../../CHANGELOG.md),
[Benchmarks](../benchmarks.md#round-trips)). They are still statements next to the search's own: the facet that
has a chosen value costs one more, and each further facet with a chosen value another one. Cheap: a plain search
(one statement), `asYouType()` (the same statement, written differently), synonyms (the stems are fetched once and
kept). Costly: a typo-tolerant search runs a second statement and the did-you-mean lookup a third, and a result
under `fallback_below` (5 hits) runs both:

<!-- tested: tests/Recipes/Shop/ShopTest.php#fallback-cost -->
```php
$labels = array_column($this->fuzzphony()->in('products')->query('wireless')->explain()->statements, 'label');
self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'did you mean'], $labels);   // 3 hits: fewer than fallback_below (5)
```

A small catalogue therefore pays the typo-tolerant branch on most searches. `fuzzphony:search products wireless
--explain` shows the statements and their plans on your data.

## Search as you type

<!-- tested: tests/Recipes/Shop/Storefront.php#dropdown -->
<!-- fragment -->
```php
/**
 * The dropdown under the search box while the visitor types.
 *
 * @return array{completions: list<string>, products: list<Hit>, categories: list<FacetValue>}
 */
public function dropdown(string $text): array
{
    $found = $this->fuzzphony->in('products')
        ->query($text)
        ->asYouType()        // the last word also matches as the beginning of a longer one
        ->unlogged()         // a search per keystroke is not what people searched for
        ->facets('category_id')
        ->limit(5)
        ->get();

    return [
        'completions' => $this->fuzzphony->suggest('products', $text, 5),   // "wireless hea" -> "wireless headphones"
        'products' => $found->hits,                                         // load name, price and picture by $hit->id
        'categories' => $found->facets['category_id'] ?? [],                // label the ids yourself
    ];
}
```

<!-- tested: tests/Recipes/Shop/ShopTest.php#typing -->
```php
$typing = $this->fuzzphony()->in('products')->query('wireless mo')->asYouType()->get();
self::assertSame([3], $typing->ids());                                 // "mo" is the beginning of "mouse"
self::assertSame('(wireless AND (mo OR mo*))', $typing->interpretedAs);

$whole = $this->fuzzphony()->in('products')->query('wireless mo')->get();
self::assertSame([1, 2, 3], $whole->ids());                            // "mo" is no word: it is ignored, and the result says so
self::assertSame(['No results for all words; ignored words that match nothing: "mo".'], $whole->warnings);

self::assertSame(['wireless headphones', 'wireless headset'], $this->fuzzphony()->suggest('products', 'wireless hea'));
```

The dropdown (its groups, pictures and prices) is your template, because the pictures and prices are your data.
`suggest()` completes only the last word, from the vocabulary, most frequent first, and charges a rate-limit token
per call ([Security](../security.md#the-rate-limit)). The completions are plain text: escape them.

## Synonyms, did you mean, ranking

<!-- tested: tests/Recipes/Shop/ShopTest.php#words -->
```php
$tv = $this->fuzzphony()->in('products')->query('tv')->get();
self::assertSame([6], $tv->ids());                                     // "Television 55 inch" never says "tv"
self::assertSame('(tv OR television)', $tv->interpretedAs);

$laptop = $this->fuzzphony()->in('products')->query('laptop')->get();
self::assertSame([7], $laptop->ids());                                 // a one-way rule: laptop => notebook | portable
self::assertSame('(laptop OR notebook OR portable)', $laptop->interpretedAs);

$typo = $this->fuzzphony()->in('products')->query('hedphones')->get();
self::assertSame('headphones', $typo->didYouMean);                     // a suggestion to show as a link: it is not searched for the visitor unless a search finds nothing
```

Synonyms are expanded on the query, so changing the list needs neither `schema --apply` nor a reindex, and typo
tolerance applies to a synonym too ([Synonyms](../searching.md#synonyms)). Escape `didYouMean` when you render it. A search that finds nothing at all and has a word with exactly one
one-edit correction (`mpuse` for `mouse`) is searched again with it, and `$result->correctedFrom` and a warning say so
([zero-result rescue](../searching.md#zero-result-rescue)): show a "Showing results for ..." line when `correctedFrom` is set.

<!-- tested: tests/Recipes/Shop/ShopTest.php#ranking -->
```php
$default = $this->fuzzphony()->in('products')->query('wireless')->get()->ids();
$popular = $this->fuzzphony()->in('products')->query('wireless')->profile('popular')->get()->ids();
self::assertSame([1, 2, 3], $default);   // equally relevant: the tie goes to the lower id
self::assertSame([3, 1, 2], $popular);   // the mouse is new and popular, the headset is old and not
```

The `popular` profile adds `boost × popularity` and a recency bonus that halves every 14 days to the relevance
(the formula is in [Ranking](../ranking.md)). Boosts reorder relevant hits and can never pull an irrelevant product
into the results. The listing takes the profile from `?sort=`: whitelist it, as `listing()` does, because
`profile()` throws on an unknown name. The test products are dated relative to now: `recency` depends on the day.

## Products and categories in one list

<!-- tested: tests/Recipes/Shop/Storefront.php#everything -->
<!-- fragment -->
```php
/** Products (in stock, twice the weight) and categories in one merged list. */
public function everything(string $text): FederatedResult
{
    return $this->fuzzphony->federated()
        ->index('products', weight: 2.0, configure: static fn(SearchBuilder $b): SearchBuilder => $b->where('in_stock', true)->highlight('name'))
        ->index('categories')
        ->query($text)
        ->limit(10)
        ->get();
}
```

<!-- tested: tests/Recipes/Shop/ShopTest.php#federated -->
```php
self::assertSame(['products:1', 'products:2', 'categories:5'], array_map(static fn($hit): string => $hit->index . ':' . $hit->hit->id, $hits));
// product 9 is out of stock and the products index skips those; the headset comes with the typo-tolerant search;
// the category is last: the products weigh twice
```

Scores of different indexes cannot be compared, so the lists are merged by reciprocal rank fusion
([Federated search](../searching.md#federated-search)). Ids repeat between indexes: use `$hit->index`. Each index
costs its own search, and page N reads `offset + limit` hits of every index (at most 1000 in all).

## The Live Component as the UI

<!-- tested: tests/Recipes/Shop/templates/shop/search.html.twig#page -->
```twig
<h1>Shop</h1>
<twig:Fuzzphony:Search index="products" query="{{ q }}" highlight="name" facets="category_id,in_stock" suggestions="5" placeholder="Search the shop…" />
```

That one tag is the search box, the completions, the facet buttons (choosing one narrows the search, choosing it
again lifts it, the other values stay: the test clicks category 2 and category 1 is still offered), the hits with
`<mark>`, "did you mean" and the count. It searches on every pause in typing, as you type, and keeps those
searches out of the search log (`log="true"` records them). Each render is one search and, with `suggestions`, one
`suggest()` that does not charge a second rate-limit token.

What it does not do: it prints the highlighted fields only, so no price or picture, and its facet buttons show the
raw values (`category_id` 2, not "Peripherals"). Override `templates/bundles/FuzzphonyBundle/components/Search.html.twig`
([Integrations](../integrations.md#live-component)), or build your own component on `Storefront::listing()`.

## Pitfalls

- **A word only in a non-fuzzy field is not completed, not typo-corrected and not "did you mean"-ed.**
- **A tenant-scoped index gives no completions and no did-you-mean**, and neither does a viewer who may not see
  every field: the vocabulary has the words of all of them (see the [multi-tenant recipe](multi-tenant-saas.md)).
- **The vocabulary is as old as its last rebuild.** A product added today is searchable at once (after the worker) but
  is completed only after the nightly rebuild.
- **Typo tolerance is trigram-based**: it can bring a look-alike (`headset` for `headphones` above):
  [limitations](../limitations.md).
- **`asYouType()` with one letter matches a lot**; the candidate limit caps the work.

## Reference

[Searching](../searching.md), [Ranking](../ranking.md), [Index definitions](../configuration.md),
[Integrations](../integrations.md#live-component), [Keeping the index in sync](../sync.md),
[Search log and analytics](../analytics.md) (what the shop's visitors searched, and found nothing).

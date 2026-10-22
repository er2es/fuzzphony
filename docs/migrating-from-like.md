# Migrating from `LIKE`

You have a Symfony and Doctrine app whose search is `WHERE name ILIKE '%mouse%'`, and you want ranked, typo-tolerant
search without running another server. This guide moves one search over, step by step, and says what you give up on the
way. Every PHP and YAML snippet and every query of [the mapping table](#your-like-patterns-in-fuzzphony) is run by
`tests/Integration/Docs/LikeGuideTest.php` against a small table, exactly as shown here. Back to the
[README](../README.md).

## Why `LIKE '%x%'` falls over

- **No tolerance.** `%hedphones%` does not find "headphones", `%creme%` does not find "Crème", `%drills%` does not
  find "drill".
- **No ranking.** Every match is equal: you get the order of the scan, or of your `ORDER BY`.
- **No phrase, exclusion or OR** unless you write out `AND name NOT ILIKE ...` yourself.
- **A leading wildcard cannot use a B-tree index**, so the search reads the whole table. (A trigram index makes
  the same predicate fast, and fixes only the speed.)

Measured with this repository's benchmark (100 000 products, PostgreSQL 17 in a local container, the median of 5 runs
after the first, 20 results each; reproduce it with `benchmarks/run.php`, see [Benchmarks](benchmarks.md)):

| case | query | `ILIKE ... LIMIT 20` | Fuzzphony |
|---|---|---:|---:|
| a common word | `wireless` | 1.5 ms, 20 rows | 8.6 ms, 20 hits |
| two words | `wireless mouse` | 4.4 ms, 20 rows | 6.4 ms, 20 hits (833 in all) |
| accent | `creme` | 92.5 ms, **0** rows | 9.5 ms, 20 hits |
| typo | `hedphones` | 95.9 ms, **0** rows | 46.2 ms, 20 hits |
| word form | `drills` | 91.8 ms, **0** rows | 7.7 ms, 20 hits |

**`ILIKE ... LIMIT 20` is the fastest option when the word is common**: it stops at the first 20 rows it meets.
Fuzzphony costs a few milliseconds more there, and a few dozen when typo tolerance has to run. What you buy is that the
accent, the typo and the word form are found at all, ranked, and that a miss no longer reads the whole table (a
200 000-row run is in [Benchmarks](benchmarks.md#example-run)). If your users never mistype and every word is common,
you may not need this.

## Step by step

### 1. Describe the index

The query you have, with the typical `LOWER(...) LIKE` (DQL has no `ILIKE`):

<!-- tested: before -->
```php
use App\Entity\Product;

$products = $em->createQueryBuilder()
    ->select('p')->from(Product::class, 'p')
    ->where('p.inStock = true')
    ->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.description) LIKE :q')
    ->setParameter('q', '%' . mb_strtolower($text) . '%')
    ->orderBy('p.popularity', 'DESC')
    ->setMaxResults(20)
    ->getQuery()
    ->getResult();
```

An index definition says which columns are searched (weight `A`, the most important, to `D`), which tolerate typos,
which are filters for the rest of your `WHERE`, and which numbers feed the ranking. Let the wizard propose one from the
table's structure and statistics, then review it (it never touches your files):

```bash
bin/console fuzzphony:wizard product --format=attributes
```

The attribute form, as it ends up on the entity (the wizard made `category` a searchable field; here it is a filter,
because people pick it rather than type it):

<!-- tested: entity -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fuzzphony\Core\Attribute\{Searchable, SearchField, SearchFilter};
use Fuzzphony\Core\Definition\SyncMode;

#[ORM\Entity]
#[Searchable(sync: SyncMode::Trigger, boost: 'popularity', recency: 'published_at')]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column, SearchField('A', fuzzy: true)] public string $name = '';
    #[ORM\Column(type: 'text'), SearchField('C')] public string $description = '';
    #[ORM\Column, SearchFilter] public string $category = '';
    #[ORM\Column, SearchFilter] public int $price = 0;
    #[ORM\Column, SearchFilter] public bool $inStock = true;
    #[ORM\Column] public float $popularity = 0.0;   // 0 to 1: it is multiplied by the profile's boost
    #[ORM\Column] public \DateTimeImmutable $publishedAt;
}
```

- `fuzzy: true` goes on the fields people mistype (names, brands), not on long descriptions.
- `boost` and `recency` name the columns the ranking may use; they do nothing until a profile weights them.
- `SyncMode::Trigger` makes a new row searchable at once, as with `LIKE` ([step 6](#6-keep-the-index-fresh)).
- The language defaults to `english` (stemming, stop words); set it per index, see [Languages](languages.md).
- Attributes cannot hold ranking profiles. Put them in YAML (`config/packages/fuzzphony.yaml`), which also overrides
  the attributes:

<!-- tested: profiles-yaml -->
```yaml
fuzzphony:
  indexes:
    products:
      profiles:
        popular: { boost: 1.0, recency: 0.2 }
```

A joined label (the supplier's name next to the product) cannot be an attribute either: define that index in YAML with
a `SELECT`, and name the tables to follow:

<!-- tested: joined-yaml -->
```yaml
fuzzphony:
  indexes:
    products:
      source:
        query: |
          SELECT p.id, p.name, p.description, s.name AS supplier, p.category, p.price, p.in_stock
          FROM product p LEFT JOIN supplier s ON s.id = p.supplier_id
      fields: { name: { weight: A, fuzzy: true }, supplier: B, description: C }
      filters: { category: string, price: int, in_stock: bool }
      watch:
        product: 'SELECT :id'
        supplier: 'SELECT id FROM product WHERE supplier_id = :id'
      sync: trigger
```

The builder for plain PHP and all YAML keys: [Index definitions](configuration.md). Bundle setup: [README](../README.md#install).

### 2. Build it

```bash
bin/console fuzzphony:schema --apply    # the sidecar table, its indexes, the sync triggers
bin/console fuzzphony:reindex           # fills it from the rows you already have
bin/console fuzzphony:doctor            # checks that both worked
```

Without `--apply` the schema command only prints the SQL. Without Symfony, or in a test, the same two steps:

<!-- tested: build -->
```php
$fuzzphony->schema()->apply($connection);   // bin/console fuzzphony:schema --apply
$fuzzphony->reindex('products');            // bin/console fuzzphony:reindex
```

`pg_trgm` and `unaccent` must exist in the database ([Requirements](../README.md#requirements); the doctor says if not).
Run the schema step again after every change to the definition, and reindex when it changes what is stored
([Building an index](configuration.md#building-an-index)). Your table is not altered: the index is a separate table,
`fuzzphony_products`, and the only thing added to yours is its sync triggers.

### 3. Replace the query

`ILIKE` becomes a search text; the rest of your `WHERE` becomes filters on the ones you declared (`where`, `whereIn`,
`whereBetween`, `whereNull`, combined with AND); `ORDER BY` becomes a ranking profile. A service keeps the controller as
it was:

<!-- tested: search -->
```php
namespace App\Search;

use App\Entity\Product;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Fuzzphony;

final readonly class ProductSearch
{
    public function __construct(private Fuzzphony $fuzzphony, private EntityLoader $loader) {}

    /** @param list<string> $categories */
    public function __invoke(string $text, ?int $maxPrice = null, array $categories = [], int $page = 1): array
    {
        $search = $this->fuzzphony->in(Product::class)
            ->query($text)
            ->where('in_stock', true)
            ->profile('popular')
            ->page($page, perPage: 20);
        if ($maxPrice !== null) {
            $search = $search->where('price', '<=', $maxPrice);
        }
        if ($categories !== []) {
            $search = $search->whereIn('category', $categories);
        }
        $result = $search->get();

        return [
            'products' => array_column($this->loader->load($result, Product::class), 'entity'),
            'total' => $result->total,
            'more' => $result->hasMore(),
        ];
    }
}
```

The builder is immutable, so a conditional query is plain reassignment; `EntityLoader` is autowirable with the bundle.
An unknown filter name makes the search throw, naming the right one (`Did you mean "price"?`). Your `ORDER BY`:

- `ORDER BY popularity DESC` or `created_at DESC` become the `boost` and `recency` columns, weighted by a profile
  ([Ranking](ranking.md)). With a text they are **added to the relevance**: a very popular, weaker match can overtake
  a better one, which is why the column should be small (0 to 1) or the weight small. With an **empty text** (the first
  load of a search page) the profile alone orders the filtered browse: `->query('')->profile('popular')` is "most
  popular first". Without a profile an empty text lists in id order.
- `ORDER BY price`, `name` or any other column has **no equivalent**. Use a filter (`price <= 2000`) instead of a sort,
  or keep that screen on SQL.

### 4. Your entities, in the order of the ranking

A hit is an `id`, a `score`, a score breakdown and highlights, not your row. `EntityLoader` (above) loads the entities
with one query and returns them in rank order. For a query of your own (a fetch join, a partial select) keep the order
yourself, because `WHERE id IN (...)` returns rows in the database's order:

<!-- tested: by-ids -->
```php
use App\Entity\Product;

$ids = $result->ids();                                      // best match first
$byId = [];
foreach ($em->getRepository(Product::class)->findBy(['id' => $ids]) as $product) {
    $byId[$product->id] = $product;                         // the database returns them in its own order
}
$products = [];
foreach ($ids as $id) {                                     // so walk the ids, not the rows
    if (isset($byId[$id])) {                                // a row deleted since the last sync is skipped
        $products[] = $byId[$id];
    }
}
```

A hit whose row is gone (a delete the queue has not processed yet, [step 6](#6-keep-the-index-fresh)) is skipped by
both. PostgreSQL has no `FIELD()`, so there is nothing to translate `ORDER BY FIELD(id, ...)` into.

### 5. Pagination and the total

`page($n, perPage: 20)` (or `limit($limit, $offset)`) replaces `LIMIT`/`OFFSET`. Only the best `candidate_limit` matches
are ranked (2000 by default, at most 10 000), so for a very common word the total is a **lower bound** ("2000+").
`hasMore()` is true while hits follow the page among the candidates and **false at the last page of the candidates**: a pager
that follows it stops at 2000 hits. A page asked for directly past them is still served and exact (the best `offset + limit`
hits, up to 10 000 hits, with a lower-bound total that grows with the page); beyond 10 000 a page is empty and `hasMore()` is false
(also with `exactCounts()`, whose total can be larger).

<!-- tested: pagination -->
```php
$result = $fuzzphony->in('products')->query($text)->page($page, perPage: 20)->get();

$total = $result->totalIsLowerBound ? $result->total . '+' : (string) $result->total;   // "2000+" or "37"
$label = ($result->offset + 1) . '-' . ($result->offset + count($result->hits)) . ' of ' . $total;
$hasNextPage = $result->hasMore();
```

`exactCounts()` counts everything instead, at the price of reading it all, and never from request input
([Facets](searching.md#facets)).

### 6. Keep the index fresh

Triggers on your table (and on the joined tables you `watch`) feed the index. When a change is searchable depends on
the sync mode ([Keeping the index in sync](sync.md)):

| mode | a change is searchable | use when |
|---|---|---|
| `trigger` | in the writing transaction, like `LIKE` | the first step of a migration; the write does the indexing |
| `queue` (the default) | after `bin/console fuzzphony:worker` has run (or `fuzzphony:worker --once` from cron) | write-heavy tables; a deleted row can be a hit until then |
| `orm` | after `flush()`, for changes made through the entity manager only | triggers are not allowed |
| `manual` | when you call `refresh()` or `reindex()` | imports, read-only data |

Raw SQL and migrations are covered by the triggers in `trigger` and `queue` mode; `TRUNCATE` and partitions have
caveats on that page.

### 7. Tests

Fuzzphony is PostgreSQL, so a test that means something runs on a disposable PostgreSQL: the two lines of step 2 in
`setUp()`, fixtures flushed with `SyncMode::Trigger` so they are searchable at once. `LikeGuideTest` is a worked example.

## Your `LIKE` patterns in Fuzzphony

The first column is run as written on `name`, the second is the Fuzzphony text, on the 16 products of the test
fixture, so "finds" means on that data.

<!-- tested-table: mapping -->
| `LIKE` | Fuzzphony text | What differs |
|---|---|---|
| `name ILIKE '%mouse%'` | `mouse` | also finds "USB receiver" (the word is in its description); ranked; `name:mouse` limits it to the name |
| `name ILIKE 'head%'` | `head*` | any word that starts with `head`; names that start with it come first |
| `name ILIKE '%phones'` | `*phones` | **not a suffix search**, see below |
| `name ILIKE '%wireless%' AND name ILIKE '%mouse%'` | `wireless mouse` | both words, any order, any field |
| `name ILIKE 'wire%mouse'` | `wire* mouse` | `%` is not a wildcard, see below |
| `name ILIKE '%noise cancelling%'` | `"noise cancelling"` | a phrase: the words next to each other |
| `name ILIKE 'wireless mouse'` | `"wireless mouse"` | with `fuzzy_mode: never`: titles that contain the phrase, the equal one first; not equality |
| `name ILIKE '%mouse%' OR name ILIKE '%keyboard%'` | `mouse OR keyboard` | either word |
| `name ILIKE '%headphones%' AND name NOT ILIKE '%wireless%'` | `headphones -wireless` | exclusion; also finds "Headphone stand" (stemming) |
| `name ILIKE '%mouse%'` | `name:mouse` | only that field |
| `name LIKE '%MOUSE%'` | `MOUSE` | case never matters (this `LIKE` finds nothing) |
| `name ILIKE '%creme%'` | `creme` | accents are folded: finds "Crème brûlée torch" |
| `name ILIKE '%hedphones%'` | `hedphones` | a typo: the headphones come first |
| `name ILIKE '%drills%'` | `drills` | word forms: "Cordless drill", "Drill bit set" |

- **Contains is not substring.** Fuzzphony matches whole words, stemmed and without accents, in every searchable
  field. A fragment inside a word is not a word: `ILIKE '%eadphon%'` finds headphones, `eadphon` only through typo
  tolerance, and nothing with `fuzzy_mode: never`. `head*` is any word that starts with `head`, not "the name starts
  with" (a name that does ranks first).
- **`%x` (ends with) and `%x%` inside a word are not supported.** A leading `*` is dropped: `*phones` is the word
  `phones` and its stem (it finds "Phone case"). With typo tolerance on, look-alike words make it look as if it worked
  (`phones` also lists the headphones, below the phone case): not a promise. If you need it, keep that query on SQL with
  a trigram index (`CREATE INDEX ... USING gin (name gin_trgm_ops)`).
- **`%` and `_` are not wildcards**, only punctuation: `wire%mouse` finds nothing. Write `a%b` as `wire* mouse` (both
  words, any order) or as a quoted phrase (next to each other). No wildcard escaping of user input is needed.
- **Equality is not a search.** `name = 'Wireless mouse'` stays SQL; the phrase ranks the equal title first.
- **Case and accents never matter.** `-word` can also be written `NOT word`; see [Searching](searching.md#query-syntax).

## What you gain, and what changes

**Ranked, tolerant, and exact when you want it.** `$hit->breakdown` says why a hit is first. By default typo tolerance
runs only when the exact search finds fewer than 5 hits (`fallback_below`), and `$result->usedFuzzy` tells which it was. For exact
matching only, per query or per index (`thresholds:` in YAML):

<!-- tested: exact -->
```php
$result = $fuzzphony->in('products')
    ->query($text)
    ->thresholds(['fuzzy_mode' => 'never'])   // no typo tolerance: only the words that are really there
    ->get();
```

That removes typo tolerance, not stemming or accent folding, which belong to the index's language (`language: simple`
with `unaccent: false` matches letter for letter, for codes). Typo tolerance also lists look-alikes below the real
hits (`hedphones` finds the headphones, then `Earphones` and `Phone case`): trigrams cannot tell a typo from a similar
word ([limitations](limitations.md#typo-tolerance-is-trigram-based)).

**A hit is a document, not a row.** The index holds what you told it to search and filter on, one document per id.
To search a joined value, put it in the index (the YAML with a `SELECT`; a one-to-many relation needs an aggregated
column, because a document is one row), and conditions can only use declared filters. The rest of the row you load by
id, as in step 4.

**The total can be a lower bound**, and the ranking of a word with more matches than `candidate_limit` has limits
([limitations](limitations.md#ranking-of-very-common-words)).

**The index is a sidecar table** in Fuzzphony's schema (`public` by default, [or its own](configuration.md#fuzzphonys-schema)).
It costs disk (a second copy of the searchable text and its indexes: look at your own data) and a little on every
write; the bundle hides it from `doctrine:migrations:diff`. Also: stop words ("the", "for") are ignored, a text is cut at
256 characters and 16 terms (with a warning in `$result->warnings`), and a search text never throws.

## Rolling it out

Nothing below changes what your users see until you flip the flag.

### Build next to the old search

Steps 1 and 2 above, then `bin/console fuzzphony:doctor`.

### Shadow search

Serve the old results, run the new search too, and log the difference. The class below does it both ways (flip
`serveFuzzphony` and the roles swap), and a failing shadow never touches the answer. The ids only the new search found
are what you hoped for (typos, accents, word forms). The ids only the old one found are the ones to read: a suffix, a
fragment of a word, a part number typed in part, anything a `LIKE` pattern did that Fuzzphony does not. Run the shadow
after the response (`kernel.terminate`) or on a sample of requests, so users do not wait for two searches.

<!-- tested: shadow -->
```php
namespace App\Search;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Core\Fuzzphony;
use Psr\Log\LoggerInterface;

final readonly class SearchRollout
{
    public function __construct(
        private Fuzzphony $fuzzphony,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private bool $serveFuzzphony,   // the feature flag: a parameter, an env var, your flag service
        private bool $shadow = true,    // also run the other search, and log what differs
    ) {}

    /** @return list<int> product ids, best first */
    public function ids(string $text): array
    {
        $like = fn(): array => $this->em->createQuery('SELECT p.id FROM App\Entity\Product p WHERE p.inStock = true AND LOWER(p.name) LIKE :q ORDER BY p.popularity DESC')
            ->setParameter('q', '%' . mb_strtolower($text) . '%')->setMaxResults(20)->getSingleColumnResult();
        $fuzzy = fn(): array => $this->fuzzphony->in('products')->query($text)->where('in_stock', true)->profile('popular')->limit(20)->get()->ids();
        [$served, $other] = $this->serveFuzzphony ? [$fuzzy, $like] : [$like, $fuzzy];

        $ids = $served();
        if ($this->shadow) {
            try {
                $others = $other();
                $this->logger->info('search shadow', ['q' => $text, 'only_served' => array_values(array_diff($ids, $others)), 'only_other' => array_values(array_diff($others, $ids))]);
            } catch (\Throwable $e) {
                $this->logger->warning('search shadow failed', ['q' => $text, 'error' => $e->getMessage()]);
            }
        }

        return $ids;
    }
}
```

### Feature flag

`serveFuzzphony` above is the flag: a container parameter, an environment variable, or your flag service by user,
percentage or role. Switch `shadow` off when the logs are quiet.

### Measure

`explain()` returns how the text was understood, the SQL it sent and PostgreSQL's plan:

<!-- tested: explain -->
```php
$explanation = $fuzzphony->in('products')->query($text)->where('in_stock', true)->explain();   // explain(analyze: true) runs it too

echo $explanation->interpretedAs, "\n";             // how the text was understood
foreach ($explanation->statements as $statement) {   // the SQL it sent
    echo $statement['label'], "\n", $statement['sql'], "\n";
}
echo implode("\n", $explanation->plan), "\n";        // PostgreSQL's plan
echo $explanation->result->tookMs, " ms\n";
```

`bin/console fuzzphony:search products 'hedphones' --explain --analyze` prints the same, with the score breakdown. In
production, watch `fuzzphony.search.took_ms` and `fuzzphony.search.fallback` ([Observability](observability.md)); with the
[search log](analytics.md) on, `bin/console fuzzphony:analytics products --zero` lists the searches that found nothing,
the list to read in the first weeks.

### Roll back

Flip the flag: the old query is still there, and your table was never touched. To remove Fuzzphony's objects too:

```bash
bin/console fuzzphony:schema --drop --apply     # asks first; --force skips the question
```

It drops the sidecar table, its triggers, functions and queued rows; your tables and your `LIKE` query are unaffected
(`LikeGuideTest` checks it). With a dedicated schema see [Fuzzphony's schema](configuration.md#fuzzphonys-schema).

## Known limits

Read [Known limitations](limitations.md) before you commit: typo tolerance is trigram-based, the ranking of very common words has
limits, and the search log is best effort. Also: no suffix or infix search, no sort by an arbitrary column,
one language per index, and PostgreSQL 15+ is the only engine.

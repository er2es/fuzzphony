# Recipe: a typo-tolerant search in an admin panel

You have an API Platform resource behind an admin panel (a list of customers, orders, tickets) and the staff
type names badly: `smiht` has to find `Smith`. You add one filter to the collection endpoint. The rest of the
endpoint (your other filters, the order filter, pagination, serialization) stays as it is. Back to the
[documentation](../README.md).

The code below is real: it lives in [`tests/Recipes/AdminPanel`](../../tests/Recipes/AdminPanel), and the tests
there run it against PostgreSQL. What they do not do is boot API Platform (the package is not installed in this
repository): they apply the filters to the query the way API Platform's collection provider does, root alias `o`,
filters in the order the attributes declare them, then the page.

## Setup

```
composer require fuzzphony/fuzzphony api-platform/doctrine-orm
```

You need PostgreSQL 15 or later with `pg_trgm` and `unaccent` (`CREATE EXTENSION`, see the
[README](../../README.md)). The bundle needs no configuration for this: it finds every Doctrine entity with
`#[Searchable]` (see [Index definitions](../configuration.md#attributes)) and uses the `default` DBAL connection.

The whole definition is the entity. The imports are left out; `Searchable`, `SearchField` and `SearchFilter` are
in `Fuzzphony\Core\Attribute`:

<!-- tested: tests/Recipes/AdminPanel/Customer.php#entity -->
```php
#[ORM\Entity]
#[ORM\Table(name: 'recipe_customer')]
#[ApiResource]
#[ApiFilter(FuzzphonySearchFilter::class)]                                   // ?q=smiht
#[ApiFilter(BooleanFilter::class, properties: ['active'])]                   // ?active=true
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]          // ?order[name]=asc
#[Searchable(language: 'english', sync: SyncMode::Orm)]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        #[SearchField('A', fuzzy: true)]
        public string $name,
        #[ORM\Column]
        #[SearchField('B', fuzzy: true)]
        public string $company,
        #[ORM\Column]
        #[SearchField('D')]
        public string $city,
        #[ORM\Column]
        #[SearchFilter]
        public bool $active = true,
        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}
}
```

The index is named `customers` (the plural of the class). `name` and `company` are typo-tolerant (`fuzzy: true`);
typo tolerance only reaches words in fuzzy fields, so a misspelt city is not found. `active` is a filter of the
index too, which you only need if you also search with `->where('active', ...)` in your own code.

## The first reindex

Creating the index and filling it from the rows that are already there are two commands. The second one is needed
once, whatever the sync mode:

```
bin/console fuzzphony:schema --apply
bin/console fuzzphony:reindex customers
```

<!-- tested: tests/Recipes/AdminPanel/expected/reindex.txt -->
```
Reindexing "customers"
  4 documents in 0.1s
  11 words in the vocabulary ("did you mean")
 [OK] Done. Tip: run fuzzphony:doctor to verify coverage.
```

(The times differ; the table had four customers. The command prints more lines than these.) It builds next to
the live index and swaps it in, so it is safe on a production panel. Then `bin/console fuzzphony:doctor` should
report no errors, and `bin/console fuzzphony:search customers smiht` prints the hits with their score
breakdown, which is the quickest way to see why a customer is where it is.

## What you get

`GET /api/customers?q=...` restricts the collection to the best matches and puts them in relevance order. These
lines are the test's assertions, with the request each stands for (the names are what the collection returns):

<!-- tested: tests/Recipes/AdminPanel/AdminPanelTest.php#requests -->
```php
// GET /api/customers?q=smiht            a typo still finds both Smiths (equally relevant: the tie goes to the lower id)
self::assertEqualsCanonicalizing(['Jonathan Smith', 'Jon Smith'], $this->collection(['q' => 'smiht']));
// GET /api/customers?q=smith            best match first: Jon Smith has the word in his name and in his company
self::assertSame(['Jon Smith', 'Jonathan Smith'], $this->collection(['q' => 'smith']));
// GET /api/customers?q=company:globex   the query syntax of the search box works too (field:value, "phrase", -word, a OR b, pre*)
self::assertSame(['Joan Smythe'], $this->collection(['q' => 'company:globex']));
// GET /api/customers?q=smith&active=true     your own filter narrows what the search found
self::assertSame(['Jonathan Smith'], $this->collection(['q' => 'smith', 'active' => 'true']));
// GET /api/customers?q=smith&order[createdAt]=asc   the relevance order wins over the column sort
self::assertSame(['Jon Smith', 'Jonathan Smith'], $this->collection(['q' => 'smith', 'order' => ['createdAt' => 'asc']]));
// GET /api/customers?order[createdAt]=desc          no search text: the collection is yours again
self::assertSame(['Joan Smythe', 'Maria Garcia', 'Jon Smith', 'Jonathan Smith'], $this->collection(['order' => ['createdAt' => 'desc']]));
```

How the filter fits with the rest of the endpoint:

- **Your filters combine with it by `AND`.** It adds `id IN (the best matches)`, so `BooleanFilter`, `DateFilter`
  and your own filters narrow what the search found. Pagination works on the restricted collection, and
  `hydra:totalItems` counts the hits.
- **No `q`, or a blank one, and the filter does nothing.** A search without hits gives an empty collection.
- **Order: the relevance order wins.** The filter sets `ORDER BY` to the ranking, and gives every hit its own
  position. If it runs before the order filter (the attribute order above), your `?order[createdAt]=asc` is added
  after that and never decides anything. If it runs after, it replaces your order outright. Either way a staff
  member who clicks a column header while a search is active sees the relevance order, not the column's. If the
  column should win, restrict only (next section).
- **The cap comes first.** The filter asks Fuzzphony for the best `maxResults` matches (500 by default, at most
  1000), and your other filters run on those. With more matches than that, `?q=smith&active=true` can miss an
  active customer who is not among the 500 best, and `hydra:totalItems` stops at 500. The tests show it with a cap
  of 1. Raise the cap for a big table, or move the condition into a filter of your own that calls `->where()` on
  the search.
- **The arguments:** `#[ApiFilter(FuzzphonySearchFilter::class, arguments: ['parameterName' => 'search',
  'profile' => 'popular', 'maxResults' => 200])]` renames the parameter, ranks with a
  [profile](../ranking.md#profiles) and lowers the cap.
- **It does only that.** No highlights, no facets, no "did you mean": for those call `Fuzzphony` from your own
  controller ([Searching](../searching.md)).

### When the column sort must win

A filter of your own that restricts to the search's ids and sets no order is a dozen lines:

<!-- tested: tests/Recipes/AdminPanel/SearchRestrictionFilter.php#filter -->
```php
/**
 * Restricts the collection to what the search finds and leaves the order to the other filters
 * (?order[name]=asc), which FuzzphonySearchFilter does not: it orders by relevance.
 */
final class SearchRestrictionFilter implements FilterInterface
{
    public function __construct(private readonly Fuzzphony $fuzzphony) {}

    /** @param array<string, mixed> $context */
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $filters = $context['filters'] ?? null;
        $text = is_array($filters) ? ($filters['q'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            return;
        }

        $ids = $this->fuzzphony->in($resourceClass)->query($text)->limit(500)->get()->ids();
        $parameter = $queryNameGenerator->generateParameterName('search_ids');
        $queryBuilder
            ->andWhere(sprintf('%s.id IN (:%s)', $queryBuilder->getRootAliases()[0], $parameter))
            ->setParameter($parameter, $ids === [] ? [0] : $ids);
    }

    /** @return array<string, array{type?: string, required?: bool}> */
    public function getDescription(string $resourceClass): array
    {
        return ['q' => ['type' => 'string', 'required' => false]];
    }
}
```

Use it in place of `FuzzphonySearchFilter` (an `#[ApiFilter]` on the entity, or a service tagged
`api_platform.filter`). The test sorts the same search by `createdAt` and gets the column's order. This one passes
no actor, so on a tenant-scoped index use `FuzzphonySearchFilter` (see the [multi-tenant recipe](multi-tenant-saas.md))
or add `->asActor()` to the call.

## Keeping the index fresh

`sync: orm` (what the entity says) fits an admin panel whose edits go through Doctrine: after `flush()` returns,
the new or changed customer is searchable, with no trigger on your table and no worker to run. The tests
persist, rename and remove a customer and search after each flush.

Know where it stops: **a write that does not go through the entity manager is not seen**. Raw SQL, an import
script, another service, and a DQL bulk `UPDATE` (Doctrine fires no entity events for it) leave the index as it
was until the next `bin/console fuzzphony:reindex customers`. If that happens in your system, switch the entity to
`sync: SyncMode::Trigger`: PostgreSQL triggers refresh the document inside the writing transaction, whoever
writes, and the test inserts with raw SQL and finds the row at once. The price is a trigger on your table (see
[Keeping the index in sync](../sync.md)). `queue` (the library's default) needs `fuzzphony:worker` running and shows
a change only after the worker has processed it, which an editor who saves and reloads the list will notice.

## Pitfalls

- **A resource without `#[Searchable]` is not searched, silently.** The filter does nothing for a class the
  registry does not know, so `?q=` returns the whole collection. If a list ignores the search box, check
  `bin/console fuzzphony:doctor` lists the index.
- **A fresh index is empty until the first `fuzzphony:reindex`**, and again after a definition change that needs one
  (the doctor says so).
- **Doctrine's identity map.** After a reindex in the same process, entities the entity manager already loaded keep
  their old values (`$em->clear()`); a request starts clean.
- **Sorting by column and relevance** are two different jobs: see above.
- **Typo tolerance has limits**, for example a letter replaced in the middle of a five to seven letter word may not
  be tolerated (`smyth` does not find `Smith`): see [Typo tolerance](../searching.md#typo-tolerance) and the
  [known limitations](../limitations.md).

## Reference

[Index definitions](../configuration.md), [Searching](../searching.md), [Keeping the index in sync](../sync.md),
[Integrations](../integrations.md#api-platform), [Console commands](../commands.md),
[Multi-tenancy](../multi-tenancy.md) (a panel for several customers' staff).

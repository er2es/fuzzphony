# Index definitions

How to describe an index, set up the Symfony bundle, and build the index. Back to the [README](../README.md).

## Concepts

An index definition has:

- a source: a table, or any `SELECT` with joins;
- searchable fields, each with a weight A–D, an optional `fuzzy` flag (typo tolerance) and an optional `code` flag (invoice numbers and SKUs, see [Codes and numbers](searching.md#codes-and-numbers));
- typed filters;
- optional boost and recency columns;
- ranking profiles and thresholds (see [Ranking and thresholds](ranking.md)).
- optional [synonyms](#synonyms), expanded on the query (they are not part of the index table).

You can write a definition three ways: attributes on an entity (the main way), YAML (overrides, or
indexes defined only in YAML), or the fluent builder.

Fuzzphony stores each index in a sidecar table, `fuzzphony_<index>` in Fuzzphony's schema (see
[Fuzzphony's schema](#fuzzphonys-schema)). It holds a weighted
`tsvector`, a normalised text for trigram matching, the same two per field (for field-scoped words:
one `tsvector` per field, one text per fuzzy field), a flag that says whether the joined vector ran past the
position limit of a `tsvector` (`tsv_capped`, see [limitations](limitations.md#very-long-texts-limit-where-a-phrase-is-found)),
typed filter columns and the ranking inputs,
each with the right index (GIN, GIN trigram, btree). Your schema is untouched. Dropping the index
is one command.

## Joins, several tables and several indexes

### Entity attributes or YAML?

| Your situation | Use |
|---|---|
| Doctrine entities, one index per entity, over (mostly) that entity's table | attributes (`#[Searchable]` on the class) |
| A legacy database without entities (plain tables, no ORM) | YAML (Symfony) or the builder (plain PHP) |
| A source with joins, or over several tables | YAML or the builder (`source.query`, `watch`) |
| Several indexes over the same table or entity (attributes are one per class) | the first by attributes if you like, the others as [definition classes](#definition-classes) (code, with the builder) or in YAML under another name |
| Fields, weights or filters that depend on code, not on a table (an admin index next to the public one) | a [definition class](#definition-classes) (`#[AsIndexDefinition]`) |
| An attribute index whose `sync`, `language`, `boost`, `watch`, ... you want to change per environment | a YAML entry with the same name overrides those keys (see [YAML](#yaml)) |

**A join goes in the source query.** Attributes only name a table, so for a join define the index in YAML (`source.query`)
or with the builder (`fromQuery()`); the first example under [YAML](#yaml) and the one under
[Builder](#builder-plain-php) are joined indexes. The query returns one row per document, with the id in a column
(`id` by default; `source.id`, or the second argument of `fromQuery()`, names another). A one-to-many join (a customer's
addresses) must be folded into the row (`string_agg(...)` and `GROUP BY`), or the id repeats.

**List every joined table in `watch`**, or a change in it is not followed. A watch answers "when a row of this table
changes, which document ids are affected": `SELECT :id` for the table the documents are keyed on, a query for a joined table
(`:id` is the changed row's key, see [Watched tables](sync.md#watched-tables)). When the joined table has a column that
points at the document, name it with `key` and keep `SELECT :id`; this follows a deleted row too:

```yaml
fuzzphony:
  indexes:
    people:
      source:
        query: |
          SELECT cu.id, cu.first_name || ' ' || cu.last_name AS name, co.name AS company,
                 string_agg(DISTINCT ad.city, ' ') AS cities, string_agg(DISTINCT ct.email, ' ') AS emails
          FROM customer cu
          JOIN company co ON co.id = cu.company_id
          LEFT JOIN address ad ON ad.customer_id = cu.id
          LEFT JOIN contact ct ON ct.customer_id = cu.id
          GROUP BY cu.id, cu.first_name, cu.last_name, co.name
      fields:
        name: { weight: A, fuzzy: true }
        company: B
        cities: C
        emails: C
      watch:
        customer: 'SELECT :id'
        company: 'SELECT id FROM customer WHERE company_id = :id'
        address: { ids: 'SELECT :id', key: customer_id }
        contact: { ids: 'SELECT :id', key: customer_id }
      sync: trigger
```

With these four tables (checked with `sync: queue`), one change in each table queued exactly these `people` documents: a
customer change its own document, a company change the documents of its customers, an address or a contact change (insert, update
or delete) its customer, and nothing else. A watch without `columns` refreshes on every `UPDATE` of its table, also of a
column the index does not use (see [Column-aware filtering](sync.md#column-aware-filtering)).

### Definition classes

A class with `#[AsIndexDefinition]` and a `build(IndexBuilder $builder): void` method defines one index with the builder, so one entity
(or a legacy table without an entity) can have several. The builder arrives with the index name and the `entity` already set; the result
is an ordinary index (same validation, fingerprint, doctor, schema, sync). The public `products` index stays on the entity, an admin
index over the same `Product` gets other fields and weights:

```php
use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;

#[AsIndexDefinition('products_admin', entity: Product::class)]
final class ProductAdminIndex
{
    public function build(IndexBuilder $b): void
    {
        $b->fromTable('product')->field('name', 'A', fuzzy: true)->field('sku', 'A', code: true)->filter('price', 'int');
    }
}
```

A legacy table has no entity: `#[AsIndexDefinition('orders')]` and `$b->fromTable('legacy_order', 'order_id')->field('customer', 'A')`.

- **Symfony:** register the class as a service (`autoconfigure` is on in the default `config/services.yaml` `App\` resource, so
  nothing else is needed); the tag `fuzzphony.index_definition` is added by the attribute. The class is built at discovery with a fresh
  `IndexBuilder`.
- **Plain PHP:** `Fuzzphony::postgres($connection, [$definition, ProductAdminIndex::class])`: a class name is instantiated without arguments.
- **Entity link:** `$fuzzphony->in('products_admin')` searches that index; `EntityLoader::load($result, Product::class)` loads its hits (the
  class of an index is `$fuzzphony->definition('products_admin')->entityClass`). `$fuzzphony->in(Product::class)` keeps answering with the
  first index of the entity (the attribute one). For API Platform name the index: `#[ApiFilter(FuzzphonySearchFilter::class, arguments: ['index' => 'products_admin'])]`;
  the Live Component takes `index="products_admin"`.
- **Conflicts:** the same index name from an attribute and a definition class, or from two definition classes, is an `InvalidDefinition` that
  names both sources. A YAML entry with the same name overrides the limited keys exactly as for an attribute index (see [YAML](#yaml)).
- **Where it came from:** `fuzzphony:doctor` shows the source in the heading of each index (`attribute App\Entity\Product`,
  `definition class App\Search\ProductAdminIndex`, plus ` + YAML` for an override).
- **Cache rule:** in production the definitions are kept in `cache.system` like the attribute ones (dropped by `cache:clear`, never used by
  the CLI, see [Attributes](#attributes)), so `build()` must be **pure and deterministic**: the same definition on every call, no
  state, no clock or random values, nothing read from the request, no closure stored in the definition. Read configuration that
  changes the index from YAML, not from `build()`.

### Several indexes

Give `indexes:` several entries (with the builder: build several definitions and give them all to `Fuzzphony::postgres()`).
Each index has its own sidecar table, triggers and queue rows, so indexes can share tables:

```yaml
    companies:
      source:
        query: |
          SELECT co.id, co.name, string_agg(cu.last_name, ' ') AS staff
          FROM company co LEFT JOIN customer cu ON cu.company_id = co.id
          GROUP BY co.id, co.name
      fields: { name: { weight: A, fuzzy: true }, staff: C }
      watch:
        company: 'SELECT :id'
        customer: { ids: 'SELECT :id', key: company_id }
    invoices:
      source:
        query: |
          SELECT i.id, i.number, cu.last_name AS customer, i.total
          FROM invoice i JOIN customer cu ON cu.id = i.customer_id
      fields: { number: A, customer: B }
      filters: { total: int }
      watch:
        invoice: 'SELECT :id'
        customer: 'SELECT id FROM invoice WHERE customer_id = :id'
```

- `customer` is now watched by `people`, `companies` and `invoices`. With `queue` sync (the default) `fuzzphony:schema --apply` puts
  ONE set of four triggers on the table (`fuzzphony_watch__customer_03195256__ins|upd|del|trn`) and the three indexes read the one change log
  through their own positions, so one `UPDATE` of a customer writes one log row per changed customer and each index turns it into its
  queue rows with its own watch query when its worker runs ([Sync](sync.md#one-trigger-per-table-one-change-log)). Applying it twice
  changes nothing. With `sync: trigger` (or `trigger_level: row`) each index keeps four triggers of its own on the table
  (`fuzzphony_sync_<index>__<table>_ins|upd|del|trn`) and one more refresh per changed document.
- Dropping one index (`fuzzphony:schema people --drop --apply`) removes its part only; the other indexes keep the triggers and the log, which
  go with the last index that watches the table.
- **One `#[Searchable]` per class.** The attribute is not repeatable (a second one is a PHP error). To index the same table
  or entity twice (a public and an admin index, with other fields, language or `sync`), define the second one under
  another name in YAML or the builder, with `source.table`; it needs no class:

```yaml
    people_admin:
      source: { table: customer }
      fields: { first_name: { weight: A, fuzzy: true }, last_name: A, note: D }
      watch: { customer: 'SELECT :id' }
      language: simple
```

### A complete example: two indexes over overlapping tables

`items` (item + category + location) and `people` (person + organisation + the organisation's location) are two
independent indexes, each with its own joins and a `watch` for every joined table. Both watch `location`. I ran this
file with `sync: trigger` on PostgreSQL 17: `schema --apply`, `reindex` (3 items, 2 people), then the searches below.

```yaml
fuzzphony:
  indexes:
    items:
      source:
        query: |
          SELECT i.id, i.name, c.name AS category, l.name AS location
          FROM item i
          JOIN category c ON c.id = i.category_id
          JOIN location l ON l.id = i.location_id
      fields:
        name: { weight: A, fuzzy: true }
        category: B
        location: C
      watch:
        item: 'SELECT :id'
        category: 'SELECT id FROM item WHERE category_id = :id'
        location: 'SELECT id FROM item WHERE location_id = :id'
      sync: trigger
    people:
      source:
        query: |
          SELECT p.id, p.name, o.name AS organisation, l.name AS location
          FROM person p
          JOIN organisation o ON o.id = p.organisation_id
          JOIN location l ON l.id = o.location_id
      fields:
        name: { weight: A, fuzzy: true }
        organisation: B
        location: C
      watch:
        person: 'SELECT :id'
        organisation: 'SELECT id FROM person WHERE organisation_id = :id'
        location: 'SELECT p.id FROM person p JOIN organisation o ON o.id = p.organisation_id WHERE o.location_id = :id'
      sync: trigger
```

Search each index on its own, or both together:

```php
$items = $fuzzphony->in('items')->query('drill')->get();      // ids [1]
$people = $fuzzphony->in('people')->query('kovacs')->get();   // ids [1]

$both = $fuzzphony->federated()->index('items')->index('people')->query('debrecen')->get();
foreach ($both as $hit) {
    echo $hit->index, ' ', $hit->id, "\n";                    // items 2, people 1, people 2
}
```

What the run showed. `UPDATE location SET name = 'Warehouse Szeged' WHERE id = 1` changed both indexes in the same
statement: `budapest` found nothing in either afterwards, `szeged` found the items 1 and 3 and the person 1. Moving
organisation 1 to location 2 reindexed only `people` (the `organisation` watch), and renaming a category only `items`. The
`location` table carries 8 triggers, four for each index.

### Searching several indexes together

One search box over several kinds of things is a [federated search](searching.md#federated-search):

```php
$result = $fuzzphony->federated()
    ->index('people')->index('companies', weight: 1.5)->index('invoices')
    ->query('acme')
    ->limit(20)
    ->get();

foreach ($result as $hit) {
    echo $hit->index, ' ', $hit->id, ' ', $hit->mergedScore; // the index, its own id, the merged score
}
```

A hit is the index's own `Hit` (`->id`, its own `->score` and highlights) with `->index`, the index it comes from, `->kind`, the source inside that index (the index name for a single-source index), and `->mergedScore`. The scores
of different indexes are not comparable ([limitations](limitations.md#scores-are-not-stable)), so the lists are merged by
rank: the best hit of each index first. The ids are those of each index (`people` 1 and `companies` 1 are different rows):
use `$hit->index` (and `$hit->kind`) to tell them apart and load each from its own table, or let the [`EntityLoader`](integrations.md#loading-mixed-results) do it.

### Several sources in one index

One list with one ranking over unrelated tables is **one index with several sources**: the index declares its fields, filters and
settings once, and every source is a named `SELECT` that aliases its own columns to those fields. A hit says which source it comes
from (`$hit->kind`; `$hit->index` is the index) and carries that source's own id (an `int`, a uuid string, a composite text), so nothing is split by hand.

```yaml
fuzzphony:
  indexes:
    site:
      sources:
        product:                                   # the name is the kind of the hits (a lowercase identifier)
          query: "SELECT id, name AS title, description AS body, account_id AS account FROM product"
          watch: { product: "SELECT :id" }
          entity: App\Entity\Product               # optional: the class this source's rows are
        article:
          query: "SELECT id, headline AS title, text AS body, owner_id AS account FROM article"
          id_type: uuid                            # default: int
          watch: { article: "SELECT :id" }
        legacy:
          query: "SELECT region || '-' || num AS id, label AS title, notes AS body, account FROM legacy_item"
          id_type: string                          # a composite id built in SQL
          watch:
            legacy_item: { ids: "SELECT region || '-' || num FROM legacy_item WHERE pk = :id", key: pk }
      fields: { title: { weight: A, fuzzy: true }, body: C }
      filters: { account: int }
      tenant: account
```

The same index with the builder, or in a [definition class](#definition-classes) (`build(IndexBuilder $b)`):

```php
IndexDefinition::builder('site')
    ->source('product', 'SELECT id, name AS title, description AS body, account_id AS account FROM product', entity: Product::class, watch: [new Watch('product')])
    ->source('article', 'SELECT id, headline AS title, text AS body, owner_id AS account FROM article', watch: [new Watch('article')], idType: 'uuid')
    ->sourceFromTable('note', 'note')                // a table whose columns already carry the field names; it is watched by itself
    ->field('title', 'A', fuzzy: true)
    ->field('body', 'C')
    ->filter('account', 'int')
    ->tenant('account')
    ->build();
```

What a source is:

- `query` / `query_file` / `table`: one of them. The `SELECT` must return `id` (or the column named by `id:`) and **every** field, filter,
  boost and recency column of the index under the index's names (alias your own columns: `name AS title`). A missing column is an
  `InvalidDefinition` naming the source and the column, found by a zero-row probe of each source when the schema is planned.
- `watch`: the tables that change this source's documents, exactly as for a single source (`table => ids query`, `key`, `columns`).
  A query source needs its own watches with `sync: queue` or `trigger`; the self-watch of a `table` source is automatic. Two sources
  may watch the same table: each maps the changed key to its own documents, and a change refreshes both.
- `entity`: the class of this source's rows. `sync: orm` maps an entity to its source (the key `product:5` is refreshed, not `5`), and the
  registry finds the index by the class.
- `id_type`: `int` (default), `uuid` or `string`; `Hit::$id` has that type. Ids need only be unique inside their source.

`sources` replaces `source`, `id_type`, `watch` and `class` of the index: use either the single form or `sources`, not both. The index's
other settings (`fields`, `filters`, `language`, `boost`, `recency`, `profiles`, `thresholds`, `tenant`, `sync`, ...) are declared once and
apply to every source. Results, filters, facets, highlights, did-you-mean, restricted viewers (`granted` fields) and tenants behave as for a
single source; a tenant filter column must come from every source.

How it is stored: [Multi-source indexes: how it is stored](architecture.md#multi-source-indexes-how-it-is-stored). To refresh one document
by hand, give its key: `$fuzzphony->refresh('site', ['product:5'])`. To rebuild one source only, `fuzzphony:reindex site --source=article`
([commands](commands.md#commands)). Limits: [limitations](limitations.md#several-sources-in-one-index).

This replaces the earlier recipe of one index over a hand-written `UNION ALL` with prefixed string ids and a prefixed watch per table:
sources give the typed id, the kind, a per-source refresh, a per-source rebuild and per-source doctor checks, and need no id splitting.

### Legacy tables with a composite key

A document has one id column. Build it in the query and make the watch return the same text. A watch `key` is one real
column, so key on a column of the primary key and return every document of that group:

```yaml
    order_lines:
      id_type: string
      source:
        query: "SELECT order_no || '/' || line_no AS id, description FROM order_line"
      fields: { description: A }
      watch:
        order_line:
          ids: "SELECT order_no || '/' || line_no FROM order_line WHERE order_no = :id"
          key: order_no
```

Changing line `SO-1/2` refreshed `SO-1/1` and `SO-1/2` (the whole order), and an insert added its document. An expression as
the key (`key: "(order_no || '/' || line_no)"`) is refused with "is not a valid column name".

### A database where Fuzzphony may not create triggers

Tried with a role that has `SELECT` on the source tables, `CREATE` on the database and a schema of its own
(`Fuzzphony::postgres($connection, $indexes, schema: 'fz')`, see [Fuzzphony's schema](#fuzzphonys-schema)). With `sync: manual`,
`schema --apply`, `reindex`, search, `refresh()` and the doctor worked, and nothing was installed on the source tables.
With `sync: queue` the apply stopped with `permission denied for table …` and created nothing: triggers need ownership of
(or `TRIGGER` on) the source tables. Without that right, run `fuzzphony:reindex` from cron or after an import, and call
`refresh()` for single documents. (`sync: orm` was not tried.)

### Which should I use

| You want | Use |
|---|---|
| Labels of a related table searchable with the row (the brand name with the product) | one index, `source.query` with a join, a `watch` for each joined table |
| Several kinds of things found in one search box, each shown as its own kind | one index per kind, searched with `federated()` |
| One list of mixed rows with one ranking, each row its own kind and id type | one index with [several sources](#several-sources-in-one-index) |
| The same table with two configurations | two indexes with different names, in YAML or the builder |
| A table with a composite key | a string id built in the query, a watch keyed on one column |

Several sources or several indexes searched together?

| | One index, several sources | Several indexes, `federated()` |
|---|---|---|
| Ranking | one text ranking over all rows (scores comparable) | merged by rank (scores of different indexes are not comparable) |
| Fields and settings | declared once, shared | each index its own (weights, language, thresholds, profiles) |
| Filters, facets, tenant | one set, every source returns it | per index |
| Best when | the kinds are the same kind of text (products, articles, pages in one box) | the kinds differ (people, companies, orders), or an index also stands alone |


## Attributes

```php
use Fuzzphony\Core\Attribute\{Searchable, SearchField, SearchFilter};

#[ORM\Entity]
#[Searchable(language: 'english', boost: 'popularity', recency: 'published_at')]
class Product
{
    #[ORM\Id, ORM\Column] public int $id;

    #[ORM\Column, SearchField('A', fuzzy: true)]  public string $name;
    #[ORM\Column, SearchField('C')]               public string $description;
    #[ORM\Column, SearchFilter]                   public int $price;       // type inferred
    #[ORM\Column, SearchFilter]                   public bool $inStock;
    #[ORM\Column]                                 public float $popularity;
    #[ORM\Column]                                 public \DateTimeImmutable $publishedAt;
}
```

`#[Searchable]` also takes `name` (default: the snake_cased plural of the class, `Product` →
`products`), `table`, `unaccent`, `sync`, `triggerLevel`, `tenant` and `log` (`false` keeps the index out of the
[search log](analytics.md#keeping-a-search-or-an-index-out-of-the-log)).

An index name matches `[a-z_][a-z0-9_]*`, at most 48 characters, and must not contain `__` (two
underscores): that is reserved for the objects of an index: its secondary indexes (`fuzzphony_<index>__tsv`, `__fz`, `__f_<filter>`,
`__pkey`, `__open_...`) and the objects of its rebuild (`fuzzphony_<index>__next`, `fuzzphony_<index>__changes`), so that an index called
`products_open` never collides with an object of `products`.

Filter and field names are always snake_case, even when the attribute is on a camelCase property:
`bool $inStock` becomes the filter `in_stock`. Names follow the property name through
`Identifier::snake()`.

The Symfony bundle registers every Doctrine entity with `#[Searchable]` (turn this off with
`discover_entities: false`). Finding them means loading the metadata of every mapped entity, so with
`kernel.debug` off (production) the discovered definitions are kept in the `cache.system` pool: a
change to an attribute (or to a `synonyms_file` an attribute names) shows up after `cache:clear`,
the same as a change to Doctrine's own metadata. Only web requests use the cache: a console command, a
worker or a Messenger consumer (the CLI) always discovers the entities fresh and neither reads nor writes
it, so `fuzzphony:schema --apply` and `fuzzphony:reindex` see current attributes even before `cache:clear`.
In debug mode nothing is cached. YAML indexes are never cached.

## YAML

YAML defines an index on its own, or overrides an attribute-defined one (YAML wins). An index over
a query with joins, as the demo defines it:

```yaml
fuzzphony:
  indexes:
    catalog:
      source:
        query: |
          SELECT p.id, p.name, p.description, b.name AS brand, p.price, p.in_stock,
                 p.popularity, p.published_at
          FROM bench_product p
          JOIN bench_brand b ON b.id = p.brand_id
      fields:
        name: { weight: A, fuzzy: true }
        brand: { weight: B, fuzzy: true }
        description: D
      filters:
        price: int
        in_stock: bool
      watch:
        bench_product: 'SELECT :id'
        bench_brand: 'SELECT id FROM bench_product WHERE brand_id = :id'
      language: english
      boost: popularity
      recency: published_at
      profiles:
        popular: { boost: 0.03, recency: 0.3, recency_half_life_days: 60 }
      thresholds:
        relevance: { min_score: 0.01 }
```

Other keys: `sync`, `trigger_level`, `unaccent`, `tenant`, `synonyms`, `id_type`, and `source: { table: … }`
for a table source.

The configuration is checked as a whole before anything is built, and every problem is listed at once: an unknown key
at any level (a field, filter, source or watch option, with a "did you mean": `wieght` for `weight`), a list where a map
belongs (`fields: [name, body]`), a string where a boolean belongs (`fuzzy: "false"` is refused, not read as true; use
`true` or `false`), a `class` that does not exist. An override of an attribute-defined index may set `sync`,
`trigger_level`, `language`, `unaccent`, `boost`, `recency`, `tenant`, `synonyms`, `log`, `profiles`, `thresholds` and
`watch`; `fields`, `filters`, `source`, `id_type` and `class` describe the index itself and are refused with a message
that names the index (change the attributes).

`thresholds` takes the four groups of [Ranking and thresholds](ranking.md#thresholds): `relevance`, `typo`, `rescue` and `limits`, each a
map of options (an unknown group or option is refused with a "did you mean", one error per group). Two options of `rescue` are switches for what a
search that found little or nothing does by itself, both on by default: `rescue.did_you_mean` (a suggestion next to the result,
[Did you mean](searching.md#did-you-mean)) and `rescue.correct_when_empty` (a search that found nothing and has a word with exactly
one one-edit correction in the vocabulary is run again with it and says so,
[Zero-result rescue](searching.md#zero-result-rescue)). `rescue.correct_when_empty: false` keeps the suggestion and drops the second
search; `rescue.did_you_mean: false` turns both off (the rescue uses the suggestion's vocabulary lookup, so `correct_when_empty` must be `false` too):

```yaml
      thresholds:
        rescue:
          correct_when_empty: false     # suggest a correction, but never search it for the visitor
```

### The source query from a file

A long query can live in an SQL file: `source: { query_file: sql/catalog.sql }` (YAML) or `->fromQueryFile('sql/catalog.sql', $baseDir)` (builder). The path is relative to the project
root (`kernel.project_dir` in the bundle) or to `$baseDir` (builder; default the current working directory) and must stay inside it: a `..` that leaves it, an absolute path elsewhere (symbolic
links are resolved), a missing, unreadable or empty file is refused with the path in the message. `query` and `query_file` (or `table`) together are refused. The file is read when the
definition loads, so its content is the source query and part of the definition fingerprint: after an edit the doctor reports "definition changed", run `fuzzphony:schema --apply` and
`fuzzphony:reindex`. The same rules as for `query` apply (a trusted file, no `$fuzzphony$`).

## Synonyms

Words that mean the same thing, expanded on the query ([how searches use them](searching.md#synonyms)).
Two entry forms: a group (a list of words) and a one-way rule (`source => target | other target`).
A member may be a phrase. The same list works in YAML, the builder and the attribute:

```yaml
fuzzphony:
  indexes:
    products:
      synonyms:
        - [tv, television]
        - [ssd, solid state drive]
        - 'laptop => notebook | portable'
```

<!-- fragment -->
```php
IndexDefinition::builder('products')->synonyms([['tv', 'television'], 'laptop => notebook'])   // builder
#[Searchable(synonyms: [['tv', 'television'], 'laptop => notebook'])]                         // attribute
```

### Many synonyms: a file, or your own storage

For a long list use a file in the Solr/Elasticsearch format, one entry per line (a comma list is a
group, `=>` a one-way rule, `#` a comment, blank lines are skipped; several words before the arrow make
one rule each):

```
# config/synonyms/products.txt
tv, television, telly
laptop => notebook, portable
```

```yaml
products:
  synonyms:
    file: '%kernel.project_dir%/config/synonyms/products.txt'
    entries: [[ssd, solid state drive]]      # optional, added to the file's
```

<!-- fragment -->
```php
IndexDefinition::builder('products')->synonymsFile($path);       // builder
#[Searchable(synonymsFile: __DIR__ . '/products.txt')]             // attribute (synonyms: [...] is added to it)
```

The file is read when the definition is loaded (in Symfony the container is rebuilt when it changes), and
the same validation applies. One file per index, and so per language: an index has one language, so
give `lang_de` its own file or list.

When the list is edited by people (an admin page, a table), keep it in your own storage and hand it to
Fuzzphony at run time:

```php
$fuzzphony->useSynonyms('products', Synonyms::fromText($text));   // or Synonyms::fromEntries([...])
```

The next search uses it: no `fuzzphony:schema --apply`, no reindex. `Synonyms::toText()` writes a list back
in the file format, and a list the application loads is validated by the same rules (`->violations()`),
so a page can refuse a bad one before saving it.

### A long list: store per entry, cache the prepared list

Reading, validating and stemming a list costs time on **every request** under PHP-FPM (a new process
each time), about 20 microseconds per entry (measured with a fresh engine per request on a development
machine: 1 000 entries about 25 ms, 5 000 about 110 ms, 20 000 about 600 ms, on top of the search itself
(about 8 ms); the figures depend on the machine). With a few dozen entries it does not matter; with thousands:

1. **Store one row per entry** (for example `synonym_entry(index_name, entry, …)`, an entry being a line of
   the file format) and a **version** per index that every save bumps. One language is one index, so
   one list.
2. **Prepare the list once per version** and keep the result in your cache (PSR-6, APCu, Redis...). A
   prepared list is plain data and serializes:

   ```php
   $prepared = $fuzzphony->stemSynonyms('products', Synonyms::fromText($text));   // one round trip: PostgreSQL stems the members
   $cache->set("synonyms.products.$version", serialize($prepared));
   ```
3. **On every request** read the version (one tiny query), take the prepared list from the cache and hand
   it over; nothing is read, validated or stemmed again:

   ```php
   $fuzzphony->useSynonyms('products', unserialize($cache->get("synonyms.products.$version")));
   ```

With the prepared list a search stems only the words of the query. The prepared list also carries the lookup table
the expansion works from, so a request builds nothing: for 20 000 entries, unserialising the list takes about 45 ms
(8 MB serialized) and the table about 0 to 10 ms, where an unprepared list needs about 330 ms to build the table and
about 450 ms to stem (the figures depend on the machine). A long-lived worker such as FrankenPHP or RoadRunner keeps the
expander between requests, so there the cost is paid once. A list prepared by an older version has no table
and builds it as before; prepare it again to get one. An engine that
cannot prepare synonyms (a custom one) throws `InvalidArgument` from `stemSynonyms()`: skip that step,
`useSynonyms()` works without it.

The demo's Synonyms page does exactly this (`demo/src/Service/SynonymStore.php`, tables in
`demo/sql/demo_synonym.sql`).

The definition is validated with everything else: a group needs two different members (at most 32),
every member is plain words (a letter or digit in it, up to 16 words, no quotes, operators, `*` or
`:`: a member is split like a query, so syntax in it would be read, not matched), a word may be in
one group only, a rule needs a source and a target (at most 32) with one `=>`, and a source may have
one rule. A YAML `synonyms` value that is not a list is an error, not an empty list. Synonyms are not stored in the index: changing them needs neither
`fuzzphony:schema --apply` nor a reindex, and a YAML `synonyms: []` clears the ones an attribute
declared.

## Builder (plain PHP)

Without Symfony, use the builder and a PDO connection:

```php
use Fuzzphony\Core\{Fuzzphony, Database\PdoConnection, Definition\IndexDefinition};

$connection = PdoConnection::fromDsn('pgsql:host=localhost;dbname=shop', 'user', 'secret');

$products = IndexDefinition::builder('products')
    ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand, p.price
                 FROM product p JOIN brand b ON b.id = p.brand_id')
    ->watch('product')                                               // sync: which tables to follow
    ->watch('brand', 'SELECT id FROM product WHERE brand_id = :id')  // a renamed brand reindexes its products
    ->field('name', 'A', fuzzy: true)
    ->field('brand', 'B')
    ->field('description', 'D')
    ->filter('price', 'int')
    ->language('english')
    ->build();                                  // validates, reporting ALL problems at once

$fuzzphony = Fuzzphony::postgres($connection, [$products]);
$fuzzphony->schema()->apply($connection);
$fuzzphony->reindex('products');
```

## Fuzzphony's schema

Every object Fuzzphony creates (sidecar tables, the sync queue, the version table, the helper
functions, the text search configurations and their stop-word dictionaries) lives in one schema,
`public` by default. `Fuzzphony::postgres($connection, $indexes, schema: 'fuzzphony')` (bundle:
`fuzzphony.schema`) puts them in their own schema instead; `fuzzphony:schema --apply` creates it.
Triggers stay on your tables, since PostgreSQL attaches a trigger to its table; they call the
schema-qualified sync function.

Every statement Fuzzphony generates or runs names its objects with their schema, so neither
Fuzzphony's schema nor the extensions' schema has to be on the `search_path`. The generated
functions are pinned too: the normaliser runs with `search_path = pg_catalog, pg_temp`, the
refresh and sync functions with the `search_path` setting of the session that ran `schema --apply`
(`SET search_path FROM CURRENT`), so your source query and watch SQL resolve their tables through
that setting, whatever the writing session's `search_path` is. The setting is kept as written, not
the schemas it resolved to then: with the default `"$user", public`, `$user` is evaluated each time
the function runs, as the role that wrote the row (the functions are `SECURITY INVOKER`), so a role
with a schema of its own name can still shadow an unqualified source table. Qualify your source
tables, or apply with an explicit `search_path`, if that matters to you. Fuzzphony's own references
are always schema-qualified. Only highlighting and `fuzzphony:reindex` run your source query in the
calling session, so that session must see your source tables.

With a dedicated schema, grant the application role what it needs there (the doctor and
`schema --apply` need more; run those as the owner):

```sql
GRANT USAGE ON SCHEMA fuzzphony TO app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA fuzzphony TO app;
```

The role that runs `fuzzphony:reindex` needs `SELECT` and `UPDATE` on `fuzzphony_meta` too (a full
reindex records there which definition built the documents), and the role that runs
`fuzzphony:doctor` needs `SELECT` on it (without it, the "Schema version" check warns and prints the
`GRANT`). `ON ALL TABLES IN SCHEMA` covers only the tables that exist when it runs: re-run it after
the first 0.4 `schema --apply`, which creates `fuzzphony_meta`. To build next to the live index
and swap it in, that role also needs `CREATE` on the schema and ownership of the index tables (or
membership in their owner); without them it reindexes in place (see
[Reindexing](sync.md#reindexing-and-orphan-pruning)).

`DROP SCHEMA fuzzphony CASCADE` then removes every index at once (drop the triggers on your tables
with `fuzzphony:schema --drop --apply` first). Moving an existing install out of `public` is not
automatic, see [UPGRADE.md](../UPGRADE.md#moving-to-a-dedicated-schema); the doctor warns (check
"Schema") when the configured schema has no sidecar table for an index but `public` still has one.

## Bundle configuration

```yaml
fuzzphony:
  connection: default          # Doctrine DBAL connection name; "default" is Doctrine's own name for the first one
  # connections: optional separate database users, so the application can stay read-only and DDL belongs to the user who deploys (docs/roles.md).
  # Each defaults to `connection`; set nothing and everything runs as that one user. worker: fuzzphony:worker and the async refresh; admin: schema, reindex, doctor.
  connections: { worker: ~, admin: ~ }
  extension_schema: public     # schema of pg_trgm and unaccent; "public" is where CREATE EXTENSION puts them unless told otherwise
  schema: public               # Fuzzphony's own schema, e.g. fuzzphony (see below); public so an upgrade moves nothing, a dedicated one is cleaner
  discover_entities: true      # register every Doctrine entity with #[Searchable]; false: only what you list
  # worker: one batch = one transaction and one refresh, so locks stay short and throughput steady.
  # 500 ids per batch is large enough to amortise the statement, small enough that a failure retries little.
  # idle_sleep: an empty queue costs one cheap query per second, and a change shows up within a second.
  # Raise batch_size for bulk imports, lower idle_sleep (minimum 0.05) for a faster refresh, raise it to spare a quiet database.
  worker: { batch_size: 500, idle_sleep: 1.0 }
  # orm_sync: async is false so nothing else is needed (no Messenger): the refresh runs inside the request's flush.
  # chunk_size: how many ids one RefreshDocuments message carries (async), the same trade-off as the worker batch.
  # Set async: true (and route RefreshDocuments to a transport) when writes must not wait for the refresh.
  orm_sync: { async: false, chunk_size: 500 }
  actor: { }                   # who searches, see below; nobody configured = anonymous, the safe choice for an unscoped index
  # search_log: off, because it stores what people type; 90 days is enough for trends and short enough for a retention policy;
  # redact empty and ip off because storing personal data is a decision for you (masked is the usual choice).
  search_log: { enabled: false, retention_days: 90, redact: [], ip: off }  # opt-in, see below
  # rate_limit: no limiter by default; per: tenant because one tenant is the unit that must not starve the others.
  rate_limit: { limiter: ~, per: tenant }                          # opt-in (tenant, user or ip), see below
  # authorization: off, since a voter is application code; FUZZPHONY_SEARCH is a name of its own that no existing role can clash with.
  authorization: { enabled: false, index_attribute: FUZZPHONY_SEARCH }  # opt-in, see below
  indexes: { }                 # YAML indexes and overrides, see above
```

### The actor

`actor` tells the integrations (the API Platform filter, the Live Component) who is searching, so a
[tenant-scoped index](multi-tenancy.md#integrations) is served without glue code. Set either a
resolver service or property paths, not both (the container then fails to build).

```yaml
fuzzphony:
  actor:
    resolver: App\Search\CurrentAccountResolver   # a service implementing Fuzzphony\Core\Search\ActorResolver
    # or read the Symfony Security user:
    # tenant: account.id        # property path that gives the tenant (an int or a string)
    # user: userIdentifier      # property path that gives the user
```

The property paths need `symfony/security-bundle` (the `security.token_storage` service) and
`symfony/property-access` (suggested, not required): without them the container fails to build, with the
`composer require` line. No token is an anonymous actor, and a user without the property gives null for
that part. A value that is a string, an integer or a `\Stringable` (such as a Symfony `Uuid`) is accepted;
an integer tenant on a string tenant filter is turned into its string. Nothing configured: the
actor is anonymous. The resolver is also available to your own code, as
`Fuzzphony\Core\Search\ActorResolver`.

### The search log

```yaml
fuzzphony:
  search_log:
    enabled: false         # true: record every search; then run fuzzphony:schema --apply
    retention_days: 90     # what fuzzphony:search-log:purge keeps (a whole number, at least 1)
    redact: []             # "email" and/or regular expressions with delimiters: matches are stored as [redacted]
    ip: off                # off | masked | full: keep the client IP (personal data; masked is recommended)
```

Off by default. Enabled, it adds the table `fuzzphony_search_log` to `fuzzphony:schema`, the commands
`fuzzphony:analytics` and `fuzzphony:search-log:purge`, a buffered write after each response, and a "Search log"
check in the doctor (neither command exists while the log is off). `redact` is validated when the container is built (also while the log is off), so a pattern that does not
compile fails there. A single index opts out with `log: false` (attribute, builder `->log(false)`, or YAML under
`indexes`). The role that only searches needs just `INSERT` on the table (the `id` is an identity column, so no
sequence privilege); the reports need `SELECT`, the purge `DELETE`. See [Search log and analytics](analytics.md).

`ip` decides whether the log keeps the client IP (`Request::getClientIp()`, so `framework.trusted_proxies` applies) in the
nullable column `actor_ip`: `off` keeps nothing, `masked` the IPv4 address with the last octet zeroed or the first 48 bits of
an IPv6 address, `full` the whole valid address; anything that is not an IP address is never stored. Enabling it needs
`fuzzphony:schema --apply` (an additive `ADD COLUMN`, run only when the column is missing); it does nothing while `enabled` is false. The values
and the privacy notes are in [The client IP](analytics.md#the-client-ip).

### The rate limit

```yaml
fuzzphony:
  rate_limit:
    limiter: search     # a framework.rate_limiter entry (service limiter.search); empty (default) = no rate limit, except per: ip
    per: user           # tenant (default), user or ip: whose searches share one bucket
```

`per: ip` limits the anonymous visitors of a public search box by client address and needs no `limiter`: without
one the bundle prepends `framework.rate_limiter.fuzzphony_ip` (a sliding window of 600 searches a minute per
address) and uses it; name your own entry in `limiter` to change the numbers (an application that defines an
entry called `fuzzphony_ip` keeps it too). The key is `Request::getClientIp()` (so `framework.trusted_proxies`
applies; without it everyone behind a proxy shares one bucket), an IPv6 address by its /64; an actor without an
address (a console command, a worker, a plain PHP call) is not limited by it. The address is used for the limit
whatever `search_log.ip` says; the log stores it only when `search_log.ip` is `masked` or `full`. A company
network or NAT shares one address among many people, hence the loose default. All of it, with the caveats, in
[Limiting anonymous visitors by IP](security.md#limiting-anonymous-visitors-by-ip).

Needs `symfony/rate-limiter` (suggested, not required: without it the container fails to build, with the
`composer require` line). `limiter` must name a `framework.rate_limiter` entry (a `compound` one works too);
an unknown name fails the build with Symfony's own "non-existent service `limiter.<name>`" message. One token per search of an actor that has the key (the tenant, the user, or a client address); over the limit the search
throws `RateLimited` (HTTP 429 with `Retry-After`). See [Security](security.md#the-rate-limit) for what is
and is not limited.

### Authorization

```yaml
fuzzphony:
  authorization:
    enabled: false                      # true: check the index and the fields before every search of an actor
    index_attribute: FUZZPHONY_SEARCH   # isGranted(attribute, indexName); write a voter, or name e.g. ROLE_USER
```

Needs `symfony/security-bundle` (suggested; it provides the `security.authorization_checker` and
`security.token_storage` services. Without `symfony/security-core` the container fails to build with the
`composer require` line; with security-core but without the bundle, with Symfony's own "non-existent
service `security.authorization_checker`" message). With the default attribute and no voter every search is denied (fail closed), and so is a
search without a Security token. A field is hidden from the viewers who are not granted its `granted:`
attribute; see [Security](security.md#granted-a-field-only-some-viewers-may-search).

### `granted` on a field

`granted: ROLE_HR` marks a field that only some viewers may search and see. It is set in every definition
style: `#[SearchField('B', granted: 'ROLE_HR')]`, the builder's `->field('salary', 'B', granted: 'ROLE_HR')`,
YAML `salary: { weight: B, granted: ROLE_HR }`, and the same key in a plain PHP array. It needs no schema
change and no reindex, and does nothing until `authorization` is enabled.

## Building an index

Building an index is always the same two steps, whether the index is new or you changed its
definition:

1. `fuzzphony:schema --apply` (or `$fuzzphony->schema()->apply($connection)`) creates or updates
   the sidecar table, its indexes, and the sync triggers and functions. It is idempotent: safe to
   rerun after every definition change, and safe in a migration.
2. `fuzzphony:reindex` (or `$fuzzphony->reindex('products')`) backfills every existing row into
   the sidecar table, batched and resumable. Step 1 only creates the structure, so this is needed
   once after it, whatever the sync mode. A full run also removes orphaned documents (see
   [Reindexing and orphan pruning](sync.md#reindexing-and-orphan-pruning)).

A fuzzy index also has a vocabulary table (`fuzzphony_<index>__vocab`: its words and in how many
documents each occurs), which `fuzzphony:schema --apply` creates empty and a full reindex fills, for
["did you mean"](searching.md#did-you-mean). `fuzzphony:reindex --vocabulary` rebuilds only that table.
The role that reindexes needs `SELECT`, `INSERT` and `DELETE` on it, and the role that searches needs
`SELECT` (the grants above cover it once the table exists; run them again after the first apply).

`fuzzphony:doctor` then confirms both steps worked. From there the [sync mode](sync.md) keeps the
sidecar table current. You only reindex again when the definition changes.

## Definitions are trusted input

The `fromQuery()` source, the `watch()` SQL, and index, field and filter names end up in generated
SQL and trigger functions. Never build them from user input. Embedded SQL must not contain
`$fuzzphony$`, the dollar-quote tag of the generated functions; the definition is rejected if it
does. See [SECURITY.md](../SECURITY.md).

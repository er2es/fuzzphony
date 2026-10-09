# Index definitions

How to describe an index, set up the Symfony bundle, and build the index. Back to the [README](../README.md).

## Concepts

An index definition has:

- a source: a table, or any `SELECT` with joins;
- searchable fields, each with a weight A–D and an optional `fuzzy` flag (typo tolerance);
- typed filters;
- optional boost and recency columns;
- ranking profiles and thresholds (see [Ranking and thresholds](ranking.md)).
- optional [synonyms](#synonyms), expanded on the query (they are not part of the index table).

You can write a definition three ways: attributes on an entity (the main way), YAML (overrides, or
indexes defined only in YAML), or the fluent builder.

Fuzzphony stores each index in a sidecar table, `fuzzphony_<index>` in Fuzzphony's schema (see
[Fuzzphony's schema](#fuzzphonys-schema)). It holds a weighted
`tsvector`, a normalised text for trigram matching, the same two per field (for field-scoped words:
one `tsvector` per field, one text per fuzzy field), typed filter columns and the ranking inputs,
each with the right index (GIN, GIN trigram, btree). Your schema is untouched. Dropping the index
is one command.

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
`products`), `table`, `unaccent`, `sync`, `triggerLevel` and `tenant`.

An index name matches `[a-z_][a-z0-9_]*`, at most 48 characters, and must not contain `__` (two
underscores): that is reserved for the objects of an index's rebuild (`fuzzphony_<index>__next`,
`fuzzphony_<index>__changes`).

Filter and field names are always snake_case, even when the attribute is on a camelCase property:
`bool $inStock` becomes the filter `in_stock`. Names follow the property name through
`Identifier::snake()`.

The Symfony bundle registers every Doctrine entity with `#[Searchable]` (turn this off with
`discover_entities: false`).

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
        min_score: 0.01
```

Other keys: `sync`, `trigger_level`, `unaccent`, `tenant`, `synonyms`, `id_type`, and `source: { table: … }`
for a table source.

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

```php
IndexDefinition::builder('products')->synonymsFile($path);       // builder
#[Searchable(synonymsFile: __DIR__ . '/products.txt')]             // attribute (synonyms: [...] is added to it)
```

The file is read when the definition is loaded (in Symfony the container is rebuilt when it changes), and
the same validation applies. One file per index, and so per language: an index has one language, so
give `lang_de` its own file or list.

When the list is edited by people (an admin page, a table), keep it in your own storage and hand it to
Fuzzphony at run time, once per request or process:

```php
$fuzzphony->useSynonyms('products', Synonyms::fromText($row['body']));   // or Synonyms::fromEntries([...])
```

The next search uses it: no `fuzzphony:schema --apply`, no reindex. The demo's Synonyms page does this
with a table (`demo/src/Service/SynonymStore.php`). `Synonyms::toText()` writes a list back in the file
format, and a list the application loads is validated by the same rules (`->violations()`), so a
page can refuse a bad one before saving it.

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
use Fuzzphony\Core\{Fuzzphony, Registry\IndexRegistry, Database\PdoConnection, Definition\IndexDefinition};
use Fuzzphony\Engine\Postgres\PostgresEngine;

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

$fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$products]));
$fuzzphony->schema()->apply($connection);
$fuzzphony->reindex('products');
```

## Fuzzphony's schema

Every object Fuzzphony creates (sidecar tables, the sync queue, the version table, the helper
functions, the text search configurations and their stop-word dictionaries) lives in one schema,
`public` by default. `new PostgresEngine($connection, schema: 'fuzzphony')` (bundle:
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
  connection: default          # Doctrine DBAL connection name
  extension_schema: public     # schema of the pg_trgm and unaccent extensions
  schema: public               # Fuzzphony's own schema, e.g. fuzzphony (see below)
  discover_entities: true      # register every Doctrine entity with #[Searchable]
  worker: { batch_size: 500, idle_sleep: 1.0 }
  orm_sync: { async: false, chunk_size: 500 }
  indexes: { }                 # YAML indexes and overrides, see above
```

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

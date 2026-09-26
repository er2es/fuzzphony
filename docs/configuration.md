# Index definitions

How to describe an index, set up the Symfony bundle, and build the index. Back to the [README](../README.md).

## Concepts

An index definition has:

- a source: a table, or any `SELECT` with joins;
- searchable fields, each with a weight A–D and an optional `fuzzy` flag (typo tolerance);
- typed filters;
- optional boost and recency columns;
- ranking profiles and thresholds (see [Ranking and thresholds](ranking.md)).

You can write a definition three ways: attributes on an entity (the main way), YAML (overrides, or
indexes defined only in YAML), or the fluent builder.

Fuzzphony stores each index in a sidecar table, `fuzzphony_<index>`. It holds a weighted
`tsvector`, a normalised text for trigram matching, typed filter columns and the ranking inputs,
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

Other keys: `sync`, `trigger_level`, `unaccent`, `tenant`, `id_type`, and `source: { table: … }`
for a table source.

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

## Bundle configuration

```yaml
fuzzphony:
  connection: default          # Doctrine DBAL connection name
  extension_schema: public     # schema of the pg_trgm and unaccent extensions
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

`fuzzphony:doctor` then confirms both steps worked. From there the [sync mode](sync.md) keeps the
sidecar table current. You only reindex again when the definition changes.

## Definitions are trusted input

The `fromQuery()` source, the `watch()` SQL, and index, field and filter names end up in generated
SQL and trigger functions. Never build them from user input. Embedded SQL must not contain
`$fuzzphony$`, the dollar-quote tag of the generated functions; the definition is rejected if it
does. See [SECURITY.md](../SECURITY.md).

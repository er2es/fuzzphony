# Fuzzphony

[![CI](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml/badge.svg)](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/er2es/fuzzphony/graph/badge.svg)](https://codecov.io/gh/er2es/fuzzphony)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Fuzzy search, in perfect harmony with your database.**

Fuzzphony is a PostgreSQL-native search layer for PHP and Symfony. It gives you typo-tolerant,
accent-insensitive, ranked full-text search with filters and a real query language, **without
running Elasticsearch, Meilisearch or any other service**. It works on existing databases:
your tables are never altered, Fuzzphony maintains a *sidecar* index table next to them.

```php
$result = $fuzzphony->in(Product::class)
    ->query('wireles mouse -cable')          // typo, exclusion
    ->where('price', '<=', 20_000)
    ->where('in_stock', true)
    ->highlight('name')
    ->get();

foreach ($result as $hit) {
    echo $hit->id, ' ', $hit->score, ' ', $hit->highlights['name'];   // "<mark>Wireless</mark> mouse"
}
```

> Status: **v0.2**. PostgreSQL engine, wizard, demo app. The API may still change before 1.0.

---

## Why

| | `LIKE '%…%'` | Fuzzphony | External engine |
|---|---|---|---|
| Typos (`hedphones`) | ✘ | ✔ trigram fallback | ✔ |
| Accents (`creme` → `crème`) | ✘ | ✔ `unaccent` | ✔ |
| Stemming (`mice` → `mouse`) | ✘ | ✔ 20+ languages | ✔ |
| Relevance ranking + field weights | ✘ | ✔ explainable | ✔ |
| Extra service to run, sync and secure | – | **none** | yes |
| Transactional consistency with your data | ✔ | ✔ (trigger mode) | eventual |

## Requirements

PHP 8.4+, PostgreSQL 15+ with the `pg_trgm` and `unaccent` extensions (both ship with PostgreSQL).
Symfony 7.x / 8.x and Doctrine are optional.

## Quickstart (Symfony)

```bash
composer require fuzzphony/symfony-bundle
```

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

Or let the wizard write the definition for an existing table (joins included):

```bash
bin/console fuzzphony:wizard product --try   # explains every choice, creates the index, lets you search it
```

```bash
bin/console fuzzphony:schema            # review the SQL first (nothing is executed)
bin/console fuzzphony:schema --apply    # or: --dump-migration=migrations
bin/console fuzzphony:reindex           # backfill, batched and resumable
bin/console fuzzphony:doctor            # verify everything, with fixes
bin/console fuzzphony:search products 'wireles mouse' -w "price<=20000"
```

Then inject `Fuzzphony\Core\Fuzzphony` anywhere. `Fuzzphony\Bridge\Doctrine\EntityLoader` turns
results into entities with one query, keeping the ranking order.

## Quickstart (plain PHP)

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

## Concepts

**Index definition.** Source (a table, or any `SELECT` with joins) → searchable *fields* (with
weights A–D and an optional `fuzzy` flag) → typed *filters* → optional boost / recency columns →
ranking *profiles* → *thresholds*. Defined by attributes (primary), YAML (overrides / pure YAML
indexes) or the fluent builder.

**Sidecar table.** `fuzzphony_<index>` holds a weighted `tsvector`, a normalised text for trigram
matching, typed filter columns and the ranking inputs, each with the right index (GIN, GIN trigram,
btree). Your schema is untouched; dropping the index is one command.

**Engine.** Everything dialect-specific lives behind `Fuzzphony\Core\Engine\Engine`, validated by a
conformance test suite (`tests/Conformance`). PostgreSQL is the only engine through 1.0.

## Query syntax

End-user search text **never throws**. Malformed input is repaired and reported in
`$result->warnings`, and `$result->interpretedAs` shows how it was understood.

| Syntax | Meaning |
|---|---|
| `wireless mouse` | both words (AND is implicit; `AND` also works) |
| `"wireless mouse"` | exact phrase |
| `mouse OR trackpad`, `mouse \| trackpad` | either |
| `-cable`, `NOT cable`, `!cable` | exclude (also honoured by typo-tolerant matches) |
| `keyb*` | prefix |
| `brand:logitech`, `name:"mx master"` | only in one field (per weight group) |
| `(mouse OR trackpad) -cable` | grouping |

Limits (`max_query_length`, `max_terms`, nesting depth 8) keep hostile input cheap.
Developer mistakes, like an unknown filter or a wrong value type, **do** throw, with a suggestion:
`Index "products" has no filter "prise". Did you mean "price"?`

## Ranking

```
relevance = text  × ts_rank_cd(weights A..D)       (0..1)
          + fuzzy × word_similarity(query, fuzzy fields)  (0..1)

score     = relevance
          + exact_bonus    (primary field equals the query)
          + prefix_bonus   (primary field starts with the query)
          + boost   × boost column
          + recency × 2^(−age / half_life)
```

`min_score` is applied to **relevance only**: boosts reorder relevant hits but can never pull an
irrelevant document into the results. Every hit carries a `ScoreBreakdown`, so "why is this first?"
always has an answer (`fuzzphony:search` prints it as a table).

Profiles let one index rank differently per use case:

```yaml
fuzzphony:
  indexes:
    products:
      profiles:
        popular: { boost: 0.05, recency: 0.3, recency_half_life_days: 14 }
        strict:  { fuzzy: 0, exact_bonus: 1.0 }
```

```php
$fuzzphony->in('products')->query('mouse')->profile('popular')->get();
```

## Thresholds

| Option | Default | Meaning |
|---|---|---|
| `min_score` | `0.0` | minimum relevance a hit needs |
| `fuzzy_mode` | `fallback` | `always`, `fallback` (only when exact matching finds few hits) or `never` |
| `fallback_below` | `5` | in fallback mode: fewer exact hits than this triggers typo tolerance |
| `fuzzy_similarity` | `0.3` | trigram word similarity needed (lower = more tolerant) |
| `fuzzy_min_length` | `3` | shorter queries skip typo tolerance |
| `candidate_limit` | `2000` | max candidates ranked per branch; `total` becomes a lower bound (`2000+`) |
| `max_query_length` / `max_terms` | `256` / `16` | input limits |

Set them per index (YAML) or per query: `->thresholds(['min_score' => 0.1, 'fuzzy_mode' => 'always'])`.
Invalid keys fail immediately with the list of allowed ones.

### Per-query tuning

Any profile value can be overridden for one query, which is what the demo's playground does with sliders:

```php
$fuzzphony->in('products')->query('mouse')->profile('popular')->ranking(['boost' => 0.1, 'fuzzy' => 0.8])->get();
```

## Configuration wizard

`fuzzphony:wizard [table]` (or the demo's web wizard) reads the table's structure and planner
statistics and suggests a complete definition, **explaining every decision**:

```
 Column         Role                                Why
 name           field A, fuzzy                      looks like the title: most important, typo tolerant
 description    field D                             long text: searchable, but weighted lowest
 status         filter                              only ~4 distinct values: better as a filter
 password_hash  skip                                looks sensitive; never indexed automatically
 brand_id       join brand.name -> field brand      related label is searchable; changes in that table are watched
 popularity     boost                               popularity-like number: used by the "popular" ranking profile
 published_at   recency                             newest first in the "popular" ranking profile
```

Output as YAML, builder code or attributes (`--format`), to a file (`--write`), or `--try` it on the
spot: schema, reindex, doctor and interactive searches. Boost weights are scaled from the column's
statistics, so the most popular document gets a bonus comparable to a good text match. Tables that
were never analysed are sampled instead.

## Keeping the index in sync

| Mode | How | Use when |
|---|---|---|
| `queue` (default) | triggers enqueue ids, `fuzzphony:worker` refreshes in batches | most apps; writes stay fast |
| `trigger` | triggers refresh inside the writing transaction | you need read-your-writes consistency |
| `orm` | Doctrine listener refreshes after `flush()` | no triggers allowed; joined data is not followed |
| `manual` | nothing automatic | batch imports, read-only data |

Triggers also watch joined tables (`watch('brand', 'SELECT id FROM product WHERE brand_id = :id')`),
so renaming a brand reindexes its products. Triggers are **statement-level** by default: they read
PostgreSQL transition tables, so `UPDATE brand SET …` touching 100 000 rows queues all affected
documents with one set-based `INSERT … SELECT` instead of 100 000 trigger calls
(`trigger_level: row` switches back; switching is idempotent and the doctor flags leftovers). The worker takes a batch and refreshes it in **one
statement**, so a failure never loses queued ids, and `SKIP LOCKED` lets several workers run side
by side. Without long-running processes: `fuzzphony:worker --once` from cron.

In `orm` mode, refreshing can move out of the request through Symfony Messenger:

```yaml
fuzzphony:
  orm_sync: { async: true }
framework:
  messenger:
    routing: { Fuzzphony\Bundle\Messenger\RefreshDocuments: async }
```

## Integrations

**API Platform.** Relevance search for any collection whose entity is searchable; other filters,
pagination and serialization keep working, results come in score order:

```php
#[ApiResource]
#[ApiFilter(FuzzphonySearchFilter::class)]            // GET /api/products?q=wireles mouse -cable
class Product { /* ... */ }
```

**Live Component.** Search-as-you-type without writing JavaScript (requires `symfony/ux-live-component`):

```twig
<twig:Fuzzphony:Search index="products" highlight="name" placeholder="Search products…" />
```

Override `templates/bundles/FuzzphonyBundle/components/Search.html.twig` to change the markup.

## Demo

`cd demo && docker compose up --build`, then http://localhost:8000: ILIKE vs Fuzzphony side by side,
a ranking playground with sliders and score breakdowns, the web wizard, benchmarks and the doctor.
See [demo/README.md](demo/README.md).

## The doctor

`fuzzphony:doctor` compares the database with the definitions and prints a fix for every problem.
Exit code is non-zero on errors (`--strict`: also on warnings), so it belongs in CI.

```
 Index "products"
  ✔ PostgreSQL version       16.4
  ✔ Extension pg_trgm        installed
  ✔ Source                   custom query
  ✔ Filter price             price (integer)
  ✘ Sidecar columns          Schema drift, missing: f_in_stock.
  ✘ Index fuzzphony_products_fz   INVALID (an interrupted concurrent build)
  ! Sync queue               12840 item(s) waiting, oldest 900s: is the worker running?
  ✔ Coverage                 999 812 of 1 000 000 documents indexed (100.0%, estimated)

 Fix for "Sidecar columns": bin/console fuzzphony:schema --apply
 Fix for "Sync queue": bin/console fuzzphony:worker   (or from cron: bin/console fuzzphony:worker --once)
```

It checks: server version, extensions, text configuration, helper functions, that the source can be
queried, id / field / filter / boost / recency column mapping and types, the source key, sidecar
column drift, missing or INVALID indexes, missing or disabled triggers, queue backlog and age,
coverage (estimated, or exact with `--deep`), and risky thresholds.

## Commands

| Command | Purpose |
|---|---|
| `fuzzphony:schema [index] [--apply\|--drop\|--dump-migration=dir]` | show / apply / export idempotent DDL (alias `fuzzphony:install`) |
| `fuzzphony:reindex [index] [--batch=5000] [--from=id]` | resumable backfill with progress |
| `fuzzphony:worker [--once] [--time-limit=s] [--index=x]` | drain the sync queue; graceful on SIGTERM |
| `fuzzphony:doctor [index] [--deep] [--strict]` | health check with fixes |
| `fuzzphony:search index 'query' [-w filter] [--explain [--analyze]]` | try queries, see score breakdowns, SQL and plans |
| `fuzzphony:wizard [table] [--format=yaml\|builder\|attributes] [--write=file] [--try]` | suggest, explain and export a definition |

## Security

* Search text is parsed by Fuzzphony, reduced to letters and digits per lexeme, and **bound as a
  parameter**; identifiers come only from validated definitions.
* Highlight snippets are HTML-escaped by Fuzzphony; only its own `<mark>` tags remain.
* Input size, term count and nesting depth are capped; candidate sets are bounded.

## Benchmarks

`benchmarks/seed.sql` generates a catalogue (products × brands × categories); `benchmarks/run.php`
compares a naive `ILIKE` with Fuzzphony: first ("cold") run and the median of the next 5 ("warm"),
20 results. CI runs it on every push to `main` and publishes the table in the job summary.
Sample run, 200 000 products, PostgreSQL 16, a small cloud VM:

| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) |
|---|---|---:|---:|---:|---:|
| plain word | `wireless` | 1.7 / 0.6 ms | 20 | 17.9 / 11.1 ms | 20 (2000+) |
| two words | `wireless mouse` | 4.1 / 3.7 ms | 20 | 19.9 / 13.1 ms | 20 (1666) |
| accent | `creme` | 257.6 / 251.6 ms | **0** | 11.9 / 10.4 ms | 20 (2000+) |
| typo | `hedphones` | 248.1 / 252.6 ms | **0** | 24.2 / 20.7 ms | 20 (2000+) ~ |
| stemming | `drills` | 342.0 / 257.1 ms | **0** | 11.9 / 10.6 ms | 20 (2000+) |
| phrase + exclusion | `"noise cancelling" -headphones` | 0.6 / 0.5 ms | 20* | 24.3 / 23.2 ms | 20 (2000+) |
| filter + text | `kettle` | 0.8 / 0.7 ms | 20 | 12.8 / 11.3 ms | 20 (2000+) |

`ILIKE … LIMIT 20` is fast when the first rows it scans match, because it does not rank and it cannot
exclude words (*); it degrades to a full scan and finds nothing as soon as the text differs from the
stored spelling. Run the numbers on your own data before believing anyone's benchmark, including this one.

## Known limitations

* Field-scoped queries (`brand:x`) work per weight group: fields sharing a weight are searched together.
* Sync triggers fire for every UPDATE of a watched table, even when only unrelated columns change
  (the refresh is idempotent, just wasted work). Column-aware filtering is planned.
* Statement-level triggers cannot be attached to individual partitions; watch the partitioned parent
  or use `trigger_level: row`.
* The extension schema (default `public`) must be on the `search_path` for the trigram operator.
* With very frequent words, ranking considers the first `candidate_limit` matches, so ordering is
  approximate beyond them (and `total` is reported as a lower bound).

## Architecture

```
fuzzphony/core             definitions, attributes, query language (AST), ranking, thresholds,
                           Engine contract, schema plans, inspection, reindexer, worker, wizard
fuzzphony/postgres-engine  tsvector + unaccent + pg_trgm; SQL compilers, schema generator, doctor,
                           introspector
fuzzphony/doctrine-bridge  DBAL connection, ORM naming, ORM sync listener, entity loader
fuzzphony/symfony-bundle   configuration, autowiring, console commands, Messenger, API Platform
                           filter, Live Component
```

A monorepo, split into read-only package repositories on every push. Design decisions are recorded
in [`docs/adr`](docs/adr).

## Roadmap

* **v0.1** PostgreSQL engine, attributes / YAML / builder, query language, ranking profiles,
  thresholds, queue / trigger / ORM sync, doctor, CLI.
* **v0.2** *(this release)* configuration wizard (CLI + web), statement-level triggers, per-query
  ranking overrides, Messenger for ORM sync, API Platform filter, Live Component, demo app,
  benchmark in CI.
* **v1.0** Enterprise readiness, stable API, BC promise. PostgreSQL only — no other engine is
  planned before 1.0.
  * Multi-tenancy: tenant-scoped sidecar schema, isolation enforced at the query layer (not just
    application-level convention).
  * Observability: hooks/events for query latency, queue lag and error rate, wired for Symfony
    Messenger middleware and any metrics backend.
  * Federated search: query multiple indexes at once with one merged, cross-index ranking.
  * Security: audit logging (who searched what, when) and per-tenant/per-user rate limiting;
    Symfony Security integration for index/field-level authorization (e.g. restricting a field
    from highlights unless the viewer is authorized).
  * Column-aware trigger filtering (a watched table's UPDATE only queues a refresh when a
    relevant column actually changed).
  * Test coverage ≥ 90% (currently 73%, tracked by Codecov in CI).

## Development

```bash
docker compose up -d
composer install
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" composer test:all
composer qa     # php-cs-fixer + PHPStan (max) + unit tests
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Licensed under the [MIT license](LICENSE).

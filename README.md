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

## Contents

- [Why](#why)
- [Requirements](#requirements)
- [Quickstart (Symfony)](#quickstart-symfony)
  - [Search controller examples](#search-controller-examples)
- [Quickstart (plain PHP)](#quickstart-plain-php)
- [Concepts](#concepts)
- [Query syntax](#query-syntax)
- [Ranking](#ranking)
- [Thresholds](#thresholds)
  - [Per-query tuning](#per-query-tuning)
- [Configuration wizard](#configuration-wizard)
- [Keeping the index in sync](#keeping-the-index-in-sync)
- [Multi-tenancy](#multi-tenancy)
- [Integrations](#integrations)
- [Demo](#demo)
- [The doctor](#the-doctor)
- [Commands](#commands)
- [Security](#security)
- [Benchmarks](#benchmarks)
- [Known limitations](#known-limitations)
- [Architecture](#architecture)
- [Roadmap](#roadmap)
- [Development](#development)

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

* **PHP 8.4+**
* **PostgreSQL 15+**
* The `pg_trgm` and `unaccent` extensions, enabled on the database Fuzzphony connects to. Both ship
  with core PostgreSQL (no separate package on most distributions), but must be turned on per
  database by a superuser or the database owner:
  ```sql
  CREATE EXTENSION IF NOT EXISTS pg_trgm;
  CREATE EXTENSION IF NOT EXISTS unaccent;
  ```
* Symfony 7.x / 8.x and Doctrine are optional (only needed for `fuzzphony/symfony-bundle`).

`bin/console fuzzphony:doctor` verifies all of the above in one shot — PostgreSQL version,
both extensions, and everything else the index needs — and prints the exact fix for anything
missing (see [The doctor](#the-doctor)).

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

### Search controller examples

A minimal search endpoint that takes the user's query straight off the request:

```php
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Hit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    #[Route('/search', name: 'search')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $result = $fuzzphony->in(Product::class)
            ->query($q)
            ->where('in_stock', true)
            ->highlight('name')
            ->limit(20)
            ->get();

        return $this->json([
            'total' => $result->total,
            'tookMs' => $result->tookMs,
            'hits' => array_map(static fn(Hit $hit): array => [
                'id' => $hit->id,
                'score' => $hit->score,
                'name' => $hit->highlights['name'] ?? null,   // "<mark>Wireless</mark> mouse"
            ], $result->hits),
        ]);
    }
}
```

Filter names are always **snake_case**, even when the attribute is on a camelCase property
(`bool $inStock` → filter `in_stock`) — `#[SearchField]`/`#[SearchFilter]` names follow the
property name through `Identifier::snake()`. An empty `$q` (no query yet, e.g. the search
page's first load) is a normal call — it returns a plain, filtered `where('in_stock', true)`
browse instead of erroring, so the same endpoint serves both "search" and "browse all
in-stock products".

A more complete endpoint — pagination, multiple filters from query params, a per-tenant
account scope, and a client-chosen ranking profile. This assumes a **different**, explicitly
tenant-scoped index (see [Multi-tenancy](#multi-tenancy)), not the plain `Product` above —
mixing tenant and non-tenant search into one example hides that tenant scoping is opt-in
per index:

```php
IndexDefinition::builder('listings')
    ->fromTable('listing')
    ->filter('account_id', 'int')
    ->tenant('account_id')
    ->filter('category', 'string')
    ->filter('price', 'int')
    ->filter('in_stock', 'bool')
    ->field('name', 'A', fuzzy: true)
    ->field('description', 'D')
    ->build();
```

```php
#[Route('/search', name: 'search')]
public function __invoke(Request $request, Fuzzphony $fuzzphony): JsonResponse
{
    $accountId = $this->getUser()?->getAccountId();
    if ($accountId === null) {
        // forTenant(null) on a tenant-scoped index throws "missing tenant"; guard before searching.
        return $this->json(['error' => 'Authentication required.'], 401);
    }

    // profile() validates eagerly and throws on an unknown name — never feed raw user input
    // into it directly, that would contradict "search input never throws". Whitelist it instead.
    $sort = $request->query->get('sort', 'default');
    $sort = \in_array($sort, ['default', 'popular'], true) ? $sort : 'default';

    $builder = $fuzzphony->in('listings')
        ->query(trim((string) $request->query->get('q', '')))
        ->forTenant($accountId)
        ->profile($sort)
        ->highlight('name', 'description')
        ->page($request->query->getInt('page', 1), perPage: 20);

    if ($request->query->has('category')) {
        $builder = $builder->whereIn('category', $request->query->all('category'));
    }
    if ($request->query->has('price_min') || $request->query->has('price_max')) {
        $builder = $builder->whereBetween(
            'price',
            $request->query->getInt('price_min', 0),
            $request->query->getInt('price_max', PHP_INT_MAX),
        );
    }
    if ($request->query->getBoolean('in_stock_only')) {
        $builder = $builder->where('in_stock', true);
    }

    $result = $builder->get();

    return $this->json([
        'total' => $result->total,
        'totalIsExact' => !$result->totalIsLowerBound,   // false: show "$total+", the candidate cap was hit
        'tookMs' => $result->tookMs,
        'warnings' => $result->warnings,                 // safe to show to users, e.g. "Only the first 16 terms were used."
        'hits' => array_map(static fn(Hit $hit): array => [
            'id' => $hit->id,
            'score' => $hit->score,
            'name' => $hit->highlights['name'] ?? null,
            'description' => $hit->highlights['description'] ?? null,
        ], $result->hits),
    ]);
}
```

Every `where*`/`forTenant`/`profile`/`ranking`/`thresholds` call is immutable and returns a new
builder, so building the query conditionally (as above) is just reassigning the variable —
nothing is applied until `->get()`.

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

**Building an index is always the same two steps**, whether the index is brand new or you just
changed its definition:

1. `fuzzphony:schema --apply` (or `$fuzzphony->schema()->apply($connection)`) — creates or updates
   the sidecar table, its indexes, and the sync triggers/functions. Idempotent: safe to rerun after
   every definition change, and safe in a migration.
2. `fuzzphony:reindex` (or `$fuzzphony->reindex('products')`) — backfills every existing row into the
   sidecar table, batched and resumable. Needed once after step 1, regardless of which sync mode you
   use — step 1 only creates the *structure*, it doesn't populate it. A full run (without `--from`)
   finishes by removing *orphans*: indexed documents whose row the source no longer returns, in
   batches, and reports how many it removed. A run resumed with `--from` covers only part of the
   source, so it never removes anything.

After that, `fuzzphony:doctor` confirms both steps actually succeeded, and the sync mode you chose
(see [Keeping the index in sync](#keeping-the-index-in-sync)) keeps the sidecar table caught up with
future writes automatically — no more manual reindexing unless the definition changes again.

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

**Typo tolerance follows the same structure.** When the typo-tolerant branch runs (see
[Thresholds](#thresholds)), every word must still be satisfied on its own, either exactly (full
text) or by trigram similarity, combined through the query's real AND / OR / NOT:

| Query | Typo-tolerant meaning |
|---|---|
| `wireles mice` | (`wireles` exactly **or** a word similar to it) **and** (`mice` exactly **or** similar) |
| `headphnoes \| torhc` | either word, each exactly or similar |
| `wireles (headphones \| mouse -silent)` | the negation applies inside the group; negated words are always exact |
| `"wireles headphones"` | the phrase exactly, or the whole phrase as one similar text |
| `ergnoo*` | the prefix exactly, or the prefix text as a similar word |

So a typo in one word never lets through documents that lack the other words. Typo tolerance only
reaches words stored in **fuzzy fields** (`fuzzy: true`): a misspelled word that appears only in a
non-fuzzy field such as a description or a category cannot be matched approximately, so the whole
query finds nothing rather than ignoring that word. Words shorter than
`fuzzy_min_length` and stop words of the index language ("for", "the") are handled like the
full-text query handles them: short words must match exactly, stop words are ignored.

Limits (`max_query_length`, `max_terms`, nesting depth 8) keep hostile input cheap.
Developer mistakes, like an unknown filter or a wrong value type, **do** throw, with a suggestion:
`Index "products" has no filter "prise". Did you mean "price"?`

## Ranking

```
relevance = text  × ts_rank_cd(weights A..D)       (0..1)
          + fuzzy × per-word similarity                  (0..1)

score     = relevance
          + exact_bonus    (primary field equals the query)
          + prefix_bonus   (primary field starts with the query)
          + boost   × boost column
          + recency × 2^(−age / half_life)
```

The per-word similarity (`r_fuzzy`, `ScoreBreakdown::$fuzzySimilarity`) follows the query: a word
scores 1.0 when it matches exactly and its trigram `word_similarity` against the fuzzy fields
otherwise; AND averages its words, OR takes the best one, negations do not score. A document
that matches both words of `wireles mouse` well therefore outranks one that matches one of them
barely. The fuzzy weight only applies when the typo-tolerant branch runs.

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
| `fuzzy_similarity` | `0.3` | trigram word similarity each word needs (lower = more tolerant) |
| `fuzzy_min_length` | `3` | shorter words must match exactly; a query with no longer word skips typo tolerance |
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

Every `INSERT`, `UPDATE` and `DELETE` on the source table (and on watched tables) is followed: an
insert adds the document, an update rebuilds it, and a delete removes it from the sidecar table
(the refresh drops every indexed id the source no longer returns). In `trigger` and `queue` mode a
`TRUNCATE` is followed too, by a separate statement-level `AFTER TRUNCATE` trigger: truncating a
table-sourced index's own table empties the index right away (and drops its queued ids), while
truncating any other watched table resyncs every document, because the removed rows can no longer
tell which documents they belonged to (see [Known limitations](#known-limitations) for the cost).
When the change shows up in search depends on the mode:

| Mode | Change visible in search |
|---|---|
| `trigger` | immediately, inside the writing transaction |
| `queue` | once the worker has processed the queue. Until then a deleted row can still appear as a hit; `EntityLoader` skips hits whose row no longer exists, and `fuzzphony:doctor` reports the queue's size and age |
| `orm` | after `flush()`, and only for changes made through the entity manager: raw SQL writes are not seen |
| `manual` | when you call `refresh()` or `reindex()` |

Triggers also watch joined tables (`watch('brand', 'SELECT id FROM product WHERE brand_id = :id')`),
so renaming a brand reindexes its products. Triggers are **statement-level** by default: they read
PostgreSQL transition tables, so `UPDATE brand SET …` touching 100 000 rows queues all affected
documents with one set-based `INSERT … SELECT` instead of 100 000 trigger calls
(`trigger_level: row` switches back; switching is idempotent and the doctor flags leftovers). The worker takes a batch and refreshes it in **one
statement**, so a failure never loses queued ids, and `SKIP LOCKED` lets several workers run side
by side. Without long-running processes: `fuzzphony:worker --once` from cron.

Watched tables also skip wasted work: a table-sourced index's own watch automatically only
refreshes on UPDATEs that actually change a mapped field, filter, boost or recency column —
no configuration needed. Joined-table watches can opt into the same behavior explicitly:

```php
->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])
// updating any OTHER column of "brand" no longer refreshes dependent products
```

Omitting `columns` on a joined watch keeps today's behavior (every UPDATE refreshes) —
this is opt-in for joined watches because Fuzzphony has no way to know which of a joined
table's columns matter without you saying so.

In `orm` mode, refreshing can move out of the request through Symfony Messenger:

```yaml
fuzzphony:
  orm_sync: { async: true }
framework:
  messenger:
    routing: { Fuzzphony\Bundle\Messenger\RefreshDocuments: async }
```

## Multi-tenancy

If your data is already partitioned by tenant/account in the same tables — a shared-schema
multi-tenant SaaS where each customer's data must never appear in another customer's search
results — mark the tenant column so Fuzzphony enforces it on every search, not just on the
ones a developer remembered to filter:

```php
IndexDefinition::builder('products')
    ->fromTable('product')
    ->filter('account_id', 'int')
    ->tenant('account_id')   // marks that filter as the tenant scope
    ->field('name', 'A', fuzzy: true)
    ->build();

$fuzzphony->in('products')->forTenant($accountId)->query('wireless mouse')->get();

// Omitting forTenant() on a tenant-scoped index throws immediately, before any SQL runs:
$fuzzphony->in('products')->query('wireless mouse')->get();
// InvalidQuery: Index "products" requires forTenant(); none was given.
```

`tenant()` also works with `#[Searchable(tenant: 'account_id')]` and the YAML `tenant: account_id` key.

**Adopting this on an existing index:** declaring `.tenant()` alone does not touch already-indexed
rows. After adding it, run `fuzzphony:schema --apply` (idempotent — safe to run anytime) followed
by `fuzzphony:reindex` to backfill the tenant column into the existing sidecar rows. Until you
reindex, the column is NULL for rows indexed before the change, so tenant-scoped searches simply
return nothing for them (fail-closed, not dangerous, but easy to mistake for a bug).

`FuzzphonySearchFilter` (API Platform), `SearchComponent` (Live Component) and `bin/console
fuzzphony:search` do not currently accept a tenant value; using any of them against a
tenant-scoped index throws `InvalidQuery` (fail-closed). This is a known limitation, not
something this release fixes.

**You don't need this** for a single-tenant application (nothing changes either way), or for
applications with fully isolated tenants — a separate database or schema per tenant already
works today via one `Connection`/engine instance per tenant.

This is an application-layer guarantee, enforced by Fuzzphony's API surface rather than by the
database. If you need defense-in-depth against raw SQL bypassing the library entirely, pair it
with your own PostgreSQL row-level security policy on the sidecar table — Fuzzphony's filter
stays correct alongside it.

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
column drift, missing or INVALID indexes, missing or disabled triggers (including the `TRUNCATE`
trigger, which an index set up with an older version lacks until `fuzzphony:schema --apply` runs
again), queue backlog and age, coverage (estimated, or exact with `--deep`), orphaned documents
(with `--deep`; fixed by `fuzzphony:reindex`), and risky thresholds.

## Commands

| Command | Purpose |
|---|---|
| `fuzzphony:schema [index] [--apply\|--drop\|--dump-migration=dir]` | show / apply / export idempotent DDL (alias `fuzzphony:install`) |
| `fuzzphony:reindex [index] [--batch=5000] [--from=id]` | resumable backfill with progress; a full run also removes orphaned documents |
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

| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) | who's actually right |
|---|---|---:|---:|---:|---:|---|
| plain word | `wireless` | 1.7 / 0.6 ms | 20 (unranked) | 17.9 / 11.1 ms | 20 (2000+) | ⚡ ILIKE faster · 🎯 Fuzzphony ranked |
| two words | `wireless mouse` | 4.1 / 3.7 ms | 20 (unranked) | 19.9 / 13.1 ms | 20 (1666) | ⚡ ILIKE faster · 🎯 Fuzzphony ranked |
| accent | `creme` | 257.6 / 251.6 ms | **0** | 11.9 / 10.4 ms | 20 (2000+) | ✅ Fuzzphony (ILIKE finds nothing) |
| typo | `hedphones` | 248.1 / 252.6 ms | **0** | 24.2 / 20.7 ms | 20 (2000+) ~ | ✅ Fuzzphony (ILIKE finds nothing) |
| stemming | `drills` | 342.0 / 257.1 ms | **0** | 11.9 / 10.6 ms | 20 (2000+) | ✅ Fuzzphony (ILIKE finds nothing) |
| phrase + exclusion | `"noise cancelling" -headphones` | 0.6 / 0.5 ms | 20 (wrong\*) | 24.3 / 23.2 ms | 20 (2000+) | ✅ Fuzzphony (ILIKE can't exclude) |
| filter + text | `kettle` | 0.8 / 0.7 ms | 20 (unranked) | 12.8 / 11.3 ms | 20 (2000+) | ⚡ ILIKE faster · 🎯 Fuzzphony ranked |

**Reading this honestly:** `ILIKE … LIMIT 20` without `ORDER BY` doesn't return the 20 *best*
matches — it returns the first 20 rows the scan happens to hit, in physical table order. That's why
it's fast when it's lucky (plain word, two words, filter + text) and catastrophic when it isn't
(accent, typo, stemming: a full sequential scan that finds **zero** correct rows in a quarter of a
second). It also has no concept of exclusion, so `-headphones` is silently ignored — its "20 hits"
on that row are simply wrong, not just unranked. Fuzzphony's 11–24 ms is the cost of doing the harder,
correct job every time: ranked, typo-tolerant, accent-insensitive, with real query semantics — not a
lucky scan that only works until your users misspell something. (`~` = the fuzzy fallback fired for
that query.) Run the numbers on your own data before believing anyone's benchmark, including this one.

## Known limitations

* Field-scoped queries (`brand:x`) work per weight group: fields sharing a weight are searched together.
* Field scoping is exact-only for the typo-tolerant side: the fuzzy fields are stored as one
  trigram-indexed text, so a scoped word that is not found exactly may match *any* fuzzy field
  once the typo-tolerant branch runs. On the demo catalogue `name:sony` finds no product with
  "sony" in its name, falls back to typo tolerance and returns Sony-*brand* products; `name:kettel`
  (a typo) still finds kettles. Per-field trigram columns would fix this and are planned separately.
* Typo tolerance is per word and deliberately lenient: at the default `fuzzy_similarity` of 0.3 a
  correctly spelled word also matches similar words (`mouse` is trigram-close to `monitor` and
  `mower`), so `wireles mouse` also lists wireless monitors, ranked below the mice. With very
  frequent words these near-misses can use up `candidate_limit` before ranking, so raise
  `fuzzy_similarity` (0.4 to 0.5 is stricter) or `candidate_limit` when that matters.
* ~~Sync triggers fire for every UPDATE of a watched table, even when only unrelated columns
  change.~~ **Shipped** for the index's own source table (automatic) and for joined-table
  watches (opt-in `columns:`, see [Keeping the index in sync](#keeping-the-index-in-sync)).
* Statement-level triggers cannot be attached to individual partitions; watch the partitioned parent
  or use `trigger_level: row`. The `TRUNCATE` trigger on a partitioned parent fires when the parent
  is truncated, but **not** when a single partition is truncated directly (`TRUNCATE
  product_2024`): run `fuzzphony:reindex` afterwards, which also removes the orphaned documents.
* `TRUNCATE` is followed in `trigger` and `queue` mode (see
  [Keeping the index in sync](#keeping-the-index-in-sync)). Truncating the index's own source table
  is cheap: the sidecar is emptied. Truncating a *joined* or otherwise watched table is not: every
  indexed document, plus every document the source returns now, is resynced — in `trigger` mode
  inside the truncating transaction (on a big index that transaction takes as long as a full
  reindex), in `queue` mode by queueing all those ids for the worker. A query source's main table
  counts as a watched table here, since Fuzzphony cannot tell that the source is now empty. Indexes
  set up with an older version get the `TRUNCATE` trigger from `fuzzphony:schema --apply`
  (`fuzzphony:doctor` reports it missing until then); `orm` and `manual` mode never see a
  `TRUNCATE`: run `fuzzphony:reindex`.
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
  * ~~Multi-tenancy: tenant scoping via a designated filter, isolation enforced at the query
    layer (not just application-level convention).~~ **Shipped** — see
    [Multi-tenancy](#multi-tenancy).
  * Observability: hooks/events for query latency, queue lag and error rate, wired for Symfony
    Messenger middleware and any metrics backend.
  * Federated search: query multiple indexes at once with one merged, cross-index ranking.
  * Security: audit logging (who searched what, when) and per-tenant/per-user rate limiting;
    Symfony Security integration for index/field-level authorization (e.g. restricting a field
    from highlights unless the viewer is authorized).
  * ~~Column-aware trigger filtering (a watched table's UPDATE only queues a refresh when a
    relevant column actually changed).~~ **Shipped** — see
    [Keeping the index in sync](#keeping-the-index-in-sync).
  * Test coverage ≥ 90% (currently 73%, tracked by Codecov in CI).

## Development

```bash
docker compose up -d
composer install
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" composer test:all
composer qa     # php-cs-fixer + PHPStan (max) + unit tests
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Licensed under the [MIT license](LICENSE).

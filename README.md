# Fuzzphony

[![CI](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml/badge.svg)](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/er2es/fuzzphony/graph/badge.svg)](https://codecov.io/gh/er2es/fuzzphony)
[![Packagist Version](https://img.shields.io/packagist/v/fuzzphony/fuzzphony)](https://packagist.org/packages/fuzzphony/fuzzphony)
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

> Status: **v0.3**. The API may still change before 1.0; breaking changes are listed in the
> [CHANGELOG](CHANGELOG.md) and explained in [UPGRADE.md](UPGRADE.md).

> ⭐ **Try it in one command:** `git clone https://github.com/er2es/fuzzphony && cd fuzzphony/demo && docker compose up --build`,
> then open http://localhost:8000 and search a real product catalogue with typos, accents and five
> languages, next to plain `ILIKE`. [What's in the demo →](#demo)

---

## Contents

- [Why](#why)
- ⭐ **[Demo](#demo)**: try it in one command
- [Requirements](#requirements)
- [Quickstart (Symfony)](#quickstart-symfony)
  - [Search controller examples](#search-controller-examples)
- [Quickstart (plain PHP)](#quickstart-plain-php)
- [Concepts](#concepts)
- [Languages](#languages)
- [Query syntax](#query-syntax)
- [Ranking](#ranking)
- [Thresholds](#thresholds)
  - [Per-query tuning](#per-query-tuning)
- [Configuration wizard](#configuration-wizard)
- [Keeping the index in sync](#keeping-the-index-in-sync)
- [Multi-tenancy](#multi-tenancy)
- [Integrations](#integrations)
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

Most applications already have a search box, and behind it a `LIKE '%…%'` that misses
`hedphones`, `creme` and `drills`, reads the whole table and ranks nothing. The usual fix is a
search cluster, which is one more service to run, sync, back up and secure, and one more copy of
your data. Fuzzphony gives you good search **inside the PostgreSQL you already have**, and
it doesn't need to change your schema to do it.

**Where it comes from.** Fuzzphony grew out of a production requirement: adding dependable,
typo-tolerant search to an established system whose database could not be restructured, without
introducing new infrastructure. The constraints behind that requirement are common: a schema owned
by other applications, operations teams wary of another service, and data that has to stay where
it is. So the solution was generalised, tested against PostgreSQL 15 to 18, and released as a
library for teams in the same position.

**A good fit when:**

- **The database is not yours to change.** It's a legacy system, an ERP, or tables another team
  or application owns. Fuzzphony adds no column and never alters your tables. The index lives in
  its own sidecar table, filled from your table or from a SQL query. If even a trigger is too much, the
  `orm` and `manual` sync modes need none.
- **You're replacing `LIKE` in admin panels, back offices, CRMs and support tools.** This is where
  typo and accent tolerance pay off on the first day.
- **One more service is one too many.** There is no cluster, and no second pipeline to keep in
  sync or to wake you up at night.
- **The data must stay in the database.** For compliance or privacy, the index stays next to the
  data, under the same backups and roles.
- **Search must be exactly as fresh as the data.** In `trigger` mode the index changes in the
  same transaction as the row, so a sold-out product or a closed ticket disappears from search on
  commit.
- **You run a shared-schema multi-tenant SaaS.** The engine enforces the tenant filter on every
  query, so a forgotten `WHERE` can't leak another customer's rows.
- **Your content is multilingual.** There is stemming for 28 languages, and accent folding
  finds `Kávéfőző` for `kavefozo` and `Crème Brûlée` for `creme brulee`.

**Not the right tool when** you need hundreds of millions of documents or thousands of searches
a second on one index, analytics-style faceted aggregations, or semantic/vector search (look at
pgvector or a dedicated engine), or when your database is not PostgreSQL (MySQL is on the
[roadmap](#roadmap)).

| | `LIKE '%…%'` | Fuzzphony | External engine |
|---|---|---|---|
| Typos (`hedphones`) | ✘ | ✔ trigram fallback | ✔ |
| Accents (`creme` → `crème`) | ✘ | ✔ `unaccent` | ✔ |
| Stemming (`drills` → `drill`) | ✘ | ✔ PostgreSQL's 28 Snowball languages | ✔ |
| Relevance ranking + field weights | ✘ | ✔ explainable | ✔ |
| Extra service to run, sync and secure | – | **none** | yes |
| Transactional consistency with your data | ✔ | ✔ (trigger mode) | eventual |

## Demo

A full Symfony app on a seeded product catalogue, running on your machine:

```bash
git clone https://github.com/er2es/fuzzphony
cd fuzzphony/demo && docker compose up --build    # http://localhost:8000
```

| Page | What you see |
|---|---|
| **ILIKE vs Fuzzphony** | the same query both ways, with timings and one-click typo / accent / stemming / phrase examples |
| **Languages** | English, German, French, Spanish and Hungarian presets, and what PostgreSQL made of every word |
| **Playground** | every ranking weight and threshold as a slider, with a score breakdown per hit and the SQL |
| **Config wizard** | pick a table, get a suggested index definition with every decision explained |
| **Benchmark** / **Doctor** | the comparison as a table, and the `fuzzphony:doctor` report in the browser |

It is a local showcase, not a production template: see [demo/README.md](demo/README.md) for the
stack, the settings and the security defaults.

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
        'warnings' => $result->warnings,                 // plain text, e.g. "Only the first 16 terms were used."; escape it when rendering HTML
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
   source, so it never removes anything. Pruning is relative to what the *reindexing session* can
   see: where that session sees fewer rows than your application (row-level security on the source,
   a query source using `current_setting(...)`, a different `search_path` for the CLI user), a full
   reindex removes the difference from the index. Pass `--no-prune` (or `prune: false` to
   `$fuzzphony->reindex()`) there. A full run whose source returns **no row at all** does not prune
   and says so, because that is far more likely a visibility problem than intent (a real `TRUNCATE`
   is handled by its trigger); `--prune-empty` (`pruneEmpty: true`) forces it.

After that, `fuzzphony:doctor` confirms both steps actually succeeded, and the sync mode you chose
(see [Keeping the index in sync](#keeping-the-index-in-sync)) keeps the sidecar table caught up with
future writes automatically — no more manual reindexing unless the definition changes again.

## Languages

Each index analyses its text in one language, set on the index:

```php
#[Searchable(language: 'hungarian')]                            // attribute
IndexDefinition::builder('termekek')->language('hungarian')     // builder
```

```yaml
language: hungarian     # YAML; add "unaccent: false" to keep accents significant
```

The value is a PostgreSQL text search configuration. PostgreSQL 15+ ships 29: `arabic`,
`armenian`, `basque`, `catalan`, `danish`, `dutch`, `english` (the default), `finnish`, `french`,
`german`, `greek`, `hindi`, `hungarian`, `indonesian`, `irish`, `italian`, `lithuanian`, `nepali`,
`norwegian`, `portuguese`, `romanian`, `russian`, `serbian`, `spanish`, `swedish`, `tamil`,
`turkish`, `yiddish` (each with a Snowball stemmer), and `simple` (no stemming, no stop words: for
codes, names or mixed-language text).

The language decides three things:

* **Stemming**: word forms are reduced to a common stem, so a search for one form finds the
  others.
* **Stop words**: the language's filler words ("the", "for", "und", and accented ones such as
  "für", "és" or "à") are ignored.
* **Accent folding** (on by default): `fuzzphony:schema --apply` creates a configuration
  `fuzzphony_<language>` that copies the built-in one and removes accents before stemming, so
  `cafe` finds `café`. Stop words are dropped before the accents are removed (with a dictionary
  `fuzzphony_<language>_stop` that uses the built-in stop-word list), so folding never turns one
  into an ordinary word. Languages whose stemmer has no stop-word list get no such dictionary.

Measured with Fuzzphony's configuration (accents removed, then stemmed):

| Language | These find each other |
|---|---|
| english | `running`, `run` · `drills`, `drill` |
| german | `Häuser`, `Haus` · `Mäuse`, `Maus` · `Bücher`, `Buch` |
| french | `chevaux`, `cheval` · `crèmes`, `crème` |
| spanish | `canciones`, `canción` |
| hungarian | `házak`, `ház` · `könyvek`, `könyv` · `egerek`, `egér` (only with accent folding) |

Typo tolerance (trigram similarity) works the same in every language.

Limits:

* Stemmers reduce regular endings only. Irregular forms such as `mice` / `mouse` or `went` / `go`
  stay different words.
* One language per index. For a multilingual catalogue, build one index per language (for example
  `->fromQuery("SELECT ... FROM product WHERE lang = 'de'")->language('german')`) and search the
  one that matches the user's locale.
* A custom configuration you installed yourself (a Hunspell dictionary, say) works with
  `unaccent: false`. With accent folding, Fuzzphony pairs the name with the built-in
  `<language>_stem` dictionary, so it must be one of the configurations above.

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
non-fuzzy field such as a description or a category cannot be matched approximately. Words shorter than
`fuzzy_min_length` and stop words of the index language ("for", "the") are handled like the
full-text query handles them: short words must match exactly, stop words are ignored.

**A query that finds nothing is relaxed.** When a query of two or more words returns no hit at
all, Fuzzphony checks each word on its own against everything the search may see (your filters
and the tenant included), exactly or by similarity just like the search does. Words that match
nothing at all are dropped, the search runs once more, and the result says so:

```php
$result = $fuzzphony->in('products')->query('wireless mouse aluminum')->get(); // "aluminium" is only in descriptions
$result->interpretedAs; // "(wireless AND mouse)"
$result->warnings;      // ['No results for all words; ignored words that match nothing: "aluminum".']
```

Words that all match something but never in the same document (`mouse kettle`) are **not**
relaxed: that empty result is the correct answer. A query that already has hits, a single word, or a
query whose every word matches nothing is never relaxed either, nor is one that would be left with
a group of only exclusions (`zzqq -mouse | wireless yyqq`). If the relaxed search finds nothing too
(`wireless mouse aluminum kettle`), you get the original empty result, without a warning.

The check costs one probe statement, a possible stop-word lookup, and one or two relaxed search
statements (strict, then fuzzy), only for empty multi-word results; turn it off with
`relax_when_empty: false`. The probe looks at whether a word matches any document at all, so it does not
apply `min_score` or the candidate limit: a word can count as matching through documents the search
would reject, and is then kept instead of dropped (the relaxation errs on the side of doing less).

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
| `relax_when_empty` | `true` | a multi-word query that finds nothing drops the words that match nothing and says so in `warnings` ([details](#query-syntax)); independent of `fuzzy_mode` |

Set them per index (YAML) or per query: `->thresholds(['min_score' => 0.1, 'fuzzy_mode' => 'always'])`.
Invalid keys fail immediately with the list of allowed ones.

The cost limits have hard caps that no setting can exceed: `candidate_limit` ≤ 10 000,
`max_query_length` ≤ 1 024 and `max_terms` ≤ 64 (`Thresholds::MAX_CANDIDATE_LIMIT`,
`MAX_QUERY_LENGTH`, `MAX_TERMS`). Larger values, and an unknown `fuzzy_mode`, throw
`InvalidDefinition`, so thresholds taken from a request can tighten the limits but never remove
them.

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
table-sourced index's own table empties the index right away when the source is then really empty
(and drops its queued ids), while
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

In `orm` mode, refreshing can move out of the request through Symfony Messenger
(`composer require symfony/messenger`; it is optional, and the container fails with that hint
when `async` is on without it):

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
tenant-scoped index throws `InvalidQuery` (fail-closed). A tenant resolver for these
integrations is planned (see [Roadmap](#roadmap)).

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
| `fuzzphony:reindex [index] [--batch=5000] [--from=id] [--no-prune] [--prune-empty]` | resumable backfill with progress; a full run also removes orphaned documents (`--no-prune` keeps them; an empty source is only pruned with `--prune-empty`) |
| `fuzzphony:worker [--once] [--time-limit=s] [--index=x]` | drain the sync queue; graceful on SIGTERM |
| `fuzzphony:doctor [index] [--deep] [--strict]` | health check with fixes |
| `fuzzphony:search index 'query' [-w filter] [--explain [--analyze]]` | try queries, see score breakdowns, SQL and plans |
| `fuzzphony:wizard [table] [--format=yaml\|builder\|attributes] [--write=file] [--try]` | suggest, explain and export a definition |

## Security

* Search text is parsed by Fuzzphony, reduced to letters and digits per lexeme, and **bound as a
  parameter**; identifiers come only from validated definitions.
* Highlight snippets are HTML-escaped by Fuzzphony; only its own `<mark>` tags remain.
* `$result->warnings` are **plain text**, not HTML: a warning may quote the user's own words (the relaxation
  warning does, as typed, minus invisible format characters, cut at 40 characters), so escape it when you render it
  as HTML (Twig's `{{ warning }}` does).
* `$result->interpretedAs` is plain text too, built from the user's words.
* Input size, term count and nesting depth are capped; candidate sets are bounded. Set a
  PostgreSQL `statement_timeout` for the application's database role as well.
* Index definitions (the `fromQuery()` source, `watch()` SQL, index, field and filter names) are
  trusted developer input: they end up in generated SQL and trigger functions, so never build them
  from user input. Embedded SQL must not contain `$fuzzphony$`, the dollar-quote tag of the
  generated functions; the definition is rejected if it does.

Report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

## Benchmarks

`benchmarks/seed.sql` generates a catalogue (products × brands × categories); `benchmarks/run.php`
compares a naive `ILIKE` with Fuzzphony: first ("cold") run and the median of the next 5 ("warm"),
20 results. The [Benchmark workflow](https://github.com/er2es/fuzzphony/actions/workflows/benchmark.yml)
runs it on every push to `main` and publishes the current table in its job summary. An example run
(200 000 products, PostgreSQL 16, a small cloud VM; the output of `run.php --markdown`):

| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) |
|---|---|---:|---:|---:|---:|
| plain word | `wireless` | 1.7 / 0.6 ms | 20 | 17.9 / 11.1 ms | 20 (2000+) |
| two words | `wireless mouse` | 4.1 / 3.7 ms | 20 | 19.9 / 13.1 ms | 20 (1666) |
| accent | `creme` | 257.6 / 251.6 ms | **0** | 11.9 / 10.4 ms | 20 (2000+) |
| typo | `hedphones` | 248.1 / 252.6 ms | **0** | 24.2 / 20.7 ms | 20 (2000+) ~ |
| stemming | `drills` | 342.0 / 257.1 ms | **0** | 11.9 / 10.6 ms | 20 (2000+) |
| phrase + exclusion | `"noise cancelling" -headphones` | 0.6 / 0.5 ms | 20 | 24.3 / 23.2 ms | 20 (2000+) |
| filter + text | `kettle` | 0.8 / 0.7 ms | 20 | 12.8 / 11.3 ms | 20 (2000+) |

`~` means the typo-tolerant fallback ran. The two columns do different work: `ILIKE … LIMIT 20`
returns the first 20 rows the scan reaches, unranked, and cannot exclude words (its 20 hits for
the exclusion query include headphones), so it is fastest when the word is common and finds
nothing for accents, typos or other word forms, after scanning the whole table. Fuzzphony ranks
every result and handles those cases in about 10-25 ms. Measure on your own data.

## Known limitations

Each of these has a planned fix on the [roadmap](#roadmap), except the partition trigger rule,
which PostgreSQL imposes.

* Field-scoped queries (`brand:x`) work per weight group: fields sharing a weight are searched together.
* Field scoping is exact-only for the typo-tolerant side: the fuzzy fields are stored as one
  trigram-indexed text, so a scoped word that is not found exactly may match *any* fuzzy field
  once the typo-tolerant branch runs. On the demo catalogue `name:sony` finds no product with
  "sony" in its name, falls back to typo tolerance and returns Sony-*brand* products; `name:kettel`
  (a typo) still finds kettles. Per-field trigram columns will fix this (roadmap: exact field scoping).
* Typo tolerance is per word and deliberately lenient: at the default `fuzzy_similarity` of 0.3 a
  correctly spelled word also matches similar words (`mouse` is trigram-close to `monitor` and
  `mower`), so `wireles mouse` also lists wireless monitors, ranked below the mice. With very
  frequent words these near-misses can use up `candidate_limit` before ranking, so raise
  `fuzzy_similarity` (0.4 to 0.5 is stricter) or `candidate_limit` when that matters.
* Statement-level triggers cannot be attached to individual partitions; watch the partitioned parent
  or use `trigger_level: row`. The `TRUNCATE` trigger on a partitioned parent fires when the parent
  is truncated, but **not** when a single partition is truncated directly (`TRUNCATE
  product_2024`): run `fuzzphony:reindex` afterwards, which also removes the orphaned documents.
* `TRUNCATE` is followed in `trigger` and `queue` mode (see
  [Keeping the index in sync](#keeping-the-index-in-sync)). Truncating the index's own source table
  is cheap: the sidecar is emptied, but only after checking that the source really is empty (a
  `TRUNCATE ONLY` on a table-inheritance parent leaves the child tables' rows in the source, so it
  resyncs like the case below). Truncating a *joined* or otherwise watched table is not cheap: every
  indexed document, plus every document the source returns now, is resynced — in `trigger` mode
  inside the truncating transaction, in `queue` mode by queueing all those ids for the worker. Measured
  on a 1M-document index, that `TRUNCATE` took **42.8 s** in `trigger` mode (holding an
  `ACCESS EXCLUSIVE` lock on the truncated table and row locks on the sidecar for that whole time)
  and **8.5 s** in `queue` mode (plus 1M queued ids). For big indexes that watch joined tables
  prefer `queue` sync, or truncate in a maintenance window. A query source's main table
  counts as a watched table here, since Fuzzphony cannot tell that the source is now empty. In
  `queue` mode a `TRUNCATE` never waits for the queue rows a running worker holds when it empties
  the index; the joined-table resync can still wait for a conflicting row the worker holds, and
  in the worst case a deadlock makes the `TRUNCATE` fail (the window is a few microseconds per
  worker batch): just retry it, the queue keeps its ids. Truncating a single partition of a
  partitioned source fires nothing (see above). Indexes
  set up with an older version get the `TRUNCATE` trigger from `fuzzphony:schema --apply`
  (`fuzzphony:doctor` reports it missing until then); `orm` and `manual` mode never see a
  `TRUNCATE`: run `fuzzphony:reindex`.
* The extension schema (default `public`) must be on the `search_path` for the trigram operator
  (to be schema-qualified in the next patch release).
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

A monorepo, published as the single Composer package `fuzzphony/fuzzphony`; each directory already
has its own `composer.json` for a later split into separate packages. Design decisions are recorded
in [`docs/adr`](docs/adr).

## Roadmap

* **v0.1** PostgreSQL engine, attributes / YAML / builder, query language, ranking profiles,
  thresholds, queue / trigger / ORM sync, doctor, CLI.
* **v0.2** configuration wizard (CLI + web), statement-level triggers, per-query ranking
  overrides, Messenger for ORM sync, API Platform filter, Live Component, demo app, benchmark in
  CI, multi-tenancy, column-aware trigger filtering.
* **v0.3** *(current)* per-word typo tolerance, empty-result relaxation, `TRUNCATE` sync and
  orphan pruning, a production-like demo stack.
* **v1.0** Enterprise readiness, stable API, BC promise. PostgreSQL only — no other engine is
  planned before 1.0.
  * Search-as-you-type: a dedicated, fast `suggest()` API for prefix suggestions, and a debounced
    dropdown in the Live Component.
  * Synonyms per index (`tv` ↔ `television`, domain abbreviations), expanded on the query side
    without dictionary files on the database server.
  * Facets: counts per filter value for the current query ("Kitchen (120) · Office (45)"), and
    an opt-in exact `total` for queries whose matches exceed `candidate_limit`.
  * Exact field scoping: per-field text and trigram columns, so `brand:x` searches that field only
    (not its whole weight group) and a scoped typo can't match another fuzzy field.
  * Length-aware typo tolerance: a stricter similarity for short words and a looser one for long
    words, so `mouse` stops matching `monitor` without losing typos in long words.
  * "Did you mean": a spelling suggestion from the index's own vocabulary when a word matches
    nothing (`hedphones` → "headphones?"), next to the existing empty-result relaxation.
  * Zero-downtime reindex: build the new index in a shadow table and swap it in, so a definition
    change or a full rebuild never serves partial results. A `TRUNCATE` on a watched table then
    queues one full-resync job instead of every document id.
  * Partition-aware sync: the `TRUNCATE` trigger on every partition, and the doctor reporting new
    partitions that miss it.
  * Search analytics: the most frequent queries and the queries that found nothing, for the
    people who own the content.
  * Doctrine Migrations integration: generate a migration class from the schema, next to
    `--dump-migration`.
  * Documentation site with a "Migrating from `LIKE`" guide and recipes (admin panel, shop,
    multi-tenant SaaS).
  * Observability: hooks/events for query latency, queue lag and error rate, wired for Symfony
    Messenger middleware and any metrics backend.
  * Federated search: query multiple indexes at once with one merged, cross-index ranking.
  * Security: audit logging (who searched what, when) and per-tenant/per-user rate limiting;
    Symfony Security integration for index/field-level authorization (e.g. restricting a field
    from highlights unless the viewer is authorized), and a tenant resolver (for example from the
    Symfony Security user) for the API Platform filter, the Live Component and `fuzzphony:search`.
  * Transaction-aware connections: the similarity-threshold setting of a fuzzy statement is restored
    afterwards so a caller's own transaction is left untouched, which costs one extra database round
    trip per fuzzy statement. An optional, non-breaking `Connection` capability (`inTransaction()`)
    would let the engine skip that round trip when no outer transaction is open.
* **After 1.0**
  * Laravel integration: a Scout driver over the same PostgreSQL engine.

## Development

```bash
docker compose up -d
composer install
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" composer test:all
composer qa     # php-cs-fixer + PHPStan (max) + unit tests
```

The unit and integration suites cover 100% of the lines in `src/`; CI fails below 90%.

See [CONTRIBUTING.md](CONTRIBUTING.md). Licensed under the [MIT license](LICENSE).

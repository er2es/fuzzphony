# Fuzzphony

[![CI](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml/badge.svg)](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/er2es/fuzzphony/graph/badge.svg)](https://codecov.io/gh/er2es/fuzzphony)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://github.com/er2es/fuzzphony/blob/main/phpstan.neon.dist)
[![Mutation score](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fer2es%2Ffuzzphony%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/er2es/fuzzphony/main)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/er2es/fuzzphony/badge)](https://scorecard.dev/viewer/?uri=github.com/er2es/fuzzphony)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/14933/badge)](https://www.bestpractices.dev/projects/14933)

[![Packagist Version](https://img.shields.io/packagist/v/fuzzphony/fuzzphony)](https://packagist.org/packages/fuzzphony/fuzzphony)
[![PHP](https://img.shields.io/packagist/dependency-v/fuzzphony/fuzzphony/php)](https://packagist.org/packages/fuzzphony/fuzzphony)
[![PostgreSQL 15 | 16 | 17 | 18](https://img.shields.io/badge/PostgreSQL-15%20%7C%2016%20%7C%2017%20%7C%2018-336791?logo=postgresql&logoColor=white)](https://github.com/er2es/fuzzphony/blob/main/.github/workflows/ci.yml)
[![Symfony 7.4 | 8.0](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0-000000?logo=symfony)](https://github.com/er2es/fuzzphony/blob/main/.github/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/er2es/fuzzphony/blob/main/LICENSE)

**Fuzzy search, in perfect harmony with your database.**

Fuzzphony is a PostgreSQL-native search library for PHP and Symfony: typo-tolerant,
accent-insensitive, ranked full-text search with filters and a query language. It never alters
your tables. The index lives in a sidecar table next to them, in the database you already run.
There is no Elasticsearch, Meilisearch or other service to add.

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

Status: v0.3. The API may still change before 1.0. Breaking changes are listed in the
[CHANGELOG](https://github.com/er2es/fuzzphony/blob/main/CHANGELOG.md) and explained in
[UPGRADE.md](https://github.com/er2es/fuzzphony/blob/main/UPGRADE.md).

## Try the demo

⭐ A full Symfony app on a seeded product catalogue, in one command:

```bash
git clone https://github.com/er2es/fuzzphony
cd fuzzphony/demo && docker compose up --build    # http://localhost:8000
```

| Page | What you see |
|---|---|
| ILIKE vs Fuzzphony | the same query both ways, with timings and one-click typo / accent / stemming / phrase examples |
| Languages | English, German, French, Spanish and Hungarian presets, and what PostgreSQL made of every word |
| Playground | every ranking weight and threshold as a slider, with a score breakdown per hit and the SQL |
| Config wizard | pick a table, get a suggested index definition with every decision explained |
| Benchmark / Doctor | the comparison as a table, and the `fuzzphony:doctor` report in the browser |

It is a local showcase, not a production template. See
[demo/README.md](https://github.com/er2es/fuzzphony/blob/main/demo/README.md) for the stack, the
settings and the security defaults.

## Why

Behind most search boxes is a `LIKE '%…%'` that misses `hedphones`, `creme` and `drills`, reads the
whole table and ranks nothing. The usual fix is a search cluster: one more service to run, sync,
back up and secure, and one more copy of your data. Fuzzphony does the search inside PostgreSQL
instead, without changing your schema.

Where it comes from: a production system needed typo-tolerant search, but its database could not
be restructured and no new infrastructure was allowed. Those constraints are common, so the
solution was generalised, tested against PostgreSQL 15 to 18, and released as a library.

A good fit when:

- The database is not yours to change: a legacy system, an ERP, tables another team owns. No
  column is added; the index is filled from your table or from a SQL query. If even a trigger is
  too much, the `orm` and `manual` sync modes need none.
- You're replacing `LIKE` in admin panels, back offices, CRMs and support tools.
- One more service is one too many.
- The data must stay in the database, under the same backups and roles, for compliance or privacy.
- Search must be as fresh as the data. In `trigger` mode the index changes in the same
  transaction as the row, so a sold-out product leaves search on commit.
- You run a shared-schema multi-tenant SaaS. The tenant filter is enforced on every query, so a
  forgotten `WHERE` can't leak another customer's rows.
- Your content is multilingual: stemming for 28 languages, and accent folding that finds
  `Kávéfőző` for `kavefozo` and `Crème Brûlée` for `creme brulee`.

Not the right tool when you need hundreds of millions of documents or thousands of searches a
second on one index, analytics-style faceted aggregations, semantic or vector search (look at
pgvector or a dedicated engine), or a database other than PostgreSQL (no other engine is planned
before 1.0).

| | `LIKE '%…%'` | Fuzzphony | External engine |
|---|---|---|---|
| Typos (`hedphones`) | ✘ | ✔ trigram fallback | ✔ |
| Accents (`creme` → `crème`) | ✘ | ✔ `unaccent` | ✔ |
| Stemming (`drills` → `drill`) | ✘ | ✔ PostgreSQL's 28 Snowball languages | ✔ |
| Relevance ranking + field weights | ✘ | ✔ explainable | ✔ |
| Extra service to run, sync and secure | – | none | yes |
| Transactional consistency with your data | ✔ | ✔ (trigger mode) | eventual |

## Requirements

- PHP 8.4+
- PostgreSQL 15+ with the `pg_trgm` and `unaccent` extensions. Both ship with PostgreSQL, but a
  superuser or the database owner must enable them per database:
  ```sql
  CREATE EXTENSION IF NOT EXISTS pg_trgm;
  CREATE EXTENSION IF NOT EXISTS unaccent;
  ```
- Optional: Symfony 7.4+ and Doctrine (DBAL 4.2+, ORM 3.3+) for the bundle.

`bin/console fuzzphony:doctor` checks all of this and prints the fix for anything missing.

## Install

```bash
composer require fuzzphony/fuzzphony
```

For Symfony, add `Fuzzphony\Bundle\FuzzphonyBundle::class => ['all' => true]` to
`config/bundles.php`. The bundle uses your Doctrine DBAL connection (`fuzzphony.connection`,
default `default`).

## Quickstart (Symfony)

1. Mark an entity as searchable:

   ```php
   use Fuzzphony\Core\Attribute\{Searchable, SearchField, SearchFilter};

   #[ORM\Entity]
   #[Searchable(language: 'english')]
   class Product
   {
       #[ORM\Id, ORM\Column] public int $id;

       #[ORM\Column, SearchField('A', fuzzy: true)]  public string $name;
       #[ORM\Column, SearchField('C')]               public string $description;
       #[ORM\Column, SearchFilter]                   public int $price;       // type inferred
       #[ORM\Column, SearchFilter]                   public bool $inStock;    // filter "in_stock"
   }
   ```

   Or let the wizard write the definition for an existing table, joins included:
   `bin/console fuzzphony:wizard product --try`.

2. Create the index and fill it:

   ```bash
   bin/console fuzzphony:schema            # review the SQL first (nothing is executed)
   bin/console fuzzphony:schema --apply    # or: --dump-migration=migrations
   bin/console fuzzphony:reindex           # backfill, batched and resumable
   bin/console fuzzphony:doctor            # verify everything, with fixes
   bin/console fuzzphony:search products 'wireles mouse' -w "price<=20000"
   ```

3. Inject `Fuzzphony\Core\Fuzzphony` and search as in the example at the top.
   `Fuzzphony\Bridge\Doctrine\EntityLoader` turns results into entities with one query, keeping
   the ranking order.

By default, triggers queue changed rows and `bin/console fuzzphony:worker` keeps the index
current (see [sync modes](https://github.com/er2es/fuzzphony/blob/main/docs/sync.md)). Without
Symfony, define indexes with `IndexDefinition::builder()` and a `PdoConnection`
([plain PHP setup](https://github.com/er2es/fuzzphony/blob/main/docs/configuration.md#builder-plain-php)).

## Query syntax

Search text from end users never throws. Malformed input is repaired and reported in
`$result->warnings`. Developer mistakes, such as an unknown filter, do throw, with a suggestion.

| Syntax | Meaning |
|---|---|
| `wireless mouse` | both words (AND is implicit; `AND` also works) |
| `"wireless mouse"` | exact phrase |
| `mouse OR trackpad`, `mouse \| trackpad` | either |
| `-cable`, `NOT cable`, `!cable` | exclude |
| `keyb*` | prefix |
| `brand:logitech`, `name:"mx master"` | only in one field (per weight group) |
| `(mouse OR trackpad) -cable` | grouping |

Typos are tolerated per word, within the query's AND / OR / NOT. A multi-word query that finds
nothing drops the words that match nothing and says so. Details:
[Searching](https://github.com/er2es/fuzzphony/blob/main/docs/searching.md).

## Documentation

- [Index definitions](https://github.com/er2es/fuzzphony/blob/main/docs/configuration.md): attributes, YAML, builder, bundle configuration, building an index.
- [Searching](https://github.com/er2es/fuzzphony/blob/main/docs/searching.md): the search builder, controller examples, query syntax, typo tolerance, empty-result relaxation.
- [Ranking and thresholds](https://github.com/er2es/fuzzphony/blob/main/docs/ranking.md): the score formula, profiles, per-query tuning, thresholds and their hard caps.
- [Languages](https://github.com/er2es/fuzzphony/blob/main/docs/languages.md): stemming, stop words and accent folding for 28 languages.
- [Keeping the index in sync](https://github.com/er2es/fuzzphony/blob/main/docs/sync.md): sync modes, triggers, `TRUNCATE`, orphan pruning, Messenger.
- [Multi-tenancy](https://github.com/er2es/fuzzphony/blob/main/docs/multi-tenancy.md): a tenant filter enforced on every search.
- [Console commands](https://github.com/er2es/fuzzphony/blob/main/docs/commands.md): every command, the doctor, the configuration wizard.
- [Integrations](https://github.com/er2es/fuzzphony/blob/main/docs/integrations.md): Doctrine, API Platform, Live Component.
- [Benchmarks](https://github.com/er2es/fuzzphony/blob/main/docs/benchmarks.md): Fuzzphony against `ILIKE` on 200 000 products.
- [Known limitations](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md): the full text of the list below.
- [Architecture](https://github.com/er2es/fuzzphony/blob/main/docs/architecture.md) and [design decisions (ADRs)](https://github.com/er2es/fuzzphony/tree/main/docs/adr).
- [Roadmap](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md): released versions and the plan for 1.0.

## Security

- Search text is parsed by Fuzzphony and bound as a parameter. Identifiers come only from
  validated definitions.
- Highlights are HTML-escaped; only Fuzzphony's `<mark>` tags remain. `$result->warnings` and
  `$result->interpretedAs` are plain text that may quote the user: escape them in HTML.
- Query length, term count, nesting depth and candidate sets are capped. Also set a PostgreSQL
  `statement_timeout` for the application's database role.
- Index definitions (`fromQuery()`, `watch()` SQL, names) are trusted developer input. Never build
  them from user input.

Report vulnerabilities privately: see
[SECURITY.md](https://github.com/er2es/fuzzphony/blob/main/SECURITY.md).

## Known limitations

Details in [docs/limitations.md](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md).

- [Field scoping](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md#field-scoping-works-per-weight-group) (`brand:x`) covers the whole weight
  group, and any fuzzy field once typo tolerance runs. Fix planned:
  [exact field scoping](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md#exact-field-scoping).
- [Typo tolerance is lenient](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md#typo-tolerance-is-lenient): at the default similarity, `mouse`
  also matches `monitor`. Fix planned: [length-aware typo tolerance](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md#length-aware-typo-tolerance).
- [`TRUNCATE` on a joined table](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md#truncate-on-a-watched-table) resyncs every document: 42.8 s
  (`trigger`) or 8.5 s (`queue`) on 1M documents. Fix planned:
  [zero-downtime reindex](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md#zero-downtime-reindex).
- [Partitions](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md#partitioned-tables): statement-level triggers go on the parent (a PostgreSQL
  rule), and truncating a single partition is not followed. Fix planned:
  [partition-aware sync](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md#partition-aware-sync).
- [Ranking is approximate beyond `candidate_limit`](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md#ranking-is-approximate-beyond-candidate_limit),
  and `total` is then a lower bound. Fix planned: an opt-in exact `total`, part of
  [facets](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md#facets).

## Roadmap

Current: v0.3 (per-word typo tolerance, empty-result relaxation, `TRUNCATE` sync and orphan
pruning). Milestones build in order, each one a foundation for the next, up to v1.0's stable API
and backward-compatibility promise. PostgreSQL only before 1.0:

- v0.4 Foundations: API cleanup, a dedicated schema, sidecar schema versioning, Doctrine
  Migrations integration.
- v0.5 Index lifecycle: zero-downtime reindex, exact field scoping, partition-aware sync.
- v0.6 Events: observability hooks/events, transaction-aware connections.
- v0.7 Relevance: length-aware typo tolerance, synonyms, a vocabulary table, "did you mean".
- v0.8 Search features: `suggest()`, facets with an opt-in exact `total`, federated search.
- v0.9 Security and analytics: search analytics, audit logging, rate limiting, Symfony Security
  integration, a tenant resolver.
- v1.0 Stable: a documentation site with a "Migrating from `LIKE`" guide, mutation score 90% with
  a CI gate, the backward-compatibility promise.

After 1.0: a Laravel Scout driver, and record linkage (matching people and companies across
records, with explainable match scores). Details in
[docs/roadmap.md](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md).

## Contributing and license

Tests need PostgreSQL; `docker compose up -d` in the repository root starts one. `composer qa`
runs php-cs-fixer, PHPStan (level max) and the unit tests. The unit and integration suites cover
100% of the lines in `src/`; CI fails below 90%. See
[CONTRIBUTING.md](https://github.com/er2es/fuzzphony/blob/main/CONTRIBUTING.md).

Licensed under the [MIT license](https://github.com/er2es/fuzzphony/blob/main/LICENSE).

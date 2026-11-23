<p align="center"><img src="docs/assets/hero.svg" alt="Fuzzphony: fuzzy search, in perfect harmony with your database." width="900"></p>

# Fuzzphony

[![CI](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml/badge.svg)](https://github.com/er2es/fuzzphony/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/er2es/fuzzphony/graph/badge.svg)](https://codecov.io/gh/er2es/fuzzphony)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://github.com/er2es/fuzzphony/blob/main/phpstan.neon.dist)
[![Mutation score](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fraw.githubusercontent.com%2Fer2es%2Ffuzzphony%2Fbadges%2Fmutation.json)](https://github.com/er2es/fuzzphony/actions/workflows/mutation.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/er2es/fuzzphony/badge)](https://scorecard.dev/viewer/?uri=github.com/er2es/fuzzphony)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/14933/badge)](https://www.bestpractices.dev/projects/14933)

[![Packagist Version](https://img.shields.io/packagist/v/fuzzphony/fuzzphony)](https://packagist.org/packages/fuzzphony/fuzzphony)
[![PHP](https://img.shields.io/packagist/dependency-v/fuzzphony/fuzzphony/php)](https://packagist.org/packages/fuzzphony/fuzzphony)
[![PostgreSQL 15 | 16 | 17 | 18](https://img.shields.io/badge/PostgreSQL-15%20%7C%2016%20%7C%2017%20%7C%2018-336791?logo=postgresql&logoColor=white)](https://github.com/er2es/fuzzphony/blob/main/.github/workflows/ci.yml)
[![Symfony 7.4 LTS | 8.1](https://img.shields.io/badge/Symfony-7.4%20LTS%20%7C%208.1-000000?logo=symfony)](https://github.com/er2es/fuzzphony/blob/main/.github/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/er2es/fuzzphony/blob/main/LICENSE)
![Anthropic CVP](https://img.shields.io/badge/Anthropic_CVP-Defense_Access_Verified-D97757?style=flat-square&logo=anthropic&logoColor=white)

**Fuzzy search, in perfect harmony with your database.**

Fuzzphony is a small, relevant alternative to Elasticsearch and Meilisearch for PHP and Symfony applications that already run
PostgreSQL: typo-tolerant, accent-insensitive, ranked full-text search with filters, facets and a query language. It never
alters your tables. The index lives in a sidecar table next to them, in the database you already run, so there is no search
service to add, feed or keep in step.

- **Consistent.** The index is in the same PostgreSQL as your data: no dual write, no second system that can disagree. With
  `sync: trigger` a row and its index entry change in one transaction (no lag); with the queue, within one worker batch. A
  search runs inside your transaction and sees what it sees.
- **Secure.** Authorization is built in: a tenant-scoped index refuses a search without a tenant, a field can be granted to a
  role, and a field a viewer may not see is not matched, ranked, highlighted, counted or suggested from. Tests prove a hidden
  field's content cannot change a restricted viewer's results. A search that names no actor is made as the current user.
- **Fast, and cheap to store.** A warm search takes from a few milliseconds (a rare word) to about 10 to 90 ms (typos, accents, stemming) on 200 000
  products, and the sidecar table of 500 000 products is 342 MB with every option on, about 700 bytes a row
  ([measured](docs/benchmarks.md), [what each option costs in disk](docs/benchmarks.md#what-each-option-costs-in-disk)).

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

Status: stable (v1.0.2). The public API follows [Semantic Versioning](https://semver.org/) ([what is covered](https://github.com/er2es/fuzzphony/blob/main/CONTRIBUTING.md#backward-compatibility));
changes are listed in the [CHANGELOG](https://github.com/er2es/fuzzphony/blob/main/CHANGELOG.md).

## Try the demo

A full Symfony app on a seeded catalogue of 500 000 products (about a minute to index), with an ERP-like data set and a header search box over all indexes, in one command:*

```bash
git clone https://github.com/er2es/fuzzphony
cd fuzzphony/demo && docker compose up --build    # http://localhost:8000
```

If Docker stops with `additional privileges requested`, set `export BUILDX_BAKE_ENTITLEMENTS_FS=0` (bash) or `$env:BUILDX_BAKE_ENTITLEMENTS_FS=0` (PowerShell) first
([why](https://github.com/er2es/fuzzphony/blob/main/demo/README.md#docker-build-privileges)).

\* The demo, the documentation ([docs/](https://github.com/er2es/fuzzphony/blob/main/docs/README.md)), the tests and the benchmarks live in the Git repository only: `composer require fuzzphony/fuzzphony` installs just the library (`src/`). See the [demo README](https://github.com/er2es/fuzzphony/blob/main/demo/README.md) (Windows, the large profile, security defaults; a local showcase, not a production template) and the [screenshots](https://github.com/er2es/fuzzphony/blob/main/docs/screenshots.md).

## Why

Behind most search boxes is a `LIKE '%…%'` that misses `hedphones`, `creme` and `drills`, reads the whole table and ranks nothing. The usual fix is a
search cluster: one more service to run, sync, back up and secure, and one more copy of your data. Fuzzphony does the search inside PostgreSQL instead,
without changing your schema.

A good fit when:

- The database is not yours to change (a legacy system, an ERP, tables another team owns): no column is added; with `schema: fuzzphony` not even a
  table lands in your schema.
- You are replacing `LIKE` in admin panels, back offices, CRMs and support tools, or one more service is one too many.
- The data must stay under the same backups and roles, and search must be as fresh as the data (`trigger` mode: same transaction).
- You run a shared-schema multi-tenant SaaS (the tenant filter is enforced on every search made through the library), or need a rate limit, index authorization and fields only some
  viewers may search, and want to know what people search for and do not find.
- A record has several values (addresses, phone numbers) that should be found by any value, or ranked by the best single one.
- Your content is multilingual: stemming for 28 languages, accent folding (`kavefozo` finds `Kávéfőző`).

Measured up to 1,000,000 documents per index (a typical search takes milliseconds to about a hundred; the commonest words, many-word queries, typo searches on wide
indexes and viewers with granted fields take longer, see [benchmarks](docs/benchmarks.md) and [limitations](docs/limitations.md)); beyond a few million documents per
index, measure first. A search is an ordinary read query, so throughput is that of your PostgreSQL and its connection pool. Not for analytics-style
aggregations, semantic or vector search, or a database other than PostgreSQL.

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
- Optional: Symfony 7.4+ and Doctrine (DBAL 4.2+, ORM 3.3+) for the bundle. CI tests every Symfony release the `^7.4 || ^8.1` constraint allows
  (7.4 LTS and 8.1 today) and the next minor as an early warning; a release Symfony no longer maintains leaves the constraint and CI together.

`bin/console fuzzphony:doctor` checks all of this and prints the fix for anything missing.

## Install

```bash
composer require fuzzphony/fuzzphony
```

For Symfony, add `Fuzzphony\Bundle\FuzzphonyBundle::class => ['all' => true]` to
`config/bundles.php`. The bundle uses your Doctrine DBAL connection (`fuzzphony.connection`,
default `default`).

Upgrading: `composer update`, then `bin/console fuzzphony:schema --apply`, `fuzzphony:reindex <index>` when the doctor asks, and `fuzzphony:doctor`
([UPGRADE.md](https://github.com/er2es/fuzzphony/blob/main/UPGRADE.md)).

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
   bin/console fuzzphony:reindex           # built next to the live index, swapped in; resumable
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
| `brand:logitech`, `name:"mx master"` | only in that field, typos included (an unknown field searches every field, with a warning) |
| `(mouse OR trackpad) -cable` | grouping |

Typos are tolerated per word (one from 4 letters, two from 8). A multi-word query that finds nothing drops the words that match nothing and says so.
Details: [Searching](https://github.com/er2es/fuzzphony/blob/main/docs/searching.md).

## Documentation

The [documentation index](https://github.com/er2es/fuzzphony/blob/main/docs/README.md) lists every page with a reading order:

- [Index definitions](https://github.com/er2es/fuzzphony/blob/main/docs/configuration.md): attributes, YAML, builder, bundle configuration.
- [Searching](https://github.com/er2es/fuzzphony/blob/main/docs/searching.md): the builder, query syntax, typo tolerance, "did you mean", facets, federated search.
- [Migrating from `LIKE`](https://github.com/er2es/fuzzphony/blob/main/docs/migrating-from-like.md), and recipes backed by tests: [admin panel](https://github.com/er2es/fuzzphony/blob/main/docs/recipes/admin-panel.md), [shop](https://github.com/er2es/fuzzphony/blob/main/docs/recipes/shop.md), [multi-tenant SaaS](https://github.com/er2es/fuzzphony/blob/main/docs/recipes/multi-tenant-saas.md).
- [Ranking](https://github.com/er2es/fuzzphony/blob/main/docs/ranking.md), [languages](https://github.com/er2es/fuzzphony/blob/main/docs/languages.md), [sync](https://github.com/er2es/fuzzphony/blob/main/docs/sync.md), [multi-tenancy](https://github.com/er2es/fuzzphony/blob/main/docs/multi-tenancy.md).
- [Search log and analytics](https://github.com/er2es/fuzzphony/blob/main/docs/analytics.md), [security](https://github.com/er2es/fuzzphony/blob/main/docs/security.md), [database roles](https://github.com/er2es/fuzzphony/blob/main/docs/roles.md).
- [Console commands](https://github.com/er2es/fuzzphony/blob/main/docs/commands.md), [observability](https://github.com/er2es/fuzzphony/blob/main/docs/observability.md), [integrations](https://github.com/er2es/fuzzphony/blob/main/docs/integrations.md).
- [Benchmarks](https://github.com/er2es/fuzzphony/blob/main/docs/benchmarks.md), [known limitations](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md), [architecture](https://github.com/er2es/fuzzphony/blob/main/docs/architecture.md) and [ADRs](https://github.com/er2es/fuzzphony/tree/main/docs/adr), [roadmap](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md).

## Security

- Search text is parsed by Fuzzphony and bound as a parameter; identifiers come only from validated definitions.
- Highlights are HTML-escaped (only `<mark>` remains); `$result->warnings` and `$result->interpretedAs` are plain text that may quote the user: escape them.
- Query length, term count, nesting depth and candidate sets are capped. Also set a PostgreSQL `statement_timeout` for the application's role.
- Index definitions (`fromQuery()`, `watch()` SQL, names) are trusted developer input: never build them from user input.
- Opt-in guards protect who searches: rate limit, index authorization and field-level `granted` ([Security](https://github.com/er2es/fuzzphony/blob/main/docs/security.md);
  row-level authorization is out of scope).

Report vulnerabilities privately: see [SECURITY.md](https://github.com/er2es/fuzzphony/blob/main/SECURITY.md).

## Known limitations and roadmap

Typo tolerance is trigram-based (it cannot tell a typo from a similar word), and a word with more matches than `limits.candidates` is ranked over its best
matches with a lower-bound `total`: see [docs/limitations.md](https://github.com/er2es/fuzzphony/blob/main/docs/limitations.md). PostgreSQL only; what may come next is in the [roadmap](https://github.com/er2es/fuzzphony/blob/main/docs/roadmap.md).

## Contributing and license

Tests need PostgreSQL; `docker compose up -d` in the repository root starts one. `composer qa`
runs php-cs-fixer, PHPStan (level max) and the unit tests. The unit and integration suites cover
100% of the lines in `src/`; CI fails below 90%. See
[CONTRIBUTING.md](https://github.com/er2es/fuzzphony/blob/main/CONTRIBUTING.md).

Licensed under the [MIT license](https://github.com/er2es/fuzzphony/blob/main/LICENSE).

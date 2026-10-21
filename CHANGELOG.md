# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may contain breaking
changes; they are always listed under **Breaking** and explained in [UPGRADE.md](UPGRADE.md).

## [Unreleased]

### Fixed

- **A restricted viewer's exact phrase spans two allowed fields.** An unrestricted search matches `"word1 word2"` with `word1` at the end of `title` and `word2` at the start of `body` (the fields are one combined vector); a viewer restricted to both fields did not on the strict path, only the typo-tolerant fallback sometimes did, an inconsistency. The restricted phrase is now also read from the vectors of the allowed fields joined in the index's order, as if the hidden fields did not exist, and the candidates come from the AND of its words. A hidden field never takes part: a phrase that reaches into one does not match, and what it holds (a word, or nothing) never changes what the viewer finds (a test varies the hidden field and compares hits, scores and totals). A phrase inside one allowed field, one allowed field alone, and every query without a phrase run the statements they ran before (a differential run of about 9 500 searches changed only the 53 restricted multi-field searches for `"word1 word2"`, all of them gaining matches). With a hidden field between two allowed ones the restricted phrase can match where the unrestricted one (which sees the hidden text in between) does not; the typo-tolerant similarity of a phrase across two fields stays per field. See [Known limitations](docs/limitations.md#field-authorization-hides-content-not-existence-or-timing).

- **A query made only of stop words finds something.** With `english`, `the who`, `who` or `to be or not to be` found nothing, not even a title "The Who": the text search configuration drops the words from the query and from the indexed text, and the typo-tolerant branch dropped them too. When every word of the query is a stop word, the typo-tolerant branch now keeps them as ordinary words (it works on the normalised text, stop words included), so the query is searched there: as a look-alike, ranked by similarity, within `candidate_limit`, with the filters, the tenant and a restricted viewer's fields applied as for any word. Only for such a query and only when the index has a typo-tolerant field and `fuzzy_mode` is not `never`; every other query (one real word, an excluded real word, no typo tolerance) runs the very statements it ran before (a differential run of about 9 500 searches over three indexes, three fuzzy modes, five viewers, filters, facets, exact counts, relaxation and highlights changed only 490 searches, all of stop words and all empty before). A stop word shorter than `fuzzy_min_length` (3) still has to match exactly, so `to be or not to be` finds nothing unquoted and the quoted phrase finds it. See [Known limitations](docs/limitations.md#stop-words-are-ignored-and-only-a-query-of-nothing-else-finds-them).

## [0.9.6] - 2026-10-21

Who searched what (the report, the optional masked client IP, the demo), the documentation round (an index, the "Migrating from `LIKE`" guide, three recipes, a link check in CI) and a reviewed list of known limitations. Nothing breaks. See [UPGRADE.md](UPGRADE.md#from-095-to-096) for the one optional step (`fuzzphony:schema --apply`, only to store the client IP).

### Added

- **Guide: [Migrating from `LIKE`](docs/migrating-from-like.md).** Step by step from `WHERE name ILIKE '%x%'` to Fuzzphony
  (index, build, query, entities in rank order, pagination, sync, tests), a table that maps `LIKE` patterns to the query syntax
  (and says what is not supported), and a rollout plan (shadow search, feature flag, measuring, rollback). Every PHP and YAML snippet
  and every query of the table is run by `tests/Integration/Docs/LikeGuideTest.php`, which fails when the guide shows other code.
- **Recipes** in [docs/recipes](docs/recipes): an admin panel (API Platform search filter), a shop (facets, search as you type, synonyms, ranking, federated search, the Live Component) and a multi-tenant SaaS (tenant, actor, guards, search log, reports). Their code lives in `tests/Recipes` and runs in a new `recipes` test suite (`composer test:recipes`, included in a plain `phpunit` run); a test fails when a code block of a recipe differs from that code.
- **Who searched what.** `SearchAnalytics::recentSearches()` lists the most recent logged searches of an index in a time window,
  newest first, as `LoggedSearch` objects (`at`, `index`, `tenant`, `user` with null for anonymous, `ip`, the stored (redacted)
  `query`, `hits`, `tookMs`, `usedFuzzy` and `status`). Filters: `user:` (exactly that name), `anonymousOnly:` and `status:`
  (the refused searches, `denied` and `rate_limited`, are included by default); `limit` 1 to 1000 (default 50). It follows the tenant
  rule of the other reports to the letter (a tenant-scoped index needs a `tenant` or an explicit `allTenants: true`; a call that
  names a tenant never returns another tenant's rows, even for a user of the same name; on an index that is not tenant-scoped a call
  without a tenant covers everyone, as the other reports do), and the user, the tenant, the status and the limit
  are bound parameters. The store side is a new optional interface, `Fuzzphony\Core\Engine\RecentSearchStore` (the PostgreSQL engine implements it; `SearchLogStore` is unchanged, so a custom store keeps working, and `recentSearches()` throws `InvalidArgument` naming the interface for a store without it). It needs
  no new index. A per-user history is personal data: see [SECURITY.md](SECURITY.md) and [Search log and analytics](docs/analytics.md#privacy-the-log-is-personal-data).
- **`fuzzphony:analytics --recent`** with `--user=`, `--anonymous`, `--status=`, `--since`, `--limit`, `--tenant` / `--all-tenants`
  and `--format=table|json`: the same report on the console (control characters of the typed text are shown as U+FFFD in the table).
- **The client IP in the search log (opt-in).** `fuzzphony.search_log.ip: off|masked|full` (default `off`: nothing is stored, even
  when the actor carries an address). `masked` zeroes the last octet of an IPv4 address and keeps the first 48 bits of an IPv6
  address (an IPv4-mapped IPv6 address is masked as IPv4); `full` keeps the valid address; anything that is not a valid IP
  address is never stored (`full` keeps the canonical form of the address, so two spellings of one IPv6 address are one value; a 6to4
  address, `2002::/16`, has the last octet of its embedded IPv4 address masked as well, because the plain /48 would keep all of it;
  an IPv6 /48 usually identifies one customer site, so masked IPv6 is less private than masked IPv4). The Core stays HTTP-agnostic: `Actor` has a new optional trailing `?string $ip = null` (nothing
  changes for existing calls), `SearchRecord` has `?string $ip = null`, and `SearchGate` takes an `IpMode` (new public enum, the
  masking is `IpMode::apply()`) and applies it before anything is stored. The Symfony bundle fills the address from
  `Request::getClientIp()` (so `framework.trusted_proxies` applies; `X-Forwarded-For` is never read directly) for the actor
  the configured resolver returns, only when the mode is not `off`. The table gets a nullable column `actor_ip`: existing installs
  add it with `fuzzphony:schema --apply` (an `ADD COLUMN` that runs only when the column is missing, so a repeated apply takes no lock on the table); an install that does not use the IP does not need it (the
  `INSERT` names the column only when a record has an address, and the report reads it only if the table has it and the role may
  read it). Turning `ip` on before applying the schema loses nothing but the addresses: the write that finds no column is repeated
  once without it (metric `fuzzphony.search_log.ip_dropped`), and the doctor's "Search log" check is an error while `ip` is on and
  the column is missing. The retention
  purge deletes whole rows, so it covers the IP. See [The client IP](docs/analytics.md#the-client-ip).
- **Demo: who searched what.** The Analytics page has a new section "Who searched what": the latest 20 searches of the `catalog` and
  `intranet` indexes (when, who, tenant, index, query, hits, status, masked client IP), newest first, with link filters for the index,
  the viewer (anonymous, Ada, Hana, Eve, Bob) and the status (answered, denied, rate limited), and a note that it is personal data
  (retention 30 days, e-mail redaction, masked IP). The demo turns on `search_log.ip: masked`. The Security page's searches are now
  logged: it used to call `explain()`, which records only refusals, so an answered search left no row; it now runs `get()` as the viewer
  (one row with the viewer as the user, the company as the tenant and the masked address; a refusal is a `denied` / `rate_limited` row) and
  takes the SQL from an unlogged search with the same tenant and fields, so the typed text is logged once and the rate limit is charged
  once. The demo sets no `trusted_proxies` on purpose (nginx hands php-fpm the client address as `REMOTE_ADDR`, and a private-range list
  would make a forged `X-Forwarded-For` believed). The Observability page points to the new section. Rows of the home page are
  anonymous and carry no address (only searches made `->asActor()` do). `flows.mjs` has 13 more checks.
- **A documentation index, [docs/README.md](docs/README.md).** GitHub shows it when the `docs` folder is opened: every page in a reading
  order, grouped (getting started, concepts, guides and recipes, reference, operations), with the question each page answers. The
  README's Documentation section points to it and lists the LIKE guide and the three recipes; the recipes link back to the index.
- **A link check for the documentation**, `php tools/check-docs-links.php` (no dependencies, no network), and the `docs-links` workflow that runs it
  on every pull request and on `main`. It checks every relative Markdown link and image, every `#anchor` (GitHub's heading slugs, `-1`
  for repeated headings, explicit `id` / `name` anchors) and every absolute link to this repository (`.../blob/main/...`, `.../tree/main/...`);
  links to other sites are not checked, and neither is code (fences, inline code). Its parser and slug rules are unit-tested
  (`tests/Unit/Docs/DocsLinkCheckerTest.php`); `tools/` is analysed by PHPStan and php-cs-fixer like `src/` and `tests/` and is not part of the package download.

### Changed

- [Console commands](docs/commands.md) lists every option of `fuzzphony:analytics` (`--recent`, `--user`, `--anonymous`, `--status`). The Live Component test kernel no longer registers the `@Fuzzphony` Twig path by hand: the bundle's own `getPath()` is enough (tests only).
- A synonym member that is one plain word of more than 256 characters is now cut at 256 characters, as any other member text already was (the plain-word fast path of `SynonymExpander` kept it whole, so the same word followed by a space was cut and the word alone was not). Both paths now give the same words; a test compares them.
- [Known limitations](docs/limitations.md) reviewed line by line against the code and grouped by topic: the candidate limit keeps the first matches the index returns, not the best ones (a better match can be missing; the page said "approximate", and promised a fix that shipped in 0.8 as `exactCounts()`), a phrase across two allowed fields for a restricted viewer, `mose` ties with `monitor` and `mower` instead of ranking below the mice; new entries for stop words, whole-word matching, typo-tolerant highlights, unstable scores, query cuts, the registry cache in production, process caches, `explain()` as not a stable API, the search-log IP and per-person history, the rate limit by tenant or user, deny-by-default authorization and tenant isolation. Documentation only.
- **The registry cache (0.9.5) serves web requests only.** A console command, a worker or a Messenger consumer (`PHP_SAPI` is `cli`) now always discovers the `#[Searchable]` entities fresh and neither reads nor writes `cache.system`, so a deploy that runs `fuzzphony:schema --apply` before `cache:clear` no longer builds the schema from stale definitions; production web requests behave exactly as before (`cache:clear` is still what refreshes them). No configuration option: the container parameter `fuzzphony.registry_cache_in_cli` is an `@internal` test seam (the real-kernel test proves the cache from the command line).
- **`PostgresEngine::resetCaches()`.** The engine remembers the stop words of the text search configuration, the stems of the synonyms and the synonym lookup tables for the life of the process, assuming the configuration does not change. The new `@internal` method forgets them, for an application that changes the text search configuration or a dictionary while a long-lived process runs (or restart the process). Nothing calls it by itself, not even `kernel.reset`: a long-lived worker keeps the caches on purpose, the prepared synonym lookup and the stop-word answers would otherwise be paid again on every request (a test pins that the engine is not tagged `kernel.reset`).

## [0.9.5] - 2026-10-20

The performance round, a bug fix for the Doctrine ORM wiring, and Symfony 8.0 leaves the supported versions. See [UPGRADE.md](UPGRADE.md#from-094-to-095) (nothing to do in most applications).

### Changed

- **Fewer database round trips per search** (no change in results, order, counts, warnings or API). A plain search used to cost 5 round trips for one `SELECT`; it costs 1 now, a typo-tolerant one 35 down to 14, a search with two facets 15 down to 3 (measured on PostgreSQL 17, counted with `log_statement = 'all'`, see [Benchmarks](docs/benchmarks.md#round-trips)).
  - `PdoConnection` sends its statements without PDO's server-side prepare on `pdo_pgsql` (`PDO::PGSQL_ATTR_DISABLE_PREPARES`, set per statement): one call instead of Parse + Bind/Execute + `DEALLOCATE`, with the same server-side parameter handling. The attribute is **not** set on your PDO, so your own statements on the same connection are prepared as before. DBAL's `pdo_pgsql` driver already does this by default, so `DbalConnection` is unchanged.
  - A statement that sets no similarity threshold (every strict full-text search, the facets, the highlight and count statements) no longer runs inside its own `BEGIN` / `COMMIT`. A statement that sets the threshold, and any search inside the caller's transaction, behave exactly as before.
  - The stop-word lookup of the typo-tolerant branch (which leaf tsqueries the text search configuration reduces to nothing) is asked once per tsquery and engine instead of once per search, and only for the tsqueries not seen yet. The answer depends only on the text search configuration, which does not change while a process runs; the cache holds at most about 5 000 entries per configuration and starts over beyond that.
  - The digest that identifies an index's synonyms is computed once per `Synonyms` object instead of on every search (about 1 ms for 5 000 groups).
  - What a round trip is worth is the network latency to the database: on a local PostgreSQL (about 0 ms) the time per search was a little lower in every run we made (a few milliseconds, within the noise of single runs), on a database across a network it is the saved round trips times the latency (12 round trips fewer at 1 ms are 12 ms for a search with two facets).
- [Security](docs/security.md#the-rate-limit): the Symfony rate limiter keeps its state in the `cache.rate_limiter` pool, which is a filesystem cache by default (a file is locked and written on every search); the docs now recommend an APCu or Redis `cache_pool`.
- **Facets share one statement.** The facets that count the search's own candidates (every facet whose filter has no condition in the search, with or without text, with `exactCounts()` or without, for a restricted viewer, on a tenant index) are computed by one statement that evaluates the candidates once and groups them per facet, where each facet used to run its own statement that repeated the whole candidate query. A facet whose own filter has a condition keeps its own statement (it leaves that condition out, so it counts other candidates), and a search with a single such facet runs exactly the statement it ran before. The facets (values, types, counts, order, `facetValues` limit, `null` values) are identical: a differential test compares every facet with the per-facet statement over a matrix of searches. Measured on the 100 000-row benchmark (local PostgreSQL, no network latency; two runs each, before to after): `wireless` with 2 facets 27-35 to 20 ms; with 3 facets on a 5-filter index 47-81 to 22-24 ms; with 5 facets 75-84 to 24-26 ms; the typo search `wireles mose` with 5 facets 299-338 to 126-143 ms; 5 facets of which one has a condition (4 share) 67-76 to 47-69 ms. A search with 2 or more shared facets also saves one database round trip per facet beyond the first (`wireless` with 2 facets: 3 statements, now 2). `explain()` lists the shared facets as one statement labelled `facet: color, size, active` (a facet on its own is still `facet: color`); the statements of `explain()` are not a stable API.
- **Faster registry in the Symfony bundle (production only).** Every request that used Fuzzphony discovered the `#[Searchable]` entities again, which loads the metadata of every entity in the application. With `kernel.debug` off, the discovered index definitions are now kept in the `cache.system` pool, so `cache:clear` (part of every deploy) drops them like Doctrine's metadata cache; in debug mode nothing is cached, and YAML indexes are always rebuilt. Measured (PHP 8.4, opcache, warm Doctrine metadata cache, 3 searchable entities): about 0.6 ms per request with 20 mapped entities, 2.0 ms with 100 and 8 ms with 300, now one cache read (unserializing the definitions takes about 0.05 ms); on a machine without opcache the 100-entity figure was 13 ms. No configuration, no API change. If you edit `#[Searchable]`/`#[SearchField]` (or a synonyms file an attribute names) on a production server, run `cache:clear`.
- Development dependency: PHPUnit `^13.0` (it was `^11.5 || ^12.0`). It is a `require-dev` entry, so it affects only people who work on Fuzzphony and the CI, not the applications that install it; `composer update -W` is done for the repository and the demo.
- **Symfony 8.0 is dropped** from the supported versions: the constraints are `^7.4 || ^8.1`, the CI matrix, the README badge and the
  docs say 7.4 LTS and 8.1. Symfony 8.0 no longer receives fixes, and every 8.0.x is affected by four `symfony/yaml` advisories
  (Composer refuses to install it). See [UPGRADE.md](UPGRADE.md).

### Fixed

- **Doctrine ORM wiring in a real Symfony kernel.** `FuzzphonyBundle` asked `$builder->hasExtension('doctrine')` while loading its services, but Symfony hands an extension a temporary container without any extensions, so the answer was always "no" in an application: `#[Searchable]` discovery (`discover_entities`), `EntityLoader` and the ORM sync listener were never registered, and only the container-definition tests (which load the extension straight onto a container that has a fake `doctrine` extension) saw them. The prepend phase, which runs on the real container, now records that the ORM is configured (`doctrine.orm` is set; DoctrineBundle without `orm:` has no entity manager to wire to; the container parameter `fuzzphony.doctrine_orm`) and the load phase reads it. An application that relied on YAML `indexes` alone is unaffected; one that has `#[Searchable]` entities, `sync: orm` or `EntityLoader` now gets them without any change. A test boots a compiled production kernel with a real `cache.system` to prove it, including the registry cache above (written on the first use, read by a fresh kernel without touching the metadata, absent in debug mode, gone after `cache:clear`).

## [0.9.4] - 2026-10-14

No upgrade steps. The repository is now one Composer package (`fuzzphony/fuzzphony`), and some dead code is gone.

### Changed

- Dead and redundant code removed (no behaviour change, no API change): the `relaxed: ` prefix on the `browse` statement (a relaxed search never browses) and a no-op `array_diff_key()` in the did-you-mean lookup (`PostgresEngine`); an unreachable default and an unreachable `null` check in the fuzzy query compiler; the `null` arm of the YAML exporter (the exported array holds no `null`); the redundant `array_values()` in `SearchQuery::highlight()` and `facets()` (`copy()` already normalises the lists); a guard in `fuzzphony:wizard` that could not be false. `AuthorizationGuard::check()` now says why it ignores `$charge`.
- **One Composer package.** The repository is published as `fuzzphony/fuzzphony` only: the `replace` block (`fuzzphony/core`, `postgres-engine`, `doctrine-bridge`, `symfony-bundle`), the dev dependency `symplify/monorepo-builder`, the four `src/*/composer.json` files and the `split.yml` workflow (it targeted split repositories that never existed) are removed. `docs/architecture.md` now describes `Core`, `Engine/Postgres`, `Bridge/Doctrine` and `Bundle` as layers of one package. The demo requires `fuzzphony/fuzzphony` through one path repository to the repository root (its image build copies the root `composer.json` and `src/`); with the live-edit compose file, an existing `vendor` volume is refreshed by the `composer install` of the first start. Nothing changes for applications that `composer require fuzzphony/fuzzphony`.
- `SECURITY.md`: security fixes are released for 0.9.x (it still said 0.8.x), and the trust model now covers the 0.9 features: authorization (what it guarantees and what it does not) and the search log.

## [0.9.3] - 2026-10-14

Documentation only. No upgrade steps.

### Added

- [Screenshots](docs/screenshots.md) of the demo (search, synonyms and the "Under the hood" menu, relevance, languages, tune), linked from the README and the demo README.

## [0.9.2] - 2026-10-14

Tests and documentation only: the library's code and API do not change. No upgrade steps.

### Changed

- README, "Try the demo": the Windows commands (`cmd` and PowerShell) for the `BUILDX_BAKE_ENTITLEMENTS_FS` variable (`setx` keeps it), `DEMO_ROWS` and the large profile.
- **Mutation testing:** 134 new tests (49 files named `<Subject>MutationsTest.php`) kill 117 of the mutants that escaped the full run on 0.9.1 (246 of 4 891 covered mutants, 94% covered-code MSI). The mutants that still escape are equivalent ones (a cast on a value that is already that type, array keys that are never read, a branch only a real clock would show); the dead or redundant code they point at is left as it is and listed in the repository's notes for a later cleanup.

## [0.9.1] - 2026-10-14

No upgrade steps. Two small follow-ups to the 0.9 search log.

### Added

- **`FederatedSearch::unlogged()`**: keeps every per-index search of a federated search out of the search log (a refusal is
  still recorded as a `denied` row), like `SearchBuilder::unlogged()` does for one index.

### Fixed

- The wizard exporters (`ArrayExporter`/YAML, `AttributeExporter`, `BuilderExporter`) keep `log: false`: an index kept out of
  the search log no longer comes back logged after an export.

## [0.9.0] - 2026-10-14

Parts 1 (identity), 2 (search log and analytics) and 3 (guards) of 0.9. Upgrade: nothing, unless you enable the log: run `fuzzphony:schema --apply`. See [UPGRADE.md](UPGRADE.md#from-08-to-09).

### Added

- **The actor.** `Fuzzphony\Core\Search\Actor` (a tenant and a user, both optional), `ActorResolver`, and
  `SearchBuilder::asActor()`, which sets the tenant of a tenant-scoped index and keeps the user on the query
  (`forTenant()` stays; the last of the two wins). A search with an actor but no tenant on a tenant-scoped
  index throws `ActorRequired` (an `InvalidQuery`); without an actor the original message stays. See
  [Multi-tenancy](docs/multi-tenancy.md#the-actor-who-is-searching).
- **`fuzzphony.actor`** (bundle): `resolver: <service id>` for your own `ActorResolver`, or `tenant:` and `user:` property paths
  read on the Symfony Security user (`symfony/security-bundle` and `symfony/property-access` are `suggest`ed; a missing
  package fails at container build with the `composer require` line). Nothing configured: an anonymous actor.
- **Tenant-scoped indexes through the integrations.** The API Platform `FuzzphonySearchFilter` and the Live
  Component resolve the actor on the server on every request (the Live Component never takes the tenant
  from the client), and `fuzzphony:search` gets `--tenant` and `--user`. A refusal is a 403
  (`AccessDeniedHttpException`) in the HTTP integrations, and a message with exit code 1 in the command.
- **`SearchGate`** (public API): every search, `explain()` and federated search passes through it when
  `Fuzzphony` is built with one (the bundle does); it checks the actor and is where parts 2 and 3 hook in.
- **`fuzzphony.search.denied`**: a counter (labels `index`, `reason=actor_required`) and a log line per refusal.
- **The search log** (opt-in, `fuzzphony.search_log`: `enabled` (default `false`), `retention_days` (90), `redact`). Every search and every
  refusal is one row of `fuzzphony_search_log` (time, index, tenant, user, the text the visitor typed, normalised text, hits,
  time taken, whether the typo-tolerant search ran, status `ok` / `denied` / `rate_limited`), created by `fuzzphony:schema --apply`
  when enabled. Per index `log: false` (attribute, builder `->log(false)`, YAML). The bundle collects the rows of a request
  and writes them in one batched `INSERT` (1000 rows a statement) after the response (`kernel.terminate`, `console.terminate`), and
  also at 1000 buffered rows and on `kernel.reset` (a worker or a long-running server never grows the buffer; a `SIGKILL` still loses
  what it holds); the `id` is an identity column, so a role that only searches needs just `INSERT` on the table (no sequence privilege);
  a failed write never touches the search: it is counted as `fuzzphony.search_log.failed`, logged and dropped. `SearchLog`,
  `SearchRecord`, `RecordStatus` and the `SearchLogStore` engine capability are public; `SearchGate` takes a
  log and a redactor. A "Search log" check in `fuzzphony:doctor` (an error when the table is missing, a warning for rows older
  than the retention or when the role cannot read the table; it never counts the rows, so it is cheap on a big log). See [Search log and analytics](docs/analytics.md).
- **`QueryRedactor` and `PatternQueryRedactor`**: search texts are redacted before anything is stored; the preset `email` and your
  own regular expressions replace matches with `[redacted]` (an invalid pattern fails the container build).
- **`SearchAnalytics`** (`Fuzzphony::analytics()`): `topQueries()` and `zeroResultQueries()` over a time window, grouped by the
  normalised text, returning `QueryStat` (query, searches, average hits, last seen), and `purge()`. A tenant-scoped index needs a
  `tenant` or `allTenants: true`; a `tenant` filters on any index (the log records the actor's tenant on a shared index too), and a
  `tenant` together with `allTenants: true` throws `InvalidArgument`.
- **`fuzzphony:analytics <index> [--top|--zero] [--since=7d] [--limit=20] [--tenant=ID|--all-tenants] [--format=table|json]`** and
  **`fuzzphony:search-log:purge`** (deletes rows older than `retention_days`; for cron). Both exist only while the log is enabled.
- **`SearchBuilder::unlogged()`** keeps one search out of the search log, for the requests of a live search box that fire on every
  pause in typing (`SearchQuery::$log`, `SearchQuery::unlogged()`); a refusal (`denied`) is recorded all the same. The Live Component
  has a `log` attribute (default `false`: its searches are unlogged; `log="true"` records them; never writable from the browser).
  In the demo, the live search and the suggestion dropdown are unlogged and the search that settles (an example click, a full page load with Enter, or the live search's silent repeat after 1.5 s without typing) is logged.
- **Guards** (opt-in): `SearchGuard` (`check(IndexDefinition, Actor, bool $charge): Access`) and `Access` (`all()`, `only()`, intersected
  across guards), `AccessDenied` and `RateLimited` (`$retryAfter`) exceptions, all public; `SearchGate` takes `guards`. They run for a
  search made `asActor()`, in `get()`, `explain()` and `suggest()` (a federated search charges the rate limit once). The tenant check
  comes before them. `Fuzzphony::suggest()` takes a trailing `bool $charge = true` (and `SearchGate::accessCounted()` too): pass `false`
  only when the same request already charged a search; the Live Component does, so a render costs one rate-limit token, not two.
  See [Security](docs/security.md).
- **`fuzzphony.rate_limit`** (`limiter`, `per: tenant|user`): one token of a Symfony rate limiter per search, keyed by the tenant or the
  user (no key: not limited); over the limit a 429 with `Retry-After`. `explain()` charges too. Any `framework.rate_limiter` works, a
  `compound` one included. Needs `symfony/rate-limiter` (suggested).
- **`fuzzphony.authorization`** (`enabled`, `index_attribute`, default `FUZZPHONY_SEARCH`): asks Symfony's authorization checker
  `isGranted(attribute, indexName)` for the index (403 when denied; with the default attribute and no voter every search is denied, and no
  token is denied too: the guard checks the token storage itself, because Symfony's checker would let the voters decide for an empty token,
  so a request outside any firewall never searches a protected index) and `isGranted(field.granted, indexName)` per field. Needs
  `symfony/security-bundle` (suggested).
- **`granted`** on a field (`#[SearchField(granted:)]`, `IndexBuilder::field(granted:)`, YAML `granted:`, the wizard's exporters): the
  attribute a viewer needs to search and see it. Not part of the fingerprint: no schema change, no reindex.
- **The restricted path** (PostgreSQL): a viewer who is not granted a field does not match, rank (scores, bonuses, breakdown), highlight,
  count (total, facets, exact counts) or get suggestions from it (no "did you mean", `suggest()` returns `[]`, the Live Component offers no
  completions); an excluded word never excludes a document by a hidden field. `explain()` returns the statements and a note instead of the
  plan. Filters and facets are not restricted. A viewer who may see every field takes the unchanged 0.8 path (a test compares the statements).
- **`SearchBuilder::restrictedToFields()`** (`SearchQuery::$allowedFields`): the same restriction for application code.
- **Metrics and logs for refusals:** `fuzzphony.search.rate_limited` (label `index`), and `fuzzphony.search.denied` now also with
  `reason=authorization`; a warning log line and a `denied` / `rate_limited` search-log row for each, also for an unlogged search.
- **403 and 429 mapping:** `AccessDenied` is a 403 and `RateLimited` a 429 with `Retry-After` in the HTTP integrations.
- **Demo: the Analytics page** (under "Under the hood", `/under-the-hood/analytics`): the most searched texts of the last seven days and the searches that found nothing, from the search log (`search_log` is enabled in the demo with 30 days of retention and `redact: [email]`). A demo database from before 0.9 shows a hint to run `fuzzphony:schema --apply` instead of failing; `flows.mjs` has checks for it (36 checks: it searches a word unique to the run and looks for exactly that word). The demo's own measuring searches (the Benchmark rows, the extra searches of Relevance) are unlogged, so they do not skew the page.
- **Demo: the Security page** (under "Under the hood", `/under-the-hood/security`): the worked example of [Security](docs/security.md). A small `intranet` index (24 notes of two companies, `demo/sql/demo_note.sql`, seeded and indexed by the init like the other demo tables) is searched by one of five fixed viewers chosen on the page and kept in the session (no passwords): Anonymous, Ada, Hana and Eve, a guest without `ROLE_USER` (Acme), Bob (Globex). It shows the tenant (the company: Ada and Bob find different notes for `budget`), the permission (Eve gets the `AccessDenied` card; an anonymous visitor is refused earlier with `ActorRequired`, because the tenant check comes before the authorization; the `confidential` field has `granted: ROLE_HR`, so for Ada `salary` finds nothing and her SQL has no column of that field, while Hana finds notes and sees the highlight) and the rate limit (five searches in 10 seconds per viewer: the sixth shows the seconds to wait as a countdown that runs in the page and, at zero, offers to search again). It uses the real configuration (`fuzzphony.actor`, `fuzzphony.authorization`, `fuzzphony.rate_limit`, a `SearchVoter`, a Security firewall that covers that page only); only the searches of this page are made `asActor()`, so the demo's other pages are unchanged. The demo now requires `symfony/security-bundle`, `symfony/rate-limiter` and `symfony/property-access`. `?reset=1` forgets the current viewer's searches of the 10-second window and redirects, so a reload does not reset again (demo only). The countdown is a small Stimulus controller (`countdown_controller.js`); the design system gained a `.button.small` variant. `flows.mjs` has 16 checks for it (55 checks); `shots.mjs` takes the page for the default viewer, Hana, Ada and Eve. The Analytics check of the nonsense word no longer claims it is absent from the most-searched table.

### Changed

- CI tests Symfony 8.0 again (on the newest PostgreSQL, PHP 8.4 and 8.5), so the list of tested releases is the list the `^7.4 || ^8.0` constraint allows: 7.4 LTS, 8.0 and 8.1, and the README badge says so. 0.8.2 had dropped 8.0 from CI while the constraint still allowed it, which promised more than was tested. From now on a Symfony release leaves the constraint and CI together, in a minor release of Fuzzphony.

- `InvalidQuery` is no longer `final` (`ActorRequired` extends it).
- `Fuzzphony::suggest()` takes an optional trailing `?Actor`: a restricted viewer gets `[]`, a refused one an exception.
- **Highlight names are validated upfront**, for every viewer: `highlight('nosuch')` or a field with `highlight: false` throws
  `InvalidQuery` even when the search finds nothing (before, only when there were hits).
- The typo-tolerant candidate probes apply the search's tenant and filters, so `explain()` no longer shows other tenants' ids (a
  disjunctive facet whose conditions differ from the search's uses the trigram condition instead of the ids).

### Fixed

- Demo, Analytics: the page stayed empty for a visitor who only typed on the home page (the live requests are unlogged and only Enter logged). A search now counts when you click an example (that request is logged), press Enter, or stop typing for about a second (the live search repeats its request silently with `X-Settled: 1` and that one is logged; it is sent at once when the page is left sooner); the half-typed words are still not.
- Demo, Languages: the list of every product of a language (the disclosure) had bullets and no padding; it is plain rows in columns now, inside the card.
- Demo, Tune: the "out of stock" facet chip was plain text; it is a button now, like "in stock" (each narrows the search; "in stock only" wins when both are set).

## [0.8.4] - 2026-10-12

Demo only; no upgrade steps.

### Fixed

- Demo, Tune: the speed figure, the facet chips, the hits and the legend were run together (the results area had lost its spacing in the 0.8.2 rebuild); they have their gaps back and the chips wrap. Code blocks (the SQL and the plan, the wizard output) wrap long lines instead of scrolling sideways.

## [0.8.3] - 2026-10-12

No upgrade steps. One performance fix in the library, the rest is the demo.

### Fixed

- **Typo tolerance no longer reads 100 000 rows to find three.** The trigram index returns, for a word that needs a higher similarity
  than the lowest word of the statement (pg_trgm has one threshold per session), every row that merely starts alike; PostgreSQL read
  and rechecked all of them, over a second per statement and three statements per search (the page and each facet). In the demo's
  1 000 003-row profile, `tv` (whose synonyms `television` and `telly` turn the fallback on) took 4.9 s. Each typo-tolerant word is now
  asked for its matches alone first, at the similarity of its own length, and the search uses the ids (`s.id = ANY(...)`): the same
  documents in the same order (checked on that catalogue: identical results, 0.23 s), also for the alternatives a synonym adds. A word
  with more matches than the candidate limit keeps the trigram condition as before. The probes are not listed in `explain()`; a search with
  typo tolerance makes one extra round trip per word.

### Changed

- **Demo: one loading language.** Waiting on the server looks the same on every page: after a short delay a hairline bar runs along the top,
  the page dims (the field too, though it stays usable), the field shows a spinner (a page without a field gets one of its own) and the status pill at the bottom says what is going on, driven by `aria-busy` (the live search, Tune, the Speed bars and page navigations). Tune had lost
  its loading indication when the Live Component (UX 3) moved from a `busy` attribute to `aria-busy`; that is fixed, and `busy_controller.js`
  and the separate "Updating…" pill are gone.
- **Demo: the "Under the hood" menu** lists the Config wizard, **Benchmark**, Doctor, Observability and **Synonyms**. The benchmark is a
  page of its own again (`/under-the-hood/benchmark`; the Speed view of the search links to it) and the synonym editor moved there from
  `/relevance/synonyms` to `/under-the-hood/synonyms` (no redirects). The "/ to search" hint in the toolbar is removed (the shortcut still works).
- `demo/tools/flows.mjs` checks the loading behaviour and the menu (32 checks); `shots.mjs` lists the current routes.

## [0.8.2] - 2026-10-12

No upgrade steps for the library. The demo's routes changed (below).

### Fixed

- `SearchBuilder::explain()` of an `asYouType()` search returned the "did you mean" text with the prefix alternative the engine saw
  (`wireless (mouse | mouse*)`); like `get()`, it now returns `wireless mouse`. Found by looking at the demo's Tune page.

### Changed

- **Demo interface, rebuilt** (the design and its reasoning: `docs/superpowers/specs/2026-10-12-v082-demo-interface-design.md`). A search home
  where the results appear in place while you type, with the facets at the side and the category illustrations; a Speed view that measures the
  same search against `ILIKE` and runs the benchmark on demand; Relevance with the synonym editor; Languages with the federated view; Tune; and
  an "Under the hood" menu (wizard, doctor, observability). Nine navigation items became four plus the menu. A native-CSS design system
  (tokens with `light-dark()`, `@layer`, cross-document view transitions), the system font stack and one inline SVG sprite; a tab bar on a
  phone; light and dark; reduced motion. **The routes changed** (no redirects): `/` is the search, `/relevance`, `/relevance/synonyms`,
  `/languages`, `/tune`, `/under-the-hood/wizard|doctor|observability`; `/compare`, `/playground` and `/benchmark` are gone (the benchmark is the
  search page's `?view=speed&run=1`). `demo/tools/shots.mjs` takes screenshots of every page and runs axe-core.
- CI tests the supported Symfony versions the way a Symfony library should: the LTS (7.4) with every PHP and PostgreSQL version,
  the current stable (8.1) on the newest PostgreSQL, and the next minor (8.2, in development) in a job of its own that may fail
  without failing the build (an early warning before it is released). Symfony 8.0, end of life since July 2026, is no longer tested;
  the composer constraint still allows it. The test matrix has 11 jobs instead of 16.

## [0.8.1] - 2026-10-12

No upgrade steps. The library gains search-as-you-type matching; the rest is the demo, the dependencies and the tests.

### Added

- **Search-as-you-type matching:** `SearchBuilder::asYouType()` (and `SearchQuery::asYouType()`): the last word of the
  text, when it ends the text, matches as the word itself (typo tolerance, synonyms, "did you mean") or as the
  beginning of a longer word (`cr` finds `Crème`, `wireless hea` finds `Wireless headphones`); a field-scoped or
  excluded word is only a prefix; nothing changes after a space, inside a quoted phrase or after an operator.
  `didYouMean` comes back without the prefix alternative (`SearchResult::withDidYouMean()`). The Live Component
  does it by default (`asYouType="false"`), `fuzzphony:search` has `--as-you-type`. See
  [Search-as-you-type](docs/searching.md#search-as-you-type), which also says who does what: the library gives the
  completions, the matching and the facets, the dropdown is the application's template.
- **Dependency audit in CI** (`.github/workflows/audit.yml`): `composer audit` for the library's dependencies and for the demo's
  lock file. On pull requests and pushes it only warns (an annotation and a summary on the run; it is not part of `ci-ok`, so
  a new advisory never turns an unrelated pull request red); every Monday, and on demand, it fails when an advisory is
  open, which GitHub emails to the maintainers.
- Demo: `docker-compose.large.yml`, an opt-in profile for `DEMO_ROWS=1000000` on a machine that gives Docker 8 GB or more.
- Demo: one search-as-you-type dropdown on every search box (Compare, Relevance, Languages, Synonyms, Playground),
  replacing the browser's `<datalist>`: the completions of the word being typed, the categories it is found in (Compare)
  and the first hits, built from `suggest()`, `asYouType()` and a facet. The Compare and Playground searches match the
  word being typed (`cr` finds `Crème`).

### Tests

- 459 new tests (1 177 to 1 636), written against the survivors of a full mutation run (5 987 mutants, 734 escaped, covered-code
  MSI 83%); the next full run in CI (5 989 mutants) scores **96%** covered-code MSI, which is what the README badge shows: defaults and boundaries of the configuration, the commands' output, the wizard's heuristics and exporters, the doctor's checks,
  the PostgreSQL introspection, the Doctrine bridge, the query parser and the new 0.8 code. The ~110 mutants that still escape in the files
  worked on are equivalent (a cast that changes nothing, an `array_values` whose keys are never read, a constant only a real sleep would show).
  The whole suite now also passes on Windows (command output is compared with normalised line endings).

### Changed

- Demo stack brought up to date: Symfony 8.1, Symfony UX 3.5, DoctrineBundle 3, PHP 8.5 (OPcache is built in, with the tracing
  JIT enabled), nginx 1.31, in the immutable image. Verified by building the images and running the whole stack
  (`init`, schema, reindex, doctor, every page). The library's own development dependencies were updated too
  (`composer update -W`: Symfony 8.1, PHPUnit 12.5, PHPStan 2.3, Doctrine DBAL 4.5 / ORM 3.7, API Platform 5.0).
- The Live Component's search-as-you-type matches the last word as a prefix too by default (`asYouType`, was
  whole words only). `asYouType="false"` restores the old behaviour.

## [0.8.0] - 2026-10-10

**Additive, no breaking changes.** After upgrading run `fuzzphony:schema --apply` once: it adds a prefix
index to the vocabulary of every typo-tolerant index (`suggest()` works without it, with a scan of the
small vocabulary table). See [UPGRADE.md](UPGRADE.md#from-07-to-08).

### Added

- **Search-as-you-type:** `Fuzzphony::suggest($index, $text, $limit = 8)` completes the last word of the
  text from the index's vocabulary (`"wireless hea"` gives `"wireless headphones"`), most frequent first.
  Plain text in the index's normalised form. A tenant-scoped index, an index without a typo-tolerant field
  and an engine without the optional `Engine\Suggestions` capability give an empty list. See
  [Search-as-you-type](docs/searching.md#search-as-you-type).
- **Facets:** `SearchBuilder::facets('category_id', 'in_stock')` counts the values of string, int, bool
  and date filters among the matches: `SearchResult::$facets` (`FacetValue`: value, count). A facet does not
  count the conditions on its own filter (what choosing another value would find); the tenant's condition always
  counts, and the tenant filter cannot be a facet. `facetValues()` limits the values per facet (default 20).
  `exactCounts()` counts every match instead of the candidates: an exact total and exact facets, at the cost
  of reading everything the query matches (a method of its own, not a threshold, so request input cannot switch
  the cost limits off). See [Facets](docs/searching.md#facets).
- **Federated search:** `Fuzzphony::federated()->index('a', weight: 2.0, configure: fn ...)->index('b')->query('x')->get()`
  searches several indexes and merges the lists by reciprocal rank fusion (`FederatedResult`, `FederatedHit`).
  Works with every engine. See [Federated search](docs/searching.md#federated-search).
- The Live Component takes `facets="category_id,in_stock"` and `suggestions="5"`; `fuzzphony:search` takes
  `-f/--facet`, `--exact` and `--suggest`; `Capability::Facets` and `Capability::Suggest`.
- Demo: every search box completes the word you type (Compare has the rich, grouped dropdown: searches, categories, products), the Compare and Playground pages have facets (the
  Playground also an opt-in exact-counts switch, `DEMO_ALLOW_EXACT=1`), and the Languages page shows one
  search over all five languages at once.

## [0.7.3] - 2026-10-10

No upgrade steps. A demo database from 0.7.1/0.7.2 converts its synonym lists on the next start.

### Added

- `Fuzzphony::stemSynonyms($index, $synonyms)` (optional engine capability `Engine\SynonymStems`,
  implemented by `PostgresEngine`): the synonyms with their members stemmed by PostgreSQL, one round trip.
  The prepared list serializes: keep it in your own cache and hand it to `useSynonyms()` on every
  request, so a request no longer reads, validates and stems a long list. See
  [Configuration](docs/configuration.md#a-long-list-store-per-entry-cache-the-prepared-list).

### Changed

- Building the synonym lookup of a list is about three times faster (one plain word needs no query parser,
  each member is keyed once): 5 000 entries cost about 110 ms per fresh engine instead of about 280 ms, and about 80 ms
  with a prepared list (1 000 entries: 27 ms instead of 66 ms). Nothing changes in what matches.
- Demo: the synonyms are stored like a long list should be: one row per entry (`demo_synonym_entry`) and a
  version per index (`demo_synonym_version`), with the prepared lists in a cache keyed by the version. An
  existing demo database converts its `demo_synonym` lists on the next start (the old table is left alone).

## [0.7.2] - 2026-10-09

A demo-only patch: the library is unchanged. No upgrade steps.

### Fixed

- Demo: the same words work on every search page. The catalogue (Compare, Playground, Relevance) had no
  television, so `tv` found nothing there while it did on the Languages page. A few televisions
  (`sql/demo_additions.sql`, added idempotently on every start, so an existing volume gets them without
  `down -v`) and a `tv, television, telly` group in the catalogue's synonyms fix that; the Relevance page
  has a `tv` example. An existing volume adds the group itself on the Synonyms page.

## [0.7.1] - 2026-10-09

No upgrade steps: nothing changes unless you use the new synonym sources.

### Added

- Synonyms from a file or from your own storage. A file in the Solr/Elasticsearch format
  (`tv, television, telly` is a group, `laptop => notebook, portable` a one-way rule, `#` a comment)
  is read with YAML `synonyms: { file: path, entries: [...] }`, `IndexBuilder::synonymsFile()`,
  `#[Searchable(synonymsFile: ...)]` (it may be combined with inline entries) or
  `Synonyms::fromFile()` / `Synonyms::fromText()` / `toText()`. `Fuzzphony::useSynonyms($index, $synonyms)`
  replaces an index's synonyms at run time (for example from a database table the application
  keeps, loaded once per request): the next search uses them, no schema change and no reindex.
- Demo: a Synonyms page edits the synonym list of each index (one per language) in the demo's
  database, validates it on save and tries a word against it; the lists are seeded for the catalogue
  and the five languages, with a television in every language (`tv`, `télé`, `tévé`). **An existing demo
  database** gets the new table and lists on the next start, but not the new television rows
  (`docker compose down -v` re-seeds everything).

## [0.7.0] - 2026-10-09

**After upgrading, run `fuzzphony:schema --apply`, then `fuzzphony:reindex --vocabulary`** (a full
`fuzzphony:reindex` does it too), and give the roles the grants for the new vocabulary table. Typo-tolerant
results change: see Breaking. See [UPGRADE.md](UPGRADE.md#from-06-to-07).

### Breaking

- Typo tolerance is proportional to the word's length by default: one typo is tolerated from 4
  letters and two from 8, and the trigram similarity a word needs is that of the worst such typo
  plus 0.03, `(n + 1 − 3t) / (n + 1 + 3t)` (0.28 for 4 letters, 0.36 for 5, 0.48 for 7, 0.23 for 8,
  0.40 for 12; 0.6 below 4 letters), computed in PostgreSQL from the normalised word. `mouse` no
  longer matches `monitor` or `mower`, while `mose`, `mouze`, `wireles` and `hedphones` still match.
  Typo-tolerant result sets change: about five times fewer look-alikes for 5 to 7 letter words, but
  a letter replaced in the middle of such a word, or two letters swapped, may no longer be
  tolerated (`mpuse`, `mosue`; about 70 to 80% of single-letter typos are found, a flat 0.3 found
  92 to 100%),
  and 3-letter words need 0.6. 4-letter words behave as before (`mose` finds `mouse`, and as
  close to it `monitor` and `mower`). `fuzzy_similarity: 0.3` (a number) keeps the old flat
  behaviour for every word. `Thresholds::$fuzzySimilarity` is now `?float` (`null`, the default,
  means by length): code that reads it must handle `null`. A custom `Engine` must apply the same
  rule (the conformance test expects `"wireles headphones"` to find only the headphones). See
  [UPGRADE.md](UPGRADE.md#from-06-to-07).

### Added

- Synonyms per index: groups (`[tv, television]`) and one-way rules (`laptop => notebook | portable`),
  in YAML `synonyms:`, `IndexBuilder::synonyms()` and `#[Searchable(synonyms: …)]`, validated with the
  rest of the definition (plain words only, at most 32 members) and carried by every exporter. A
  search expands them on the query: a word that is a member (compared by its stem, accents folded;
  a word with a symbol or hyphen as it is) becomes an OR with its alternatives, `-tv` excludes them
  too, a field-scoped word expands inside its field, a multi-word member matches a quoted phrase,
  and `interpretedAs` shows the expansion. No schema change and no reindex; the exact-title bonus
  ignores the alternatives, and the empty-result relaxation treats a word and its alternatives as
  one word. A query gets at most four times `max_terms` alternatives (a warning says when a word
  was left as typed). PostgreSQL stems the words: the synonyms' stems once per engine, and one
  small statement for each query word it has not seen yet. New public class
  `Fuzzphony\Core\Definition\Synonyms`; see [Synonyms](docs/searching.md#synonyms).

- "Did you mean": a search that finds fewer hits than `fallback_below` (or had to use typo tolerance) and has a whole word the index
  does not know (not in the vocabulary, not a stop word, and no document matches it: so not a word of a
  field that is not typo-tolerant, an inflection or a word added since the last reindex) sets
  `SearchResult::$didYouMean` to the query with that word replaced by the nearest word of the index
  (trigram candidates, then edit distance, then document count; prefixes, excluded words, words a
  synonym expands and codes with a digit are never corrected). Threshold `did_you_mean` (default `true`).
  A tenant-scoped index never suggests (its vocabulary would mix tenants), and a role that cannot read the
  vocabulary gets no suggestion rather than an error. It reads the new vocabulary table of a fuzzy index
  (`fuzzphony_<index>__vocab`, additive: the sidecar layout stays 2), which `fuzzphony:schema --apply`
  creates empty and every full reindex fills in one transaction (`ReindexResult::$vocabulary`,
  `$vocabularyError`; `ReindexOptions::$vocabulary`); `Fuzzphony::rebuildVocabulary()` and
  `fuzzphony:reindex --vocabulary` rebuild only it. The doctor has a "Vocabulary" check, `fuzzphony:search`
  and the Live Component show the suggestion, and `explain()` lists the lookup statement (label `did you mean`). New optional `Fuzzphony\Core\Engine\Vocabulary` interface:
  an engine that does not implement it gives no suggestions and needs no change. See
  [Did you mean](docs/searching.md#did-you-mean).

### Changed

- The doctor's configuration check shows `similarity=by word length` for the default and warns
  "very tolerant" only for an explicit `fuzzy_similarity` below 0.2.
- `fuzzphony:search --threshold fuzzy_similarity=null` goes back to the by-length default.
- Demo: the Playground has a "Fuzzy similarity by word length" switch (on by default); the slider
  applies only when it is off. A new Relevance page shows typo tolerance by word length next to a flat
  0.3, the catalogue's synonyms (`mice`, `display`, `headset`, `drill => screwdriver`) and the "did you
  mean" suggestion, which the ILIKE vs Fuzzphony and Playground pages show too; `init` fills the
  vocabulary of an index that already existed.
- The typo-tolerant statement rechecks each word against the similarity of its own length
  (`word_similarity(…) >= q.th<n>` next to `<%`, in the search, the field-scoped words and the
  empty-result relaxation probe, with the threshold computed in SQL, once, from the normalised
  word's length); a word below it does not score. The session's
  `pg_trgm.word_similarity_threshold` is set per statement to the lowest similarity any of its words
  needs, so the GIN indexes return no more candidates than necessary.

## [0.6.1] - 2026-10-01

### Fixed

- A search text with no word in it (`**********`, `!!!`) listed the whole index as if it were an empty
  (browsing) search. It now returns no hits and the warning "The search has no word to look for; use
  letters or digits." An empty text still browses.

### Changed

- Demo: the Observability page logs what Fuzzphony reports (`docker compose logs -f php`, values included)
  and shows those lines; the worker container only logs errors, so an idle worker stays quiet.

## [0.6.0] - 2026-10-01

### Added

- `Fuzzphony\Core\Observability\MetricsCollector`: an optional interface for counters
  (`increment()`), durations (`observe()`) and point-in-time values (`gauge()`).
  `NullMetricsCollector` (the default: zero cost) and `LoggingMetricsCollector` (one structured
  PSR-3 log line per call, at `debug` level) ship in Core. See [Observability](docs/observability.md).
- `PostgresEngine` instruments every operation (`guard()`'s existing try/catch) and each search's
  total latency and fuzzy-fallback rate through an optional `MetricsCollector` (new 4th
  constructor parameter, defaulting to `NullMetricsCollector`).
- `Worker` gauges each index's queue depth once per cycle, counts items processed and counts
  rebuild failures, through the same optional `MetricsCollector` (new 3rd constructor parameter).
- `RefreshDocumentsHandler` (the ORM-sync Messenger handler) observes its own handling duration and
  counts errors, through the same optional `MetricsCollector`.
- `Fuzzphony\Core\Database\TransactionAware`: an optional `Connection` capability
  (`inTransaction(): bool`). When a `Connection` implements it and no outer transaction is open,
  fuzzy searches skip a now-redundant round trip that restored the previous similarity threshold —
  `PostgresEngine`'s own transaction commit already reverts it. `PdoConnection` and `DbalConnection`
  both implement it; a `Connection` that doesn't keeps today's behavior exactly.
- `Fuzzphony\Bundle\Observability\PrometheusMetricsCollector` (internal, bundle service
  `fuzzphony.metrics`): used automatically when `promphp/prometheus_client_php` is installed and
  the `apcu` extension is loaded and enabled; `LoggingMetricsCollector` wired to the app's `logger`
  otherwise, tagged with its own Monolog channel (`fuzzphony`) so it can be routed or excluded.
  `PostgresEngine`, `Worker` and `RefreshDocumentsHandler` all receive it.
- `fuzzphony:doctor --format=prometheus` prints one `fuzzphony_doctor_check` line per check plus a
  `fuzzphony_queue_depth` line for queue-mode indexes, in Prometheus text-exposition format, for a
  cron/systemd-timer feeding node_exporter's textfile collector.
- A sample Grafana dashboard for the metrics `PrometheusMetricsCollector` produces ships in
  [`docs/grafana/`](docs/grafana/).

## [0.5.0] - 2026-10-01

**After upgrading, run `fuzzphony:schema --apply`, then one full `fuzzphony:reindex`.** See
[UPGRADE.md](UPGRADE.md#from-04-to-05).

### Breaking

- The sidecar layout is 2. `fuzzphony:schema --apply` upgrades a layout-1 index (a guarded step in
  the index's plan, also in `--dump-migration`) and clears its documents record, so the doctor's
  "Documents" check asks for one full `fuzzphony:reindex`. The documents hash now includes the
  layout.
- Field-scoped queries are exact: `brand:x` searches the `brand` field only, on the full-text and
  on the typo-tolerant side. 0.4 searched every field of the same weight, and any fuzzy field once
  typo tolerance ran, so results of field-scoped queries change (`name:sony` no longer returns
  Sony-brand products). An excluded one (`-brand:x`, also inside a group such as
  `-(brand:x | cable)`) excludes by that field only. A scoped word of a field that is not fuzzy is
  matched exactly only. The index table stores one `tsvector` per field and one normalised text
  per fuzzy field (roughly one more copy of the indexed text).
- `fuzzphony:schema --drop --apply` and `fuzzphony:reindex --prune-empty` now explain what they
  are about to remove and ask for confirmation (default no); a script that ran either
  non-interactively needs the new `--force` option.
- `Engine` has five new methods for the zero-downtime reindex: `beginRebuild(IndexDefinition $index,
  bool $resume = false): bool`, `refreshShadow(IndexDefinition $index, array $ids): int`,
  `finishRebuild(IndexDefinition $index): void`, `abortRebuild(IndexDefinition $index, bool
  $keepShadow = false): void` and `discardLeftoverRebuild(IndexDefinition $index): bool`. Custom
  engines must implement them; an engine that cannot build next to the live index returns `false`
  from `beginRebuild()` (the reindex then runs in place) and from `discardLeftoverRebuild()`, and
  leaves the others empty.
- A full `fuzzphony:reindex` / `Fuzzphony::reindex()` builds the index next to the live one and
  swaps it in (zero-downtime reindex). It needs disk for a second copy of the index while it runs,
  and a role with `CREATE` on Fuzzphony's schema that owns the index table (otherwise it runs in
  place, as before, and the command says so). After a swap `ReindexResult::$pruned` is `null` (the
  orphans went with the old index) and the new `ReindexResult::$swapped` is `true`. A second full
  reindex of the same index while one runs fails with the new `RebuildAlreadyRunning` (an
  `\InvalidArgumentException`). Starting or discarding a rebuild waits at most 3 s for the index
  table's lock, like the swap (long transactions or autovacuum hold it): starting then fails and
  changes nothing, discarding fails and leaves the rebuild behind for the next full run. The run holds a
  session-level advisory lock: behind a transaction-pooling proxy (PgBouncer in transaction mode)
  it can be released on another server connection than the one that took it, which PostgreSQL only
  warns about, and the lock stays held there (later reindexes fail with "already running" and
  `fuzzphony:schema --apply` refuses) until the pooler closes that connection. Reindex with
  `--in-place` there, or over a session connection.
- `fuzzphony:schema --apply` and `--drop --apply` fail while a full rebuild of an index in their
  plan runs, a `fuzzphony:reindex` or the worker's rebuild of a job a `TRUNCATE` queued: run them
  again when it has finished (a deploy pipeline that applies the schema should retry). A
  `--dump-migration` migration is not transactional, so it only refuses while a rebuild is running
  at its guard statement.
- Index names must not contain `__` (two underscores): it is reserved for the objects of an
  index's rebuild (`fuzzphony_<index>__next`, `fuzzphony_<index>__changes`,
  `fuzzphony_refresh_<index>__next`), which an index named `products__next` would share with
  `products`. Such a definition now fails validation (`InvalidDefinition`). Rename the index
  before upgrading: drop the old one with 0.4 (`fuzzphony:schema --drop --apply`), then apply and
  reindex the new name.
- A failed `fuzzphony:reindex` prints the error and the command to resume with (`--from`, plus
  `--in-place` / `--no-prune` when the run wrote in place), and exits with code 1 (instead of an
  uncaught exception); it stops at the first index that fails. A full `--in-place` run discards a
  rebuild a failed run left behind.
- `Engine` has two new methods for the rebuild job a `TRUNCATE` queues:
  `rebuildRequested(IndexDefinition $index): bool` (the worker asks whether a job is queued) and
  `recordRebuildFailure(IndexDefinition $index, string $message): void` (the worker records that
  the rebuild it ran failed, for the doctor). Custom engines must implement them; an engine whose
  queue has no such job returns `false` from the first and leaves the second empty, and its worker
  only drains the queue. The PostgreSQL engine completes the job when a full run succeeds: in the
  swap, or in `pruneOrphans()` at the end of a full in-place run.
- In `queue` mode a `TRUNCATE` that needs a full resync queues one job, the queue row
  `(index_name, '*')`, instead of every document id, and the worker runs it as a full rebuild.
  Code that reads `fuzzphony_queue` directly must skip that row, and a string document id `*` is
  reserved (changes to such a document queue a full rebuild). The job stays queued until a full
  run that started after it succeeds (the worker's, or any full `fuzzphony:reindex`, `--in-place`
  included); a run that fails or is killed keeps it, and a `TRUNCATE` during a run keeps a newer
  one. The `TRUNCATE` trigger now updates that row (`ON CONFLICT … DO UPDATE`), so a role that
  truncates watched tables needs `UPDATE` on `fuzzphony_queue` as well as `INSERT`. The worker role
  builds next to the live index when it may (see the reindex entry above), else in place.
- A full rebuild that fails never stops `fuzzphony:worker`: it writes the error to stderr, keeps
  the job, syncs the index's queued ids and the other indexes as usual, and tries the job again
  after a back-off (1 minute, doubling, at most 1 hour). `fuzzphony:worker --once` then exits with
  code 1. The shared meta table has three new columns (`rebuild_failed_at`, `rebuild_failures`,
  `rebuild_error`, added by `fuzzphony:schema --apply`) and the doctor's "Sync queue" check warns
  "a full rebuild keeps failing" until a full run succeeds.

### Added

- Partition-aware sync: `fuzzphony:schema --apply` puts the `TRUNCATE` trigger on every partition
  of a partitioned watched table, at every level, so truncating one partition is followed. The
  doctor lists partitions without it (one attached after the last apply) or with it disabled, and
  warns at `trigger_level: statement`, where writes that target a partition directly are not
  synced. A detached partition keeps its trigger: the doctor warns, and `fuzzphony:schema --apply`
  and `--drop --apply` remove it. `ATTACH PARTITION` / `DETACH PARTITION` fire no triggers: run
  `fuzzphony:reindex` afterwards.
- The layout step runner: `fuzzphony:schema --apply` upgrades an index built with an older sidecar
  layout.
- Doctor: a "Shared objects" check of the shared objects' version row (`*` in `fuzzphony_meta`): a
  warning when it is missing, an error when its layout is older or newer than the library's, or
  when the schema or extension schema changed since the last apply.
- `fuzzphony:schema --drop --apply` and `fuzzphony:reindex --prune-empty` ask for confirmation
  before running (`--force` skips it, and is required in non-interactive runs).
- The integration tests (`PostgresTestCase::dsn()`) and `benchmarks/run.php` refuse to run against
  a database whose name doesn't contain "test" (integration tests) or "bench"/"test" (benchmark),
  case-insensitive, so `FUZZPHONY_TEST_DSN` / `FUZZPHONY_BENCH_DSN` can no longer be pointed at a
  real database by mistake; see [CONTRIBUTING.md](CONTRIBUTING.md).
- `ReindexOptions::$inPlace` and `fuzzphony:reindex --in-place` write the live index directly (the
  0.4 behaviour: no second copy on disk).
- Doctor: a "Rebuild" check reports a full reindex that did not finish (with how to resume it, or
  what is left over when it cannot be resumed), and one that is running.

## [0.4.0] - 2026-09-27

**After upgrading, run `fuzzphony:schema --apply`, then one full `fuzzphony:reindex`**, and grant the new
`fuzzphony_meta` table to the roles that run the doctor (`SELECT`) and the reindex (`SELECT`,
`UPDATE`). See [UPGRADE.md](UPGRADE.md#from-03-to-04).

### Breaking

- Every exception Fuzzphony throws implements `FuzzphonyException`, except `\LogicException` for
  internal invariants. New `InvalidArgument` (a wrong runtime argument: batch size below 1, an
  unknown table in the wizard, `AttributeExporter::export()` on a definition it cannot express, a
  non-finite number) and `InvalidConfiguration` (an invalid extension schema, `orm_sync.async`
  without Messenger, no index configured); both extend `\InvalidArgumentException`, so existing
  `catch (\InvalidArgumentException)` blocks still work. An enum typo in the builder or YAML
  (`sync: realtime`, `->field('name', 'E')`) is an `InvalidDefinition` naming the allowed values
  instead of a `\ValueError`; a composite Doctrine identifier is an `InvalidDefinition` instead of
  a `\LogicException`; `AttributeExporter::export()` on a joined source throws `InvalidArgument`
  instead of `\LogicException`. Driver errors from `sourceIds()`, `queueSize()`, `explain()`,
  highlighting, the doctor and `SchemaPlan::apply()` arrive as `EngineFailure` (the driver
  exception is its previous exception). `fuzzphony:schema --dump-migration` into a directory that
  cannot be created prints the error and exits 1 instead of throwing.
- `IndexDefinition::with(...)` is removed. It accepted any named argument, ignored unknown keys
  and silently kept the old value on a wrong type. Use the typed withers instead: `withName()`,
  `withSource()`, `withFields()`, `withFilters()`, `withWatches()`, `withIdType()`, `withSync()`,
  `withText()`, `withBoostColumn()`, `withRecencyColumn()`, `withProfiles()`,
  `withThresholds()`, `withEntityClass()`, `withTriggerLevel()`, `withTenant()`. A wrong type is
  now a PHP `TypeError` at the call site.
- `Fuzzphony::reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult`
  replaces the positional `$batchSize, $onBatch, $onPruned, $prune, $pruneEmpty, $onPruneSkipped`
  and the `int` return value; `Reindexer::run(IndexDefinition, ReindexOptions): ReindexResult`
  likewise. `ReindexResult` has `written`, `pruned` (null when pruning did not run) and
  `pruneSkippedEmptySource`, which replace the `onPruned` / `onPruneSkipped` callbacks. A batch size
  below 1 throws `InvalidArgument` when the options are created.
- PostgreSQL naming and types left Core: `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
  `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
  `RankingProfile::tsRankWeights()` and `Identifier::limit()` are removed. They now live in the
  engine (`Fuzzphony\Engine\Postgres\Schema\Names` and `Types`, both internal).
- Fuzzphony no longer uses the `search_path` to place or find its objects: they are created in and
  read from one configured schema (`public` unless `schema` is set), and the generated functions are
  re-created with a pinned `search_path`. After upgrading, run `fuzzphony:schema --apply`; an
  install whose objects live outside `public` must set `schema` to that schema. See
  [UPGRADE.md](UPGRADE.md#from-03-to-04), step 5.
- `Engine` has a new method `recordReindex(IndexDefinition $index): void`, called after a full
  reindex. Custom engines must implement it (an empty body is fine).
- The new `fuzzphony_meta` table needs grants: `SELECT` and `UPDATE` for the role that runs
  `fuzzphony:reindex`, `SELECT` for the role that runs `fuzzphony:doctor`. Without `SELECT` the
  doctor's "Schema version" check is a warning with the `GRANT` to run (so `--strict` fails)
  instead of an inspection failure. A schema-wide `GRANT … ON ALL TABLES` must be re-run after the
  first 0.4 `schema --apply`.
- The public API is now explicit ([docs/architecture.md](docs/architecture.md#public-api)): 51
  classes are marked `@internal` (loaders, validators, the query parser and AST, the reindexer and
  worker, the console command classes, the SQL compilers, the schema generator, the doctor, the
  introspector, …) and may change in any release. The unused `Core\Engine\Analyzer` interface is
  removed, and `YamlExporter`'s constructor no longer takes an (internal) `ArrayExporter`.

### Added

- Mutation testing with [Infection](https://infection.github.io) (`composer mutation`): the
  `@default` mutator set against `src/`, running both the unit and integration test suites,
  multi-threaded (each worker gets its own throwaway database). A monthly CI job records the
  baseline MSI; pull requests only mutate their changed lines. No `minMsi` gate yet — see
  [docs/roadmap.md](docs/roadmap.md#mutation-testing). The first full run scored 85% (3,944
  mutants, 17 minutes); the README shows the current score as a badge.
- `PostgresEngine` takes a `schema` argument (default `public`): the schema of every object
  Fuzzphony creates. `fuzzphony:schema --apply` creates it when it is not `public`.
- `fuzzphony.schema` bundle setting (default `public`) for Fuzzphony's own schema; an invalid name
  fails the container build with `InvalidConfiguration`. The doctor looks its objects up in that
  schema and warns when an index is still in `public` from before the setting; the wizard hides
  the dedicated schema.
- `fuzzphony_meta`: `fuzzphony:schema --apply` records per index the sidecar layout version (1),
  a hash of the definition parts that shape the DDL, and the library version; a full reindex
  records a hash of the parts that shape the documents. The doctor's new "Schema version",
  "Definition" and "Documents" checks report a missing record, a layout older or newer than the
  library's, a definition changed since the last apply, and documents built from another
  definition. `--drop` deletes the index's record; `--dump-migration` includes the upsert.
- Doctrine Migrations: with DoctrineBundle, the bundle sets the DBAL `schema_filter` of
  Fuzzphony's connection so `doctrine:migrations:diff` never proposes dropping Fuzzphony's tables.
  An application that sets its own filter keeps it; `fuzzphony:doctor` warns, with the regex to
  merge, only while that filter still lets Fuzzphony's tables through, so a merged filter passes
  `--strict`. With `connection` or `schema` set from a parameter or `%env()%`, the bundle sets no
  filter and the doctor does not check it. `doctrine/migrations` is suggested.

### Changed

- Every generated and runtime statement schema-qualifies Fuzzphony's own objects. The normaliser
  function runs with `search_path = pg_catalog, pg_temp`; the refresh and sync functions keep the
  `search_path` of the session that applied the schema (`SET search_path FROM CURRENT`).
- Roadmap: reordered into milestones 0.4-1.0, so what other features build on ships first (API
  cleanup and the dedicated schema before reindex, events before analytics, the vocabulary table
  before `suggest()`).
- Roadmap: a dedicated schema for Fuzzphony's tables (`schema: fuzzphony`), default `public`.
- Roadmap: record linkage (matching people and companies, with explainable match scores) is
  planned after 1.0.
- Roadmap: the v0.4 Foundations items (API cleanup, dedicated schema, sidecar schema version,
  Doctrine Migrations integration) are listed as done; the sidecar layout's upgrade step runner
  moves to 0.5, with the first layout change.
- README: shortened to what the library does, the demo, install and quickstart; the details moved
  to `docs/`, the install command is `composer require fuzzphony/fuzzphony` (the bundle is not a
  separate package), and new badges show PHPStan, OpenSSF Best Practices, PHP, PostgreSQL and
  Symfony support.
- Demo: the database is PostgreSQL 18.6 (was 17.11). An existing demo needs
  `docker compose down -v` once and seeds again on the next `up --build`.
- Demo: the compare page shows Fuzzphony's results at once and loads the ILIKE column
  separately (`GET /compare/ilike`), so the page feels as fast as Fuzzphony is instead of waiting
  on ILIKE's ~400 ms full scan too.
- Demo: runs on `schema: fuzzphony`; the application role is granted the `fuzzphony` schema's
  tables instead of the `fuzzphony_*` tables in `public`. An existing demo needs
  `docker compose down -v` once.

### Fixed

- An `extension_schema` whose name needs quoting (upper-case letters, e.g. `Ext`) broke the
  normaliser function: its `unaccent` dictionary was looked up as `ext.unaccent`.
- `fuzzphony:doctor`'s Doctrine schema filter check probed only the sync queue table, so an
  application filter that hid it but not `fuzzphony_meta` (or another shared table) passed
  silently; it now probes every shared table name and warns if any of them gets through.

## [0.3.2] - 2026-09-25

No action needed after upgrading: only the generated search statements change.

### Added

- `.bestpractices.json` with the answers for the OpenSSF Best Practices badge.

### Changed

- README: every known limitation now points to its planned fix; the roadmap adds exact field
  scoping, length-aware typo tolerance, an exact `total`, partition-aware sync and a one-job
  resync after `TRUNCATE`.
- CI: every workflow runs with a read-only token and pins its actions to a commit SHA; an OpenSSF
  Scorecard workflow publishes the score shown in the README badge. The demo's base images are
  pinned by digest, and Dependabot keeps them current. The package now contains `.github/`
  (a few KB of workflow files): Scorecard reads the same archive Composer downloads.
- `main` is protected: changes land through pull requests once CI passes.

### Fixed

- PostgreSQL: the trigram operator (`<%`) and `word_similarity()` in the typo-tolerant / relaxed
  search SQL are now schema-qualified with the configured `extension_schema`, like every other
  `pg_trgm` / `unaccent` reference already was. `pg_trgm` and `unaccent` no longer need to be on
  the `search_path`.

## [0.3.1] - 2026-09-25

**After upgrading, run `fuzzphony:schema --apply`, then a full `fuzzphony:reindex`** so accented
stop words are dropped from the index too. See [UPGRADE.md](UPGRADE.md).

### Added

- Demo: a Languages page (`/languages`) that searches a small hand-written catalogue in English,
  German, French, Spanish and Hungarian, one index per language, with one-click examples and the
  lexeme PostgreSQL produced for every query word.
- Demo: the Benchmark and ILIKE vs Fuzzphony pages state why a run takes a while, derived from the
  actual query/run counts, e.g. "Searching 500,000 products (bench_product): 8 queries × (1 cold +
  5 warm runs) × 2 engines = 96 statements."

### Changed

- The test suite covers 100% of the lines in `src/` (539 tests, up from 400 and 91.5%). CI fails
  below 90% line coverage, and Codecov reports the coverage of every pull request's changes.
- Demo: the default catalogue size (`DEMO_ROWS`) is 500 000 products (was 200 000); `shared_buffers`,
  `effective_cache_size`, `maintenance_work_mem` and the `db` container's memory limit were raised to
  match (see `demo/README.md` "Tuning"). Verified at that size in an isolated compose project: cold
  start (empty volume to first response) 54-57 s, `fuzzphony:doctor --deep` reports 500000 of 500000
  documents indexed (100.0%, exact) — see `demo/README.md` "Proven on 500 000 rows".
- README: the demo moved up front; "Why" says who Fuzzphony is for and when it is not the right
  tool; the Messenger section says `symfony/messenger` is optional; the roadmap lists
  search-as-you-type, synonyms, facets, "did you mean", zero-downtime reindex, search analytics
  and a Laravel driver.

### Fixed

- With accent folding (the default), accented stop words were not ignored: `unaccent` ran before
  the stemmer, which then looked up `fur` instead of `für` in its stop-word list. German `für`,
  Hungarian `és`, French `à` (and the like in every language with a stop-word list) were indexed
  and had to match like ordinary words, so `Tasche für Laptop` did not find `Laptop Tasche`, and
  `à` alone matched 13 products of the demo's French catalogue. The configuration
  `fuzzphony_<language>` now drops stop words first, with a new dictionary
  `fuzzphony_<language>_stop` built from the language's own stop-word list; the typo-tolerant
  branch and the empty-result relaxation ignore them too. `fuzzphony:schema --apply` now also
  repairs an existing configuration, and `fuzzphony:doctor` reports one that still keeps accented
  stop words. **Run `fuzzphony:schema --apply`, then a full `fuzzphony:reindex`**, see
  [UPGRADE.md](UPGRADE.md).

## [0.3.0] - 2026-09-25

**After upgrading, run `fuzzphony:schema --apply`** (idempotent) so existing indexes get the new
trigger and sync functions, then a full `fuzzphony:reindex` to clear what earlier `TRUNCATE`s left
behind. See [UPGRADE.md](UPGRADE.md).

### Breaking

- The minimum Symfony version is 7.4 (was 7.3). 7.3 is end-of-life and every `symfony/yaml`
  7.3.x release carries security advisories, so Composer refuses to install a 7.3-pinned set.
- `fuzzphony:search`: the long option `--profile` is now `--rank-profile` (`-p` is unchanged). The
  old name collided with Symfony FrameworkBundle's global `--profile` flag and made the command
  fail with "An option named 'profile' already exists".
- `Engine` gained `pruneOrphans()`; a third-party engine must implement it.
- A full `fuzzphony:reindex` (and `Fuzzphony::reindex()`) now removes orphaned documents at the
  end. Opt out with `--no-prune` / `prune: false`.
- `NodeInspector::topLevelExclusions()` was removed (no engine uses it any more).
  `SearchSqlBuilder::ranked()` (`@internal`) changed signature.
- `Thresholds` rejects `candidate_limit` above 10 000, `max_query_length` above 1 024 and
  `max_terms` above 64, and an unknown `fuzzy_mode` throws `InvalidDefinition` (it used to throw a
  bare `ValueError`).
- A source query or watch SQL that contains `$fuzzphony$` is rejected by the definition validator.
- The `ext-pdo_pgsql` extension is now a hard requirement of `fuzzphony/fuzzphony` (the
  PostgreSQL engine is always part of the package); install it before upgrading.

### Added

- **Empty-result relaxation.** When a query of two or more words finds nothing, each word is
  checked on its own against the searched set (filters and tenant included, with the same exact or
  typo-tolerant condition the search uses) in one probe statement. The words that match nothing
  are dropped and the search runs again; `SearchResult::$warnings` says
  `No results for all words; ignored words that match nothing: "aluminum".` and `interpretedAs`
  shows the reduced query. On the demo catalogue `wireless mouse aluminum` (the word is only in
  descriptions) returns the 1 666 wireless mice instead of nothing.
  - Never relaxed: words that all match something but never together (`mouse kettle`), single
    words, queries with hits, and a reduction that would leave a group of only negations
    (`zzqq -mouse | wireless yyqq`). If the relaxed search finds nothing too, the original empty
    result stands, without a warning.
  - The warning is **plain text** that quotes the user's words (invisible format characters
    removed, cut at 40 characters, each word once): escape it when you render it as HTML.
  - New threshold `relax_when_empty` (default `true`, independent of `fuzzy_mode`). `explain()`
    lists the `relaxation probe` and `relaxed: …` statements and shows the plan of the last search
    statement.
  - The probe reads the text indexes through one materialized CTE per word (10–35 ms at 200 000
    rows) and changes no session or transaction setting.
- **`TRUNCATE` sync.** `trigger` and `queue` sync add an `AFTER TRUNCATE … FOR EACH STATEMENT`
  trigger to every watched table, at both trigger levels. Truncating a table-sourced index's own
  table empties the index when the source really is empty (and drops its queued ids, skipping
  rows a running worker holds); otherwise, and for any other watched table, every indexed document
  and every document the source now returns is resynced (queued in `queue` mode, refreshed inside
  the transaction in `trigger` mode: expensive on a big index, see "Known limitations").
  Truncating a single partition directly still fires nothing.
- **Orphan pruning.** `Engine::pruneOrphans()` removes, in batches, documents whose row the source
  no longer returns. A full reindex calls it and reports the count; a run resumed with `--from`
  never prunes. A full run whose source returns no row at all does not prune unless
  `--prune-empty` / `pruneEmpty: true` is given (`$onPruneSkipped` reports it).
  `Fuzzphony::reindex()` accepts `$onPruned`, `$prune`, `$pruneEmpty` and `$onPruneSkipped`.
- `fuzzphony:doctor` reports a missing `TRUNCATE` trigger and, with `--deep`, counts orphaned
  documents.
- Test coverage for the Symfony bundle and the Doctrine bridge, previously untested: DI wiring,
  the `fuzzphony:*` commands, `OrmSyncListener`, `EntityLoader` (one query, ranking order),
  `FuzzphonySearchFilter`, the Live Component, and console option collisions with FrameworkBundle.
- CI: a `composer validate --strict` gate, a Symfony 7.4 / 8.0 × PHP 8.4 / 8.5 × PostgreSQL 15–18
  matrix, a `--prefer-lowest` job, a demo smoke job, and Dependabot for Composer and GitHub
  Actions.
- `authors`, `homepage` and `support` metadata in `composer.json`; `UPGRADE.md`.

### Changed

- **Typo-tolerant (fuzzy) matching is per word.** It used to compare the whole query as one string
  with all fuzzy fields, so one long common word could satisfy it on its own: on the demo
  catalogue `wireles mice` returned 20 000 products (chairs, drills, kettles, …) of which 1 666
  were mice. Every word must now match on its own, exactly or by trigram similarity, through the
  query's real AND / OR / NOT structure, and `wireles mice` returns exactly the 1 666 wireless mice.
  - Fuzzy result sets get narrower. A typo in a word found only in a non-fuzzy field can no longer
    be matched approximately; such a query finding nothing is relaxed instead (see Added).
  - Negations are honoured at any depth by the fuzzy branch (previously only top-level ones).
  - `ScoreBreakdown::$fuzzySimilarity` is per word (1.0 for an exact word, AND = mean, OR = max),
    so scores of strict matches shift slightly in `fuzzy_mode: always`.
  - `fuzzy_min_length` applies per word, and stop words of the index language are ignored like in
    the full-text query.
  - Measured at 1 000 000 rows: at most 1.3× the old fuzzy statement's time, still on the GIN
    indexes.
- `fuzzphony:doctor` warns about a `candidate_limit` above 5 000 (was 20 000, which is now above
  the cap).
- The similarity threshold a fuzzy statement sets is restored afterwards, so a search inside a
  caller's own transaction leaves no setting behind.
- The dev-only parts of the repository (`demo/`, `benchmarks/`, `docs/`, `tests/`, CI and tool
  configuration) are no longer part of the Composer package.
- Demo: `demo/docker-compose.yml` is a production-like stack (nginx + php-fpm, a worker, a
  one-shot idempotent `init`, a tuned PostgreSQL 17 with a named volume) built from an immutable
  multi-stage image; `docker-compose.dev.yml` keeps live editing. The old demo volume is not
  reused: the first start seeds again.

### Security

- A source query or watch `affectedIds` containing `$fuzzphony$`, the dollar-quote tag of the
  generated functions, could break out of the generated function body; it is now rejected. The
  wizard skips table, column and foreign-key names that are not plain identifiers instead of
  building SQL from them.
- Threshold overrides can no longer lift the cost limits (see Breaking).
- Chained exclusions (`NOT NOT …`, `- - …`) are parsed in a loop instead of recursively; long
  chains are collapsed with the warning `Repeated exclusions ("-" / NOT) were collapsed.`
- Demo: published on 127.0.0.1 only by default, refuses to start exposed with the default secret
  or password, connects as a non-superuser role with a 5 s `statement_timeout`, clamps every
  playground input, makes EXPLAIN ANALYZE and the deep doctor opt-in (`DEMO_ALLOW_ANALYZE`,
  `DEMO_ALLOW_DEEP_DOCTOR`), accepts only listed tables in the web wizard, and escapes the hit
  title when there is no highlight.

### Fixed

- `<twig:Fuzzphony:Search />` failed with "There are no registered paths for namespace Fuzzphony"
  in every real installation: `FuzzphonyBundle::getPath()` pointed one directory too high.
- `TRUNCATE` on a source or watched table left stale documents in the index forever, and not even
  `fuzzphony:reindex` removed them (see Added).
- `TRUNCATE ONLY` on a table-inheritance parent no longer empties the index while the children's
  rows are still in the source.
- Demo: the CSP blocked AssetMapper's importmap entry for `app.js`'s stylesheet import, which
  aborted `app.js` so no JavaScript ran; the demo's `benchmarks/seed.sql` assigned categories
  independently of product names.
- Docs: README examples use snake_case filter names, and the tenant-scoped example uses a
  tenant-scoped index.

## [0.2.0] - 2026-09-24

### Breaking

- `OrmSyncListener` takes a `RefreshDispatcher` instead of an `Engine`.

### Added

- **Multi-tenancy**: `IndexDefinition::tenant` / `IndexBuilder::tenant()` /
  `#[Searchable(tenant:)]` / YAML `tenant:` mark a filter as the tenant scope; every search on a
  tenant-scoped index must call `->forTenant()` (`InvalidQuery::missingTenant()`) and every
  non-scoped index rejects one (`InvalidQuery::unexpectedTenant()`), enforced in the engine.
  Doctor gained a "Tenant scoping" check; exporters round-trip `tenant`.
- **Column-aware trigger filtering**: a watched table's UPDATE only queues a refresh when a
  relevant column changed. Automatic for a table-sourced index's own watch; opt-in for joined
  watches via `.watch(..., columns: [...])` / YAML `columns:`. Doctor gained a "Column-aware
  filtering" check that also validates explicit `columns`.
- **Configuration wizard**: `fuzzphony:wizard` suggests a definition from table structure and
  planner statistics, explains every decision, exports YAML / builder / attributes, `--try` it.
- **Statement-level sync triggers** (default) using transition tables. `trigger_level: row` keeps
  the previous behaviour; the doctor detects leftovers.
- Per-query ranking overrides: `->ranking(['boost' => 0.1])`, `RankingProfile::with()/toArray()`.
- Messenger: `orm_sync.async` dispatches `RefreshDocuments` messages.
- API Platform `FuzzphonySearchFilter`, Live Component `<twig:Fuzzphony:Search>`.
- Demo app (`demo/`): ILIKE comparison, playground, web wizard, benchmark, doctor.
- Benchmarks: cold + warm timings, `--markdown` / `--json`, CI workflow with job summary.

### Initial feature set

0.2.0 is the first tagged release. It also contains the initial feature set that was never tagged
on its own:

- PostgreSQL engine: weighted full-text search, accent folding, stemming, trigram typo tolerance.
- Index definitions via attributes, YAML or a fluent builder, validated with all violations at once.
- Query language: AND/OR/NOT, phrases, prefixes, field scoping, grouping; never throws on user input.
- Ranking profiles (text, fuzzy, exact/prefix bonus, boost, recency) with score breakdowns.
- Thresholds: min score, fuzzy modes, similarity, candidate limit, input limits.
- Sync modes: queue (default), trigger, ORM, manual; watches for joined tables.
- Doctor with fixes; CLI commands for schema, reindex, worker, search and explain.

[Unreleased]: https://github.com/er2es/fuzzphony/compare/v0.9.6...HEAD
[0.9.6]: https://github.com/er2es/fuzzphony/compare/v0.9.5...v0.9.6
[0.9.5]: https://github.com/er2es/fuzzphony/compare/v0.9.4...v0.9.5
[0.9.4]: https://github.com/er2es/fuzzphony/compare/v0.9.3...v0.9.4
[0.9.3]: https://github.com/er2es/fuzzphony/compare/v0.9.2...v0.9.3
[0.9.2]: https://github.com/er2es/fuzzphony/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/er2es/fuzzphony/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/er2es/fuzzphony/compare/v0.8.4...v0.9.0
[0.8.4]: https://github.com/er2es/fuzzphony/compare/v0.8.3...v0.8.4
[0.8.3]: https://github.com/er2es/fuzzphony/compare/v0.8.2...v0.8.3
[0.8.2]: https://github.com/er2es/fuzzphony/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/er2es/fuzzphony/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/er2es/fuzzphony/compare/v0.7.3...v0.8.0
[0.7.3]: https://github.com/er2es/fuzzphony/compare/v0.7.2...v0.7.3
[0.7.2]: https://github.com/er2es/fuzzphony/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/er2es/fuzzphony/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/er2es/fuzzphony/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/er2es/fuzzphony/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/er2es/fuzzphony/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/er2es/fuzzphony/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/er2es/fuzzphony/compare/v0.3.2...v0.4.0
[0.3.2]: https://github.com/er2es/fuzzphony/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/er2es/fuzzphony/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/er2es/fuzzphony/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/er2es/fuzzphony/releases/tag/v0.2.0

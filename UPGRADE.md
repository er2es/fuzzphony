# Upgrading

Upgrading: `composer update`, then `bin/console fuzzphony:schema --apply`, `fuzzphony:reindex <index>` when the doctor asks, and `fuzzphony:doctor`.

Before 1.0 a minor version may contain breaking changes. Each section lists what to change; the [CHANGELOG](CHANGELOG.md) has the full list.
Restart long-running processes (workers, RoadRunner, FrankenPHP) after an apply: the engine looks some catalog facts up once per process.

## From 0.12.x to 0.12.5

No API change, no change of results, **no reindex needed**. Two schema changes, both applied by `schema --apply`:

- **An index whose `granted` fields are all non-fuzzy loses its open-class trigram index** (`...__open_fz_<hash>`, a copy of `fz`): the apply
  drops it (`DROP INDEX CONCURRENTLY`, safe to repeat). Order: deploy the code, restart workers, then apply. A still-running 0.12.4 process
  keeps the same results (slower typo lookups for restricted viewers until it restarts). A dumped migration leaves the drop out; the doctor
  reports the index as stale until an apply drops it. Going back to 0.12.4: its apply builds the index again.
- **After a column backfill the apply runs `VACUUM (ANALYZE)` on that table** (only when rows were updated) and prints how much the table
  grew; `fuzzphony:reindex <index>` compacts it online, and the doctor's "Sidecar size" warning stays until then. A dumped migration has
  the `VACUUM` as a comment: run it after the migration.

### Opting in: `same_row_as`

To hold a city filter to the address that holds the words under `manyMatch('single')`, declare it on the many filter (the field must be a
many field over the same child rows with `rank: best`; a `rank: whole` field is refused):

```yaml
filters:
  city: { type: string, many: { from: address, key: customer_id, column: city, same_row_as: address } }
```

1. Deploy the definition and run `fuzzphony:schema --apply` right away: it adds one nullable `text[]` column to the values table (no
   rewrite) and the refresh function that fills it. Until the apply a coupled search keeps the document-level filter (not an error); no
   restart needed afterwards.
2. `fuzzphony:reindex <index>`: rows written before the option have NULL and match the city of any address until the reindex (or the sync)
   rewrites them; the doctor counts them ("Same row").

Removing the option (or changing its column, field or child table) needs only `schema --apply`; searches fall back to the document-level
filter at once. A process still running the old definition retries once without the column (no error), except inside your own transaction,
which the failed statement aborted: deploy the new definition to such processes before the apply, or restart them. Going back to 0.12.4
needs nothing.

## From 0.12.x to 0.12.4

No API change. A per-field column whose value another column of the same row holds is stored empty (`z_<field>` of the primary field or
the only fuzzy field, `t_<field>` of a one-field index). A new text column `field_map` lists, per row, which columns are empty and which
column holds each (NULL = every column filled). A search reads the other column only for a row written with the current map. Results,
scores and highlights are unchanged.

### Deploy order

1. `composer update fuzzphony/fuzzphony`.
2. `bin/console fuzzphony:schema --apply` **before** the new code serves searches: it adds the nullable `field_map` column (no rewrite)
   and the new refresh function; nothing is dropped. **No reindex is needed**: old rows keep being read from their own columns; rows the
   sync rewrites, and every row of the next full reindex, are stored without the copy.
3. Deploy the code. New code on a table without `field_map` fails with `SchemaNotApplied` ("Run: bin/console fuzzphony:schema --apply").
   Old code on an applied table keeps running and never shows a hidden field, but during a rolling deploy a row the new refresh function
   wrote meanwhile misses the old code's field-scoped and restricted searches. Keep the window short.

### A definition change (any release from 0.12.4 on)

When a definition change moves a column in or out of the map (a field added or removed, the primary field or `fuzzy` changed), rows
written under the old map have columns stored empty that the new definition reads as their own. `schema --apply` copies them back (the
**backfill**): per old map, in id order, 20 000 rows per committed statement, then sets the map to NULL. It runs after the apply's
transaction, is idempotent and resumes where an interrupted run stopped; its cost is an `UPDATE` of those rows (seconds to minutes per
million rows; searches go on). A change that keeps the map (a weight, `code`, a filter, `granted`) rewrites nothing.

- `fuzzphony:schema --apply` shows how many rows wait and **asks** (default no). `--force` runs without asking; a non-interactive run
  without `--force` applies the rest, skips the backfill, warns and exits with 1. Until it runs, those rows miss field-scoped and
  restricted searches on the affected columns (never a hidden field). `fuzzphony:doctor` warns while such rows exist.
- `--dump-migration` contains the backfill as a `DO` block that commits per batch: run it outside a transaction (the generated
  migration is non-transactional already).
- A full `fuzzphony:reindex` also fixes those rows.

**Going back to 0.12.3 or earlier:** run its `schema --apply` and a full reindex, or field-scoped and restricted searches miss the rows
written with a map. The `field_map` column stays unused. Change no definition between the downgrade and that reindex. 0.12.4 guards
against a stale map (an apply that finds a refresh function writing no `field_map` stops trusting every map; the doctor asks for the
reindex).

## From 0.11.x to 0.12.0

Additive: an index that uses none of the new options keeps its tables, statements and results.

### Checklist (in this order)

1. `composer update fuzzphony/fuzzphony`.
2. `bin/console fuzzphony:schema --apply` (as the owner role, or `--dump-migration`). Every index gets one or two idempotent drop
   statements (an unused value-count column, a stale `<values>_best_...` index); nothing else changes.
3. Only for an index that gets **`many: { rank: best }`** (opt-in per field): the apply builds a partial GIN index on the values table and
   adds the count column `n_<field>`. Results are right at once; run **`fuzzphony:reindex <index>`** (online) to write the counts. Until
   then a search reads the values of every candidate (slower; the doctor warns). Turning the option off drops index and column at the next apply.
4. If your search role was created from `--dump-grants`: nothing new for the values table, and a `returnable` many field adds a `SELECT` on
   its child table to the dump ([roles](docs/roles.md#the-matrix)).
5. `bin/console fuzzphony:doctor`: "Values ranking" per `rank: best` field must be green.

### What changes behaviour

- **`with()` reads the whole page in one statement** (one `UNION ALL`): `explain()` shows one `returned fields: <index>`; only matters if
  you assert statement labels.
- **A many field's name in `returnable` returns its values** (`list<string>`) instead of the source column of the same name; `Hit::$data`
  is typed `array<string, scalar|list<string>|null>`. Rename one of them if both exist.
- **A many field in `with()` follows the visibility of the search:** a viewer restricted to other fields can no longer ask for it (refused
  like an unknown name).

### Added

- **`returnable` aliases** (`{ url: slug }`, `->returnable(['url' => 'slug'])`); a granted column stays gated by its name and its column.
- **`many: { rank: best }`** (`ManyRank`) ranks a many field by its best single value, and **`SearchBuilder::manyMatch('single'|'any', ...$fields)`**
  asks that one value holds every word needed from the field. See [configuration](docs/configuration.md#several-values-per-document-many),
  [searching](docs/searching.md#several-values-one-address-manymatch). Costs 1.4 to 2 times a `rank: whole` search
  ([benchmarks](docs/benchmarks.md#ranking-by-the-best-value-rank-best)). `manyMatch('single')` needs no reindex of its own and checks at
  most 3 fields together (with 4 or more `rank: best` fields name them, else `InvalidQuery`).
- **`ScoreBreakdown::$bestValues`** (with `explainMatches()` only): the position of the value each `rank: best` field ranked the hit by.

## From 0.10.x to 0.11.0

### Added

- **Index definition classes** (`#[AsIndexDefinition]`): several indexes per entity ([configuration](docs/configuration.md#definition-classes)).
- **Multi-source indexes** (`sources:` in YAML, `->source()` / `->sourceFromTable()`): several named `SELECT`s share one set of fields; hits
  carry `kind` and the source's typed id; `fuzzphony:reindex <index> --source=<name>` rebuilds one source
  ([configuration](docs/configuration.md#several-sources-in-one-index)). A single-source index is unchanged. The shared `fuzzphony_watches`
  table gets a `source` column (`schema --apply`). Apply the release to every process (web, worker, console) before the first
  multi-source index is applied: earlier code cannot read its text key, and there is no schema-level downgrade.
- **`Hit::$index`** names the index of a hit; `Hit::$kind` is the source inside it (the index name for a single-source index). Both are in `toArray()`.
- **`EntityLoader::loadHits()`** loads mixed hits with one query per class ([integrations](docs/integrations.md#loading-mixed-results)).
- **Doctor `Watches` check and `--fail-on`:** warns about every table a `queue` or `trigger` source reads that no watch covers; `--fail-on=warning`
  (like `--strict`) fails a CI step on it ([sync](docs/sync.md#the-doctor-finds-the-tables-you-forgot-to-watch)).
- **New metrics:** worker gauges `fuzzphony.watch_log.entries`, `.oldest_age_seconds`, `fuzzphony.watch.bad_rows`, `fuzzphony.queue.failing_items`,
  `fuzzphony.queue.oldest_age_seconds` (label `index`); `fuzzphony.reindex.documents` and `fuzzphony.worker.source_rebuild_failures` (labels
  `index`, `source`) ([observability](docs/observability.md)).
- **Federated `atLeast` floor:** `->index('articles', atLeast: 3)`; `weight` refuses `NAN` and `INF` ([searching](docs/searching.md#federated-search)).
- **Returned source fields:** `returnable: [title, slug]` in the definition and `->with('title', 'slug')` in a search; one extra statement per
  source, only when asked; a returnable `granted` field is returned only to actors who hold it ([searching](docs/searching.md#returning-source-fields-with)).
  The search role needs `SELECT` on those source tables (`--dump-grants --role=search`). Adding `returnable` changes the definition hash:
  run `schema --apply` (no reindex).
- **Several values per document (`many`):** a field or filter takes its values from a child table (`city: { weight: C, many: { from: address, key: customer_id, column: city } }`).
  The index gets a `fuzzphony_<index>__values` table, a `text[]` column for a many filter and a derived watch: run `schema --apply` and
  `fuzzphony:reindex <index>`, and give the roles the new grants (`--dump-grants`). Changing an existing filter to many changes its column
  type: give it a new name ([configuration](docs/configuration.md#several-values-per-document-many)).
- `FuzzphonySearchFilter` has an `index` argument; `Fuzzphony::postgres()` also accepts definition class names.

### Changed

- **Federated results:** use `$hit->index` for the index of a hit; `$hit->kind` is the source (equal to the index name for single-source indexes).

## From 0.9.13 to 0.10.0

### Checklist (the required steps, in this order)

1. `composer update fuzzphony/fuzzphony` (PHP 8.4, PostgreSQL 14+ as before).
2. Rename in your code and configuration (the old names fail with a message that names the new one):
   - `Fuzzphony::suggest()` to `complete()`, `useSuggestion` to `useDidYouMean` (also in a Twig override), `fuzzphony:search --suggest` to `--complete`;
   - the flat `thresholds:` keys into the groups `relevance`, `typo`, `rescue`, `limits` (also `->thresholds([...])`, `new Thresholds(...)`, `--threshold`);
   - `FederatedHit` to `Hit`; anything that used an `@internal` class, `Fuzzphony::registry()` or `engine()` (use `Fuzzphony::postgres()`, `wizard()`, `indexes()`, `definition()`).
3. Searches that must see everything (exports, admin commands, Messenger handlers, tests, public catalogue pages) say `->asSystem()`; every other
   search now runs as the current user, and an index with a `granted` field needs `fuzzphony.authorization.enabled: true`.
4. `bin/console fuzzphony:schema --apply` for every index, before the new code serves traffic (new columns, new index names, one trigger set per table; online).
5. `bin/console fuzzphony:reindex <index>` only for an index that uses `code: true` (new) or whose primary key you want renamed; nothing else needs a rebuild.
6. If the application, worker and admin use separate database users, run `fuzzphony:schema --dump-grants` and adjust the grants (the writer needs
   `INSERT` on `fuzzphony_watch_log`, the worker `UPDATE` on it); optional `fuzzphony.connections: { worker, admin }` ([Database roles](docs/roles.md)).
7. Restart the workers after the apply, then run `bin/console fuzzphony:doctor`.

### Breaking

- **An actor with a tenant can no longer search another tenant.** `->forTenant($x)` different from the actor's tenant throws `AccessDenied`
  (before, the order of `asActor()` and `forTenant()` decided). A job that must search across tenants uses `->asSystem()->forTenant($x)`;
  `Fuzzphony::logSearch()` takes the same `forTenant:`.
- **A search that names no actor is made as the application's current user.** Before, a search without `->asActor()` ran no guard and saw every
  `granted` field (and was not rate limited). Now the service asks the `ActorResolver` (bundle: the Security token and, with the client IP in
  use, the request; plain PHP: `Fuzzphony::postgres(..., actors: $resolver)`); an explicit `->asActor()` still wins. Where nobody can be
  resolved (command, worker, test, request without a token) the actor is anonymous: guards run, a `granted` field stays hidden, a
  tenant-scoped index refuses it (`ActorRequired`). With `fuzzphony.authorization.enabled` a search without a token is denied (`AccessDenied`).
  - Code that searched on purpose with full access says `->asSystem()` (also on `FederatedSearch`; `Fuzzphony::complete(..., system: true)`): it
    skips the guards, is logged as the user `(system)`, and `fuzzphony.authorization.allow_system: false` forbids it (`AccessDenied`). A
    tenant-scoped index still needs `forTenant()` with it.
  - Without `guards:` / the bundle's guards nothing changes. `FederatedSearch` has `asActor()` and `asSystem()` too.
  - The rate limit now also counts searches that used to skip the guards.
- **A `granted` field needs the guard that hides it:** with `fuzzphony.authorization.enabled: false` the container build fails
  (`InvalidDefinition`), `Fuzzphony::postgres()` refuses it without `guards:`, and the doctor reports an error.
- **`restrictedToFields()` can only narrow:** a `granted` field the actor does not hold adds nothing, a list with no allowed field finds nothing
  (no exception); a name that is no field of the index is an `InvalidQuery`.
- **Hits know their index:** `Hit::$kind` (the index name) and a typed `Hit::$id` (`int` or `string`); `new Hit(kind, id, score, breakdown, ...)`.
  `FederatedHit` is removed: `$hit->kind` is the index (was `$hit->index`), `$hit->id` the id (was `$hit->hit->id`), `$hit->mergedScore` the
  fusion score (`$hit->score` is now the index's own score). `EntityLoader::load()` is unchanged.
- **New search-log column `rescued`** (existing rows `false`): apply the schema first (a missing column throws `SchemaNotApplied`).
- **The wizard's YAML writes lists as sequences** (`- [tv, television]`) and multi-line text exactly; a stored copy reads back as before.
- **Searches in a caller's transaction:** a typo-tolerant search inside your transaction uses `SAVEPOINT fuzzphony_search` instead of a nested
  transaction per statement, and no longer leaves your transaction aborted when it fails. A query-log or test that counted the old `SET`/restore round trips needs the new numbers.
- **Index objects have new names:** `fuzzphony_<index>__<kind>` (`__tsv`, `__fz`, `__f_<filter>`, `__pkey`, `__open_fz_<hash>`, `__open_tsv_<hash>`;
  `__` cannot occur in an index name), where it was `fuzzphony_<index>_<kind>`. The apply builds the new names (`CREATE INDEX CONCURRENTLY`)
  and drops the old ones; the doctor reports `OLD NAME` until then; the primary key is renamed by the next `fuzzphony:reindex`. A monitoring
  query, `REINDEX` script or `EXPLAIN` assertion of yours needs the new names.
- **Run `fuzzphony:schema --apply` before the new code serves traffic.** The columns `tsv_capped` (sidecar tables), `actor_ip` and `rescued`
  (search log) are required: a search, refresh or log write that finds one missing throws `SchemaNotApplied`; the doctor reports an error. The
  slower no-column statements, the `fuzzphony.search_log.ip_dropped` metric and the `searchLogIp` argument of `PostgresEngine` are removed.
- **One trigger set and one change log per watched table (`queue` sync).** The apply replaces the four triggers per index and table by one set
  per table (`fuzzphony_watch__<table>__ins|upd|del|trn`) in one transaction, registers the index in `fuzzphony_watches` and creates
  `fuzzphony_drain_<index>()`; queued ids stay and nothing is lost. `sync: trigger` / `trigger_level: row` are unchanged. Apply, then restart workers.
  - Queue rows appear when the worker takes a batch, not in the writing statement; a `TRUNCATE` of a table-sourced index's own table empties
    the index at that drain. A monitoring query on `fuzzphony_queue` misses what is in `fuzzphony_watch_log`: use `fuzzphony:doctor` or `queueSize()`.
  - Roles: the writer needs `INSERT` on `fuzzphony_watch_log` (was `fuzzphony_queue`); the worker `SELECT`, `UPDATE`, `DELETE` on the log,
    `SELECT`, `UPDATE` on `fuzzphony_watches`, the usual rights on the queue, and `SELECT` on every table a `watch` query reads.
  - A transaction that wrote and stays open delays the processing of everything committed after it ([limitations](docs/limitations.md#a-long-writing-transaction-delays-the-sync-of-every-index)).
  - Doctrine's `schema_filter` must let the new tables through (`SchemaAssetFilter::regex()` does).
- **A batch the worker cannot refresh because of the data is split:** only the failing ids stay queued with `attempts`, `last_error` (class and
  SQLSTATE) and `failed_at` (columns the apply adds) and a back-off from 60 s to an hour. The worker's stderr shows class and SQLSTATE only.
- **The public API is smaller (77 types, [list](docs/architecture.md#public-api)).** Everything else is `@internal`. If your code imports one, change it:

  | Was | Now |
  |---|---|
  | `new Fuzzphony(new PostgresEngine(...), new IndexRegistry([...]), new SearchGate(...))` | `Fuzzphony::postgres($connection, [$index, ...], schema: ..., metrics: ..., searchLog: ..., guards: [...], log: ..., redact: [...], ipMode: ...)` (the bundle wires it) |
  | `PostgresEngine::resetCaches()` | `Fuzzphony::resetCaches()` |
  | `DefinitionSuggester`, `SourceIntrospector`, `YamlExporter`, `BuilderExporter`, `AttributeExporter` | `$fuzzphony->wizard()`: `tables()`, `suggest($table, $name, $language)`, `yaml($definition)`, `builder($definition)`, `attributes($definition)` |
  | `Fuzzphony::engine()`, `registry()`, `SearchBuilder::toQuery()`, constructors of `SearchResult`, `ScoreBreakdown`, `SearchAnalytics`, `SearchRecord`, `ReindexResult`, `FederatedSearch`, `SearchBuilder`, `IndexDefinition`, `FuzzphonySearchFilter`; `Engine`, `PostgresEngine`, `IndexRegistry`, `SearchQuery`, `Condition`, `SearchGate`, dispatchers, `OrmSyncListener`, `SearchComponent`, redactor and metrics collectors, `IpMode`, introspection classes | `@internal` |
  | `Capabilities`, `Capability`, `Vocabulary`, `SynonymStems`, `Suggestions`, `SearchLogStore`, `RecentSearchStore` | removed: `Engine` has the methods and `capabilities()` is gone |
  | `TransactionAware` | removed: `Connection::inTransaction()` is part of `Connection` (`return false;` when it cannot tell) |
  | `analytics()` / `stemSynonyms()` throwing "engine keeps no search log" | the PostgreSQL engine supports both |
- **`suggest()` is `complete()`, `useSuggestion` is `useDidYouMean`, `fuzzphony:search --suggest` is `--complete`.** No aliases. In Twig
  overrides of `Search.html.twig` change `data-live-action-param="useSuggestion"`.
- **Thresholds are four named groups** (`relevance`, `typo`, `rescue`, `limits`); the old flat names are refused with a message that names the new key.

  | Was | Now |
  |---|---|
  | `min_score` | `relevance.min_score` / `RelevanceThresholds(minScore:)` |
  | `fuzzy_similarity` | `typo.similarity` / `TypoThresholds(similarity:)` |
  | `fuzzy_min_length` | `typo.min_length` / `TypoThresholds(minLength:)` |
  | `fuzzy_mode` | `typo.mode` / `TypoThresholds(mode:)` |
  | `fallback_below` | `typo.fallback_below` and `rescue.did_you_mean_below` (unset: the same number) |
  | `relax_when_empty` | `rescue.drop_unmatched` / `RescueThresholds(dropUnmatched:)` |
  | `did_you_mean` | `rescue.did_you_mean` / `RescueThresholds(didYouMean:)` |
  | `rescue_empty` | `rescue.correct_when_empty` / `RescueThresholds(correctWhenEmpty:)` |
  | `candidate_limit` | `limits.candidates` / `LimitThresholds(candidates:)` |
  | `max_query_length`, `max_terms` | `limits.max_query_length`, `limits.max_terms` |

  ```yaml
  # before                              # after
  thresholds:                           thresholds:
    fuzzy_mode: always                    typo: { mode: always, similarity: 0.4 }
    fuzzy_similarity: 0.4                 rescue: { correct_when_empty: false }
    rescue_empty: false                   limits: { candidates: 1000 }
    candidate_limit: 1000
  ```

  ```php
  // before
  $builder->thresholds(new Thresholds(fuzzyMode: FuzzyMode::Always, fuzzySimilarity: 0.4, rescueEmpty: false, candidateLimit: 1000));
  $fuzzphony->in('products')->query('mpuse')->thresholds(['fuzzy_mode' => 'always'])->get();
  // after
  $builder->thresholds(new Thresholds(
      typo: new TypoThresholds(mode: FuzzyMode::Always, similarity: 0.4),
      rescue: new RescueThresholds(correctWhenEmpty: false),
      limits: new LimitThresholds(candidates: 1000),
  ));
  $fuzzphony->in('products')->query('mpuse')->thresholds(['typo' => ['mode' => 'always']])->get();
  // command line: fuzzphony:search products mpuse --threshold typo.mode=always
  ```

  An error names its group (one per group, with a "did you mean"); numeric strings are accepted for every number; `rescue.correct_when_empty`
  needs `rescue.did_you_mean`, so switching the suggestion off means `rescue: { did_you_mean: false, correct_when_empty: false }`. Stored
  configurations, environment-specific YAML and Live Components need the new keys; `fuzzphony:wizard` prints the grouped form.

### Added

- **Database roles and grants:** `fuzzphony:schema --dump-grants [--role=search|worker|admin] [--to=role]` prints the least-privilege `GRANT`s for your
  real tables; `fuzzphony.connections: { worker, admin }` (each defaults to `connection`) runs the worker and the schema/reindex/doctor/analytics/purge
  commands on other DBAL connections; the doctor has a **Privileges** check (an error when a role in use lacks a grant) ([roles](docs/roles.md)).
- **`Fuzzphony::indexes()` and `Fuzzphony::definition($index)`** (unknown name: `UnknownIndex`).
- **`SearchResult::$correctedTo`** (the text the zero-result rescue ran), **`SearchRecord::$rescued` / `LoggedSearch::$rescued`** (needs the apply above).
- **`Fuzzphony::logSearch(...)`** records a search answered elsewhere (no engine call, no guard) with the usual redaction, IP mode and actor rules;
  throws `InvalidArgument` when the index has `log: false` or the log is off.
- **`toArray()` and `json_encode()`** of `SearchResult`, `Hit`, `FacetValue`, `MatchedWord` with stable keys; highlights are HTML-escaped (`<mark>` only) ([integrations](docs/integrations.md#your-own-frontend)).
- **`->explainMatches()`** adds `Hit::$matches` (which word of which field matched and how); one more statement over the page's ids, only when asked ([searching](docs/searching.md#what-matched)).
- **`code: true` on a field** (`INV-001`, `AB-12/3`): indexed with separators as spaces plus the code without them. After adding it: `schema --apply`
  and `fuzzphony:reindex` (the doctor reports the definition as changed until then).
- **`source: { query_file: ... }`** / `fromQueryFile($path, ?$baseDir = null)`: the source SQL read from a file relative to the project root or `$baseDir`
  (`..` escapes and absolute paths outside it are refused); editing the file changes the definition fingerprint.

## From 0.9.10 to 0.9.11

Nothing to do. Rankings of `asYouType()` searches may change: the exact-title and prefix bonuses compare the typed words with the title, so a
title equal to the typed text ranks first, as [ranking](docs/ranking.md) says.

## From 0.9.8 to 0.9.9

**Must do**, only if an index has a `granted` field: `fuzzphony:schema --apply`, then restart long-running processes. The apply builds two indexes over
the non-granted fields (`CREATE INDEX CONCURRENTLY`); until then the doctor reports them missing and searches run as before. After you change which
fields are `granted`, apply again (the indexes carry a hash of their definition: new ones are built, old ones dropped; a failed drop is a warning;
`--dump-migration` leaves it out). No reindex.

**Stricter configuration.** `granted` in YAML must be a non-empty string; `~`, a list, `false` or `""` now throw `InvalidDefinition` naming the field.
The whole index configuration is checked: an unknown key at any level, a string where a boolean belongs (`fuzzy: "false"` used to switch typo
tolerance ON), a list where a map belongs, and an unknown `class` throw `InvalidDefinition` with the closest key. An override of an attribute-defined
index may no longer carry `fields`, `filters`, `source`, `id_type` or `class` (change the attributes).

**Results that change.**

- `the who -rock` (top-level AND of stop words with exclusions) used to return every document without `rock` (a bug); it now searches `the who` and
  excludes `rock`. For "everything except X" use a filter.
- In a capped typo search an exact word is no longer ranked below look-alikes, `total` of a capped `fuzzy_mode: always` search can be larger (`2000+`),
  a page past `candidate_limit` is served by the typo branch, short text is highlighted whole, and swapped letters (`mosue`) get a "did you mean".
- A restricted viewer's typo candidates no longer depend on a field the viewer cannot see. Upgrade if you use `restrictedToFields()` or `granted`; no reindex.
- `RankingProfile::with()` / `->ranking()` merges `label_weights` (before, the other labels fell back to the library defaults).
- `fuzzphony:search` is unlogged by default (pass `--log`). `fuzzphony:worker` retries a failed queue after a back-off (60 s doubling to an hour) and
  exits with 1 under `--once`. Metrics no longer carry the search text (`fuzzphony.search.took_ms`, `.fallback`, `.rescued`: label `index` only).

**API changes.** `RebuildAlreadyRunning` extends `\RuntimeException` (was `\InvalidArgumentException`); a bad per-query `->thresholds()` / `->ranking()` throws
`InvalidQuery`; the 403 body of a refused search is the fixed text "Searching is not allowed for this caller."; new interface `SearchRefused`
(`AccessDenied`, `ActorRequired`, `RateLimited`); `LoggingMetricsCollector` lost `logQueryText`, a `MetricsCollector` of yours no longer gets a `query`
label; `Synonyms` has a new internal `$table` parameter (re-prepare a cached list to get the saving).

**Good to know.** One document the source cannot read (a failing cast) keeps its index's queue from draining; the worker retries with a back-off and
the failure message on stderr can contain a value from the source row, so treat worker logs as sensitive ([limitations](docs/limitations.md#one-unreadable-document-does-not-stop-its-indexs-queue)).

## From 0.9.7 to 0.9.8

Nothing is required, but run `fuzzphony:schema --apply` and then `fuzzphony:reindex` (item 5); some searches that returned nothing may now return results, and
searches with a very common word change (items 3 and 4).

1. **Two swapped neighbouring letters are tolerated** (`mosue` finds `mouse`): words of 4 to 15 letters, on the typo-tolerant branch only.
2. **The zero-result rescue** (on by default): a search that found nothing and has a word with exactly one one-edit correction in the vocabulary is run
   again with it; `SearchResult::$correctedFrom` is what the visitor typed, `interpretedAs` the corrected query, and a warning says so. A page that
   says "No results for ..." when `total` is 0 should show `correctedFrom` or switch the rescue off (`rescue_empty: false` in `thresholds`, or per
   query). `did_you_mean: false` also turns it off; tenant-scoped indexes, restricted viewers and search-as-you-type are never rescued. The search log
   records what the visitor typed, with the rescued hits.
3. **Searches with a very common word rank differently:** when a word matches more documents than `candidate_limit` (`totalIsLowerBound`), the candidates
   are the best matches by the page's sort key, not the first the index returned; equal scores come in id order; a page past the candidates (up to
   10 000 hits) is exact. Snapshots of common-word searches change; `explain()` shows `fts_fast` and `fts` CTEs. A word with more than 100 000 matches
   ranks among the first 100 000 only ([limitations](docs/limitations.md#ranking-of-very-common-words)).
4. **`hasMore()` is false at the last page of the candidates** (`SearchResult`, `FederatedResult`): true only while hits follow this page among the
   ranked candidates, never from 10 000 hits on. A pager stops at `candidate_limit` hits (2000 by default); `page(150)` asked directly is served up to
   10 000 hits. Raise `candidate_limit` for deeper paging.
5. **Phrases and field-scoped words after a very long field are found** (a fix; no change under about 16 000 words) and need a new sidecar column:
   `schema --apply` adds `tsv_capped`, `fuzzphony:reindex` fills it. Until then a phrase on an index with several fields is correct but slower (about
   17 to 22 times for a common-word phrase on 12 fields); rows not reindexed are treated as capped (right, slow). Restart workers after the apply;
   the doctor warns about the missing or unfilled column. Single-field indexes, plain words, exclusions, prefixes and typos are unaffected.

A custom `Engine` or `SearchResult` construction: the new `correctedFrom` parameter is optional and last.

## From 0.9.6 to 0.9.7

Nothing required. **A restricted viewer's phrase or field-scoped-word match no longer depends on the length of a hidden field** (a security fix: a hidden
field of more than about 16 000 words in front of an allowed one could change whether `"word1 word2"` or `secret:word1` matched). Upgrade if you
use `restrictedToFields()` or `granted`; no reindex.

## From 0.9.5 to 0.9.6

**Search log: who searched what, and the optional client IP.** Nothing is required. To keep the client address (`fuzzphony.search_log.ip: masked`,
recommended, or `full`; personal data, see [SECURITY.md](SECURITY.md)) run `fuzzphony:schema --apply` once: it adds the nullable `actor_ip` column to
`fuzzphony_search_log` (the role must own the table). New optional trailing arguments `ip` / `ipMode` on `SearchRecord` / `SearchGate`;
`recentSearches()` needs the optional `RecentSearchStore` on a custom engine.

## From 0.9.4 to 0.9.5

1. **Symfony 8.0 is no longer supported** (`^7.4 || ^8.1`): every 8.0.x is affected by `symfony/yaml` denial-of-service advisories, so Composer refuses
   it. Move to 8.1 (`composer update "symfony/*" -W` after raising your constraints) or stay on 7.4 LTS.
2. **With DoctrineBundle and `doctrine.orm` configured, the ORM wiring is now really active** (a bug fix): `#[Searchable]` entities are discovered
   (`discover_entities: false` turns that off), `EntityLoader` is a service and `sync: orm` follows the entity manager. A hand-registered
   `DoctrineIndexDiscovery`, `EntityLoader` or `OrmSyncListener` is now a duplicate of the bundle's.

## From 0.8 to 0.9

Nothing is required; the results are what 0.8 did. Optional steps:

1. **The search log** needs a table only when enabled (`fuzzphony.search_log.enabled: true`): run `schema --apply` then. The guards (`rate_limit`,
   `authorization`) need no table; they need `symfony/rate-limiter` / `symfony/security-bundle` (a missing one fails the container build).
2. **`granted` on a field** needs no schema change and no reindex, and does nothing until `fuzzphony.authorization` is enabled. For an index built
   before 0.5, reindex first (a restricted viewer of an index without the per-field columns finds nothing).
3. **Highlight names are validated upfront:** `highlight('nosuch')` throws `InvalidQuery` even when nothing is found.
4. **`explain()` no longer shows other tenants' ids:** the candidate probes now apply the search's tenant and filters.
5. `InvalidQuery` is no longer `final`; `Fuzzphony::suggest()` takes an optional trailing `?Actor`; `SearchGate` takes `guards` last.
6. `fuzzphony:search` runs no guard, with or without `--tenant` / `--user` ([Security](docs/security.md#fuzzphonysearch-and-the-guards)).

## From 0.7 to 0.8

No breaking changes. Optional: `fuzzphony:schema --apply` adds a prefix index to the vocabulary table of every typo-tolerant index (used by
`suggest()`); schedule `fuzzphony:reindex --vocabulary` so facets and completions have the right words ([Did you mean](docs/searching.md#did-you-mean)).
A custom `Engine` keeps working (facets and `exactCounts()` read new `SearchQuery` fields; implement the optional `Suggestions` interface to support
`suggest()`). `SearchResult` and `SearchQuery` got trailing constructor parameters.

## From 0.6 to 0.7

1. **Typo tolerance is proportional to the word's length.** The default `fuzzy_similarity` is no longer a flat 0.3: one typo is tolerated from 4
   letters and two from 8 (0.6 below 4 letters). Searches relying on the lenient behaviour return far fewer look-alikes; a letter replaced in the
   middle of a 5 to 7 letter word, or two swapped letters, may no longer be tolerated. To keep the old behaviour set `fuzzy_similarity: 0.3`
   (`thresholds:` or per query). No schema change, no reindex.
2. **`Thresholds::$fuzzySimilarity` is `?float`** (`null` = by word length); a custom `Engine` must apply the same rule.
3. **Vocabulary for "did you mean" (no hurry).** The new table of a fuzzy index is empty until `fuzzphony:schema --apply` and then
   `fuzzphony:reindex --vocabulary` (or any full reindex); the doctor warns "Vocabulary" meanwhile. Grants: `SELECT` for the search role (without it
   there is just no suggestion), `SELECT`, `INSERT`, `DELETE` for the reindexing role; re-run a schema-wide `GRANT ... ON ALL TABLES` after the apply.
   A tenant-scoped index never suggests (its vocabulary would mix tenants); use `did_you_mean: false` where filters hide words that must stay secret.

## From 0.4 to 0.5

1. **Apply, then reindex.** `fuzzphony:schema --apply` upgrades every index to sidecar layout 2 (`--dump-migration` contains the step); then one full
   `fuzzphony:reindex` (until then the doctor's "Documents" check warns, so `doctor --strict` fails). Deploy the code and apply together, and stop or
   restart workers: 0.5 code sends field-scoped searches to new columns that fail until the apply, and a 0.4 worker fails on the full-rebuild queue
   row a `TRUNCATE` queues (step 6).
2. **Field-scoped queries are exact:** `brand:x` no longer matches other fields of the same weight, and a scoped typo no longer matches another fuzzy
   field. The index grows by about one more copy of the text; until the reindex, field-scoped words find nothing in older documents.
3. **`--drop --apply` and `--prune-empty` ask for confirmation** (default no). A non-interactive script must add `--force`, or it fails with exit 1.
   The PHP API never prompts.
4. **Custom engines** implement `beginRebuild()`, `refreshShadow()`, `finishRebuild()`, `abortRebuild()`, `discardLeftoverRebuild()` (the minimal
   implementation: `beginRebuild()` returns `false`, `discardLeftoverRebuild()` returns `false`, the rest empty, `refreshShadow()` returns `0`).
5. **A full reindex builds next to the live index** (`fuzzphony_<index>__next`) and swaps it in, so searches never see a half-built index; plan disk
   space for a second copy. The role needs `CREATE` on Fuzzphony's schema and must own the index table, and the connection must be a session (not a
   transaction-pooling PgBouncer: use `--in-place`). A role without those rights reindexes in place and says so; `--in-place` asks for it, `--no-prune`
   always runs in place. After a swap `ReindexResult::$pruned` is `null`: check `$swapped`. A second full reindex of an index throws
   `RebuildAlreadyRunning`. A failed run leaves a rebuild behind (the doctor warns): resume with `--from` or reindex again. Call `Fuzzphony::reindex()`
   outside a transaction, and do not run `schema --apply` while a full rebuild runs (it refuses; retry).
6. **`TRUNCATE` in queue mode** queues one full-rebuild job (the row `(index_name, '*')` in `fuzzphony_queue`; `*` is a reserved string id) that the worker
   runs before the queued ids, with the reindex rights of step 5 (else in place), in the worker session's `search_path`. A role that truncates watched
   tables needs `UPDATE` on `fuzzphony_queue`. A failed rebuild is retried after a back-off and makes `fuzzphony:worker --once` exit with 1.
   The apply adds the failure columns. Custom engines implement `rebuildRequested()` and `recordRebuildFailure()`.
7. **Partitioned tables.** The apply puts the `TRUNCATE` trigger on every partition. After attaching or detaching a partition run
   `schema --apply` again (the doctor lists the gaps) and `fuzzphony:reindex <index>`.
8. **Index names must not contain `__`** (reserved for rebuild objects): such a definition fails validation. Drop the old index with 0.4
   (`schema --drop --apply`), then apply and reindex the new name on 0.5.

## From 0.3 to 0.4

1. **Exceptions.** Catch `Fuzzphony\Core\Exception\FuzzphonyException` for everything. `\ValueError` around definition building becomes
   `InvalidDefinition`; `\LogicException` from `AttributeExporter::export()` / `DoctrineNamingStrategy` becomes `InvalidArgument` / `InvalidDefinition`;
   `\PDOException` or DBAL exceptions around `explain()`, highlighting, the doctor or `SchemaPlan::apply()` become `EngineFailure` (driver exception in
   `getPrevious()`). `catch (\InvalidArgumentException)` keeps working.
2. **`IndexDefinition::with()` is gone:** `$definition->with(sync: SyncMode::Manual, tenant: null)` becomes
   `$definition->withSync(SyncMode::Manual)->withTenant(null)` (`withName()` ... `withTenant()`). Withers do not validate; registering does.
3. **Reindexing from PHP** takes a `ReindexOptions` and returns a `ReindexResult`:

   ```php
   // 0.3
   $written = $fuzzphony->reindex('products', 10_000, $onBatch, onPruned: fn(int $n) => ..., prune: false);
   // 0.4
   $result = $fuzzphony->reindex('products', new ReindexOptions(batchSize: 10_000, onBatch: $onBatch(...), prune: false));
   $written = $result->written;
   $pruned = $result->pruned;                        // was onPruned; null when pruning did not run
   $skipped = $result->pruneSkippedEmptySource;      // was onPruneSkipped
   ```

   `onBatch` must be a `\Closure`. The console command is unchanged.
4. **Removed Core helpers:** `IndexDefinition::sidecarTable()`, `TextConfig::configName()`, `IdType::sqlType()`, `FilterType::sqlType()`,
   `FilterType::compatibleSqlTypes()`, `RankingProfile::tsRankWeights()`, `Identifier::limit()`. The sidecar table is `fuzzphony_<index>` in Fuzzphony's
   schema (`public` unless set, see [Moving to a dedicated schema](#moving-to-a-dedicated-schema)).
5. **Apply the schema once:** it re-creates the functions with a fixed `search_path` and schema-qualified names, and creates `fuzzphony_meta`. Everything is
   created in and read from one configured schema, so a setup that relied on the `search_path` (one set per tenant schema) no longer works. Apply it with
   the same role and settings as your application (the functions resolve your source tables with the applying session's `search_path`). Until the next
   full reindex the doctor warns about "Documents"; run one before `doctor --strict` in CI. Roles: reindex needs `SELECT`, `UPDATE` on `fuzzphony_meta`,
   the doctor `SELECT`; re-run a schema-wide `GRANT` after the first apply.
6. **Custom engines** implement `Engine::recordReindex(IndexDefinition $index): void` (an empty body is fine).
7. **Internal classes.** Only the [Public API](docs/architecture.md#public-api) is covered by these notes. Move from `@internal` classes (`Reindexer`,
   `Worker`, `ArrayDefinitionLoader`, `QueryParser`, ...) to `Fuzzphony`, `IndexDefinition::builder()` and the bundle's configuration; PHPStan reports such uses.

### Moving to a dedicated schema

Optional. Nothing moves by itself. The sync triggers on your tables keep their names, so the new `schema --apply` re-points them to the new functions; a
`--drop` with the old configuration run *afterwards* would remove them again.

**Without downtime** (the deployed application keeps searching the old tables until you deploy the new setting):

1. Prepare `fuzzphony.schema: fuzzphony` (or `schema:` for `PostgresEngine`) in a separate checkout pointed at the same database; keep the deployed application on the old setting.
2. From that checkout: `bin/console fuzzphony:schema --apply` (creates the schema and every object, re-points the triggers: queue and trigger sync now feed the new objects, the old tables go stale).
3. `bin/console fuzzphony:reindex` fills the new sidecar tables (search keeps using the old ones meanwhile).
4. Deploy the new setting. With ORM sync, run `bin/console fuzzphony:reindex` once more.
5. Drop the old objects by hand (never with `--drop`):

   ```sql
   DROP TABLE public.fuzzphony_<index>;              -- one per index
   DROP TABLE public.fuzzphony_queue, public.fuzzphony_meta;
   DROP FUNCTION public.fuzzphony_refresh_<index>, public.fuzzphony_sync_<index>__<table>, public.fuzzphony_norm;
   DROP TEXT SEARCH CONFIGURATION public.fuzzphony_<language>;
   DROP TEXT SEARCH DICTIONARY public.fuzzphony_<language>_stop;
   ```

**With a short search outage:** with the old configuration `bin/console fuzzphony:schema --drop --apply` (the queue table, `fuzzphony_norm` and the text
search configurations stay and can be dropped as above); then set the schema, `fuzzphony:schema --apply`, `fuzzphony:reindex`.

`fuzzphony:doctor` warns ("Schema") when the configured schema has no sidecar table for an index but `public` still has one.

### Doctrine Migrations

With DoctrineBundle the bundle sets the DBAL `schema_filter` of Fuzzphony's connection, so `doctrine:migrations:diff` stops proposing to drop `fuzzphony_*`
tables. If your connection has its own `schema_filter` the bundle leaves it alone and the doctor warns with the regex to merge. If `connection` or `schema`
comes from a parameter or environment variable the bundle sets no filter and the doctor does not check yours: add Fuzzphony's regex yourself. A migration
from `--dump-migration` can be generated again after upgrading (it now ends with the version record).

## From 0.3.1 to 0.3.2

Nothing to do: the trigram operator in generated statements is now schema-qualified (`pg_trgm` no longer has to be on the `search_path`).

## From 0.3.0 to 0.3.1

Only indexes with accent folding (`unaccent`, the default) are affected: their text search configuration kept accented stop words (`für`, `és`, `à`).
Run `bin/console fuzzphony:schema --apply` (creates the stop-word dictionary `fuzzphony_<language>_stop`, re-maps the configuration) and
`bin/console fuzzphony:reindex` for every such index; until reindexed, documents can rank differently. The doctor reports the configuration until applied.

## From 0.2 to 0.3

1. **Requirements.** Symfony 7.4 or 8.0 (7.3 dropped) and the `pdo_pgsql` extension (now required).
2. **Apply the schema, then reindex once** (sync functions changed, every watched table gets a `TRUNCATE` trigger; both are safe on a live index):
   `bin/console fuzzphony:schema --apply`, then `bin/console fuzzphony:reindex` (also removes documents left behind by earlier TRUNCATEs).
3. **`fuzzphony:search --profile` is now `--rank-profile`** (`-p` unchanged).
4. **A full reindex now prunes orphaned documents.** If the reindexing session sees fewer rows than your application (row-level security, another
   `search_path`), pass `--no-prune` / `prune: false`. A source that returns no row is never pruned without `--prune-empty`.
5. **Custom engines** implement `pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int`.
6. **Removed:** `NodeInspector::topLevelExclusions()` (walk the AST for top-level `Not` nodes).
7. **Behaviour:** typo-tolerant results are narrower (every query word must match on its own); a multi-word query that finds nothing may return results
   for a subset of its words with a warning (`relax_when_empty: false` disables it; the warning quotes the user's words: escape it in HTML);
   `ScoreBreakdown::$fuzzySimilarity` is per word, so absolute scores in `fuzzy_mode: always` shift slightly.

## From 0.1 to 0.2

`OrmSyncListener` takes a `RefreshDispatcher` instead of an `Engine` (the bundle wires it; otherwise `new ImmediateRefreshDispatcher($engine)`).

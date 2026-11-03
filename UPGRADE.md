# Upgrading

Before 1.0, a minor version may contain breaking changes. Each section lists what to change,
and the [CHANGELOG](CHANGELOG.md) has the full list of changes.

## From 0.10.x to 0.11.0

### Added

- **Index definition classes** (`#[AsIndexDefinition]`, public): several indexes per entity, see [Definition classes](docs/configuration.md#definition-classes). Nothing to do for
  existing indexes: attribute, YAML and builder definitions, fingerprints and the schema are unchanged.
- **Multi-source indexes** (`sources:` in YAML, `->source()` / `->sourceFromTable()` on the builder, also in a definition class): several named `SELECT`s share one set of fields, with their own watches, entity and id type; hits carry `kind` and the source's typed id; `fuzzphony:reindex <index> --source=<name>` rebuilds one source ([Configuration](docs/configuration.md#several-sources-in-one-index)). Nothing to do for existing indexes: a single-source index keeps its sidecar, statements and fingerprint. The shared watch registry `fuzzphony_watches` gets a `source` column (`fuzzphony:schema --apply` adds it). Public API added: `IndexSource`, `IndexBuilder::source()`, `IndexBuilder::sourceFromTable()`, `ReindexOptions::$source`, `ReindexResult::$perSource`. Apply the release to every process (web, worker, console) before the first multi-source index is applied: earlier code cannot read its text key, and there is no schema-level downgrade.
- **`Hit::$index`** (new) names the index a hit comes from; `Hit::$kind` is the source inside it (the index name for a single-source index). Both are in `toArray()` / JSON (`index`, `kind`). `new Hit(...)` (internal) takes an optional trailing `index`.
- **`EntityLoader::loadHits()`** loads the entities of mixed hits (several sources or indexes, several entity classes) with one query per class ([Integrations](docs/integrations.md#loading-mixed-results)).
- **`FuzzphonySearchFilter`** has an `index` argument (default: the index of the resource's entity).
- `Fuzzphony::postgres()` also accepts the class names of definition classes next to `IndexDefinition` objects.

### Changed

- **Federated results:** use `$hit->index` for the index a hit comes from. `$hit->kind` is the hit's source: equal to the index name for single-source indexes (nothing changes for them), the source name for a multi-source index.

## From 0.9.13 to 0.10.0

### Checklist (the required steps, in this order)

1. `composer update fuzzphony/fuzzphony` (PHP 8.4, PostgreSQL 14+ as before).
2. Rename in your code and configuration (the old names fail with a message that names the new one):
   - `Fuzzphony::suggest()` to `complete()`, `useSuggestion` to `useDidYouMean` (also in a Twig override), `fuzzphony:search --suggest` to `--complete`;
   - the flat `thresholds:` keys (`fuzzy_mode`, `rescue_empty`, `candidate_limit`, ...) into the groups `relevance`, `typo`, `rescue`, `limits` (also `->thresholds([...])`, `new Thresholds(...)`, `--threshold`);
   - `FederatedHit` to `Hit` (`$hit->kind`, `$hit->mergedScore`); anything that used an `@internal` class, `Fuzzphony::registry()` or `engine()` (use `Fuzzphony::postgres()`, `wizard()`, `indexes()`, `definition()`).
3. Searches that must see everything (exports, admin commands, Messenger handlers, tests, public catalogue pages) say `->asSystem()`; every other search now runs as the current user,
   and an index with a `granted` field needs `fuzzphony.authorization.enabled: true`.
4. `bin/console fuzzphony:schema --apply` for every index, before the new code serves traffic (new columns, new index names, one trigger set per table; the apply is online).
5. `bin/console fuzzphony:reindex <index>` only for an index that uses `code: true` (new) or whose primary key you want renamed to the new scheme; nothing else needs a rebuild.
6. If the application, worker and admin use separate database users, run `fuzzphony:schema --dump-grants` and adjust the grants (the writer needs `INSERT` on `fuzzphony_watch_log`, the worker
   `UPDATE` on it); optional `fuzzphony.connections: { worker, admin }` ([Database roles](docs/roles.md)).
7. Restart the workers after the apply, then run `bin/console fuzzphony:doctor`.

The details of each change follow.

### Breaking

- **An actor with a tenant can no longer search another tenant.** `->forTenant($x)` with `$x` different from the tenant of the actor (the named one, or the current user when none is named) now throws `AccessDenied` instead of searching `$x` while the search log recorded the user under it. Before, the order of `asActor()` and `forTenant()` decided who won, and the ambient user never overrode `forTenant()`. A job that must search across tenants uses `->asSystem()->forTenant($x)`; `Fuzzphony::logSearch()` takes the same `forTenant:` for a system record.
- **A search that names no actor is made as the application's current user.** Until now a search without `->asActor()` ran no guard: a controller that forgot it
  searched as everybody and saw every `granted` field (and was not rate limited). Now the service asks the `ActorResolver` itself (the Symfony bundle: the Security
  token and, with the client IP in use, the request; plain PHP: `Fuzzphony::postgres(..., actors: $resolver)`), so every search is guarded and logged as that actor, and an
  explicit `->asActor($actor)` still wins. Where nobody can be resolved (a command, a worker, a test, a request without a token) the actor is anonymous: the
  guards run for it, a `granted` field stays hidden, a tenant-scoped index still refuses it (`ActorRequired`: a tenant must be known). With
  `fuzzphony.authorization.enabled` a search without a token is denied (`AccessDenied`) as before. What to change:
  - Code that searched on purpose with full access (an export, an admin command, a Messenger handler, a test) says so: `->asSystem()` (on `SearchBuilder`,
    `FederatedSearch`; `Fuzzphony::complete(..., system: true)`). It skips the guards, is written to the search log as the user `(system)`, and can be switched off with
    `fuzzphony.authorization.allow_system: false` (then it throws `AccessDenied`). A tenant-scoped index still needs `forTenant()` with it.
  - Without `Fuzzphony::postgres(guards: ...)` or the bundle's guards nothing changes: there is no guard to run.
  - `FederatedSearch` has `asActor()` and `asSystem()` too (one actor for every index; a `configure` closure may still name another).
  - The rate limit now also counts the searches that used to skip the guards (`per: ip` limits a controller that forgot `asActor()`).
- **A `granted` field needs the guard that hides it.** An index with a `granted` field while `fuzzphony.authorization.enabled` is false fails the container build
  (`InvalidDefinition`: enable `fuzzphony.authorization`), `Fuzzphony::postgres()` refuses it without `guards:`, and `fuzzphony:doctor` reports it as an error.
  Before, the field was visible to everybody and the flag only looked like protection.
- **`restrictedToFields()` can only narrow.** The fields searched are the ones in the list that the actor may also see (the guards' answer): naming a `granted` field the
  actor does not hold adds nothing, and a list with none of the allowed fields finds nothing (no exception). A name that is no field of the index is an `InvalidQuery`
  listing the index's fields.
- **Hits know their index: `Hit::$kind` (the index name) and a typed `Hit::$id` (`int` for an integer key, `string` for a uuid or string key).** `new Hit(kind, id, score, breakdown, ...)`
  has the kind first. `FederatedHit` is removed: a `FederatedResult` holds plain `Hit`s, `$hit->kind` is the index (was `$hit->index`), `$hit->id` the id (was `$hit->hit->id`) and `$hit->mergedScore`
  the fusion score (was `$hit->score` of the `FederatedHit`; `$hit->score` is now the index's own score). `EntityLoader::load()` is unchanged: pass the result of the index of that entity.
- **New search-log column `rescued`.** Run `fuzzphony:schema --apply` before the new code serves traffic (a missing column throws `SchemaNotApplied`, the doctor reports it).
  Existing rows are `false`.
- **The YAML of the wizard (`$fuzzphony->wizard()->yaml()`, `fuzzphony:wizard`) writes lists as YAML sequences** (`synonyms:` with `- [tv, television]`; it wrote numbered keys `0: 0: tv`)
  and a multi-line text exactly (`|-` without a final newline). A stored copy reads back as before; regenerate it to get the clean form.
- **Searches in a caller's transaction.** A typo-tolerant search inside a transaction you opened sends `SAVEPOINT fuzzphony_search` at its first
  threshold and `RELEASE SAVEPOINT fuzzphony_search` at its end (or `ROLLBACK TO` and `RELEASE` when it fails), instead of a nested transaction
  per statement. With `PdoConnection` a search that fails after its first threshold no longer leaves your transaction aborted (the DBAL bridge already
  behaved so). A query log or a test that counted the old per-statement `SET`/restore round trips needs the new numbers.
- **Index objects have new names.** The secondary indexes and the primary key of a sidecar table are now `fuzzphony_<index>__<kind>`
  (`fuzzphony_products__tsv`, `__fz`, `__f_<filter>`, `__pkey`, `__open_fz_<hash>`, `__open_tsv_<hash>`; `__` cannot occur in an index name), where they were
  `fuzzphony_<index>_<kind>`: an index named `products_open` collided with the open-class objects of `products`. `fuzzphony:schema --apply` builds the indexes
  under the new names (`CREATE INDEX CONCURRENTLY`, the table stays writable) and then drops the old ones (`DROP INDEX CONCURRENTLY`); the doctor
  reports an old one as `OLD NAME` until then. The primary key keeps its old name until the next `fuzzphony:reindex` renames it. Until the apply, the
  doctor reports the indexes missing and searches run as before. Anything of yours that names these indexes (a monitoring query, a `REINDEX`
  script, an `EXPLAIN` assertion) needs the new names.
- **Run `fuzzphony:schema --apply` before the new code serves traffic.** The columns `tsv_capped` (sidecar tables), `actor_ip` and `rescued` (search log) are
  required. A search, refresh or log write that finds one missing throws `Fuzzphony\Core\Exception\SchemaNotApplied` ("The Fuzzphony schema is
  not up to date (missing: ...). Run: bin/console fuzzphony:schema --apply"); `fuzzphony:doctor` reports the same as an error. The slower
  no-column statements, the per-process catalog lookups for these columns, the retry of a log batch without `actor_ip`, the metric
  `fuzzphony.search_log.ip_dropped` and the `searchLogIp` constructor argument of `PostgresEngine` are removed. Long-running workers need no
  restart for these columns any more (only the open-class indexes of `granted` fields are still looked up once per process).
- **One trigger set and one change log per watched table (`queue` sync, statement-level triggers).** `fuzzphony:schema --apply` replaces the four
  triggers per index and table (`fuzzphony_sync_<index>__<table>_ins|upd|del|trn`) by one set per table (`fuzzphony_watch__<table>__ins|upd|del|trn`)
  in the same transaction, registers the index in `fuzzphony_watches` and creates `fuzzphony_drain_<index>()`; ids already in the queue stay and are
  processed as before, nothing is lost during the swap. `sync: trigger` and `trigger_level: row` are unchanged. Deploy: apply the schema, then restart the
  workers. What changes for you:
  - The queue rows of a change appear when the worker takes a batch (it drains the log first), not in the writing statement; a `TRUNCATE` of a
    table-sourced index's own table empties the index at that drain. Anything that read `fuzzphony_queue` directly (a monitoring query) misses
    what is still in `fuzzphony_watch_log`: use `fuzzphony:doctor` ("Sync queue" counts both) or `queueSize()`.
  - Roles: the role that writes a watched table needs `INSERT` on `fuzzphony_watch_log` (it was `INSERT` on `fuzzphony_queue`); the worker's role needs
    `SELECT`, `UPDATE`, `DELETE` on the log (`DELETE ... FOR UPDATE` needs `UPDATE`), `SELECT`, `UPDATE` on `fuzzphony_watches`, the usual rights on the queue, and `SELECT` on every table a `watch` query
    reads (the query now runs in the worker, not in the writing statement).
  - A transaction that wrote and stays open delays the processing of everything committed after it until it ends ([limitations](docs/limitations.md#a-long-writing-transaction-delays-the-sync-of-every-index)).
  - Doctrine's `schema_filter` must let the new tables through the same way it does for `fuzzphony_queue` (`SchemaAssetFilter::regex()` already does).
- **A batch the worker cannot refresh because of the data is split.** Only the ids that fail on their own stay queued, with `attempts`, `last_error`
  (exception class and SQLSTATE) and `failed_at` (three columns the apply adds to `fuzzphony_queue`) and a back-off from 60 s to an hour; the doctor names them. The
  worker's stderr shows the exception class and SQLSTATE only (the driver message used to be printed: a log scraper that matched it needs the SQLSTATE).
- **The public API is smaller (77 types, [list](docs/architecture.md#public-api)).** Everything that is not on the list is tagged `@internal` and may change in any
  release. If your code imports one of these, change it:

  | Was | Now |
  |---|---|
  | `new Fuzzphony(new PostgresEngine($connection, ...), new IndexRegistry([...]), new SearchGate(...))` | `Fuzzphony::postgres($connection, [$index, ...], schema: ..., metrics: ..., searchLog: ..., guards: [...], log: ..., redact: [...], ipMode: ...)` (the bundle wires it for you) |
  | `PostgresEngine::resetCaches()` | `Fuzzphony::resetCaches()` |
  | `new DefinitionSuggester()`, `SourceIntrospector`, `YamlExporter`, `BuilderExporter`, `AttributeExporter` | `$fuzzphony->wizard()`: `tables()`, `suggest($table, $name, $language)`, `yaml($definition)`, `builder($definition)`, `attributes($definition)` (null when attributes cannot express it) |
  | `Fuzzphony::engine()`, `Fuzzphony::registry()`, `new Fuzzphony(...)`, `SearchBuilder::toQuery()`, the constructors of `SearchResult`, `ScoreBreakdown`, `SearchAnalytics`, `SearchRecord`, `ReindexResult`, `FederatedSearch`, `SearchBuilder`, `IndexDefinition`, `FuzzphonySearchFilter` | `@internal` |
  | `Engine`, `PostgresEngine`, `IndexRegistry`, `SearchQuery`, `Condition`, `Operator`, `SearchGate`, `RefreshDispatcher`, `ImmediateRefreshDispatcher`, `RefreshDocuments`, `OrmSyncListener`, `SearchComponent`, `PatternQueryRedactor`, `LoggingMetricsCollector`, `NullMetricsCollector`, `IpMode`, `ColumnKind`, `ColumnProfile`, `DefinitionSuggester`, `ForeignKey`, `SourceIntrospector`, `TableProfile`, the three exporters | `@internal` (not on the public list) |
  | `Capabilities`, `Capability`, `Vocabulary`, `SynonymStems`, `Suggestions`, `SearchLogStore`, `RecentSearchStore` (optional `Engine` interfaces) | removed: `Engine` has the methods (`rebuildVocabulary()`, `stemSynonyms()`, `suggestWords()`, `writeSearchLog()`, `purgeSearchLog()`, `topQueries()`, `zeroResultQueries()`, `recentSearches()`, `introspector()`) and `capabilities()` is gone |
  | `TransactionAware` | removed: `Connection::inTransaction()` is part of the `Connection` interface. A `Connection` of your own must implement it (`return false;` when it cannot tell: the engine then treats the connection as outside a transaction) |
  | `Fuzzphony::analytics()` throwing "the engine keeps no search log", `stemSynonyms()` throwing "the engine does not prepare synonyms" | the PostgreSQL engine supports both: nothing to catch |
- **`Fuzzphony::suggest()` is `complete()`, `useSuggestion` is `useDidYouMean`, `fuzzphony:search --suggest` is `--complete`.** No aliases: the old names fail. In your
  Twig overrides of `Search.html.twig` change `data-live-action-param="useSuggestion"` to `useDidYouMean`.
- **Thresholds are four named groups.** The 11 flat options of `Thresholds` (constructor, `->thresholds([...])` per query, `thresholds:` in the YAML/array configuration,
  `fuzzphony:search --threshold`) are split into `relevance`, `typo`, `rescue` and `limits`. The old flat names are refused with a message that names the new key.

  | Was | Now |
  |---|---|
  | `min_score` / `minScore` | `relevance.min_score` / `RelevanceThresholds(minScore:)` |
  | `fuzzy_similarity` / `fuzzySimilarity` | `typo.similarity` / `TypoThresholds(similarity:)` |
  | `fuzzy_min_length` / `fuzzyMinLength` | `typo.min_length` / `TypoThresholds(minLength:)` |
  | `fuzzy_mode` / `fuzzyMode` | `typo.mode` / `TypoThresholds(mode:)` |
  | `fallback_below` / `fallbackBelow` | `typo.fallback_below` (typo tolerance) and `rescue.did_you_mean_below` (the did-you-mean trigger; unset: the same number) |
  | `relax_when_empty` / `relaxWhenEmpty` | `rescue.drop_unmatched` / `RescueThresholds(dropUnmatched:)` |
  | `did_you_mean` / `didYouMean` | `rescue.did_you_mean` / `RescueThresholds(didYouMean:)` |
  | `rescue_empty` / `rescueEmpty` | `rescue.correct_when_empty` / `RescueThresholds(correctWhenEmpty:)` |
  | `candidate_limit` / `candidateLimit` | `limits.candidates` / `LimitThresholds(candidates:)` |
  | `max_query_length` / `maxQueryLength` | `limits.max_query_length` / `LimitThresholds(maxQueryLength:)` |
  | `max_terms` / `maxTerms` | `limits.max_terms` / `LimitThresholds(maxTerms:)` |

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

  What else changes: an error names its group (`thresholds.typo`, one error per group, with a "did you mean" for a misspelled option and the allowed options of the group);
  numeric strings (`"0.1"`, `"500"`) are accepted for every number; `rescue.correct_when_empty` needs `rescue.did_you_mean`, so switching the suggestion off now means
  `rescue: { did_you_mean: false, correct_when_empty: false }` (the combination `did_you_mean: false` alone is refused at configuration time instead of being ignored).
  Thresholds in a stored configuration, an environment-specific YAML or a Live Component of yours need the new keys; `fuzzphony:wizard` prints the grouped form.

### Added

- **Database roles and grants:** `fuzzphony:schema --dump-grants [--role=search|worker|admin] [--to=role]` prints the `GRANT` statements of the least-privilege roles for your real tables;
  `fuzzphony.connections: { worker, admin }` (each defaults to `connection`, so nothing changes without it) runs `fuzzphony:worker` and the async refresh, and `fuzzphony:schema`,
  `:reindex`, `:doctor`, `:analytics` and `:search-log:purge`, as other DBAL connections; the doctor has a **Privileges** check (an **error** when a role in use lacks a grant, e.g. the
  worker's `UPDATE` on `fuzzphony_watch_log`, which the 0.10 drain needs). Nothing to do unless you want separate users: [Database roles](docs/roles.md).
- **`Fuzzphony::indexes()` and `Fuzzphony::definition($index)`** (public): the names of the registered indexes and the `IndexDefinition` of one (unknown name: `UnknownIndex`), so an
  application no longer needs the `@internal` `registry()`.
- **`SearchResult::$correctedTo`**: the text the zero-result rescue ran (`$correctedFrom` is what the visitor typed); both null without a rescue.
- **`SearchRecord::$rescued` / `LoggedSearch::$rescued`**: the search log marks the searches the rescue answered. The "found nothing" report still counts by hits
  (a rescued search found something). Needs `fuzzphony:schema --apply` (above).
- **`Fuzzphony::logSearch($index, $text, $hits, $tookMs, $actor, $usedFuzzy, $rescued, $system)`** records a search that was answered elsewhere without running it (no engine call, no guard),
  with the redaction, IP mode and actor rules of a search. It throws `InvalidArgument` when the index has `log: false` or the search log is off, and returns the `LoggedSearch`.
- **`toArray()` and `json_encode()` of `SearchResult`, `Hit`, `FacetValue`, `MatchedWord`** with stable keys (`hits`, `total`, `totalIsLowerBound`, `facets`, `didYouMean`,
  `correctedFrom`, `correctedTo`, `warnings`, `tookMs`; a hit: `index`, `kind`, `id`, `score`, `breakdown`, `highlights`, `mergedScore`, `matches`). The highlights are already HTML-escaped
  (`<mark>` only); everything else is plain text ([Your own frontend](docs/integrations.md#your-own-frontend)).
- **`->explainMatches()`** on the search builder adds `Hit::$matches` to the hits of the returned page: which word of which field matched and how (`exact`, `stem`, `prefix`,
  `typo` with its similarity, `synonym`). One more statement over the page's ids, only when asked; a restricted viewer gets no hidden field and no hidden word ([searching](docs/searching.md#what-matched)).
- **`code: true` on a field.** A code or identifier field (`INV-001`, `AB-12/3`, `INV.007`) is indexed with every run of non-alphanumeric characters as one space
  (`inv`, `001`) plus the code without separators (`inv001`), in the index's own text configuration, so what is typed reaches what is stored. Nothing changes for a
  field without the flag. After adding it: `fuzzphony:schema --apply` and `fuzzphony:reindex` (the doctor reports the definition as changed until then). A code whose parts are
  stop words of the language (`IT-5` in English) also finds other rows with the same remaining parts; the exact match ranks first. Replaces the `regexp_replace` workaround in the source query.
- **`source: { query_file: ... }`** / `fromQueryFile($path, ?$baseDir = null)`: the SQL of the source query read from a file, relative to the project root (the bundle) or to `$baseDir`
  (default: the working directory); `..` escapes and absolute paths outside it are refused. The file is read when the definition loads, so editing it changes the definition fingerprint.

## From 0.9.10 to 0.9.11

**What you must do.** Nothing. The rankings of searches made with `asYouType()` (the Twig search component, `--as-you-type`) may change: the
exact-title and prefix bonuses now compare the words the visitor typed with the title, so a title equal to the typed text ranks first, as
[ranking](docs/ranking.md) says. Searches without `asYouType()` and their statements are unchanged.

## From 0.9.8 to 0.9.9

**What you must do.** Only if an index has a `granted` field: run `fuzzphony:schema --apply`, then restart long-running processes
(Messenger workers, RoadRunner, FrankenPHP: the engine looks the new indexes, and the `tsv_capped` / `actor_ip` columns an apply may add
to an existing table, up once per process; the command says so). The apply builds two indexes over the fields that are not granted, with
`CREATE INDEX CONCURRENTLY` (the table stays writable); until then the doctor reports them missing and the definition as changed, and
searches run as before. After you change which fields are `granted` later, run it again: the indexes carry a hash of their definition, the
apply builds the new ones and drops the old (each with `DROP INDEX CONCURRENTLY`, found by the end of its name at any name length, so no search waits behind the drop; a drop that fails is a warning, run the apply again; `--dump-migration` leaves this step out), and the doctor calls an old one stale. No reindex is required, no setting is new, and
indexes without a `granted` field change nothing.

**Stricter configuration.** `granted` in YAML or an array must be a non-empty string: `granted: ~`, a key without a value, a list of
roles, `false` and an empty string used to load (as an open field, or one that is always hidden) and now throw an `InvalidDefinition` that
names the field. Fix the key the message names, or remove it to leave the field open.

**Good to know (not new, now documented).** One document the source cannot read (a failing cast) keeps the whole queue of its index from draining; the worker retries it with a back-off and the other indexes go on. The failure message printed to stderr can contain a value from the source row, so treat worker logs as sensitive ([limitations](docs/limitations.md#one-unreadable-document-does-not-stop-its-indexs-queue)).

**Results that change.**

- A top-level AND of stop words with exclusions (`the who -rock`) used to return every document without `rock` (a bug); it now searches
  `the who` and leaves out the documents that have `rock`, so such a query returns far fewer results. If you showed a page for
  "everything except X" through it, use a filter instead.
- An exact word is no longer ranked below its look-alikes in a capped typo search (a full-text match keeps its typo score), `total` of a
  capped `fuzzy_mode: always` search can be larger (`2000+`, `2667+`) because those matches count, a page past `candidate_limit` is served
  by the typo-tolerant branch and by a filter-only listing, a short text is highlighted whole (`Ergonomic mouse 1001`, not
  `Ergonomic mouse`), and swapped letters (`mosue`) get a "did you mean" and a mark. A capped typo search is **not** slower for an
  unrestricted viewer or a tenant (25 to 75 ms on 200 000 products). A word with more matches than the limit contributes the first matches
  of the table, not the closest spellings (a lower bound, like `total`).
- A restricted viewer's typo candidates are the best by the page's sort key within a work budget (one more selection), and no longer depend
  on a field the viewer cannot see: `mouse*`, a word that grows in normalisation (`ßßßab`) and the size of the visible text are looked up
  and counted without the hidden fields. A viewer who may see every typo-tolerant field gets the unrestricted lookup. If you use
  `restrictedToFields()` or `granted` fields, upgrade; no reindex is needed.
- `RankingProfile::with()` / `->ranking()` merges `label_weights`: a per-query override that names some labels keeps the profile's own
  weights for the others (before, the others fell back to the library defaults 1.0 / 0.4 / 0.2 / 0.1). Only code that passes
  `label_weights` per query on a profile with non-default labels sees other scores.
- The index configuration is checked as a whole: an unknown key at any level (`wieght`, `fuzy`, `tabel`, `colums`), a string where a boolean
  belongs (`fuzzy: "false"` used to switch typo tolerance ON; use `true`/`false`), `fields: [name, body]` or `profiles: popular` (a list or
  a string where a map belongs) and an unknown `class` throw an `InvalidDefinition` that names the place and suggests the closest key. An
  override of an attribute-defined index may no longer carry `fields`, `filters`, `source`, `id_type` or `class` (they were silently
  dropped); change the attributes instead. Correct configurations are unaffected.
- `fuzzphony:search` is unlogged by default (it wrote a row to the search log, with the `--user` / `--tenant` you gave it, for every run):
  pass the new `--log` to write one. `fuzzphony:worker` now also retries a queue it could not process after a back-off (60 s, doubling to
  an hour) while the other indexes go on, and exits with code 1 under `--once`.
- Metrics no longer carry the search text: `fuzzphony.search.took_ms`, `.fallback` and `.rescued` have the single label `index`.

**API changes.**

- `RebuildAlreadyRunning` now extends `\RuntimeException` (it was an `\InvalidArgumentException`): a `catch (\InvalidArgumentException)`
  around a reindex no longer catches it, catch `RebuildAlreadyRunning` itself. A bad per-query `->thresholds([...])` / `->ranking([...])`
  throws `InvalidQuery` (was `InvalidDefinition`). The 403 body of a refused search is the fixed text "Searching is not allowed for this
  caller." (the exception in `previous` has the details). New interface `SearchRefused`, implemented by `AccessDenied`, `ActorRequired` and
  `RateLimited`; nothing else about them changes.
- The `LoggingMetricsCollector` constructor lost its `logQueryText` argument: remove the named argument if you pass it. A
  `MetricsCollector` of your own no longer receives a `query` label; drop any code that read it.
- `Synonyms` has a new last constructor parameter `$table` (internal: the lookup table `Fuzzphony::stemSynonyms()` now builds). A list
  prepared and cached by an older version still works (it has no table and builds it as before); prepare it again to get the saving. The
  cached object is about 2.5 times larger.

## From 0.9.7 to 0.9.8

Nothing is required, but **run `fuzzphony:schema --apply` and then `fuzzphony:reindex`** (item 5), **searches that returned nothing may now return results**, in two ways, and **the results of searches with a very common word change** (items 3 and 4):

1. **Two swapped neighbouring letters are tolerated** (`mosue` finds `mouse`): a typed word of 4 to 15 letters also matches its
   neighbour-swapped variants as whole words on the typo-tolerant branch (below exact hits, in typo-tolerant fields only; also on
   tenant-scoped indexes). It only changes a search that already ran the typo-tolerant branch.
2. **The zero-result rescue** (on by default): a search that found nothing and has a word with exactly one one-edit correction in
   the did-you-mean vocabulary (`mpuse` -> `mouse`) is run again with it, and the result is flagged:
   `SearchResult::$correctedFrom` is the text the visitor typed (`null` otherwise), `interpretedAs` is the corrected query and a
   warning says so. If your page says "No results for ..." when `$result->total` is 0, it now shows results for the corrected text:
   show `correctedFrom` ("Showing results for mouse, search instead for mpuse") or switch the rescue off with `rescue_empty: false`
   in the index's `thresholds` (or per query: `->thresholds(['rescue_empty' => false])`). The suggestion `didYouMean` stays as it
   was for every search that is not rescued. `did_you_mean: false` also turns the rescue off; tenant-scoped indexes, restricted
   viewers and search-as-you-type are never rescued. The search log records what the visitor typed, with the rescued hits, so
   the zero-result report no longer lists a search the rescue answered.

3. **Searches with a very common word rank differently (for the better).** When a word matches more documents than `candidate_limit`
   (`totalIsLowerBound` is true), the full-text candidates are now the best matches by the page's own sort key instead of the first ones
   the index returned: the exact title and the word repeated come first, equal scores come in id order, and a page past the candidates
   (`offset + limit` up to 10 000) is exact. Searches with fewer matches than the limit return exactly what they did. If you
   snapshot or assert the hits of a common-word search, expect other documents; `explain()` shows a `fts_fast` and an `fts` CTE in the
   statement. A search of a word with more than 100 000 matches ranks among the first 100 000 only (a cost bound, not a setting), and
   a typo-tolerant word is still approximate ([limitations](docs/limitations.md#ranking-of-very-common-words)).
4. **`hasMore()` is false at the last page of the candidates.** `SearchResult::hasMore()` and `FederatedResult::hasMore()` used to be
   true whenever `totalIsLowerBound` was true, so a pager that followed it offered an empty page after the last candidate (page 101 at
   `candidate_limit: 2000` and 20 per page). Now it is true only while hits follow this page among the ranked candidates (`offset + count($hits) < $total`), never for an empty page and
   never from 10 000 hits on (the most a search ranks, also with `exactCounts()`, whose total counts every match: before, a pager that followed
   `hasMore()` there never stopped), and a federated result is false where the next `limit()` would be refused (1 000 hits of an index).
   A pager built on it stops at `candidate_limit` hits (2000 by default) and does not offer deeper pages; a page asked for directly
   (`page(150)`) is served, exact, up to 10 000 hits, and the lower-bound total grows with it (`2000+`, then `3000+`, ...). If you want deep paging
   in the pager, raise `candidate_limit` or use `totalIsLowerBound` yourself (it did not change). Nothing else to do; no configuration changed.
5. **Phrases and field-scoped words after a very long field are found** (a fix, no change for texts under about 16 000 words), and
   the fast way to find them needs a new sidecar column. `fuzzphony:schema --apply` adds `tsv_capped` (a nullable boolean, only when
   it is missing, so an apply that finds it takes no lock) and `fuzzphony:reindex` fills it (true when the joined vector reached
   position 16 383 or a lexeme has 256 or more positions, where PostgreSQL drops the rest: documents written before this release
   or by an older refresh function need the reindex). Until then a phrase (`"usb receiver"`, `usb-c`) on an index with **several fields** is correct but slower: the
   later fields' vectors are read for every candidate `tsv` does not match as a phrase (about 17 to 22 times slower for a quoted
   phrase whose words are common on a 12-field index, 1.3 to 1.8 times on 3 fields; measured). A document that is not reindexed
   yet has no answer (NULL) and is treated as capped: right, slow. An application that has not applied the schema keeps working
   (the column is looked up in the catalog, once per process: restart long-running workers, or call `PostgresEngine::resetCaches()`,
   after applying, and after dropping the column too). `fuzzphony:doctor` warns about the missing column, and about a column that
   is not filled yet (NULL rows: "run fuzzphony:reindex"). Going back to a release without the column and forward again leaves the
   flags the old refresh function did not touch as they were, so a document changed meanwhile can have a stale `false`: reindex. Single-field indexes, plain words, excluded words,
   prefixes and typos are not affected. A rebuild with `fuzzphony:reindex` before the schema is applied also fills the column.

If you implement your own `Engine` or build `SearchResult` yourself, the new constructor parameter `correctedFrom` is optional and last.

## From 0.9.6 to 0.9.7

Nothing is required. One thing to know: **a restricted viewer's phrase or field-scoped-word match no longer depends on the length of a hidden field** (a security fix: before, a hidden field of more than about 16 000 words in front of an allowed one could change whether `"word1 word2"` or `secret:word1` matched for a restricted viewer). If you use `restrictedToFields()` or `granted` fields, upgrade; no reindex is needed.

## From 0.9.5 to 0.9.6

1. **Search log: who searched what, and the optional client IP.** Nothing is required. To keep the client address
   (`fuzzphony.search_log.ip: masked`, recommended, or `full`; it is personal data, see
   [SECURITY.md](SECURITY.md)) run `fuzzphony:schema --apply` once: it adds the nullable column `actor_ip` to
   `fuzzphony_search_log` (the role must own the table). Without the setting nothing is stored and the column is not needed;
   `SearchAnalytics::recentSearches()` and `fuzzphony:analytics --recent` work either way (the address is then null). If you
   implement `Fuzzphony\Core\Engine\SearchLogStore` yourself, nothing changes: `recentSearches()` needs the new, optional
   `Fuzzphony\Core\Engine\RecentSearchStore` (implement it to offer the report; without it `recentSearches()` throws
   `InvalidArgument` and the other reports keep working). If you call
   `new SearchRecord(...)` or `new SearchGate(...)` with positional arguments, nothing changes: the new `ip` and `ipMode`
   arguments are trailing and optional.

## From 0.9.4 to 0.9.5

1. **Symfony 8.0 is no longer supported** (the `symfony/*` constraints are now `^7.4 || ^8.1`). Symfony 8.0 is not a
   long-term release and no longer receives fixes: every 8.0.x is affected by four `symfony/yaml` denial-of-service
   advisories (CVE-2026-45133, -45304, -45305, CVE-2026-109413), so Composer refuses to install it. Move to Symfony 8.1
   (`composer update "symfony/*" -W` after raising your constraints to `^8.1`) or stay on the 7.4 LTS. Nothing else changes.
2. **With DoctrineBundle and `doctrine.orm` configured, the ORM wiring is now really active** (a bug: the bundle never detected
   DoctrineBundle in a running application, see the CHANGELOG). `#[Searchable]` entities are discovered
   (`discover_entities: false` turns that off), `EntityLoader` is a service and `sync: orm` indexes follow the
   entity manager. Nothing to do unless you had worked around the missing wiring: a hand-registered
   `DoctrineIndexDiscovery`, `EntityLoader` or `OrmSyncListener` is now a duplicate of the bundle's, and an index
   that is both an attribute and in YAML `indexes` is overridden by the YAML as documented.

## From 0.8 to 0.9

Nothing is required: without configuration a search is what 0.8 did (the same results; the same statements
except the probe and facet change listed in the CHANGELOG: the candidate probes carry the search's tenant and
filters, and a disjunctive facet with different conditions uses the trigram condition). Optional steps and
things to know:

1. **The search log** needs a table only when you enable it (`fuzzphony.search_log.enabled: true`): run
   `fuzzphony:schema --apply` then. The guards (`fuzzphony.rate_limit`, `fuzzphony.authorization`) need no
   table. New `suggest` packages: `symfony/rate-limiter` (for `rate_limit`) and `symfony/security-bundle` (for
   `authorization`: `symfony/security-core` alone is not enough, Symfony's own "non-existent service
   security.authorization_checker" message then fails the build). A missing `symfony/rate-limiter` fails at
   container build with the `composer require` line; a `rate_limit.limiter` that is no `framework.rate_limiter`
   entry fails with Symfony's "non-existent service limiter.<name>" message.
2. **`granted` on a field** needs no schema change and no reindex, and does nothing until
   `fuzzphony.authorization` is enabled. If you enable it for an index built before 0.5, reindex first: a
   restricted viewer of an index without the per-field columns finds nothing (it fails closed).
3. **Highlight names are validated upfront.** `highlight('nosuch')` (an unknown field, or one with
   `highlight: false`) now throws `InvalidQuery` even when the search finds nothing; before, only when there
   were hits.
4. **`explain()` no longer shows other tenants' ids.** The typo-tolerant candidate probes now apply the
   search's tenant and filters; the statements of a tenant-scoped search bind only that tenant's ids.
5. **`InvalidQuery` is no longer `final`** (`ActorRequired` extends it), and `Fuzzphony::suggest()` takes an
   optional trailing `?Actor`. `SearchGate` takes `guards` as a trailing constructor argument. A custom
   `Engine` needs nothing.
6. `fuzzphony:search` runs no guard, with or without `--tenant` / `--user`: the bundle gives it its own
   `fuzzphony.operator` service (see [Security](docs/security.md#fuzzphonysearch-and-the-guards)).

See [Security](docs/security.md) and [Search log and analytics](docs/analytics.md).

## From 0.7 to 0.8

No breaking changes. Optional steps:

1. `fuzzphony:schema --apply` (or `--dump-migration` for a Doctrine migration): adds a prefix index to the
   vocabulary table of every typo-tolerant index, which `suggest()` uses. Search-as-you-type works without it.
2. A **custom `Engine`** keeps working: facets and `exactCounts()` read `SearchQuery::$facets`,
   `$facetValues` and `$exactCounts`, so an engine that ignores them returns `SearchResult::$facets = []`.
   Implement the optional `Fuzzphony\Core\Engine\Suggestions` to support `suggest()` (without it `suggest()`
   returns an empty list). `Capabilities` lists two new cases (`Facets`, `Suggest`). Federated search needs nothing
   from an engine.
3. `SearchResult` and `SearchQuery` got new constructor parameters at the end (`facets`; `facets`,
   `facetValues`, `exactCounts`): code that builds them positionally is unaffected, and code that extends them
   cannot (they are final).
4. Facets and completions need the right words in the vocabulary: schedule `fuzzphony:reindex --vocabulary`
   (see [Did you mean](docs/searching.md#did-you-mean)).

## From 0.6 to 0.7

1. **Typo tolerance is proportional to the word's length.** The default `fuzzy_similarity` is no
   longer a flat 0.3: one typo is tolerated from 4 letters and two from 8, and a word needs the
   trigram similarity of the worst such typo plus 0.03 (0.28 for 4 letters, 0.36 for 5, 0.48 for 7,
   0.23 for 8, 0.40 for 12; 0.6 below 4 letters, so a 3-letter word is matched almost exactly).
   Searches that relied on the lenient behaviour return far fewer look-alikes (`mouse` no longer
   lists monitors), but a letter replaced in the middle of a 5 to 7 letter word, or two letters
   swapped, may no longer be tolerated (`mpuse`, `mosue`; `torhc` still finds `torch`: at the end
   of a word a swap costs less). 4-letter words behave as before. To keep the
   old behaviour set `fuzzy_similarity: 0.3` in `thresholds:` (YAML, which also overrides
   attribute-defined indexes, or `IndexBuilder::thresholds()`) or per query with
   `->thresholds(['fuzzy_similarity' => 0.3])`. Tests that pinned old fuzzy result sets may need new
   expectations. No schema change, no reindex.
2. **`Thresholds::$fuzzySimilarity` is `?float`.** `null` (the default) means by word length. Code
   that read it as a `float` handles `null`. A custom `Engine` that honours it must apply the same
   rule: the shared conformance test now expects `"wireles headphones"` to find only the headphones
   (a phrase of 17 letters tolerates two typos).

3. **Vocabulary for "did you mean" (no hurry).** The new table of a fuzzy index is empty until you run
   `fuzzphony:schema --apply` (it creates the table; additive, the layout stays 2) and then
   `fuzzphony:reindex --vocabulary` (or any full reindex). Until then searches work as before and
   `fuzzphony:doctor` warns "Vocabulary" (an error before the schema is applied). Give the roles the
   grants for the new table: `SELECT` to the role that searches (without it there is just no suggestion,
   never an error), `SELECT`, `INSERT` and `DELETE` to the role that reindexes; a schema-wide
   `GRANT ... ON ALL TABLES IN SCHEMA` must be run again after the apply (the table is new). A role
   without the privilege still reindexes: the command says the vocabulary was not rebuilt. A
   tenant-scoped index never suggests anything (its vocabulary would mix tenants); switch the
   suggestion off with `did_you_mean: false` on an index whose filters hide words that must stay secret.
   A custom `Engine` needs nothing (the new `Vocabulary` interface is optional). `ReindexResult` and
   `ReindexOptions` have new trailing properties, `Fuzzphony::rebuildVocabulary()` is new, and
   `Thresholds` has the new key `did_you_mean`.

## From 0.4 to 0.5

1. **Apply, then reindex.** Run `fuzzphony:schema --apply`: it upgrades every index to sidecar
   layout 2. Then run one full `fuzzphony:reindex`; until then the doctor's "Documents" check is a
   warning (so `fuzzphony:doctor --strict` fails in CI). With Doctrine Migrations,
   `fuzzphony:schema --dump-migration` contains the upgrade step. Deploy the 0.5 code and run the
   apply together, and stop the workers for the upgrade (or restart them on 0.5 right after the
   apply): 0.5 code sends field-scoped searches (`brand:x`) to new columns that fail until the
   apply has created them, and once the 0.5 schema is applied a `TRUNCATE` queues a full-rebuild
   job (the queue row `'*'`, step 6) that a 0.4 worker fails on in every batch, so that index's
   queue stays stuck until the worker runs 0.5.
2. **Field-scoped queries are exact.** `brand:x` no longer matches other fields of the same
   weight, and a scoped typo no longer matches another fuzzy field. If you relied on the old
   behaviour, search without the field prefix. The index table grows by roughly one more copy of
   the indexed text; the new columns are filled by the reindex of step 1 (until then field-scoped
   words find nothing in documents indexed before the upgrade).
3. **`--drop --apply` and `--prune-empty` ask for confirmation.** `fuzzphony:schema --drop --apply`
   now prints what it is about to remove and asks `Drop these Fuzzphony objects? (yes/no) [no]:`;
   `fuzzphony:reindex --prune-empty` asks `Prune every document of an empty source? (yes/no)
   [no]:`. Both default to no. A script or cron job that runs either non-interactively must add
   `--force`, or it now fails with exit code 1 and a message telling you to. This does not apply to
   plain `fuzzphony:schema --apply` or `fuzzphony:reindex` (without `--prune-empty`), and never to
   the PHP API (`Fuzzphony::schema()`, `Fuzzphony::reindex()`), which never prompts.
4. **Custom engines** implement `beginRebuild()`, `refreshShadow()`, `finishRebuild()`,
   `abortRebuild()` and `discardLeftoverRebuild()`. The minimal implementation keeps the 0.4
   behaviour:
   `public function beginRebuild(IndexDefinition $index, bool $resume = false): bool { return false; }`
   (the reindex then writes in place), `discardLeftoverRebuild()` returning `false`, and empty
   bodies for the other three (`refreshShadow()` returns `0`).
5. **A full reindex builds next to the live index.** `fuzzphony:reindex` and
   `Fuzzphony::reindex()` fill `fuzzphony_<index>__next` and swap it in when it is complete, so
   searches never see a half-built index. Plan for disk space for a second copy of the index
   while it runs. The reindexing role needs `CREATE` on Fuzzphony's schema and must own the index
   table (or be a member of its owner), and the connection must be a session (the run holds an
   advisory lock; not a transaction-pooling PgBouncer, which can release it on another server
   connection than the one that took it: PostgreSQL only warns, the lock stays held, and later
   reindexes fail with "already running" until the pooler closes that connection; use
   `--in-place` there). A role without those rights reindexes in
   place, as in 0.4, and the command says so; `--in-place` / `new ReindexOptions(inPlace: true)`
   asks for that explicitly, and `--no-prune` always runs in place. After a swap
   `ReindexResult::$pruned` is `null` (the orphans went with the old index): check
   `ReindexResult::$swapped`. A second full reindex of the same index while one runs throws
   `RebuildAlreadyRunning` (an `\InvalidArgumentException`). A run that fails leaves a rebuild
   behind (the doctor warns): resume it with `--from`, or run a full reindex again.
   Call `Fuzzphony::reindex()` outside a transaction (inside one it writes in place), and do not
   run `fuzzphony:schema --apply` while a full rebuild runs (a `fuzzphony:reindex`, or the worker's
   rebuild of a job a `TRUNCATE` queued, step 6): it refuses, run it again afterwards (let a deploy
   pipeline retry).
6. **`TRUNCATE` in queue mode.** A `TRUNCATE` of a joined table (or of a query source's table)
   queues one full-rebuild job, the row `(index_name, '*')` in `fuzzphony_queue`, instead of every
   document id; the worker runs it before the queued ids. If you read the queue yourself, skip that
   row. For a zero-downtime rebuild the worker's role needs the reindex rights of step 5; without
   them it rebuilds in place. Either way the rebuild reads the source in the worker's session, like
   `fuzzphony:reindex`: its `search_path` must see the source tables. A role that truncates watched
   tables needs `UPDATE` on `fuzzphony_queue` (the trigger moves a queued job's time on), and a
   string document id `*` is reserved for the job. A rebuild that fails does not stop the worker
   (it retries after a back-off and the doctor warns), but `fuzzphony:worker --once` then exits
   with code 1: check a cron job that alerts on it. `fuzzphony:schema --apply` adds the failure
   columns to the meta table. Custom engines implement `rebuildRequested()` and
   `recordRebuildFailure()`; an engine whose queue has no rebuild job returns `false` from the
   first and leaves the second empty.
7. **Partitioned tables.** The apply of step 1 puts the `TRUNCATE` trigger on every partition of a
   watched partitioned table. After attaching or detaching a partition, run
   `fuzzphony:schema --apply` again (the doctor lists partitions without the trigger and detached
   tables that still have it) and `fuzzphony:reindex <index>` (neither fires a trigger).
8. **Index names must not contain `__`** (two underscores): Fuzzphony reserves it for an index's
   rebuild objects (`fuzzphony_<index>__next`, `fuzzphony_<index>__changes`), so an index named
   `products__next` would be destroyed by the rebuild of `products`. Such a definition now fails
   validation. Rename the index before upgrading: drop the old one with 0.4
   (`fuzzphony:schema --drop --apply`), then, on 0.5, apply and reindex the new name.

## From 0.3 to 0.4

1. **Exceptions.** Catch `Fuzzphony\Core\Exception\FuzzphonyException` to handle everything
   Fuzzphony throws. If you caught `\ValueError` around definition building (a YAML or builder
   enum typo), catch `InvalidDefinition`; if you caught `\LogicException` from
   `AttributeExporter::export()` or `DoctrineNamingStrategy`, catch `InvalidArgument` /
   `InvalidDefinition`; if you caught `\PDOException` or DBAL exceptions around `explain()`,
   highlighting, the doctor or `SchemaPlan::apply()`, catch `EngineFailure` (the driver exception
   is `getPrevious()`). `catch (\InvalidArgumentException)` keeps working.
2. **`IndexDefinition::with()` is gone.** Replace each named argument with its wither, and chain
   them: `$definition->with(sync: SyncMode::Manual, tenant: null)` becomes
   `$definition->withSync(SyncMode::Manual)->withTenant(null)`. The names are the property names:
   `name`, `source`, `fields`, `filters`, `watches`, `idType`, `sync`, `text`, `boostColumn`,
   `recencyColumn`, `profiles`, `thresholds`, `entityClass`, `triggerLevel`, `tenant` →
   `withName()` … `withTenant()`. Withers do not validate; registering the definition does.
3. **Reindexing from PHP.** `reindex()` takes a `ReindexOptions` and returns a `ReindexResult`:

   ```php
   // 0.3
   $written = $fuzzphony->reindex('products', 10_000, $onBatch, onPruned: fn(int $n) => ..., prune: false);
   // 0.4
   $result = $fuzzphony->reindex('products', new ReindexOptions(batchSize: 10_000, onBatch: $onBatch(...), prune: false));
   $written = $result->written;
   $pruned = $result->pruned;                        // was onPruned; null when pruning did not run
   $skipped = $result->pruneSkippedEmptySource;      // was onPruneSkipped
   ```

   `onBatch` must be a `\Closure` (use `$callable(...)` for other callables). The console command
   is unchanged.
4. **Removed Core helpers.** `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
   `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
   `RankingProfile::tsRankWeights()` and `Identifier::limit()` are gone. Nothing replaces them in
   the public API: the engine derives these names itself. If you queried the sidecar table by
   hand, its name is `fuzzphony_<index>` in Fuzzphony's schema (`public` unless you set one, see
   [Moving to a dedicated schema](#moving-to-a-dedicated-schema)).
5. **Apply the schema once.** `bin/console fuzzphony:schema --apply` re-creates the functions
   with a fixed `search_path` and schema-qualified names. Nothing moves: without a `schema`
   setting everything stays in `public`. Everything is now created in and read from one
   configured schema, so a setup that relied on the `search_path` to place or find Fuzzphony's
   objects (one set per tenant schema, say) no longer works that way. The refresh and sync
   functions resolve your source tables with the `search_path` of the session that applies the
   schema: apply it with the same role and settings as your application. It also creates
   `fuzzphony_meta` and records each index's layout and definition. Until the next full
   `bin/console fuzzphony:reindex`, `fuzzphony:doctor` warns that the documents' definition is
   unknown (check "Documents"); search works without it, but run one reindex before
   `fuzzphony:doctor --strict` in CI. The role that runs `fuzzphony:reindex` needs `SELECT` and
   `UPDATE` on `fuzzphony_meta`, and the role that runs `fuzzphony:doctor` needs `SELECT` on it
   (without it the "Schema version" check warns, which fails `--strict`); a schema-wide
   `GRANT … ON ALL TABLES IN SCHEMA` covers only the tables that exist, so re-run it after the
   first 0.4 `schema --apply` (the table is new).
6. **Custom engines** implement `Engine::recordReindex(IndexDefinition $index): void`; an empty
   body is fine if the engine does not track which definition built its documents.
7. **Internal classes.** Only the classes listed under
   [Public API](docs/architecture.md#public-api) are covered by the upgrade notes from now on. If
   you use an `@internal` class directly (`Reindexer`, `Worker`, `ArrayDefinitionLoader`,
   `QueryParser`, `DefinitionValidator`, …), move to the public entry points (`Fuzzphony`,
   `IndexDefinition::builder()`, the bundle's configuration) or open an issue describing the use
   case. PHPStan reports such uses (`@internal` from outside the `Fuzzphony` namespace).

### Moving to a dedicated schema

Optional. Nothing moves by itself. The sync triggers on your tables keep their names in the new
schema, so the new `schema --apply` re-points them to the new functions; a `--drop` with the old
configuration run *afterwards* would remove them again. Two ways:

**Without downtime** (the deployed application keeps searching the old tables until you deploy
the new setting):

1. Keep the deployed application on the old setting. Prepare the new one,
   `fuzzphony.schema: fuzzphony` (or `schema:` for `PostgresEngine`), somewhere only a one-off
   process uses it: a separate checkout or release directory pointed at the same database.
2. From that process: `bin/console fuzzphony:schema --apply`. It creates the schema and every
   object in it and re-points the triggers to the new functions, so from now on trigger and queue
   sync feed the new objects (queued changes wait in the new queue) and the old tables go stale.
3. From that process: `bin/console fuzzphony:reindex`: fills the new sidecar tables. Search still
   works meanwhile, from the old, increasingly stale tables.
4. Deploy the new setting. With ORM sync, the old deployment refreshed the old tables until now:
   run `bin/console fuzzphony:reindex` once more.
5. Last, drop the old objects by hand (never with `--drop`, see above):

   ```sql
   DROP TABLE public.fuzzphony_<index>;              -- one per index
   DROP TABLE public.fuzzphony_queue, public.fuzzphony_meta;
   DROP FUNCTION public.fuzzphony_refresh_<index>, public.fuzzphony_sync_<index>__<table>, public.fuzzphony_norm;
   DROP TEXT SEARCH CONFIGURATION public.fuzzphony_<language>;
   DROP TEXT SEARCH DICTIONARY public.fuzzphony_<language>_stop;
   ```

**With a short search outage:**

1. With the old configuration: `bin/console fuzzphony:schema --drop --apply` (removes the
   triggers, functions and sidecar tables; the queue table, `fuzzphony_norm` and the text search
   configurations stay and can be dropped as above).
2. Set the schema, `bin/console fuzzphony:schema --apply`, `bin/console fuzzphony:reindex`.

`fuzzphony:doctor` warns ("Schema") when the configured schema has no sidecar table for an index but
`public` still has one.

### Doctrine Migrations

With DoctrineBundle the bundle now sets the DBAL `schema_filter` of Fuzzphony's connection, so
`doctrine:migrations:diff` stops proposing to drop the `fuzzphony_*` tables. If your connection
has its own `schema_filter`, the bundle leaves it alone, and `fuzzphony:doctor` warns with the
regex to merge until your filter hides Fuzzphony's tables. If `connection` or `schema` is set
from a parameter or an environment variable, the bundle cannot resolve it early enough: it sets
no filter and the doctor does not check yours, so add Fuzzphony's regex yourself. A migration
generated with `fuzzphony:schema --dump-migration` can be generated again after upgrading; it now
ends with the version record.

## From 0.3.1 to 0.3.2

Nothing to do: only the generated search statements change (the trigram operator is now
schema-qualified, so `pg_trgm` no longer has to be on the `search_path`).

## From 0.3.0 to 0.3.1

No code changes. Only indexes with accent folding (`unaccent`, the default) are affected: their
text search configuration kept accented stop words (`für`, `és`, `à`), so the stored documents
contain them.

1. **Apply the schema.** It creates the stop-word dictionary `fuzzphony_<language>_stop` and
   re-maps the existing `fuzzphony_<language>` configuration; queries ignore accented stop words
   from then on.
2. **Reindex every such index.** Documents indexed before still contain the stop words, and
   until they are rebuilt a search can rank them differently.

```bash
bin/console fuzzphony:schema --apply
bin/console fuzzphony:reindex
```

Until you apply the schema, `fuzzphony:doctor` reports that the text search configuration keeps
accented stop words.

## From 0.2 to 0.3

1. **Requirements.** Symfony 7.4 or 8.0 (7.3 is no longer supported), and the `pdo_pgsql` PHP
   extension, which is now a hard requirement of `fuzzphony/fuzzphony`.
2. **Apply the schema, then reindex once.** Sync functions changed and every watched table gets
   a new `TRUNCATE` trigger. Both commands are safe to run on a live index:

   ```bash
   bin/console fuzzphony:schema --apply
   bin/console fuzzphony:reindex        # also removes documents left behind by earlier TRUNCATEs
   ```

   Until you apply the schema, `fuzzphony:doctor` reports the missing `TRUNCATE` trigger.
3. **`fuzzphony:search --profile` is now `--rank-profile`.** The short form `-p` is unchanged.
   Update scripts that use the long option.
4. **A full reindex now prunes orphaned documents** (indexed rows the source no longer returns).
   If the session that runs the reindex sees fewer rows than your application (row-level
   security, a different `search_path`), pass `--no-prune` / `prune: false`, or run it as a role
   that sees everything. A source that returns no row at all is never pruned unless you pass
   `--prune-empty`.
5. **Custom engines** (implementations of `Fuzzphony\Core\Engine\Engine`) must implement
   `pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int`.
6. **Removed:** `NodeInspector::topLevelExclusions()`. It had no caller in the library; if you
   used it, walk the AST for top-level `Not` nodes yourself.
7. **Behaviour you may notice:**
   - Typo-tolerant results are narrower: every word of the query must now match on its own.
     Tests that pinned the old, wider fuzzy result sets may need new expectations.
   - A multi-word query that finds nothing may now return results for a subset of its words,
     with a warning in `SearchResult::$warnings`. Disable it with the threshold
     `relax_when_empty: false`. The warning quotes the user's words: escape it when rendering HTML.
   - `ScoreBreakdown::$fuzzySimilarity` is computed per word, so absolute scores in
     `fuzzy_mode: always` shift slightly; relative order of strict matches is unaffected.

## From 0.1 to 0.2

- `OrmSyncListener` takes a `RefreshDispatcher` instead of an `Engine`. With the Symfony bundle
  nothing changes; wire `new ImmediateRefreshDispatcher($engine)` yourself otherwise.

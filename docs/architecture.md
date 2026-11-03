# Architecture

How the code is organised into layers, and where design decisions are recorded. Back to the
[README](../README.md).

## Layers

```
src/Core             definitions, attributes, query language (AST), ranking, thresholds,
                           Engine contract, schema plans, inspection, reindexer, worker, wizard
src/Engine/Postgres  tsvector + unaccent + pg_trgm; SQL compilers, schema generator, doctor,
                     introspector
src/Bridge/Doctrine  DBAL connection, ORM naming, ORM sync listener, entity loader
src/Bundle           configuration, autowiring, console commands, Messenger, API Platform
                     filter, Live Component
```

These are layers (namespaces and directories) of ONE Composer package, `fuzzphony/fuzzphony`; there are
no separate packages and no split repositories. The layering is a rule of the code, not of Composer:
`Core` knows nothing of the others, the engine and the bridge depend on `Core`, the bundle on all three.

## Engine

Everything dialect-specific lives behind `Fuzzphony\Core\Engine\Engine`, validated by a
conformance test suite (`tests/Conformance`). PostgreSQL is the only engine through 1.0. A full
reindex is driven through the engine too (`beginRebuild()`, `refreshShadow()`, `finishRebuild()`,
`abortRebuild()`, `discardLeftoverRebuild()`), so `Reindexer` stays engine-agnostic; the PostgreSQL engine builds next to the
live table and swaps it in ([ADR 0008](adr/0008-shadow-rebuild-with-a-change-log.md)).

`PostgresEngine` is a thin facade (all of these are `@internal`): `Search\SearchRunner` runs a search (validation, the strict
and typo-tolerant statements, relaxation, exact total, did-you-mean, rescue, hits), `Search\FacetRunner` counts the facets by one
path (one statement per group of facets that count under the same conditions), `SearchSession` owns the connection's
`pg_trgm` settings and the search transaction, `TextConfigCache` the process caches (stop words, synonym stems, the
open-class gate), `VocabularyService` the vocabulary and did-you-mean, `IndexMaintenance` refresh, queue and rebuild records.
A `Statement` carries its `StatementKind` (explain() and the metrics read the kind, never the label) and declares whether it
reads `pg_trgm.word_similarity_threshold`; the SQL builders (`Sql\SearchSqlBuilder`) say so from the operator they emit.

## Public API

From 1.0 the backward-compatibility promise covers exactly these 79 classes, interfaces and enums; everything else in
`src/` is marked `@internal` and may change in any release. The engine contract (`Core\Engine\Engine`), the wizard's
building blocks, the index registry, the search gate and the other container types are `@internal`: build the service
with `Fuzzphony::postgres()` (or the bundle) and read the wizard through `Fuzzphony::wizard()`; `Fuzzphony::indexes()` and `Fuzzphony::definition()` list and read the registered indexes. The constructor of an
output type (`SearchResult`, `ScoreBreakdown`, `SearchAnalytics`, `ReindexResult`, ...) is `@internal` too: a field
added to a result is not a breaking change.

- Entry point: `Fuzzphony\Core\Fuzzphony` (`postgres()` builds it without a framework, `wizard()` returns the wizard)
- Attributes: `Fuzzphony\Core\Attribute\Searchable`, `Fuzzphony\Core\Attribute\SearchField`,
  `Fuzzphony\Core\Attribute\SearchFilter`, `Fuzzphony\Core\Attribute\AsIndexDefinition`
- Definitions: `Fuzzphony\Core\Definition\IndexDefinition`, `Fuzzphony\Core\Definition\IndexBuilder`,
  `Fuzzphony\Core\Definition\Source`, `Fuzzphony\Core\Definition\IndexSource`, `Fuzzphony\Core\Definition\FieldDefinition`,
  `Fuzzphony\Core\Definition\FilterDefinition`, `Fuzzphony\Core\Definition\Watch`,
  `Fuzzphony\Core\Definition\Synonyms`,
  `Fuzzphony\Core\Definition\TextConfig`, `Fuzzphony\Core\Definition\Weight`,
  `Fuzzphony\Core\Definition\FilterType`, `Fuzzphony\Core\Definition\IdType`,
  `Fuzzphony\Core\Definition\SyncMode`, `Fuzzphony\Core\Definition\TriggerLevel`
- Searching: `Fuzzphony\Core\Search\SearchBuilder`, `Fuzzphony\Core\Search\SearchResult`,
  `Fuzzphony\Core\Search\Hit`, `Fuzzphony\Core\Search\MatchedWord`, `Fuzzphony\Core\Search\ScoreBreakdown`,
  `Fuzzphony\Core\Search\Explanation`, `Fuzzphony\Core\Search\FacetValue`,
  `Fuzzphony\Core\Search\Actor`, `Fuzzphony\Core\Search\ActorResolver`,
  `Fuzzphony\Core\Search\SearchGuard`, `Fuzzphony\Core\Search\FieldRestrictingGuard`, `Fuzzphony\Core\Search\Access`,
  `Fuzzphony\Core\Search\FederatedSearch`, `Fuzzphony\Core\Search\FederatedResult`
- Search log: `Fuzzphony\Core\Analytics\SearchLog`, `Fuzzphony\Core\Analytics\SearchRecord`,
  `Fuzzphony\Core\Analytics\RecordStatus`, `Fuzzphony\Core\Analytics\QueryRedactor`,
  `Fuzzphony\Core\Analytics\SearchAnalytics`, `Fuzzphony\Core\Analytics\IpMode`,
  `Fuzzphony\Core\Analytics\QueryStat`, `Fuzzphony\Core\Analytics\LoggedSearch`
- Ranking and thresholds: `Fuzzphony\Core\Ranking\RankingProfile`, `Fuzzphony\Core\Ranking\Thresholds`,
  `Fuzzphony\Core\Ranking\RelevanceThresholds`, `Fuzzphony\Core\Ranking\TypoThresholds`,
  `Fuzzphony\Core\Ranking\RescueThresholds`, `Fuzzphony\Core\Ranking\LimitThresholds`,
  `Fuzzphony\Core\Ranking\FuzzyMode`
- Database: `Fuzzphony\Core\Database\Connection`, `Fuzzphony\Core\Database\PdoConnection`
- Schema: `Fuzzphony\Core\Schema\SchemaPlan`, `Fuzzphony\Core\Schema\Statement`
- Sync: `Fuzzphony\Core\Sync\ReindexOptions`, `Fuzzphony\Core\Sync\ReindexResult`
- Doctor: `Fuzzphony\Core\Inspection\InspectOptions`, `Fuzzphony\Core\Inspection\InspectionReport`,
  `Fuzzphony\Core\Inspection\Check`, `Fuzzphony\Core\Inspection\CheckStatus`
- Observability: `Fuzzphony\Core\Observability\MetricsCollector`
- Exceptions: `Fuzzphony\Core\Exception\FuzzphonyException`, `Fuzzphony\Core\Exception\EngineFailure`,
  `Fuzzphony\Core\Exception\InvalidArgument`, `Fuzzphony\Core\Exception\InvalidConfiguration`,
  `Fuzzphony\Core\Exception\InvalidDefinition`, `Fuzzphony\Core\Exception\InvalidQuery`,
  `Fuzzphony\Core\Exception\ActorRequired`, `Fuzzphony\Core\Exception\AccessDenied`,
  `Fuzzphony\Core\Exception\RateLimited`, `Fuzzphony\Core\Exception\SearchRefused` (implemented by the three refusals),
  `Fuzzphony\Core\Exception\RebuildAlreadyRunning`, `Fuzzphony\Core\Exception\SchemaNotApplied`,
  `Fuzzphony\Core\Exception\UnknownIndex`
- Wizard: `Fuzzphony\Core\Wizard\Wizard`, `Fuzzphony\Core\Wizard\Suggestion`, `Fuzzphony\Core\Wizard\Decision`
- Doctrine: `Fuzzphony\Bridge\Doctrine\DbalConnection`, `Fuzzphony\Bridge\Doctrine\EntityLoader` (`load()`, `loadHits()`)
- Symfony: `Fuzzphony\Bundle\FuzzphonyBundle`, `Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter`

## Sidecar table

Fuzzphony never alters your tables. Each index lives in its own table, `fuzzphony_<index>`, in
Fuzzphony's schema (`public` unless configured, see
[Fuzzphony's schema](configuration.md#fuzzphonys-schema))
([ADR 0001](adr/0001-sidecar-table.md)); see [Index definitions](configuration.md#concepts) for
what it contains.

A fuzzy index also has `fuzzphony_<index>__vocab` (same schema): its words and in how many documents
each occurs, created by `fuzzphony:schema --apply` and rebuilt after every full reindex, for "did you
mean" ([ADR 0009](adr/0009-vocabulary-rebuilt-after-a-full-reindex.md)). It is additive: the layout
below does not change.

An index with a `granted` field has two more indexes over the open (not granted) fields, `...__open_fz_<hash>` (trigram over their `z_`
columns) and `...__open_tsv_<hash>` (full text over their `t_` columns). A restricted viewer who holds none of the granted fields looks its words
up there instead of in `fz` / `tsv` (`OpenClass`, `TextConfigCache::openClass()`): the lookup only has to find a superset of the allowed
fields' own matches, the recheck against their columns decides, so the result is unchanged. The engine uses them only when both exist, are
valid and ready and each is smaller than the one it replaces (`fz`, `tsv`) (asked once per process, `resetCaches()`); a viewer who holds a granted field keeps
`fz` / `tsv`.

`fuzzphony_meta` (same schema) records, per index, the sidecar layout version, a hash of the
definition it was applied with and a hash of the definition its documents were built from;
`fuzzphony:doctor` compares them with the current definition.

`fuzzphony:schema --apply` upgrades an index built with an older sidecar layout: each layout step
is a guarded `DO` block in the index's plan that runs only while the stored layout is older (so
`--dump-migration` contains it too), before the meta row records the new layout. The `*` row
records the shared objects (queue, normaliser, text configurations); the doctor's "Shared objects"
check compares it.

## Multi-source indexes: how it is stored

An index with `sources:` (several SELECTs that share one set of fields) keeps the layout above; only the key changes, and only for
such an index. A single-source index (the vast majority) is untouched: same columns, same statements, no migration.

- The sidecar key `id` is **text** and holds a composite `<kind>:<id>` (`product:42`, `article:0b1d...`). A source name is a lowercase
  identifier, so the first colon always ends the kind. Two plain columns, `kind` and `src_id` (the raw id as text), come with it, and a
  secondary index on `kind`. Every statement of the engine keeps working on `s.id` (filters, facets, windows, ordering, highlight, the
  restricted-viewer machinery); a hit gets `kind` from the column and `id` from `src_id`, converted by that source's `id_type`.
- The document SELECT is a `UNION ALL` of one branch per source (`DocumentSql`), each aliasing the source's columns to the common fields
  and building the composite id. A refresh by ids pushes the ids of each kind into its own branch (`WHERE id = ANY(...)` on the source's
  own key), so a refresh of one document never scans another source.
- Watches belong to a source (`Watch::$source`; the shared registry `fuzzphony_watches` has a `source` column). A change queues
  composite keys; a TRUNCATE queues a rebuild of that source only (queue row `*:<kind>`); `fuzzphony:reindex <index> --source=<kind>`
  rebuilds the rows of one kind in place; a full reindex builds all sources into the `__next` table and swaps as usual.
- Queue and change-log tables carry the composite key as they carry any id. Ids are not unique across sources by construction, and a
  source's own ids must be unique inside it (the refresh keeps one row per key, like for a single source).

## Why the combined vector is stored

The sidecar row keeps the combined full-text vector `tsv` of all fields next to the per-field vectors (`t_<field>`). On the demo catalogue
(499 978 rows) `tsv` is 151 bytes of a 530-byte heap row, about 28%, so it is the first thing one would drop. A variant that computes the combined vector from
the per-field columns with a SQL function (no stored `tsv`, a function-based GIN index) was built and measured: the common searches
became 25 to 49% slower. The stored vector stays: speed and exactness come first and storage is cheap. What each column and index costs is in
[Benchmarks](benchmarks.md#what-each-option-costs-in-disk); an index that does not need a restricted viewer's per-field lookups does not
pay for the ones that do (the open-class indexes exist only for indexes with a `granted` field).

## Design decisions

Recorded as ADRs in [`docs/adr`](adr/).

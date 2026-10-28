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
`tsv_capped` probe), `VocabularyService` the vocabulary and did-you-mean, `IndexMaintenance` refresh, queue and rebuild records.
A `Statement` carries its `StatementKind` (explain() and the metrics read the kind, never the label) and declares whether it
reads `pg_trgm.word_similarity_threshold`; the SQL builders (`Sql\SearchSqlBuilder`) say so from the operator they emit.

## Public API

From 1.0 the backward-compatibility promise covers exactly these classes, interfaces and enums;
everything else in `src/` is marked `@internal` and may change in any release.

- Entry point: `Fuzzphony\Core\Fuzzphony`
- Attributes: `Fuzzphony\Core\Attribute\Searchable`, `Fuzzphony\Core\Attribute\SearchField`,
  `Fuzzphony\Core\Attribute\SearchFilter`
- Definitions: `Fuzzphony\Core\Definition\IndexDefinition`, `Fuzzphony\Core\Definition\IndexBuilder`,
  `Fuzzphony\Core\Definition\Source`, `Fuzzphony\Core\Definition\FieldDefinition`,
  `Fuzzphony\Core\Definition\FilterDefinition`, `Fuzzphony\Core\Definition\Watch`,
  `Fuzzphony\Core\Definition\Synonyms`,
  `Fuzzphony\Core\Definition\TextConfig`, `Fuzzphony\Core\Definition\Weight`,
  `Fuzzphony\Core\Definition\FilterType`, `Fuzzphony\Core\Definition\IdType`,
  `Fuzzphony\Core\Definition\SyncMode`, `Fuzzphony\Core\Definition\TriggerLevel`
- Searching: `Fuzzphony\Core\Search\SearchBuilder`, `Fuzzphony\Core\Search\SearchResult`,
  `Fuzzphony\Core\Search\Hit`, `Fuzzphony\Core\Search\ScoreBreakdown`,
  `Fuzzphony\Core\Search\Explanation`, `Fuzzphony\Core\Search\FacetValue`,
  `Fuzzphony\Core\Search\Actor`, `Fuzzphony\Core\Search\ActorResolver`, `Fuzzphony\Core\Search\SearchGate`,
  `Fuzzphony\Core\Search\SearchGuard`, `Fuzzphony\Core\Search\Access`,
  `Fuzzphony\Core\Search\FederatedSearch`, `Fuzzphony\Core\Search\FederatedResult`, `Fuzzphony\Core\Search\FederatedHit`,
  `Fuzzphony\Core\Query\SearchQuery`,
  `Fuzzphony\Core\Query\Filter\Condition`, `Fuzzphony\Core\Query\Filter\Operator`
- Search log: `Fuzzphony\Core\Analytics\SearchLog`, `Fuzzphony\Core\Analytics\SearchRecord`,
  `Fuzzphony\Core\Analytics\RecordStatus`, `Fuzzphony\Core\Analytics\QueryRedactor`,
  `Fuzzphony\Core\Analytics\PatternQueryRedactor`, `Fuzzphony\Core\Analytics\SearchAnalytics`,
  `Fuzzphony\Core\Analytics\QueryStat`, `Fuzzphony\Core\Analytics\LoggedSearch`, `Fuzzphony\Core\Analytics\IpMode`
- Ranking: `Fuzzphony\Core\Ranking\RankingProfile`, `Fuzzphony\Core\Ranking\Thresholds`,
  `Fuzzphony\Core\Ranking\FuzzyMode`
- Database: `Fuzzphony\Core\Database\Connection`, `Fuzzphony\Core\Database\PdoConnection`,
  `Fuzzphony\Core\Database\TransactionAware`
- Registry and schema: `Fuzzphony\Core\Registry\IndexRegistry`, `Fuzzphony\Core\Schema\SchemaPlan`,
  `Fuzzphony\Core\Schema\Statement`
- Sync: `Fuzzphony\Core\Sync\ReindexOptions`, `Fuzzphony\Core\Sync\ReindexResult`,
  `Fuzzphony\Core\Sync\RefreshDispatcher`, `Fuzzphony\Core\Sync\ImmediateRefreshDispatcher`
- Doctor: `Fuzzphony\Core\Inspection\InspectOptions`, `Fuzzphony\Core\Inspection\InspectionReport`,
  `Fuzzphony\Core\Inspection\Check`, `Fuzzphony\Core\Inspection\CheckStatus`
- Observability: `Fuzzphony\Core\Observability\MetricsCollector`,
  `Fuzzphony\Core\Observability\NullMetricsCollector`,
  `Fuzzphony\Core\Observability\LoggingMetricsCollector`
- Exceptions: `Fuzzphony\Core\Exception\FuzzphonyException`, `Fuzzphony\Core\Exception\EngineFailure`,
  `Fuzzphony\Core\Exception\InvalidArgument`, `Fuzzphony\Core\Exception\InvalidConfiguration`,
  `Fuzzphony\Core\Exception\InvalidDefinition`, `Fuzzphony\Core\Exception\InvalidQuery`,
  `Fuzzphony\Core\Exception\ActorRequired`, `Fuzzphony\Core\Exception\AccessDenied`,
  `Fuzzphony\Core\Exception\RateLimited`, `Fuzzphony\Core\Exception\SearchRefused` (implemented by the three refusals),
  `Fuzzphony\Core\Exception\RebuildAlreadyRunning`,
  `Fuzzphony\Core\Exception\UnknownIndex`
- Engine SPI (for custom engines): `Fuzzphony\Core\Engine\Engine`, `Fuzzphony\Core\Engine\Capabilities`,
  `Fuzzphony\Core\Engine\Capability`, `Fuzzphony\Core\Engine\Vocabulary`, `Fuzzphony\Core\Engine\SynonymStems`, `Fuzzphony\Core\Engine\Suggestions` and `Fuzzphony\Core\Engine\SearchLogStore` and `Fuzzphony\Core\Engine\RecentSearchStore` (all optional); the PostgreSQL engine: `Fuzzphony\Engine\Postgres\PostgresEngine`
- Wizard: `Fuzzphony\Core\Wizard\DefinitionSuggester`, `Fuzzphony\Core\Wizard\SourceIntrospector`,
  `Fuzzphony\Core\Wizard\Suggestion`, `Fuzzphony\Core\Wizard\Decision`,
  `Fuzzphony\Core\Wizard\TableProfile`, `Fuzzphony\Core\Wizard\ColumnProfile`,
  `Fuzzphony\Core\Wizard\ColumnKind`, `Fuzzphony\Core\Wizard\ForeignKey`,
  `Fuzzphony\Core\Wizard\Export\YamlExporter`, `Fuzzphony\Core\Wizard\Export\BuilderExporter`,
  `Fuzzphony\Core\Wizard\Export\AttributeExporter`
- Doctrine: `Fuzzphony\Bridge\Doctrine\DbalConnection`, `Fuzzphony\Bridge\Doctrine\EntityLoader`,
  `Fuzzphony\Bridge\Doctrine\OrmSyncListener`
- Symfony: `Fuzzphony\Bundle\FuzzphonyBundle`, `Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter`,
  `Fuzzphony\Bundle\Twig\SearchComponent`, `Fuzzphony\Bundle\Messenger\RefreshDocuments`

The console commands' names, arguments and options are public too; their classes are not.
`tests/Unit/PublicApiTest.php` keeps this list, the `@internal` tags and the demo's imports in
step.

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

`fuzzphony_meta` (same schema) records, per index, the sidecar layout version, a hash of the
definition it was applied with and a hash of the definition its documents were built from;
`fuzzphony:doctor` compares them with the current definition.

`fuzzphony:schema --apply` upgrades an index built with an older sidecar layout: each layout step
is a guarded `DO` block in the index's plan that runs only while the stored layout is older (so
`--dump-migration` contains it too), before the meta row records the new layout. The `*` row
records the shared objects (queue, normaliser, text configurations); the doctor's "Shared objects"
check compares it.

## Design decisions

Recorded as ADRs in [`docs/adr`](adr/).

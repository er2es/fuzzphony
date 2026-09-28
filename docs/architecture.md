# Architecture

How the code is split into packages, and where design decisions are recorded. Back to the
[README](../README.md).

## Packages

```
fuzzphony/core             definitions, attributes, query language (AST), ranking, thresholds,
                           Engine contract, schema plans, inspection, reindexer, worker, wizard
fuzzphony/postgres-engine  tsvector + unaccent + pg_trgm; SQL compilers, schema generator, doctor,
                           introspector
fuzzphony/doctrine-bridge  DBAL connection, ORM naming, ORM sync listener, entity loader
fuzzphony/symfony-bundle   configuration, autowiring, console commands, Messenger, API Platform
                           filter, Live Component
```

This is a monorepo, published as the single Composer package `fuzzphony/fuzzphony`. Each directory
already has its own `composer.json` for a later split into separate packages.

## Engine

Everything dialect-specific lives behind `Fuzzphony\Core\Engine\Engine`, validated by a
conformance test suite (`tests/Conformance`). PostgreSQL is the only engine through 1.0.

## Public API

From 1.0 the backward-compatibility promise covers exactly these classes, interfaces and enums;
everything else in `src/` is marked `@internal` and may change in any release.

- Entry point: `Fuzzphony\Core\Fuzzphony`
- Attributes: `Fuzzphony\Core\Attribute\Searchable`, `Fuzzphony\Core\Attribute\SearchField`,
  `Fuzzphony\Core\Attribute\SearchFilter`
- Definitions: `Fuzzphony\Core\Definition\IndexDefinition`, `Fuzzphony\Core\Definition\IndexBuilder`,
  `Fuzzphony\Core\Definition\Source`, `Fuzzphony\Core\Definition\FieldDefinition`,
  `Fuzzphony\Core\Definition\FilterDefinition`, `Fuzzphony\Core\Definition\Watch`,
  `Fuzzphony\Core\Definition\TextConfig`, `Fuzzphony\Core\Definition\Weight`,
  `Fuzzphony\Core\Definition\FilterType`, `Fuzzphony\Core\Definition\IdType`,
  `Fuzzphony\Core\Definition\SyncMode`, `Fuzzphony\Core\Definition\TriggerLevel`
- Searching: `Fuzzphony\Core\Search\SearchBuilder`, `Fuzzphony\Core\Search\SearchResult`,
  `Fuzzphony\Core\Search\Hit`, `Fuzzphony\Core\Search\ScoreBreakdown`,
  `Fuzzphony\Core\Search\Explanation`, `Fuzzphony\Core\Query\SearchQuery`,
  `Fuzzphony\Core\Query\Filter\Condition`, `Fuzzphony\Core\Query\Filter\Operator`
- Ranking: `Fuzzphony\Core\Ranking\RankingProfile`, `Fuzzphony\Core\Ranking\Thresholds`,
  `Fuzzphony\Core\Ranking\FuzzyMode`
- Database: `Fuzzphony\Core\Database\Connection`, `Fuzzphony\Core\Database\PdoConnection`
- Registry and schema: `Fuzzphony\Core\Registry\IndexRegistry`, `Fuzzphony\Core\Schema\SchemaPlan`,
  `Fuzzphony\Core\Schema\Statement`
- Sync: `Fuzzphony\Core\Sync\ReindexOptions`, `Fuzzphony\Core\Sync\ReindexResult`,
  `Fuzzphony\Core\Sync\RefreshDispatcher`, `Fuzzphony\Core\Sync\ImmediateRefreshDispatcher`
- Doctor: `Fuzzphony\Core\Inspection\InspectOptions`, `Fuzzphony\Core\Inspection\InspectionReport`,
  `Fuzzphony\Core\Inspection\Check`, `Fuzzphony\Core\Inspection\CheckStatus`
- Exceptions: `Fuzzphony\Core\Exception\FuzzphonyException`, `Fuzzphony\Core\Exception\EngineFailure`,
  `Fuzzphony\Core\Exception\InvalidArgument`, `Fuzzphony\Core\Exception\InvalidConfiguration`,
  `Fuzzphony\Core\Exception\InvalidDefinition`, `Fuzzphony\Core\Exception\InvalidQuery`,
  `Fuzzphony\Core\Exception\UnknownIndex`
- Engine SPI (for custom engines): `Fuzzphony\Core\Engine\Engine`, `Fuzzphony\Core\Engine\Capabilities`,
  `Fuzzphony\Core\Engine\Capability`; the PostgreSQL engine: `Fuzzphony\Engine\Postgres\PostgresEngine`
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

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

## Sidecar table

Fuzzphony never alters your tables. Each index lives in its own table, `fuzzphony_<index>`
([ADR 0001](adr/0001-sidecar-table.md)); see [Index definitions](configuration.md#concepts) for
what it contains.

## Design decisions

Recorded as ADRs in [`docs/adr`](adr/).

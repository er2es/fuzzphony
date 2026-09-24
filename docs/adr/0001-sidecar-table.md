# 1. Sidecar table instead of columns on the source table

Status: accepted

## Context
Fuzzphony must attach to existing, often legacy databases. Adding `tsvector` columns or generated
columns to application tables needs migrations in someone else's schema, rewrites large tables and
cannot express documents built from joins.

## Decision
Each index owns one table, `fuzzphony_<index>`, keyed by the source id. A generated SQL function
`fuzzphony_refresh_<index>(ids)` rebuilds documents from the source SELECT (upsert) and deletes
documents whose source rows disappeared.

## Consequences
+ Source schema untouched; documents can join any tables; dropping the index is trivial.
+ All search columns (tsvector, trigram text, typed filters, ranking inputs) live together.
− An extra join-free table to keep in sync (see ADR 2) and extra storage.

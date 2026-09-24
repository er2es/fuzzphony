# 6. Statement-level sync triggers by default

Status: accepted (supersedes the row-level default of v0.1)

## Context
Row-level triggers run once per changed row. A bulk `UPDATE` on a watched table with fan-out
(brand → products) runs the affected-ids query once per row and inserts ids one by one.

## Decision
Use `FOR EACH STATEMENT` triggers with transition tables (`REFERENCING NEW TABLE / OLD TABLE`).
The affected-ids SQL is applied with `CROSS JOIN LATERAL` over the transition table, and the result is
enqueued (or refreshed) with one `INSERT … SELECT DISTINCT`. PostgreSQL requires one trigger per event
when transition tables are used, so an index gets `_ins`, `_upd`, `_del` triggers sharing one function.
`trigger_level: row` remains available; the schema generator drops the triggers of the other level.

## Consequences
+ Bulk writes cost one set-based statement; deduplication happens before touching the queue.
+ Same semantics for queue and trigger sync modes.
− Not available on individual partitions (PostgreSQL restriction); three triggers instead of one.

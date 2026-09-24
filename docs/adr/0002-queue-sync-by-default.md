# 2. Queue-based sync as the default

Status: accepted

## Context
Options: ORM events (miss raw SQL and joined data), synchronous triggers (consistent, but every write
pays for re-analysing text), or triggers that only enqueue ids.

## Decision
Default to `queue`: AFTER triggers insert `(index, id)` into `fuzzphony_queue` (deduplicated by the
primary key); a worker takes a batch with `DELETE … FOR UPDATE SKIP LOCKED` and refreshes it in the
same statement. `trigger`, `orm` and `manual` remain available per index.

## Consequences
+ Writes stay cheap; raw SQL, imports and joined-table changes are all captured.
+ Failure-safe: a failed refresh rolls the dequeue back. Parallel workers are safe.
− Eventual consistency (typically sub-second); a worker (or cron `--once`) must run. The doctor
  warns when the backlog grows or ages.

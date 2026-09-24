# Column-aware trigger filtering — design

Status: approved (design), not yet implemented. Second piece of the v1.0 "enterprise
readiness" roadmap line in `README.md`, after multi-tenancy.

## Problem

Per `README.md`'s Known limitations: "Sync triggers fire for every UPDATE of a watched
table, even when only unrelated columns change (the refresh is idempotent, just wasted
work)." A table with frequent, mostly-irrelevant updates (e.g. a `last_login_at` bump on
a `user` row that's watched only for a `display_name` field) queues/executes a full
document refresh on every single write, regardless of whether anything the index actually
cares about changed.

## Scope decision

Two cases, decided explicitly during design:

1. **Self-watch** (the index's own source table, auto-registered by
   `IndexDefinition::effectiveWatches()` for table sources): Fuzzphony has *certain*
   knowledge of which columns matter — every `FieldDefinition::column()`,
   `FilterDefinition::column()`, `$boostColumn`, `$recencyColumn`. This case is handled
   **automatically**, with no new required API and no opt-in: existing indexes get faster
   sync as soon as `fuzzphony:schema --apply` regenerates the trigger functions. Not
   applicable to query sources (no source table to map columns against).
2. **Joined-table watch** (`Watch` entries for tables other than the source table, e.g.
   `.watch('brand', 'SELECT id FROM product WHERE brand_id = :id')`): Fuzzphony cannot
   know which of the *watched* table's columns matter, because that mapping is buried in
   arbitrary, developer-authored SQL (`$watch->affectedIds`, or the index's own
   `fromQuery()`). This case requires a new, **explicit, opt-in** `columns` parameter on
   `Watch`/`.watch()`. Omitting it preserves exactly today's behavior (fire on every
   UPDATE) — zero behavior change for existing joined watches.

Both cases are in scope for this one feature; both were confirmed with the user despite
the added complexity of case 2's statement-level trigger implementation (see below).

## Where "relevant columns" lives

Not on the `Watch` value object for the self-watch case — that stays a pure, computed
fact of the `IndexDefinition` (fields + filters + boost + recency), resolved at
schema-generation time. `Watch` gains one new property, `?array $columns` (a
`list<string>|null`), used **only** to carry an explicit, developer-declared column list
for a joined watch. `null` means "unknown / not narrowed" (self-watch: resolved
automatically from the definition; joined watch: no filtering, current behavior).

`PostgresSchemaGenerator` gets one new private method:

```php
/** @return list<string>|null null = no column-diff filtering (fire on every UPDATE) */
private function relevantColumns(IndexDefinition $index, Watch $watch): ?array
{
    if ($watch->columns !== null) {
        return $watch->columns !== [] ? $watch->columns : null;
    }
    if ($index->source->table !== null && $watch->table === $index->source->table) {
        $columns = [];
        foreach ($index->fields as $field) { $columns[] = $field->column(); }
        foreach ($index->filters as $filter) { $columns[] = $filter->column(); }
        if ($index->boostColumn !== null) { $columns[] = $index->boostColumn; }
        if ($index->recencyColumn !== null) { $columns[] = $index->recencyColumn; }

        return array_values(array_unique($columns));
    }

    return null;
}
```

An empty explicit `columns: []` is treated the same as `null` (no filtering) rather than
"never refresh on update" — a defensive default against a confusing footgun, not a
meaningful use case.

## SQL generation

### Row-level trigger (`syncFunction()`)

Both self-watch and joined-watch cases are simple here: prepend one guard, computed once,
to the existing function body (which still runs its two `NEW`/`OLD` branches unchanged):

```sql
IF TG_OP = 'UPDATE' AND NOT (NEW."col1" IS DISTINCT FROM OLD."col1" OR NEW."col2" IS DISTINCT FROM OLD."col2") THEN
    RETURN NULL;
END IF;
```

Generated only when `relevantColumns()` returns a non-empty list; otherwise the function
body is unchanged from today (byte-identical DDL for non-adopting indexes — no schema
churn for anyone not affected).

### Statement-level trigger (`statementSyncFunction()`) — the harder case

This is the **default** `TriggerLevel`, so it must be handled, but it cannot use the same
"prepend a guard" trick. Each of the three physical triggers (`_ins`/`_upd`/`_del`)
registers only the transition table(s) relevant to its own event
(`REFERENCING NEW TABLE AS fz_new` / `OLD TABLE AS fz_old` / both), and all three call the
*same* function body. Today's function has exactly two branches:

- `IF TG_OP IN ('INSERT', 'UPDATE') THEN <uses fz_new> END IF;`
- `IF TG_OP IN ('UPDATE', 'DELETE') THEN <uses fz_old> END IF;`

A tempting shortcut — add `AND (TG_OP <> 'UPDATE' OR EXISTS (SELECT 1 FROM fz_old ...))` to
the first branch's query — is **wrong**: during an `_ins`-only invocation, `fz_old` isn't
registered as a transition table at all, and Postgres must resolve every relation named in
a statement it actually executes, even inside a WHERE clause that would runtime-short-circuit
past it. Referencing `fz_old` from a query that executes during an `_ins` firing throws,
regardless of the WHERE clause's logical structure.

The correct fix restructures the two combined branches into **three** mutually exclusive
ones, so a query naming both transition tables is only ever reached (and only ever
resolved) during the one physical trigger invocation (`_upd`) where both actually exist:

```sql
IF TG_OP = 'INSERT' THEN
    -- unfiltered, uses fz_new only (unchanged from today's INSERT behavior)
    INSERT INTO fuzzphony_queue (index_name, doc_id)
    SELECT DISTINCT 'products', a.doc_id::text
    FROM fz_new r CROSS JOIN LATERAL (<affectedIds with r.key>) AS a(doc_id)
    WHERE a.doc_id IS NOT NULL
    ON CONFLICT (index_name, doc_id) DO NOTHING;
END IF;

IF TG_OP = 'DELETE' THEN
    -- unfiltered, uses fz_old only (unchanged from today's DELETE behavior)
    INSERT INTO fuzzphony_queue (index_name, doc_id)
    SELECT DISTINCT 'products', a.doc_id::text
    FROM fz_old r CROSS JOIN LATERAL (<affectedIds with r.key>) AS a(doc_id)
    WHERE a.doc_id IS NOT NULL
    ON CONFLICT (index_name, doc_id) DO NOTHING;
END IF;

IF TG_OP = 'UPDATE' THEN
    -- only reached during the _upd trigger, where BOTH fz_new and fz_old exist;
    -- computes affected ids from both the new-key and old-key perspective (in case
    -- keyColumn isn't a stable primary key — see below), restricted to rows whose
    -- relevant columns actually changed
    INSERT INTO fuzzphony_queue (index_name, doc_id)
    SELECT DISTINCT 'products', a.doc_id::text
    FROM fz_new n JOIN fz_old o ON n."<keyColumn>" = o."<keyColumn>"
    CROSS JOIN LATERAL (
        (<affectedIds with n.key>) UNION (<affectedIds with o.key>)
    ) AS a(doc_id)
    WHERE a.doc_id IS NOT NULL
      AND (n."col1" IS DISTINCT FROM o."col1" OR n."col2" IS DISTINCT FROM o."col2")
    ON CONFLICT (index_name, doc_id) DO NOTHING;
END IF;
```

Why the `UPDATE` branch computes affected ids from *both* `n` (new row) and `o` (old row):
today's code does this too (separately, once per combined branch) to correctly invalidate
both sides when `$watch->keyColumn` isn't a stable identifier — the general contract
doesn't assume the correlating key can't change. This design preserves that guarantee
inside the single merged `UPDATE` branch via `UNION`.

When `relevantColumns()` returns `null` for a watch, the generated function must be
**byte-identical to today's two-branch version** — the three-branch/JOIN structure is
only emitted when there's an actual column list to filter by. This is a real,
acknowledged complexity/duplication cost (two SQL-shape code paths in
`statementSyncFunction()`), accepted because the alternative (always emitting the
three-branch form) would be a gratuitous DDL/behavior change for every existing
statement-level index, including ones with no opinion on this feature.

## API surface

```php
// Builder
IndexBuilder::watch(string $table, string $affectedIds = 'SELECT :id', string $keyColumn = 'id', ?array $columns = null): self

// YAML
watch:
  brand:
    ids: "SELECT id FROM product WHERE brand_id = :id"
    columns: [name]        # new, optional key; omit for current (unfiltered) behavior
  # short string form (watch: { brand: "SELECT ..." }) still works, means columns: null
```

`#[Searchable]` does not gain a `watch`/`columns` equivalent — it never had `.watch()` at
all (`AttributeExporter::supports()` already refuses any index with explicit watches;
attribute-defined indexes only ever get the automatic self-watch, which needs no new API
since its column list is derived automatically).

`Watch` gains `public ?array $columns = null` (a `list<string>|null`). No change to its
constructor's existing three parameters' order or defaults.

## Validation

`DefinitionValidator::validate()`, in its existing per-watch loop (alongside the current
`Identifier::isTable($watch->table)` / key-column / `:id`-count checks): if
`$watch->columns !== null`, assert each entry is `Identifier::isColumn(...)`, same style as
the existing filter/field column checks.

## Exporters (learned from the multi-tenancy retrospective: teach export parity up front)

- `ArrayExporter`: a watch with `columns !== null` must use the array form
  (`['ids' => ..., 'key' => ..., 'columns' => [...]]`) — the existing short-string form
  (`watch: { brand: "SELECT ..." }`) can't carry a column list.
- `BuilderExporter`: emits `columns: [...]` as a **named** argument (not positional) so it
  composes correctly whether or not `keyColumn` is also non-default — PHP requires named
  arguments once a positional argument is skipped, e.g.
  `->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])`.
- `AttributeExporter`: no change (see above — it never exports watches; refuses to export
  any index that has one).
- `YamlExporter`: no change (delegates entirely to `ArrayExporter`).
- `ExportersTest`: extend the existing watch/round-trip coverage with a case that declares
  `columns` on a joined watch and asserts it survives export → reload.

## Doctor

One new informational line in `PostgresInspector`, alongside the existing per-watch
trigger check: when `relevantColumns()` is non-null for a watch, something like
`Check::ok('Column-aware filtering', sprintf('active for %s (%s)', $watch->table, implode(', ', $columns)))`.
Self-watch and joined-watch cases both produce this line (self-watch always will, once
shipped, since it's automatic).

## Migration story

None needed, same shape as multi-tenancy's: `CREATE OR REPLACE FUNCTION` is idempotent, so
a normal `fuzzphony:schema --apply` regenerates every trigger function body to the new
shape. Self-watch indexes get the optimization automatically on next apply, with no code
change. Joined-watch indexes are unaffected until a developer explicitly adds `columns:`
to a `.watch()` call.

## Testing

- Unit: `PostgresSchemaGenerator::relevantColumns()` (self-watch auto-derivation, explicit
  joined-watch columns, empty-list-treated-as-null); `DefinitionValidator` rejects an
  invalid column name in `columns`; exporter round-trip test for a joined watch with
  `columns` set.
- Integration (Postgres): a self-watch table update that only touches an *unrelated*
  column must **not** enqueue/refresh; one that touches a relevant column **must**. Same
  pair of assertions for a joined watch with explicit `columns`, covering both row-level
  and statement-level `TriggerLevel`, and both `SyncMode::Queue` and `SyncMode::Trigger`
  (queue mode: assert `fuzzphony_queue` row count; trigger mode: assert the sidecar row's
  `indexed_at` did/didn't advance). At minimum: one test proving the statement-level
  `_ins`/`_del`-only invocations don't error when a relevant-columns list is configured
  (regression guard for the exact transition-table-reference bug this design works around).

## Open questions for the implementation plan

- Exact wording of the doctor check and README updates — not architecturally significant.
- Whether `fuzzphony:wizard`'s `DefinitionSuggester` should ever suggest joined-watch
  `columns` automatically — out of scope for this iteration (the wizard doesn't inspect
  joined tables' column usage today; suggesting columns would need it to parse the
  developer's own `affectedIds`/`fromQuery()` SQL, which the wizard doesn't do for
  anything else either).

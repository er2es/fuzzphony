# Multi-tenancy design

Status: approved (design), not yet implemented. First piece of the v1.0 "enterprise
readiness" roadmap line in `README.md`.

## Problem

Fuzzphony has no built-in concept of a tenant. An application that stores multiple
customers' data in the same tables (a shared-schema multi-tenant SaaS) has no way to
guarantee that a search can never return another tenant's documents — today that
guarantee, if it exists at all, lives entirely in application code that calls
`SearchQuery::where()` by convention, which is easy to forget on one code path and
never notice until it leaks.

Fully isolated tenants (separate database/schema per tenant) are **already** supported
today: instantiate a separate `Connection` / `PostgresEngine` per tenant. This design is
for the shared-sidecar-table case only.

## Requirements (from stakeholder discussion)

- Must work for any tenant shape: few large tenants or many small SaaS tenants; source
  tables that already carry a tenant column, and ones that don't yet.
- Isolation guarantee: **application-layer**, enforced structurally by the Fuzzphony API
  (impossible to silently search across tenants by omission). Postgres RLS / DB-level
  enforcement is explicitly out of scope for this iteration (see below) — the design
  must not preclude adding it later as an independent layer.
- Zero behavior change, zero schema change, zero new required calls for indexes that
  don't opt in. This is a purely additive, per-index opt-in feature.

## Approach

Tenant scoping is implemented as a **designated existing filter**, not a new schema
concept. The tenant column is declared exactly like any other `FilterDefinition`
(`->filter('account_id', 'int')`); a new `.tenant('account_id')` call marks that filter
as the tenant scope. This means:

- The sidecar column (`f_account_id`), its btree index, and `FilterCompiler`'s WHERE
  generation are **100% reused** — zero new DDL, zero new schema-generator code.
  `PostgresSchemaGenerator` does not change.
  - The btree index that's already created for any filter is sufficient for tenant
    lookups; no new composite-index tuning is in scope for this iteration.
- Sync (queue / trigger / ORM / manual) needs **no changes**. The refresh function
  already re-runs the full document `SELECT` (source → sidecar) filtered by id; the
  tenant value flows through as an ordinary column, the same as any other filter.
  Reindexing and the worker are already id-only and tenant-agnostic by construction.

Rejected alternative: a first-class `tenantColumn` parallel to `boostColumn`/
`recencyColumn`, with its own schema column, index and `DocumentSql` alias. Functionally
equivalent to the chosen approach but duplicates the filter machinery for no benefit.

Explicitly deferred (not part of this iteration, and not precluded by it):
Postgres RLS / session GUC enforcement, cross-tenant/admin search, schema-per-tenant
(already achievable today via separate `Connection` instances).

## API surface

**Definition (all three ways to define an index get the option):**

```php
// Builder
IndexDefinition::builder('products')
    ->fromTable('product')
    ->filter('account_id', 'int')
    ->tenant('account_id')   // marks that filter as the tenant scope
    ->field('name', 'A', fuzzy: true)
    ->build();

// Attribute
#[Searchable(tenant: 'account_id')]
class Product { ... }

// YAML (ArrayDefinitionLoader)
products:
  tenant: account_id
```

**Query:**

```php
$fuzzphony->in('products')->forTenant($accountId)->query('wireless mouse')->get();

// Omitting forTenant() on a tenant-scoped index:
$fuzzphony->in('products')->query('wireless mouse')->get();
// throws InvalidQuery('Index "products" requires forTenant(); none was given.')
```

Non-tenant-scoped indexes are entirely unaffected: `forTenant()` is simply never called,
`$index->tenant` stays `null`, no guard fires, no condition is injected.

## Implementation surface

- `IndexDefinition`: new `public ?string $tenant = null` property (the tenant filter's
  **name**, not a raw SQL column) — added through the same `with()`-style constructor
  path already hardened during the PHPStan cleanup (explicit, validated named
  arguments; no untyped merge+spread).
- `IndexBuilder::tenant(string $filterName): self` — same shape as `boostBy()`/
  `recencyBy()`.
- `Searchable` attribute: new `tenant: ?string` constructor option, read by
  `AttributeDefinitionLoader`.
- `ArrayDefinitionLoader`: new `tenant` YAML/array key, following the existing
  `self::str(...)` narrowing pattern.
- `DefinitionValidator`: if `$index->tenant !== null`, assert a filter with that name
  exists in `$index->filters` (`InvalidDefinition` otherwise, matching existing style).
- `SearchQuery`: new `mixed $tenant = null` property; `SearchQuery::copy()` gets a new
  explicit branch for it (same validated-extraction pattern as the other properties).
- `SearchBuilder::forTenant(mixed $value): self` → `$this->copy(tenant: $value)`.
- `PostgresEngine::execute()`: at the top —
  1. Guard: `$index->tenant !== null && $query->tenant === null` → `throw InvalidQuery(...)`
     (reuses the existing exception; no new exception class).
  2. Build `$conditions`: if `$index->tenant !== null`, prepend
     `new Condition($index->tenant, Operator::Eq, $query->tenant)` to `$query->conditions`
     once, before any of the three branches (`ranked()`'s fts/fuzzy CTEs, `browse()`)
     consume it — guaranteeing every branch inherits it from a single injection point.
- `PostgresInspector`: one additional informational `Check::ok('Tenant scoping', ...)`
  line when `$index->tenant !== null` (non-blocking, purely informational).

## Migration story

None needed — purely additive/opt-in. An index that adopts tenant scoping later follows
the exact same path as adding any other new filter today: declare the filter (if not
already present), call `.tenant()`, run `fuzzphony:schema --apply` (idempotent) and
`fuzzphony:reindex` (backfills the column into existing sidecar rows). No new CLI
command or migration tooling is needed.

## Documentation

`README.md` gets a new "Multi-tenancy" subsection (placed near "Keeping the index in
sync" / "Integrations"), covering:

- **When you need it**: your data is already partitioned by tenant/account in the same
  tables (shared-schema multi-tenant SaaS) and a search must never be able to return
  another tenant's documents — e.g. each customer sees only their own product catalogue.
- **When you don't**: single-tenant applications (no behavior change either way), or
  applications with fully isolated tenants (separate database/schema per tenant) —
  those already work today via one `Connection`/`PostgresEngine` instance per tenant.
- A short code example mirroring the API surface above.
- One line noting Postgres RLS as a possible additional, independent hardening layer,
  not provided by Fuzzphony itself.

The `README.md` Roadmap entry updates from a one-line bullet to a completed item once
implemented (tracked by the eventual implementation plan, not by this spec).

## Testing

- Unit: `DefinitionValidator` rejects `.tenant('unknown_filter')`; `SearchQuery::copy()`
  correctly carries/validates the new `tenant` property (mirroring the existing
  `SearchQueryTest` coverage added for the other `copy()` fields).
- Integration (Postgres): two tenants sharing one sidecar table — assert a search for
  tenant A never returns tenant B's rows even when both would otherwise match; assert
  `forTenant()` omission throws `InvalidQuery` on a tenant-scoped index; assert a
  non-tenant-scoped index is unaffected (regression guard).

## Open questions for the implementation plan

- Exact wording/placement of the `InvalidQuery` message and the README subsection —
  left to implementation, not architecturally significant.
- Whether `fuzzphony:doctor`'s new check needs a `--deep` variant (e.g. sampling for
  cross-tenant leaks) — deferred; the informational `Check::ok` line is sufficient for
  v1 of this feature.

# Multi-tenancy

Enforcing a tenant filter on every search of a shared-schema index. Back to the
[README](../README.md).

## When you need it

Use it when your data is partitioned by tenant or account in the same tables: a shared-schema
multi-tenant SaaS where one customer's data must never appear in another customer's results. Mark
the tenant column, and Fuzzphony enforces it on every search, not only on the ones a developer
remembered to filter.

You don't need it for a single-tenant application, or when tenants are fully isolated. A separate
database or schema per tenant already works with one `Connection` / engine instance per tenant.

## Usage

```php
IndexDefinition::builder('products')
    ->fromTable('product')
    ->filter('account_id', 'int')
    ->tenant('account_id')   // marks that filter as the tenant scope
    ->field('name', 'A', fuzzy: true)
    ->build();

$fuzzphony->in('products')->forTenant($accountId)->query('wireless mouse')->get();

// Omitting forTenant() on a tenant-scoped index throws immediately, before any SQL runs:
$fuzzphony->in('products')->query('wireless mouse')->get();
// InvalidQuery: Index "products" requires forTenant(); none was given.
```

`tenant()` also works as `#[Searchable(tenant: 'account_id')]` and as the YAML `tenant: account_id`
key. A full controller example is in [Searching](searching.md#a-fuller-endpoint).

## Adding it to an existing index

Declaring `tenant()` does not touch rows that are already indexed. After adding it, run
`fuzzphony:schema --apply` and then `fuzzphony:reindex` to backfill the tenant column. Until you
reindex, the column is NULL for rows indexed before the change, so tenant-scoped searches return
nothing for them. That fails closed, but is easy to mistake for a bug.

## Integrations

`FuzzphonySearchFilter` (API Platform), `SearchComponent` (Live Component) and
`bin/console fuzzphony:search` do not accept a tenant value yet. Using any of them on a
tenant-scoped index throws `InvalidQuery` (fail-closed). A tenant resolver for them is planned (see
the [roadmap](roadmap.md#security)).

## What it guarantees

This is an application-layer guarantee, enforced by Fuzzphony's API rather than by the database.
For defense in depth against raw SQL that bypasses the library, add your own PostgreSQL row-level
security policy on the sidecar table. Fuzzphony's filter stays correct alongside it.

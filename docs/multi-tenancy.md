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

## The actor: who is searching

`forTenant()` takes a tenant value you already have. When the tenant comes from the logged-in user,
describe the searcher once, as an `Actor` (a tenant and a user, both optional), and pass it with
`asActor()`:

```php
use Fuzzphony\Core\Search\Actor;

$fuzzphony->in('products')
    ->asActor(new Actor(tenant: $account->id, user: $user->getUserIdentifier()))
    ->query('wireless mouse')
    ->get();
```

The actor has an optional third argument, `ip`, for the client address: the Core is HTTP-agnostic, so the caller (the
Symfony bundle, from `Request::getClientIp()`) gives it, and it only ever reaches the [search log](analytics.md#the-client-ip),
masked or not at all as `search_log.ip` says.

`asActor()` sets the tenant when the actor has one (an actor without a tenant never clears a tenant
set before; the last of `forTenant()` and `asActor()` wins) and keeps the user on the query. The user
changes no SQL: it is the identity that the [search log](analytics.md) and the
[guards](security.md) (rate limit and authorization) read. `forTenant()` stays and works as before. The search log's
per-user report, [`recentSearches()`](analytics.md#who-searched-what), follows the same tenant rule as the other reports:
on a tenant-scoped index it needs a tenant or an explicit `allTenants: true`, and with a tenant named the history of a user in
one tenant is never returned for another tenant, even when two tenants have a user of the same name. On an index that is not
tenant-scoped, a call without a tenant covers everyone (as the other reports do, but these rows are per person): name the tenant.

On a tenant-scoped index, a search with an actor but no tenant throws `ActorRequired`, a subclass of
`InvalidQuery`. A search without an actor still throws the original `InvalidQuery` ("requires
forTenant(); none was given"), so code that catches `InvalidQuery` keeps working.

## Integrations

`FuzzphonySearchFilter` (API Platform), `SearchComponent` (Live Component) and
`bin/console fuzzphony:search` serve a tenant-scoped index. The first two resolve the actor on the
server, on every request, with the resolver you configure under `fuzzphony.actor`
([configuration](configuration.md#the-actor)): either a service of yours implementing
`Fuzzphony\Core\Search\ActorResolver`, or property paths read on the Symfony Security user.

```yaml
fuzzphony:
  actor:
    resolver: App\Search\CurrentAccountResolver   # your ActorResolver service
```

```yaml
fuzzphony:
  actor:
    tenant: account.id          # property paths on the Security user
    user: userIdentifier        # (need symfony/security-bundle and symfony/property-access)
```

With nothing configured the actor is anonymous: an index without a tenant works as before, and a
tenant-scoped index is refused. No token (nobody logged in) is anonymous too, not an error.

- **API Platform filter:** the resolved actor is applied to every search the filter runs.
- **Live Component:** the actor is resolved on the server on every render and re-render. The tenant
  is never a `LiveProp`: a client cannot send it, change it or replay another tenant's value.
- **`fuzzphony:search`:** a command has no logged-in user, so it takes `--tenant` and `--user`:
  `bin/console fuzzphony:search products 'mouse' --tenant=42 --user=ada` (the value is matched against
  the type of the tenant filter: `42` works for an integer and for a string filter). On a tenant-scoped
  index without `--tenant` it prints why and exits with code 1; an empty `--tenant=` or `--user=` is refused.
- **Explicit stays explicit:** the core has no ambient actor. In your own controllers and services,
  resolve it and pass it yourself: `->asActor($resolver->resolve())`, with
  `Fuzzphony\Core\Search\ActorResolver` injected (the bundle aliases it to the configured resolver).
  A search you do not scope throws; it is never scoped by a hidden request context.

### A refusal is a 403

When a tenant-scoped index is searched by an actor without a tenant, the HTTP integrations answer
**403 Forbidden** (`AccessDeniedHttpException`, the original `ActorRequired` as its previous
exception), not a 500 and not an empty result. Each refusal increments the counter
`fuzzphony.search.denied` (labels `index` and `reason=actor_required`, see
[Observability](observability.md)) and writes a log line. The tenant check comes before the
[guards](security.md#the-guards-and-their-order): an actor without a tenant is `ActorRequired` even if
it would also lack the permission. A search denied by authorization (403) or over the rate limit (429
with `Retry-After`) is explained in [Security](security.md).

## Who may search, and how often

The tenant scope decides *whose rows* a search sees. Who may search at all, how often, and which text
fields a viewer may search and see are the guards of [Security](security.md): a rate limit per tenant or
user, index- and field-level authorization on Symfony's voters.

## Did you mean

A tenant-scoped index never suggests a spelling: its vocabulary is the words of every tenant, and a
suggestion (or the lack of one) would tell one customer which words another customer's documents
have. `SearchResult::$didYouMean` stays `null` there.

## What it guarantees

This is an application-layer guarantee, enforced by Fuzzphony's API rather than by the database.
For defense in depth against raw SQL that bypasses the library, add your own PostgreSQL row-level
security policy on the sidecar table. Fuzzphony's filter stays correct alongside it.

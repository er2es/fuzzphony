# Integrations

Doctrine, API Platform, Symfony UX Live Component and Messenger. Back to the
[README](../README.md).

## Doctrine

- `Fuzzphony\Bridge\Doctrine\EntityLoader` turns search results into entities with one query,
  keeping the ranking order. In `queue` mode it skips hits whose row no longer exists.
- `Fuzzphony\Bridge\Doctrine\DbalConnection` runs Fuzzphony on an existing Doctrine DBAL
  connection. The Symfony bundle uses the connection named in `fuzzphony.connection` (default
  `default`).
- `orm` sync mode refreshes documents after `flush()` through an ORM listener (see
  [Keeping the index in sync](sync.md)).
- With the bundle, every Doctrine entity with `#[Searchable]` is registered as an index.

## API Platform

Relevance search for any collection whose entity is searchable (requires
`api-platform/doctrine-orm`). Other filters, pagination and serialization keep working; results
come in score order:

```php
#[ApiResource]
#[ApiFilter(FuzzphonySearchFilter::class)]            // GET /api/products?q=wireles mouse -cable
class Product { /* ... */ }
```

## Live Component

Search-as-you-type without writing JavaScript (requires `symfony/ux-live-component`):

```twig
<twig:Fuzzphony:Search index="products" highlight="name" placeholder="Search products…" />
```

Override `templates/bundles/FuzzphonyBundle/components/Search.html.twig` to change the markup.

The API Platform filter and the Live Component do not accept a tenant value yet; see
[Multi-tenancy](multi-tenancy.md#integrations).

## Messenger

In `orm` sync mode, refreshing can run asynchronously through Symfony Messenger. See
[Messenger (orm mode)](sync.md#messenger-orm-mode).

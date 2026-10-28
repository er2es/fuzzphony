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

## Doctrine Migrations

`bin/console fuzzphony:schema --dump-migration=migrations` writes the schema as a Doctrine
migration class (needs `doctrine/migrations`). Every statement is idempotent (`IF NOT EXISTS`,
`CREATE OR REPLACE`), so it is safe to commit and to run again. Each part records itself in
`fuzzphony_meta` once its objects exist: the shared objects first, then every index after its own
statements, so the migration ends with the last index's record. It is not transactional, because
the indexes are built `CONCURRENTLY`, and `down()` is irreversible (use
`fuzzphony:schema --drop --apply`).

Doctrine's schema tools must not see Fuzzphony's tables, or `doctrine:migrations:diff` proposes
dropping them. With DoctrineBundle, the bundle sets the connection's `schema_filter` for you:
`~^(?!(public\.)?fuzzphony_)~`, or `~^(?!fuzzphony\.)~` with `schema: fuzzphony`. If your
connection already has a `schema_filter`, the bundle leaves it alone and `fuzzphony:doctor` warns,
with the regex to merge, as long as your filter lets Fuzzphony's tables through; merge the two and
the warning goes away, for example:

```yaml
doctrine:
  dbal:
    schema_filter: '~^(?!(public\.)?(fuzzphony_|legacy_))~'   # or '~^(?!(fuzzphony\.|legacy_))~' with schema: fuzzphony
```

If `fuzzphony.connection` or `fuzzphony.schema` comes from a parameter or an environment variable
(`%env(...)%`), the bundle cannot read it when the filter is set up: it sets no filter and the
doctor does not check yours, so add Fuzzphony's regex to your connection yourself.

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

A few attributes add the search-as-you-type features:

```twig
<twig:Fuzzphony:Search index="products" facets="category_id,in_stock" suggestions="5" />
```

- `suggestions` (default 5, `0` switches it off): the word being typed is completed from the index's vocabulary
  ([search-as-you-type](searching.md#search-as-you-type)) in a list under the input; choosing one searches it.
- `asYouType` (default `true`, `false` turns it off): the word being typed also matches as the beginning of a longer
  one, so `cr` already finds `Crème` ([matching the word being typed](searching.md#matching-the-word-being-typed)).
- `log` (default `false`): the component searches on every pause in typing, so its searches stay out of the search log
  (`SearchBuilder::unlogged()`); `log="true"` records them. A refusal is recorded either way. It is a server-side
  attribute: the browser cannot change it.
- `facets` (comma-separated filter names): the values of those filters with their counts
  ([facets](searching.md#facets)) as toggle buttons; choosing one narrows the search, choosing it again lifts it.
  Only the filters named in `facets` can be chosen (the component ignores any other, so a forged request cannot
  add a filter). The counts are over the candidates, with a `+` when the search hit its candidate limit.

Override `templates/bundles/FuzzphonyBundle/components/Search.html.twig` to change the markup.

The API Platform filter and the Live Component search as the configured actor (`fuzzphony.actor`), so a
tenant-scoped index works with both; the tenant is resolved on the server, never taken from the client. See
[Multi-tenancy](multi-tenancy.md#integrations).

## Your own frontend

The Live Component above is a convenience, not a requirement. Fuzzphony is a library that returns a
`SearchResult`; any UI (Twig, a JSON endpoint for a single-page app, a mobile client, a CLI) can render it. What a custom
UI depends on:

| `SearchResult` | What it is | Rendering |
|---|---|---|
| `hits` | `Hit` objects in rank order: `id`, `score`, `highlights` (field => snippet) | Load your own rows by `id` (`ids()` gives the list) |
| `total`, `totalIsLowerBound` | The number of matches; a lower bound when the search hit `candidate_limit` | Show `2000+` when `totalIsLowerBound` is true |
| `hasMore()`, `limit`, `offset`, `page()` | Paging | A pager follows `hasMore()` |
| `facets` | `filter => list<FacetValue>` (`value`, `count`) for the filters named in `facets()` | Counts are lower bounds when `totalIsLowerBound` |
| `didYouMean` | A spelling suggestion, or `null` | Plain text; search it by passing it to `query()` |
| `correctedFrom` | The visitor's text when the search found nothing and the result is the one of the corrected text, or `null` | Plain text; say "results for ..., you wrote ..." |
| `warnings` | Corrections applied to the text | Plain text |
| `usedFuzzy`, `interpretedAs`, `tookMs` | Diagnostics | Optional |

**The escape rule.** `$hit->highlights['name']` is HTML: Fuzzphony escapes the whole text (`&`, `<`, `>`, quotes) and adds
`<mark>` and `</mark>` around the matching words, nothing else. Render it as HTML (`innerHTML`, Twig `|raw`), or strip the
`<mark>` tags first if you want plain text. Never escape it a second time (the visitor would see `&amp;`), and never render
a value that is not a highlight as HTML. `didYouMean`, `correctedFrom`, `warnings` and everything from your own rows are
plain text: escape them. A field you did not ask to highlight, and every search with an empty text, has no highlight: escape the
stored value instead.

A framework-free endpoint (the index is defined as in [Builder (plain PHP)](configuration.md#builder-plain-php); run
`$fuzzphony->schema()->apply($connection)` and `reindex()` once):

```php
// search.php
$pdo = new PDO('pgsql:host=localhost;dbname=shop', 'user', 'secret');
$fuzzphony = new Fuzzphony(new PostgresEngine(new PdoConnection($pdo)), new IndexRegistry([
    IndexDefinition::builder('products')
        ->fromQuery('SELECT id, name, category FROM product')
        ->watch('product')
        ->field('name', 'A', fuzzy: true)
        ->filter('category', 'string')
        ->language('english')
        ->build(),
]));

$result = $fuzzphony->in('products')
    ->query(trim((string) ($_GET['q'] ?? '')))
    ->highlight('name')
    ->facets('category')
    ->page(max(1, (int) ($_GET['page'] ?? 1)), perPage: 10)
    ->get();

// A hit carries its id, score and highlights, not your columns: read the rows you show (here: the name) by id.
$names = [];
if ($result->hits !== []) {
    $statement = $pdo->prepare('SELECT id, name FROM product WHERE id IN (' . implode(',', array_fill(0, count($result->hits), '?')) . ')');
    $statement->execute($result->ids());
    $names = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
}

header('Content-Type: application/json');
echo json_encode([
    'total' => $result->total,
    'totalIsLowerBound' => $result->totalIsLowerBound,   // show "2000+" instead of "2000"
    'hasMore' => $result->hasMore(),
    'didYouMean' => $result->didYouMean,                 // plain text: escape it
    'correctedFrom' => $result->correctedFrom,           // plain text: escape it
    'warnings' => $result->warnings,                     // plain text: escape it
    'hits' => array_map(static fn($hit): array => [
        'id' => $hit->id,
        // HTML, escaped by Fuzzphony with <mark> as the only tag; without a highlight, escape the plain value yourself
        'nameHtml' => $hit->highlights['name'] ?? htmlspecialchars($names[$hit->id] ?? ''),
    ], $result->hits),
    'facets' => array_map(static fn(array $values): array => array_map(
        static fn(FacetValue $v): array => ['value' => $v->value, 'count' => $v->count],
        $values,
    ), $result->facets),
], JSON_THROW_ON_ERROR);
```

and the page that calls it:

```html
<input id="q" type="search" placeholder="Search products…" autocomplete="off">
<div id="results"></div>
<script>
  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  function render(r) {
    const total = r.total + (r.totalIsLowerBound ? '+' : '');
    return [
      r.correctedFrom ? `<p>Showing results for the corrected search (you wrote <i>${esc(r.correctedFrom)}</i>)</p>` : '',
      r.didYouMean ? `<p>Did you mean <a href="?q=${encodeURIComponent(r.didYouMean)}">${esc(r.didYouMean)}</a>?</p>` : '',
      `<p>${total} results</p>`,
      '<ul>' + r.hits.map((h) => `<li>${h.nameHtml}</li>`).join('') + '</ul>',
    ].join('');
  }

  async function search(q) {
    const response = await fetch('/search.php?q=' + encodeURIComponent(q));
    document.querySelector('#results').innerHTML = render(await response.json());
  }

  document.querySelector('#q').addEventListener('input', (e) => search(e.target.value));
</script>
```

For search-as-you-type, call the endpoint after a pause in typing, not on every key, and add `->unlogged()` to the builder if
those searches should stay out of the [search log](analytics.md). A public endpoint needs a rate limit: with Symfony it is
`fuzzphony.rate_limit.per: ip` ([Security](security.md#the-rate-limit)); without it, use your framework's. The template of the
component (`src/Bundle/templates/components/Search.html.twig`) shows one way to render each part of the result.

## Messenger

In `orm` sync mode, refreshing can run asynchronously through Symfony Messenger. See
[Messenger (orm mode)](sync.md#messenger-orm-mode).

# Searching

The search builder, the query syntax, typo tolerance and empty-result relaxation. Back to the
[README](../README.md).

## The search builder

```php
$result = $fuzzphony->in(Product::class)       // or the index name: ->in('products')
    ->query('wireles mouse -cable')
    ->where('price', '<=', 20_000)
    ->where('in_stock', true)
    ->highlight('name')
    ->get();
```

- Every `where*` / `forTenant` / `profile` / `ranking` / `thresholds` call is immutable and returns
  a new builder. Building a query conditionally is just reassigning the variable. Nothing runs until
  `->get()`.
- An empty query (a search page's first load) is a normal call. It returns a plain, filtered
  browse instead of an error, so one endpoint serves both "search" and "browse".
- Filter names are snake_case (`in_stock`, not `inStock`).
- `Fuzzphony\Bridge\Doctrine\EntityLoader` turns results into entities with one query, keeping the
  ranking order.

### A minimal endpoint

```php
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Hit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    #[Route('/search', name: 'search')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $result = $fuzzphony->in(Product::class)
            ->query($q)
            ->where('in_stock', true)
            ->highlight('name')
            ->limit(20)
            ->get();

        return $this->json([
            'total' => $result->total,
            'tookMs' => $result->tookMs,
            'hits' => array_map(static fn(Hit $hit): array => [
                'id' => $hit->id,
                'score' => $hit->score,
                'name' => $hit->highlights['name'] ?? null,   // "<mark>Wireless</mark> mouse"
            ], $result->hits),
        ]);
    }
}
```

### A fuller endpoint

Pagination, several filters from query parameters, a tenant scope and a client-chosen ranking
profile. This uses a different index, one that is explicitly tenant-scoped (see
[Multi-tenancy](multi-tenancy.md)). Tenant scoping is opt-in per index.

```php
IndexDefinition::builder('listings')
    ->fromTable('listing')
    ->filter('account_id', 'int')
    ->tenant('account_id')
    ->filter('category', 'string')
    ->filter('price', 'int')
    ->filter('in_stock', 'bool')
    ->field('name', 'A', fuzzy: true)
    ->field('description', 'D')
    ->build();
```

```php
#[Route('/search', name: 'search')]
public function __invoke(Request $request, Fuzzphony $fuzzphony): JsonResponse
{
    $accountId = $this->getUser()?->getAccountId();
    if ($accountId === null) {
        // forTenant(null) on a tenant-scoped index throws "missing tenant"; guard before searching.
        return $this->json(['error' => 'Authentication required.'], 401);
    }

    // profile() validates eagerly and throws on an unknown name. Never pass raw user input to it:
    // that would contradict "search input never throws". Whitelist it instead.
    $sort = $request->query->get('sort', 'default');
    $sort = \in_array($sort, ['default', 'popular'], true) ? $sort : 'default';

    $builder = $fuzzphony->in('listings')
        ->query(trim((string) $request->query->get('q', '')))
        ->forTenant($accountId)
        ->profile($sort)
        ->highlight('name', 'description')
        ->page($request->query->getInt('page', 1), perPage: 20);

    if ($request->query->has('category')) {
        $builder = $builder->whereIn('category', $request->query->all('category'));
    }
    if ($request->query->has('price_min') || $request->query->has('price_max')) {
        $builder = $builder->whereBetween(
            'price',
            $request->query->getInt('price_min', 0),
            $request->query->getInt('price_max', PHP_INT_MAX),
        );
    }
    if ($request->query->getBoolean('in_stock_only')) {
        $builder = $builder->where('in_stock', true);
    }

    $result = $builder->get();

    return $this->json([
        'total' => $result->total,
        'totalIsExact' => !$result->totalIsLowerBound,   // false: show "$total+", the candidate cap was hit
        'tookMs' => $result->tookMs,
        'warnings' => $result->warnings,                 // plain text, e.g. "Only the first 16 terms were used."; escape it when rendering HTML
        'hits' => array_map(static fn(Hit $hit): array => [
            'id' => $hit->id,
            'score' => $hit->score,
            'name' => $hit->highlights['name'] ?? null,
            'description' => $hit->highlights['description'] ?? null,
        ], $result->hits),
    ]);
}
```

## Query syntax

End-user search text never throws. Malformed input is repaired and reported in
`$result->warnings`, and `$result->interpretedAs` shows how it was understood.

| Syntax | Meaning |
|---|---|
| `wireless mouse` | both words (AND is implicit; `AND` also works) |
| `"wireless mouse"` | exact phrase |
| `mouse OR trackpad`, `mouse \| trackpad` | either |
| `-cable`, `NOT cable`, `!cable` | exclude (also honoured by typo-tolerant matches) |
| `keyb*` | prefix |
| `brand:logitech`, `name:"mx master"` | only in that field, typos included (an unknown field searches every field, with a warning) |
| `(mouse OR trackpad) -cable` | grouping |

## Typo tolerance

When the typo-tolerant branch runs (see [thresholds](ranking.md#thresholds)), every word must
still be satisfied on its own, either exactly (full text) or by trigram similarity. The words are
combined through the query's real AND / OR / NOT:

| Query | Typo-tolerant meaning |
|---|---|
| `wireles mice` | (`wireles` exactly or a word similar to it) and (`mice` exactly or similar) |
| `headphnoes \| torhc` | either word, each exactly or similar |
| `wireles (headphones \| mouse -silent)` | the negation applies inside the group; negated words are always exact |
| `"wireles headphones"` | the phrase exactly, or the whole phrase as one similar text |
| `ergnoo*` | the prefix exactly, or the prefix text as a similar word |

So a typo in one word never lets through documents that lack the other words.

- Typo tolerance only reaches words stored in fuzzy fields (`fuzzy: true`). A misspelled word that
  appears only in a non-fuzzy field, such as a description or a category, cannot be matched
  approximately.
- The similarity a word needs is proportional to its length: one typo is tolerated from 4 letters,
  two from 8. A missing, extra or replaced letter changes at most three of a word's `n + 1`
  trigrams, so `t` such typos leave a trigram similarity of at least `(n + 1 − 3t) / (n + 1 + 3t)`;
  a word needs that plus a slack of 0.03 (0.28 for 4 letters, 0.36 for 5, 0.43 for 6, 0.48 for 7,
  0.23 for 8, 0.28 for 9, 0.40 for 12, 0.59 for 20), and words below 4 letters need 0.6. `n` is the
  length of the word after PostgreSQL normalised it (accents folded, `ß` → `ss`; a phrase counts
  its letters without the spaces; a prefix such as `ergnoo*` gets one letter of allowance, because
  its last trigram cannot match inside a longer word). The threshold of each word is computed in
  SQL, and a word below its own threshold does not score.
  `mouse` therefore does not match `monitor` or `mower` (0.33 against the 0.36 five letters
  need), while `mose`, `mouze`, `wireles`, `hedphones` and `moitor` still find what they mean.
  The slack is also the price: a letter replaced in the middle of a 5 to 7 letter word, or two
  letters swapped (a swap changes four trigrams), is the worst case and leaves exactly the
  similarity of a different word, so `mpuse` and `mosue` are not tolerated (a swap at the end of a
  word costs less: `torhc` still finds `torch`). Measured on
  generated typos of 24 common words, a 5 to 7 letter word finds about 70 to 80% of its
  single-letter typos (a flat 0.3: 92 to 100%) while it lists about five times fewer look-alikes.
  Set `fuzzy_similarity` to a number (per index or per query) to use that value for every word,
  for example `0.3` for the pre-0.7 behaviour, or to `null` (`--threshold fuzzy_similarity=null` on
  the command line) to go back to by length.
- Words shorter than `fuzzy_min_length` must match exactly.
- Stop words of the index language ("for", "the") are ignored, as in the full-text query.

## Synonyms

An index can know that words mean the same thing (`tv` and `television`, an abbreviation and what it
stands for). Synonyms are expanded on the query, so changing them needs no schema change and no
reindex. Two forms, in the index definition ([how](configuration.md#synonyms), also from a file with thousands of entries or from your own table):

| Entry | Meaning |
|---|---|
| `[tv, television]` | a group: every member finds every other member |
| `laptop => notebook \| portable` | a one-way rule: `laptop` also finds `notebook` and `portable`, but not the reverse |

```php
$result = $fuzzphony->in('products')->query('tv -bracket')->get();
$result->interpretedAs;   // "((tv OR television) AND NOT bracket)"
```

- A synonym finds documents like any word of the query does: it is stemmed with the index's
  language and accents are folded, it is highlighted, and typo tolerance applies to it. PostgreSQL
  does the stemming: the stems of the synonyms are fetched once (with the first search that has a
  word to expand) and kept, and each word of a query it has not seen yet costs one more small
  statement. So `Televisions` finds the group of `television`.
- Stemming is the language's: English leaves a plural abbreviation such as `tvs` or `ssds` as it
  is, so list it as a member (`[tv, tvs, television]`).
- A word with a symbol or a hyphen (`wi-fi`, `c++`, `c#`) is compared as it is, without stemming.
- `-tv` excludes the documents that match `television` too. A word scoped to a field
  (`name:tv`) expands inside the same field. A prefix (`tv*`) is not expanded.
- A member may be a phrase (`ssd` ⇄ `solid state drive`). It matches a quoted phrase in the query
  (`"solid state drive"` finds `ssd`); the same three words typed without quotes are three separate
  words and do not.
- A synonym of a synonym is not followed (`a => b` and `b => c`: `a` finds `b`, not `c`).
- The exact-title and prefix bonuses compare with the words the user typed, never with the
  alternatives. The empty-result relaxation treats a word and its alternatives as one word: it
  ignores it only when none of them matches, and names it as typed.
- A query gets at most four times `max_terms` alternatives, so a large group cannot make a
  statement huge; a word whose alternatives do not fit stays as typed, with a warning.
- `$result->interpretedAs` shows the expansion.
- Only the PostgreSQL engine applies synonyms: a custom `Engine` gets no expansion.

Synonyms are developer input, like the rest of the index definition: never build them from user input.

## Did you mean

A search that finds few hits and has a whole word the index does not know suggests the spelling it
probably meant, next to the hits (`SearchResult::$didYouMean`):

```php
$result = $fuzzphony->in('products')->query('hedphones -cable')->get();
$result->didYouMean;   // "headphones -cable": the query with the word replaced, the rest as typed
```

- It is a suggestion: Fuzzphony never searches it by itself. Show it as a link that searches
  `$result->didYouMean`. It is plain text made of the user's own words: escape it when you render it.
- When: fewer hits than `fallback_below` (5), or typo tolerance had to run (so it also appears next to the
  results typo tolerance found: `hedphones` finds headphones and still suggests the word). It is not computed for a browse, a search with enough hits, or when `did_you_mean` is `false`.
- Which words: whole words of at least `fuzzy_min_length` letters (the index's own minimum) that the
  index does not know: not in the vocabulary, not a stop word, and no document of the index matches
  them (so a word of a field that is not typo-tolerant, an inflection such as `headphone`, `wi-fi`, or a
  word added since the last full reindex is never "corrected"). Not corrected either: prefixes
  (`keyb*`), excluded words (`-cabel`), words a synonym expands (the index knows them by definition)
  and words with a digit (`rtx4090`: the nearest code is not what the user meant).
- Which suggestion: the vocabulary word nearest by edit distance (the trigram index only picks ten
  candidates; a trigram ranking alone suggests `most` for `mose`), then the one in more documents.
  Nothing farther than a third of the word's length away is suggested, and a candidate has to share
  enough trigrams with the word (pg_trgm's similarity of 0.3), so a word that is two edits away at the
  start and the end gets none. The suggested word is the
  vocabulary's, so lowercase and without accents.
- Tenants and filters: the vocabulary is the words of the whole index, whatever a search's filters
  say, so a suggestion could reveal that a word exists in documents the search may not see.
  Therefore a tenant-scoped index never suggests anything. On any other index with visibility filters
  (unpublished or private rows whose words must stay secret), switch it off: `did_you_mean: false`.
- The role that searches needs `SELECT` on the vocabulary table; without it, or before the table exists,
  there is simply no suggestion (never an error).
- The vocabulary is the words of the index's typo-tolerant fields and in how many documents each
  occurs. A full `fuzzphony:reindex` rebuilds it (`fuzzphony:reindex --vocabulary` rebuilds only it);
  changes to single documents do not touch it, so it ages as the data changes, and the doctor does not
  say how old it is (only that it is missing or empty). The effect is mild: a word that is new since the
  last rebuild is not "corrected" (the suggestion also asks the index itself), it only cannot be
  suggested for a neighbouring misspelling. **Rebuild it on a schedule**, for example nightly, or right
  after a big import: it is one pass over the index (about 10 s for 500 000 documents) and readers are
  never blocked.

  ```
  # crontab: every night at 03:15
  15 3 * * * cd /var/www/app && bin/console fuzzphony:reindex --vocabulary --no-interaction
  ```

  An index without the table (it is created by `fuzzphony:schema --apply`) or with an empty one gives no
  suggestion, and the doctor says so.
- `explain()` (and `fuzzphony:search --explain`, and the demo's SQL views) list the lookup as the
  statement labelled `did you mean`, with the words bound as parameters; the plan shown is still the
  search statement's.

## Search-as-you-type

Three things make a search box feel instant, and each is its own call. The library gives you the data and
the matching; the dropdown (its look, groups, pictures, prices) is **your template**, because the pictures and
prices are your data, not the index's:

| What the visitor sees | The call (backend) | What you build (frontend) |
|---|---|---|
| the word being completed ("hea" -> "headphones") | `$fuzzphony->suggest($index, $text, $limit)` | a list under the input |
| results while typing ("cr" already finds "Crème") | `->asYouType()` on the search builder | the result list |
| groups: categories, products with price and picture | `facets()` and the hits of the same search | the grouped dropdown, see [A rich dropdown](#a-rich-dropdown) |

There is **no separate "suggest list" to configure**: the completions come from the index's vocabulary, which is
made of the words of the **typo-tolerant fields** (`fuzzy: true`) with at least `fuzzy_min_length` letters. To
suggest a field's words, make the field fuzzy (see [Configuration](configuration.md)); which words come first is
their frequency. Nothing else: how many (`$limit`), the grouping and the looks are per call and per page.

### Matching the word being typed

By default a word is a whole word: `cr` finds nothing where `creme` is, and a word of fewer than
`fuzzy_min_length` letters is not typo-tolerant either. `asYouType()` is for a box that searches while the
visitor types:

```php
$fuzzphony->in('products')->query('wireless hea')->asYouType()->get();
// understood as: (wireless AND (hea OR hea*))
```

- The **last word** matches as the word itself (typo tolerance, synonyms and "did you mean" as before) **or** as
  the beginning of a longer word (`hea` finds `headphones`). The words before it stay whole words.
- A word scoped to a field (`brand:son`) or excluded (`-ca`) is only a prefix. The text is left alone once it
  ends with a space or a symbol (the word is finished), inside a quoted phrase, after an operator, and for a
  hyphenated word (`wi-fi`).
- `didYouMean` stays a plain text (`wireless mouse`, without the prefix alternative); `interpretedAs` shows
  `(hea OR hea*)`.
- It matches the beginning of a *word*, not any letters inside it (that is what `ILIKE '%cr%'` does).
  A one-letter prefix matches a lot (the search is still capped by the candidate limit).
- The Live Component does it by default (`asYouType="false"` turns it off), `fuzzphony:search ... --as-you-type`
  tries it, and with a [federated search](#federated-search) set it in each index's `configure` closure.

### Completing the word

`suggest()` completes the word being typed from the index's vocabulary (the one "did you mean" reads): from the index's vocabulary (the one "did you mean" reads):

```php
$fuzzphony->suggest('products', 'wireless hea');   // ["wireless headphones", "wireless headset"]
```

- Only the **last word** is completed, and only when the text ends with it (not with a space or a
  symbol); the words before it, and a leading `-`, stay as typed. The most frequent word comes first
  (`$limit`, 1 to 20, default 8).
- The completions are in the index's normalised form (lowercase, accents folded: `Crè` gives `creme`)
  and are **plain text, not HTML**: escape them when you render them.
- Only words of the typo-tolerant fields are in the vocabulary, with at least `fuzzy_min_length`
  letters, and it is filled by a full reindex (`fuzzphony:reindex --vocabulary` rebuilds just it): a word
  that is new since the last rebuild is not suggested yet. Schedule that rebuild, see [Did you
  mean](#did-you-mean). A **tenant-scoped index** gives no completions (the vocabulary has no tenant
  column, so it would list other tenants' words), and so does an index without a typo-tolerant field or
  an engine without a vocabulary: the list is empty.
- `fuzzphony:schema --apply` adds a prefix index on the vocabulary (`text_pattern_ops`), and the
  trigram index of 0.7 serves prefixes of three letters or more; without them it still works, with a
  scan of the (small) vocabulary table.
- `fuzzphony:search products 'wireless hea' --suggest` tries it from the terminal.

The [Live Component](integrations.md#live-component) offers the completions as you type, and the
demo's search boxes do too (a `<datalist>` filled from a small endpoint).

### A rich dropdown

The grouped dropdown of a shop (searches, categories, products with price and picture) is three ordinary
calls, composed by your endpoint, because the picture and the price are your data, not the index's:

```php
$completions = $fuzzphony->suggest('products', $text, 6);                              // "Searches"
$found = $fuzzphony->in('products')->query($text . '*')->limit(5)->facets('category_id')->get();
$categories = $found->facets['category_id'];                                           // "Categories" (label the ids yourself)
$products = $found->hits;                                                              // "Products": load their rows by $hit->id
```

The `*` makes the word being typed a prefix. Which of the three to show, and how, is the page's setting: the
demo's Compare page shows all three (`data-suggest-rich-value="true"` on its search form), every other search box
only the completions (the default, a `<datalist>`). The library has no global "rich" switch on purpose: the Live
Component's `suggestions`, `facets` and its `hit` block cover the same ground inside it, and the rest is your template.

## Facets

`facets()` counts the values of filters among the matches ("Kitchen (120) · Office (45)"):

```php
$result = $fuzzphony->in('products')->query('mouse')->where('in_stock', true)
    ->facets('category_id', 'in_stock')->get();

foreach ($result->facets['category_id'] as $facet) {   // FacetValue: ->value, ->count
    echo $facet->value, ' (', $facet->count, ')';
}
```

- Facets can be string, int, bool and date filters (not float or datetime: every value would be its own
  group), and not the tenant filter. `facetValues($n)` keeps the `$n` most frequent values (1 to 100,
  default 20); values come as the filter's type (`int`, `bool`, ...), `null` for the documents without one.
- **A facet does not count the conditions on its own filter**, so it shows what choosing another value
  would find (the usual shop behaviour): after `where('category_id', 3)` the category facet still lists
  the other categories, with the counts they would have; the other conditions, and the tenant, count.
- **Counts are over the candidates**, like `total`: when the search hit its candidate limit
  (`$result->totalIsLowerBound`), the counts are lower bounds ("120+"). `exactCounts()` counts every
  match instead, for the total and for the facets:

  ```php
  ->query('mouse')->facets('category_id')->exactCounts()->get();
  ```

  It reads everything the query matches, so it can take seconds on a large index (the page of hits is
  still the ranked candidates). It is deliberately a method of its own, not a threshold: **never wire it to
  request input**, as an override of `candidate_limit` can only tighten the limit. It costs nothing when
  the candidates were not cut off (the total is exact already).
- Every facet is one more statement (listed by `explain()`, labelled `facet: category_id`), with its own
  candidate stage: three facets cost roughly four searches. A search without text (filter browsing) is
  counted too. With empty-result relaxation the facets follow the relaxed search.
- `fuzzphony:search products mouse -f category_id -f in_stock [--exact]` prints them.

## Federated search

One text over several indexes, with one merged list:

```php
$result = $fuzzphony->federated()
    ->index('products', weight: 2.0, configure: fn(SearchBuilder $b) => $b->where('in_stock', true)->highlight('name'))
    ->index('articles')
    ->query('wireless mouse')
    ->limit(20)          // or page(2, 20)
    ->get();

foreach ($result as $hit) {
    echo $hit->index, ' ', $hit->hit->id, ' ', $hit->score;   // FederatedHit
}
$result->results['products']->total;   // each index's own SearchResult (facets, didYouMean, ...)
```

- The scores of different indexes cannot be compared (each index ranks with its own statistics), so the
  lists are merged by **reciprocal rank fusion**: a hit's merged score is `weight / (60 + its rank in its
  own index)`. The best hit of every index comes first, then the second best, and a `weight` above 1.0
  moves an index up (below 1.0 down). Ties go to the index added first. `$hit->hit->score` is still the
  index's own score.
- Each index is searched on its own, so its filters, tenant, thresholds, ranking profile and highlights
  are what the `configure` closure says (a tenant-scoped index needs `forTenant()` there, as always).
- **Deep pages cost more:** page N reads the first `offset + limit` hits of every index and merges them,
  so `offset + limit` is at most 1000. For a "load more" list prefer a larger `limit` to deep offsets.
- `total` is the sum of the indexes' totals (`totalIsLowerBound` when any was cut off), warnings are
  prefixed with the index name (`[products] ...`), and the ids are those of each index (an `articles` id
  can equal a `products` id): use `$hit->index` to tell them apart. It works with every engine, as it only
  uses `search()`.

## Empty-result relaxation

When a query of two or more words returns no hit, Fuzzphony checks each word on its own against
everything the search may see (your filters and the tenant included), exactly or by similarity,
as the search does. Words that match nothing are dropped, the search runs once more, and the
result says so:

```php
$result = $fuzzphony->in('products')->query('wireless mouse aluminum')->get(); // "aluminium" is only in descriptions
$result->interpretedAs; // "(wireless AND mouse)"
$result->warnings;      // ['No results for all words; ignored words that match nothing: "aluminum".']
```

A query is not relaxed when:

- all its words match something, just never in the same document (`mouse kettle`): the empty
  result is the correct answer;
- it already has hits, or is a single word;
- none of its words match anything;
- dropping words would leave a group of only exclusions (`zzqq -mouse | wireless yyqq`).

If the relaxed search finds nothing too (`wireless mouse aluminum kettle`), you get the original
empty result without a warning.

Cost: one probe statement, a possible stop-word lookup, and one or two relaxed search statements
(strict, then fuzzy), only for empty multi-word results. Turn it off with
`relax_when_empty: false`. The probe checks whether a word matches any document at all, so it does
not apply `min_score` or the candidate limit. A word can count as matching through documents the
search would reject; it is then kept, not dropped. The relaxation errs on the side of doing less.

## Limits and errors

`max_query_length`, `max_terms` and a nesting depth of 8 keep hostile input cheap (see
[thresholds](ranking.md#thresholds) for defaults and hard caps).

Developer mistakes, such as an unknown filter or a wrong value type, do throw, with a suggestion:

```
Index "products" has no filter "prise". Did you mean "price"?
```

## Rendering results safely

- Search text is parsed by Fuzzphony, reduced to letters and digits per lexeme, and bound as a
  parameter.
- Highlight snippets are HTML-escaped. Only Fuzzphony's own `<mark>` tags remain.
- `$result->warnings` is plain text, not HTML. A warning may quote the user's own words: the
  relaxation warning quotes them as typed, minus invisible format characters, cut at 40
  characters. Escape it when you render it as HTML (Twig's `{{ warning }}` does).
- `$result->interpretedAs` is plain text too, built from the user's words.

See [SECURITY.md](../SECURITY.md) for the trust model.

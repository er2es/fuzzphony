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
reindex. Two forms, in the index definition ([how](configuration.md#synonyms)):

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
- When: fewer hits than `fallback_below` (5), so it also appears next to the results typo tolerance
  found. It is not computed for a browse, a search with enough hits, or when `did_you_mean` is `false`.
- Which words: whole words of at least `fuzzy_min_length` letters that are not in the index's
  vocabulary. Not corrected: prefixes (`keyb*`), excluded words (`-cabel`), stop words, words a
  synonym expands (the index knows them by definition) and the words the vocabulary has.
- Which suggestion: the vocabulary word nearest by edit distance (the trigram index only picks ten
  candidates; a trigram ranking alone suggests `most` for `mose`), then the one in more documents.
  Nothing farther than a third of the word's length away is suggested, and a candidate has to share
  enough trigrams with the word (pg_trgm's similarity of 0.3), so a word that is two edits away at the
  start and the end gets none. The suggested word is the
  vocabulary's, so lowercase and without accents.
- The vocabulary is the words of the index's typo-tolerant fields and in how many documents each
  occurs. A full `fuzzphony:reindex` rebuilds it (`fuzzphony:reindex --vocabulary` rebuilds only
  it); changes to single documents do not touch it, so a word that is new since the last full reindex
  is not known yet. An index without it (the table is created by `fuzzphony:schema --apply`) or
  with an empty one gives no suggestion, and the doctor says so.

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

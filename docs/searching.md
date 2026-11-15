# Searching

The search builder, the query syntax, typo tolerance and empty-result relaxation. Back to the [README](../README.md).

Coming from `LIKE` or `ILIKE`? [Migrating from `LIKE`](migrating-from-like.md) maps your patterns to this syntax, step by step.

## The search builder

```php
$result = $fuzzphony->in(Product::class)       // or the index name: ->in('products')
    ->query('wireles mouse -cable')
    ->where('price', '<=', 20_000)
    ->where('in_stock', true)
    ->highlight('name')
    ->get();
```

- Every `where*` / `forTenant` / `profile` / `ranking` / `thresholds` call is immutable and returns a new builder. Nothing runs until `->get()`.
- An empty query (a search page's first load) is a normal call: it returns a plain, filtered browse, so one endpoint serves "search" and "browse".
- Filter names are snake_case (`in_stock`, not `inStock`).
- `Fuzzphony\Bridge\Doctrine\EntityLoader` turns results into entities with one query, keeping the ranking order.

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

Pagination, several filters from query parameters, a tenant scope and a client-chosen ranking profile, on an explicitly tenant-scoped index (see
[Multi-tenancy](multi-tenancy.md)):

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

<!-- fragment -->
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

## Highlighting

`highlight('name', 'description')` puts an HTML snippet in `$hit->highlights['name']`: matching words wrapped in `<mark>`, the rest escaped (nothing
else is ever a tag), a text of up to about 200 characters whole, a longer one as up to two fragments joined by ` … `.

A hit found only through typo tolerance has no word that matches exactly, so Fuzzphony marks the words of the text that are the typo-tolerant match
(`hedphones` shows `Wireless <mark>Headphones</mark>`), with the same normalisation and similarity rule the search used, per typo-tolerant word, in the
`fuzzy` fields (a word scoped to a field is marked in that field only; a field the viewer may not see is not highlighted). In a hit that matched some
words exactly and others by typo, the typo matches are added to the snippet. This costs one more statement only when the page has hits found by typo
tolerance and a `fuzzy` field is highlighted. What it does not mark: [Limitations](limitations.md#highlights-run-the-source-query-and-mark-typo-matches-by-word).

## What matched

`explainMatches()` says, for each hit of the page you get back, which word of which field matched and how:

```php
$result = $fuzzphony->in('products')->query('mouse')->limit(10)->explainMatches()->get();

foreach ($result->hits as $hit) {
    foreach ($hit->matches as $match) {      // a list of MatchedWord
        echo $match->field, ' ', $match->word, ' ', $match->via;   // name mouses stem
    }
}
```

`via` is `exact` (the typed word), `stem` (another form of it), `prefix` (a `mou*` term or the word being typed with `asYouType()`), `synonym` or
`typo`, with the `similarity` that made it match. The words are the stored text in its normalised form (lower case, no accents). It is **opt-in**:
without the call `Hit::$matches` is null and the statements are those of a search without it; with it the engine runs one more statement over the ids of
the page (at most 50 words per hit). A viewer who may not see a field gets no word and no name of it ([Security](security.md)).

For a field with [several values per document](configuration.md#several-values-per-document-many) each `MatchedWord` also carries `valueOrd` (from 0)
and `value` (the stored text), so you can show "found in the Vienna address"; a word that matches several values is listed once, with the first.
`toArray()` and the JSON form add `value_ord` and `value` only for such a word. The highlight of a many field shows the value that matches best.

A field declared [`rank: best`](configuration.md#several-values-per-document-many) ranks a document by its best single value: the text rank is computed
as for the whole document with that field holding only one of its values, for each value that holds a word of the query, and the highest counts (with
several `rank: best` fields the best of them counts). Words of other fields still count with it (`kiss szeged`: the name and one address). A document
none of whose values holds a query word, or whose words are spread over several values, keeps the whole-field rank; it is still found. The score scale,
profiles and `min_score` are unchanged. With `explainMatches()`, `ScoreBreakdown::$bestValues` maps each such field the viewer may see to the position
(`valueOrd`) of the value that gave the rank (named only when its best-value rank produced the document's text rank; none when the whole-field rank was
used), and the highlight shows that value. A typo-tolerant match is scored on the whole field as before.

### Several values, one address: `manyMatch()`

For a many field declared with [`rank: best`](configuration.md#several-values-per-document-many), `->manyMatch('single')` keeps only the documents where
ONE value holds every word the document needs from that field (`fo utca szeged` finds the customer whose single address has all three, not the one with
`Fo utca` in one address and `Szeged` in another). `->manyMatch('single', 'addresses')` limits the mode to the named fields; none means every such field
the viewer may search; `manyMatch('any')` is the default. A word you exclude (`-kossuth`) excludes the document when ANY of its values holds it. Refused
with `InvalidQuery` when the index has no `rank: best` field (decided from the definition, never from what the viewer may see), for a mode other than
`any` / `single`, for fields given to `any`, and for a name that is no such field or that the viewer may not see; a viewer who sees none of them gets
the plain search.

How a word counts in the single-value mode: every positive word must be in one of the document's other visible fields or in the SAME value of each named
field (`kiss szeged` finds Kiss Anna with a Szeged address). A phrase must be inside one field or one value; a word scoped to the field
(`address:szeged address:fo`) must be in that value; a typo-tolerant word (`fo utca szgeed`) is judged by its similarity to that value or to one other
visible field, never to several fields joined (`kisanna` over the name "Kiss" and the address "Anna utca 1" matches in the default mode but not in the
single-value mode). The last word of a search-as-you-type is a prefix inside the value. With two named fields, one value of each is chosen together; at
most 3 fields are checked together: with more (named, or every `rank: best` field the viewer sees when none is named) the search is refused with
`InvalidQuery`; name the ones that must hold the words in one value. Totals, facets and candidates follow the mode; the rank is the best-value rank.
Only the first `limit` values of a document are stored, so a value past the limit never satisfies the mode. The empty-result relaxation and "did you
mean" judge each word on its own. Cost: [benchmarks](benchmarks.md#ranking-by-the-best-value-rank-best).

**A filter on the same address.** When a many filter declares [`same_row_as`](configuration.md#several-values-per-document-many) a named field (`city`
with `same_row_as: address`), the single-value mode also holds the filter to that value's own child row:

```php
$people->query('fő utca')->manyMatch('single')->where('city', 'Eger')->get();
```

finds the customer whose address in Eger holds "fő utca", not the one with "Fő utca 1" in Budapest and another address in Eger (the default mode finds
both). `whereIn('city', [...])` holds when that row's city is one of them; several conditions on the filter must all hold for the same row; the null
checks stay per customer. A customer whose words are all in other fields needs no address, so the city is then any address of the customer. The coupling
follows from the definition and the mode (`explainMatches()` and the highlight show the coupled value). The facet of the filter counts the same way
(`Eger (1)` = customers whose address holding the words is in Eger); the other facets count the coupled result. A viewer who may not see the field, a
field not named in `manyMatch()`, and the default mode get the document-level filter. Rows indexed before the option behave as before until
`fuzzphony:reindex` writes their city (the doctor counts them).

## Returning source fields: `with()`

A result row usually needs a title, a slug, a price. `with()` returns those columns of the hit's **source row** in `Hit::$data`, so a page can be
rendered and its links built without loading entities:

```php
// the index declares what may be returned: returnable: [title, slug, price]  (see Configuration)
$result = $fuzzphony->in('products')->query('mouse')->limit(10)->with('title', 'slug', 'price')->get();

foreach ($result->hits as $hit) {
    echo $hit->data['title'], ' ', $hit->data['price'];   // 'Wireless mouse' 19.9
}
```

- `with('title', 'slug')` takes a subset of the index's `returnable` columns; `with()` with no argument means all of them; calling it again adds. A name
  that is not returnable is an `InvalidQuery` that lists the names you may ask for.
- **Opt-in and fresh.** Without `with()` nothing changes and `Hit::$data` is `[]`. With it the engine runs **one more statement per page** (a `UNION ALL`
  of one SELECT per source) over the ids of the page, reading the source query itself: always the current value, nothing is copied into the index.
  `explain()` lists it once, as `returned fields: <index>`; every value is bound and the column names come from the validated list.
- **Aliases.** `returnable: { url: slug, title: title }` (YAML; a list entry is its own alias), builder `->returnable(['url' => 'slug', 'title'])`. You ask
  `with('url')` and `Hit::$data` is keyed `url`; the real name `slug` is not returnable unless listed too. A multi-source index uses one alias set, each
  source mapping it to its own column. Grants follow the column (see [Security](security.md#returned-fields)).
- **Several values.** Name a `many` field in `returnable` and `with('city')` returns its values as a `list<string>` (the first `limit`, in the index's
  order); no child row gives `[]`.
- **Types.** Integers, floats (a `numeric` is a float) and booleans come back as PHP ones, text as strings, dates and timestamps as ISO 8601 strings,
  `json`/`jsonb` and arrays as compact JSON strings, `null` as `null`. Keep to scalar columns for what you render.
- **Several sources.** Each source declares its own `returnable` columns; a hit carries every name you asked for, `null` for one its source does not
  return. A hit whose source row was deleted since the last sync has `data = []`.
- **Viewers.** A returnable column that is also a `granted` field is returned only to an actor who holds it; for anyone else it is an unknown name
  (`with()` with no argument leaves it out). `restrictedToFields()` narrows it like the search. A tenant-scoped index repeats its tenant condition on the
  source query ([Security](security.md#returned-fields)).
- **Federated search.** Each index's `configure` closure may call `with()` (`->index('products', configure: fn($b) => $b->with('title'))`); merged hits
  keep their `data`. A federated search reads `offset + limit` hits per index: keep pages small.
- **Roles.** The search role needs `SELECT` on the source tables of an index that declares `returnable` columns, and only of those ([Database roles](roles.md)).

## Query syntax

End-user search text never throws. Malformed input is repaired and reported in `$result->warnings`, and `$result->interpretedAs` shows how it was understood.

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

When the typo-tolerant branch runs (see [thresholds](ranking.md#thresholds)), every word must still be satisfied on its own, either exactly (full text)
or by trigram similarity, combined through the query's real AND / OR / NOT:

| Query | Typo-tolerant meaning |
|---|---|
| `wireles mice` | (`wireles` exactly or a word similar to it) and (`mice` exactly or similar) |
| `headphnoes \| torhc` | either word, each exactly or similar |
| `wireles (headphones \| mouse -silent)` | the negation applies inside the group; negated words are always exact |
| `"wireles headphones"` | the phrase exactly, or the whole phrase as one similar text |
| `ergnoo*` | the prefix exactly, or the prefix text as a similar word |

So a typo in one word never lets through documents that lack the other words.

- Typo tolerance only reaches words stored in fuzzy fields (`fuzzy: true`).
- **The similarity a word needs is proportional to its length:** one typo is tolerated from 4 letters, two from 8. A missing, extra or replaced letter
  changes at most three of a word's `n + 1` trigrams, so `t` typos leave a similarity of at least `(n + 1 − 3t) / (n + 1 + 3t)`; a word needs that plus a
  slack of 0.03 (0.28 for 4 letters, 0.36 for 5, 0.43 for 6, 0.48 for 7, 0.23 for 8, 0.40 for 12, 0.59 for 20), and words below 4 letters need 0.6. `n` is
  the length after normalisation (accents folded, `ß` → `ss`; a phrase counts its letters without spaces; a prefix such as `ergnoo*` gets one letter of
  allowance). `mouse` therefore does not match `monitor` or `mower` (0.33 against the 0.36 five letters need), while `mose`, `mouze`, `wireles`,
  `hedphones` and `moitor` find what they mean. pg_trgm has one threshold per session, so each word is first asked for its matches alone at its own
  similarity and the search uses those ids (the same documents, much cheaper); a word with more matches than the candidate limit keeps the trigram
  condition. The slack is also the price: a letter replaced in the middle of a 5 to 7 letter word leaves exactly the similarity of a different word, so
  `mpuse` is not tolerated in the results ("did you mean" catches it). On generated typos of 24 common words a 5 to 7 letter word finds about 70 to 80%
  of its single-letter typos (a flat 0.3: 92 to 100%) with about five times fewer look-alikes. Set `typo.similarity` to a number (per index or per
  query) to use it for every word, or to `null` (`--threshold typo.similarity=null`) to go back to by length.
- **Two neighbouring letters swapped** (`mosue` for `mouse`): on the typo-tolerant branch a typed word of 4 to 15 letters also matches each of its
  neighbour-swapped variants as a whole word (`mosue` looks for `omsue`, `msoue`, `mouse`, `moseu`), made from the normalised word and looked up in the
  typo-tolerant fields only. No vocabulary is involved, so it works on tenant-scoped indexes. A hit found only through a swap scores the lowest similarity
  an ordinary typo of that word may score ([Ranking](ranking.md#swapped-letters)), so an exact hit ranks above it. It applies only where typo tolerance
  already runs (fewer than `typo.fallback_below` exact hits, or `typo.mode: always`). Not swapped: words with a digit, prefixes, phrases, the alternatives
  a synonym adds, and words shorter than 4 or longer than 15 letters. Bounded: at most 14 variants per word and 48 per query (a word whose variants do
  not fit gets none), one more index condition per word, not a statement.
- Words shorter than `typo.min_length` must match exactly.
- Stop words of the index language ("for", "the") are ignored, except in a query of nothing else: `the who` is searched as it is, on the typo-tolerant
  fields ([Known limitations](limitations.md#stop-words-are-ignored-and-only-a-query-of-nothing-else-finds-them)).

## Synonyms

An index can know that words mean the same thing (`tv` and `television`). Synonyms are expanded on the query, so changing them needs no schema change and
no reindex. Two forms, in the index definition ([how](configuration.md#synonyms), also from a file or your own table):

| Entry | Meaning |
|---|---|
| `[tv, television]` | a group: every member finds every other member |
| `laptop => notebook \| portable` | a one-way rule: `laptop` also finds `notebook` and `portable`, but not the reverse |

```php
$result = $fuzzphony->in('products')->query('tv -bracket')->get();
$result->interpretedAs;   // "((tv OR television) AND NOT bracket)"
```

- A synonym finds documents like any word of the query: stemmed with the index's language, accents folded, highlighted, typo tolerance applies. PostgreSQL
  stems: the stems of the synonyms are fetched once (with the first search that has a word to expand) and kept; each new query word costs one small
  statement. So `Televisions` finds the group of `television`.
- Stemming is the language's: English leaves `tvs` or `ssds` as is, so list it as a member (`[tv, tvs, television]`). A word with a symbol or hyphen
  (`wi-fi`, `c++`) is compared as it is.
- `-tv` excludes the documents that match `television` too. A word scoped to a field (`name:tv`) expands inside the same field. A prefix (`tv*`) is not expanded.
- A member may be a phrase (`ssd` ⇄ `solid state drive`); it matches a quoted phrase in the query, not the same words typed without quotes.
- A synonym of a synonym is not followed (`a => b` and `b => c`: `a` finds `b`, not `c`).
- The exact-title and prefix bonuses compare with the words the user typed. The empty-result relaxation treats a word and its alternatives as one word.
- A query gets at most four times `limits.max_terms` alternatives; a word whose alternatives do not fit stays as typed, with a warning.
- Only the PostgreSQL engine applies synonyms. Synonyms are developer input: never build them from user input.

## Did you mean

A search that finds few hits and has a whole word the index does not know suggests the spelling it probably meant (`SearchResult::$didYouMean`):

```php
$result = $fuzzphony->in('products')->query('hedphones -cable')->get();
$result->didYouMean;   // "headphones -cable": the query with the word replaced, the rest as typed
```

- It is a suggestion: Fuzzphony does not search it for the visitor, except for a search that found nothing and has a unique one-edit correction (the
  [zero-result rescue](#zero-result-rescue)). Show it as a link that searches `$result->didYouMean`. It is plain text made of the user's words: escape it.
- When: fewer hits than `typo.fallback_below` (5), or typo tolerance had to run. Not computed for a browse, a search with enough hits, or when
  `rescue.did_you_mean` is `false`.
- Which words: whole words of at least `typo.min_length` letters that the index does not know (not in the vocabulary, not a stop word, no document
  matches them: so a word of a non-fuzzy field, an inflection such as `headphone`, `wi-fi`, or a word added since the last full reindex is never
  "corrected"). Not corrected either: prefixes, excluded words, words a synonym expands, and words with a digit.
- Which suggestion: the vocabulary word nearest by edit distance (the trigram index picks ten candidates, plus the neighbour-swapped variants), then the
  one in more documents. A swap of two neighbouring letters is one edit. Nothing farther than a third of the word's length away is suggested (at least
  one edit), and a candidate has to share enough trigrams (similarity 0.25) unless it is a swapped variant. The suggested word is the vocabulary's:
  lowercase, no accents.
- **Ranking:** a search whose typo tolerance runs looks the suggestion up first, and the word a typo most likely meant (fewest edits, then at
  least twice the documents of the next candidate at that distance; a closer tie corrects nothing) ranks its hits above the look-alikes: such a
  hit's word scores 0.95 (one edit, 0.05 less per further edit) instead of its trigram similarity, under an exact hit's 1.0 (`szaob` lists `Szabó`
  before `Szalai`, `peetr` `Péter` before `Petra`). Only the order changes: the hits, the total and the facets are those of the search without it.
  Not for a restricted viewer, a tenant-scoped index, `rescue.did_you_mean: false` or a `relevance.min_score` above 0.
- **Tenants and filters:** the vocabulary is the words of the whole index, whatever a search's filters say, so a suggestion could reveal that a word
  exists in documents the search may not see. Therefore a tenant-scoped index never suggests anything. On any other index with visibility filters
  (unpublished or private rows whose words must stay secret), switch it off: `rescue.did_you_mean: false` (with `rescue.correct_when_empty: false`).
- The role that searches needs `SELECT` on the vocabulary table; without it, or before the table exists, there is simply no suggestion (never an error).
- The vocabulary is the words of the index's typo-tolerant fields and in how many documents each occurs. A full `fuzzphony:reindex` rebuilds it
  (`--vocabulary` rebuilds only it); single-document changes do not touch it, so it ages (the doctor reports only that it is missing or empty). The
  effect is mild: a new word is not "corrected" and cannot be suggested for a neighbouring misspelling. **Rebuild it on a schedule** (one pass over the
  index, about 10 s for 500 000 documents, readers never blocked):

  ```
  # crontab: every night at 03:15
  15 3 * * * cd /var/www/app && bin/console fuzzphony:reindex --vocabulary --no-interaction
  ```
- `explain()` lists the lookup as the statement labelled `did you mean`.

### Zero-result rescue

A search that finds **nothing** and has a word the vocabulary can correct with certainty is run once more with the corrected words, and the result is
the one of the corrected search, flagged:

```php
$result = $fuzzphony->in('products')->query('mpuse')->get();   // nothing contains "mpuse", one word of the index is one edit away
$result->correctedFrom;   // "mpuse": the text as the visitor wrote it (null when nothing was corrected)
$result->interpretedAs;   // "mouse": the query that really ran
$result->warnings;        // ["No results for 'mpuse'; showing results for 'mouse'."]
$result->didYouMean;      // null: the suggestion was taken
```

Show it as a bar ("Showing results for mouse", with a link that searches the typed text as it is, for example with
`thresholds(['rescue' => ['correct_when_empty' => false]])`). `correctedFrom` is the visitor's own text: escape it.

- **When**: the search found nothing after everything else (a search that found something, however few hits, is never rescued), `rescue.correct_when_empty`
  and `rescue.did_you_mean` are on (the defaults), typo tolerance is not switched off (`typo.mode: never` or a profile with `fuzzy: 0`), and the text is
  not a search-as-you-type one.
- **Which words**: those did-you-mean may correct. A word is corrected only when the vocabulary has **exactly one word one edit away** (replaced, missing,
  extra or swapped letter) among its ten closest candidates: `mpuse` becomes `mouse` when nothing else is one edit from `mpuse`. A tie means no rescue at
  all, and so does a tie in any one word of a longer query. Two edits are for the plain suggestion, never the rescue.
- **One attempt, and the whole corrected query must match**: it is searched once, with the rescue off. When it finds nothing, or finds something only
  after the [empty-result relaxation](#empty-result-relaxation) dropped a word, the answer is the original, empty one (with its did-you-mean); the rescue
  never loops and never corrects the correction.
- **Everything else is the original search's**: filters, facets (counted over the corrected search), paging, highlights (of the corrected words), the
  tenant, the actor, the guards and a restricted viewer's fields.
- **Never**: on a tenant-scoped index or for a restricted viewer (the vocabulary holds the words of every tenant's and every field's documents, so what a
  viewer finds never depends on a hidden field or another tenant), with `rescue.did_you_mean: false`, or with `rescue.correct_when_empty: false`. On an
  index whose filters hide rows whose words must stay secret, switch both off, as for did-you-mean.
- **Observability**: `explain()` lists the rescue's statements with the label prefix `rescue: `; the counter `fuzzphony.search.rescued` counts rescued
  searches ([Observability](observability.md#instrumentation-points)). The search log records what the visitor **typed** with the hits of the rescued
  search, and `FederatedSearch` rescues each index on its own (the warning is prefixed `[products] ...`, and
  `$result->results['products']->correctedFrom` says which).
- **Cost**: nothing for a search that has results; for one with none, one more search and no extra vocabulary lookup.

## Search-as-you-type

Three things make a search box feel instant, and each is its own call. The library gives you the data and the matching; the dropdown is **your template**:

| What the visitor sees | The call (backend) | What you build (frontend) |
|---|---|---|
| the word being completed ("hea" -> "headphones") | `$fuzzphony->complete($index, $text, $limit)` | a list under the input |
| results while typing ("cr" already finds "Crème") | `->asYouType()` on the search builder | the result list |
| groups: categories, products with price and picture | `facets()` and the hits of the same search | the grouped dropdown, see [A rich dropdown](#a-rich-dropdown) |

There is **no separate "suggest list" to configure**: completions come from the index's vocabulary, made of the words of the **typo-tolerant fields**
(`fuzzy: true`) with at least `typo.min_length` letters, the most frequent first. To suggest a field's words, make the field fuzzy.

### Matching the word being typed

By default a word is a whole word: `cr` finds nothing where `creme` is. `asYouType()` is for a box that searches while the visitor types:

```php
$fuzzphony->in('products')->query('wireless hea')->asYouType()->get();
// understood as: (wireless AND (hea OR hea*))
```

- The **last word** matches as the word itself (typo tolerance, synonyms and "did you mean" as before) **or** as the beginning of a longer word. The
  words before it stay whole words.
- A word scoped to a field (`brand:son`) or excluded (`-ca`) is only a prefix. The text is left alone once it ends with a space or a symbol, inside a
  quoted phrase, after an operator, and for a hyphenated word.
- `didYouMean` stays plain text (without the prefix alternative); `interpretedAs` shows `(hea OR hea*)`.
- It matches the beginning of a *word*, not any letters inside it. A one-letter prefix matches a lot (still capped by the candidate limit).
- The Live Component does it by default (`asYouType="false"` turns it off), `fuzzphony:search ... --as-you-type` tries it, and with a
  [federated search](#federated-search) set it in each index's `configure` closure.

### Completing the word

`complete()` completes the word being typed from the index's vocabulary (the one "did you mean" reads):

```php
$fuzzphony->complete('products', 'wireless hea');   // ["wireless headphones", "wireless headset"]
```

- Only the **last word** is completed, and only when the text ends with it; the words before it, and a leading `-`, stay as typed. The most frequent
  word comes first (`$limit`, 1 to 20, default 8).
- Completions are in the index's normalised form (`Crè` gives `creme`) and are **plain text, not HTML**: escape them.
- The vocabulary has only words of the typo-tolerant fields with at least `typo.min_length` letters, filled by a full reindex: a new word is not
  suggested yet (schedule the rebuild, see [Did you mean](#did-you-mean)). A **tenant-scoped index** gives no completions (the vocabulary has no tenant
  column), and so does an index without a typo-tolerant field or an engine without a vocabulary: the list is empty.
- `fuzzphony:schema --apply` adds a prefix index on the vocabulary (`text_pattern_ops`); without it completion still works, by a scan of the (small) table.
- `fuzzphony:search products 'wireless hea' --complete` tries it from the terminal. The [Live Component](integrations.md#live-component) offers
  completions as you type.

### A rich dropdown

The grouped dropdown of a shop (searches, categories, products with price and picture) is three ordinary calls, composed by your endpoint, because the
picture and the price are your data:

```php
$completions = $fuzzphony->complete('products', $text, 6);                              // "Searches"
$found = $fuzzphony->in('products')->query($text . '*')->limit(5)->facets('category_id')->get();
$categories = $found->facets['category_id'];                                           // "Categories" (label the ids yourself)
$products = $found->hits;                                                              // "Products": load their rows by $hit->id
```

The `*` makes the word being typed a prefix. The library has no global "rich" switch on purpose.

## Facets

`facets()` counts the values of filters among the matches ("Kitchen (120) · Office (45)"):

```php
$result = $fuzzphony->in('products')->query('mouse')->where('in_stock', true)
    ->facets('category_id', 'in_stock')->get();

foreach ($result->facets['category_id'] as $facet) {   // FacetValue: ->value, ->count
    echo $facet->value, ' (', $facet->count, ')';
}
```

- A filter declared [`many`](configuration.md#several-values-per-document-many) matches when any of its values does and its facet counts **documents**
  per value. `!=` and `NOT IN` are refused on it (the stored values are truncated at `limit`).
- Facets can be string, int, bool and date filters (not float or datetime), and not the tenant filter. `facetValues($n)` keeps the `$n` most frequent
  values (1 to 100, default 20); values come as the filter's type, `null` for the documents without one.
- **A facet does not count the conditions on its own filter**, so it shows what choosing another value would find: after `where('category_id', 3)` the
  category facet still lists the other categories with the counts they would have; the other conditions, and the tenant, count.
- **Counts are over the candidates**: when the search hit its candidate limit (`$result->totalIsLowerBound`), the counts are lower bounds ("120+"), taken
  over the very candidates the hits come from ([limitation](limitations.md#facet-counts-are-over-the-candidates)). `exactCounts()` counts every match
  instead, for the total and the facets:

  ```php
  ->query('mouse')->facets('category_id')->exactCounts()->get();
  ```

  It reads everything the query matches, so it can take seconds on a large index (the page of hits is still the ranked candidates). **Never wire it to
  request input.** It costs nothing when the candidates were not cut off.
- The facets without a condition on their own filter share one statement (`facet: category_id, in_stock`) that evaluates the candidates once; a facet
  that leaves a condition out is one more statement. A search without text (filter browsing) is counted too. With empty-result relaxation the facets
  follow the relaxed search.
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
    echo $hit->kind, ' ', $hit->id, ' ', $hit->mergedScore;   // the index, its own id, the merged score
}
$result->results['products']->total;   // each index's own SearchResult (facets, didYouMean, ...)
```

- Scores of different indexes cannot be compared, so the lists are merged by **reciprocal rank fusion**: a hit's merged score is `weight / (60 + its rank
  in its own index)`. The best hit of every index comes first, then the second best; a `weight` above 1.0 moves an index up. Ties go to the index added
  first. `$hit->mergedScore` is the fusion score (null in a single-index search); `$hit->score` is the index's own. `kind` is the index of a hit.
- **Weights** (finite, above 0, default 1.0): `products` hits `P1 P2 P3`, `articles` hits `A1 A2 A3`. Equal weights give `P1 A1 P2 A2 P3 A3`;
  `->index('products', weight: 2.0)` gives `P1 P2 P3 A1 A2 A3`; `weight: 0.5` gives `A1 A2 A3 P1 P2 P3`.
- **Floors** (`->index('articles', atLeast: 3)`) guarantee that a heavy index cannot crowd out another: if the index has hits, the page holds at least
  `min(atLeast, its hits)` of its best ones. They replace the lowest-scored hits of indexes that stay at or above their own floor; the page keeps the
  merged order. Floors that add up to more than `limit` throw `InvalidQuery`. The hits a floor pushes out follow in merged order on the next pages, so
  with the same `limit` no hit is shown twice or lost: use the same `limit` for every page of one search.
- Each index is searched on its own: its filters, tenant, thresholds, profile and highlights are what the `configure` closure says (a tenant-scoped
  index needs `forTenant()` there).
- **Deep pages cost more:** page N reads the first `offset + limit` hits of every index, so `offset + limit` is at most 1000. For a "load more" list
  prefer a larger `limit`.
- `total` is the sum of the indexes' totals (`totalIsLowerBound` when any was cut off), warnings are prefixed with the index name, and ids belong to each
  index: use `$hit->kind`. It works with every engine.
- Each index is [rescued](#zero-result-rescue) on its own empty result (a typo correction, a cut-off code): its `SearchResult` carries
  `correctedFrom`, the warning is prefixed with its name, and its hits follow every hit of the indexes that found the typed text itself, whatever
  their weight or merged score (a floor still brings them onto the page).
- `unlogged()` keeps every per-index search out of the [search log](analytics.md#keeping-a-search-out-of-the-log) (a refusal is still recorded).
- A [multi-source index](configuration.md#several-sources-in-one-index) can be one of the indexes: its hits carry `index` and `kind`.

| | Federated search | Multi-source index |
|---|---|---|
| Ranking | each index ranks with its own statistics, merged by rank (weights, floors) | one ranking over all sources |
| Per-part setup | filters, tenant, thresholds, profile per index | one configuration |
| Use when | the parts differ (own tenant, fields, viewers) or already exist as indexes | the parts are one kind of thing to rank together |

## Empty-result relaxation

When a query of two or more words returns no hit, Fuzzphony checks each word on its own against everything the search may see (your filters and the
tenant included), exactly or by similarity. Words that match nothing are dropped, the search runs once more, and the result says so:

```php
$result = $fuzzphony->in('products')->query('wireless mouse aluminum')->get(); // "aluminium" is only in descriptions
$result->interpretedAs; // "(wireless AND mouse)"
$result->warnings;      // ['No results for all words; ignored words that match nothing: "aluminum".']
```

A query is not relaxed when all its words match something, just never in the same document (`mouse kettle`: the empty result is the correct answer), when
it already has hits or is a single word, when none of its words match anything, or when dropping words would leave a group of only exclusions. If the
relaxed search finds nothing too, you get the original empty result without a warning.

Cost: one probe statement, a possible stop-word lookup, and one or two relaxed search statements, only for empty multi-word results. Turn it off with
`rescue.drop_unmatched: false`. The probe does not apply `relevance.min_score` or the candidate limit, so a word can count as matching through
documents the search would reject; it is then kept, not dropped (the relaxation errs on the side of doing less).

## Codes and numbers

Invoice numbers, SKUs and part numbers (`INV-001`, `AB-12/3`, `INV.007`) are found by marking the field as a code:

<!-- fragment -->
```php
->field('number', 'A', code: true)
```

The same in YAML (`fields: { number: { weight: A, code: true } }`) and as an attribute (`#[SearchField('A', code: true)]`). The field's text is indexed
with every run of characters that is not a letter or a digit as one space (`INV-001` as `inv`, `001`), and the code without separators is added
(`inv001`), in the index's text configuration. `INV-001`, `inv-001`, `"INV-001"`, `001`, `INV-2024-0042`, `AB-12/3`, `INV.007` and `INV001` then find
their row, in any case; `number:INV-001` works as for any field, and the whole code ranks first. A beginning of a code finds it up to a separator
(`INV-2024` finds `INV-2024-0042`), and `004*` asks for the beginning of a part. A last part cut off inside (`INV-2024-004`, or `INV2024004`
without separators) is no whole word: when the search finds nothing at all, it runs once more with its last word as a prefix on the code fields
the viewer may search (`number:INV-2024-004*`) and answers with that, flagged like the [zero-result rescue](#zero-result-rescue)
(`SearchResult::$correctedFrom`, `$correctedTo`, a warning). A search with results and `rescue.correct_when_empty: false` never do this;
search-as-you-type does it only when it left the last word as typed (after a hyphen: `INV-2024-004`; `INV 2024 004` is a prefix already). Typo tolerance stays the field's own `fuzzy` flag. A
highlight marks the whole code in the original text (a query written without separators, `INV001`, finds the row but marks nothing). A code with
accents inside a word of letters and digits (`Ä12`) is found by the unaccented form only through its separated parts. A code whose parts are stop words
of the language (`IT-5` in English) also finds rows that have the other parts. The flag is part of the definition: run `fuzzphony:schema --apply` and
`fuzzphony:reindex` after adding it.

Without the flag, PostgreSQL's parser indexes a hyphen before digits as a sign (`INV-001` becomes `inv` and `-001`) and a dot or slash between a letter
and a digit as one token, while the query is split at every non-alphanumeric character, so the two never meet. The older workaround
`regexp_replace(number, '[^[:alnum:]]+', ' ', 'g') AS number` in the source query still works, but `code: true` also finds `INV001`.

## Limits and errors

`limits.max_query_length`, `limits.max_terms` and a nesting depth of 8 keep hostile input cheap (see [thresholds](ranking.md#thresholds) for defaults
and hard caps). Developer mistakes, such as an unknown filter or a wrong value type, do throw, with a suggestion:

```
Index "products" has no filter "prise". Did you mean "price"?
```

## Restricted fields

An index can mark a field `granted: ROLE_HR`; a viewer who is not granted it searches the other fields only: a word that exists only in the hidden
field finds nothing, is not ranked or highlighted, and gets no "did you mean" or completion. The same restriction is available to your own code with
`->restrictedToFields('title', 'body')`. Filters and facets are not restricted. Details and limits: [Security](security.md).

## Hits and results as data

A `Hit` has `kind` (the index it comes from; a federated search mixes them), a typed `id` (an `int` for an integer key, a `string` for a uuid or string
key), `score`, `breakdown`, `highlights`, `mergedScore` (federated only), `matches` (`explainMatches()` only) and `data` ([`with()`](#returning-source-fields-with)
only, left out of the JSON when empty). `SearchResult`, `Hit`, `FacetValue` and `MatchedWord` have `toArray()` and are `JsonSerializable`, with the same
stable keys:

```php
echo json_encode($result);
// {"hits":[{"kind":"products","id":7,"score":0.84,"breakdown":{...},"highlights":{"name":"Wireless <mark>mouse</mark>"},"mergedScore":null,"matches":null}],
//  "total":1,"totalIsLowerBound":false,"facets":{"category":[{"value":"mice","count":1}]},
//  "didYouMean":null,"correctedFrom":null,"correctedTo":null,"warnings":[],"tookMs":3.2}
```

The `highlights` are already HTML-escaped (`<mark>` is the only tag): put them into HTML as they are. Every other string (`didYouMean`, `correctedFrom`,
`correctedTo`, `warnings`, the matched words) is plain text: escape it. `correctedTo` is the text the [zero-result rescue](#zero-result-rescue) searched.

## Rendering results safely

- Search text is parsed by Fuzzphony, reduced to letters and digits per lexeme, and bound as a parameter. Highlight snippets are HTML-escaped; only
  Fuzzphony's own `<mark>` tags remain.
- `$result->warnings` and `$result->interpretedAs` are plain text built from the user's words, not HTML: the relaxation warning quotes them as typed,
  minus invisible format characters, cut at 40 characters. Escape them when you render HTML (Twig's `{{ warning }}` does).

See [SECURITY.md](../SECURITY.md) for the trust model.

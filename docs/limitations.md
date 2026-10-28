# Known limitations

What Fuzzphony does not do, or does less well than you might expect, what happens instead, and what to do
about it. Back to the [README](../README.md).

Some of these are limits of the technique (trigrams, PostgreSQL's triggers), some keep the cost of a search
bounded, some protect the privacy of a log, and some are things to do when you deploy. Each entry says what
happens, why, and what to do. The statements here were checked against the code and the tests.

- [Search quality](#search-quality): [typo tolerance](#typo-tolerance-is-trigram-based), [whole words](#a-word-is-matched-as-a-whole-word),
  [stop words](#stop-words-are-ignored-and-only-a-query-of-nothing-else-finds-them), [languages](#stemming-covers-regular-forms-one-language-per-index),
  [very common words](#ranking-of-very-common-words), [facet counts](#facet-counts-are-over-the-candidates),
  [synonyms](#synonyms-are-expanded-on-the-query-and-stop-there), [highlights](#highlights-run-the-source-query-and-mark-typo-matches-by-word),
  [scores](#scores-are-not-stable), [query limits](#long-queries-are-cut), [very long texts](#very-long-texts-limit-where-a-phrase-is-found),
  [did you mean](#did-you-mean-and-completions-come-from-a-vocabulary-that-ages)
- [Data and sync](#data-and-sync): [partitions](#attaching-or-detaching-a-partition-is-not-followed), [sync modes](#what-each-sync-mode-sees),
  [document ids](#one-id-column-per-document), [full reindex](#a-full-reindex-needs-room-and-a-session-connection)
- [Operations](#operations): [registry cache](#the-registry-cache-in-production-needs-cacheclear),
  [process caches](#text-search-configuration-caches-assume-the-configuration-does-not-change-in-a-process),
  [explain()](#explain-and-its-statement-labels-are-not-a-stable-api), [rescued searches in the log](#a-rescued-search-is-not-a-zero-result-search-in-the-log), [search log](#the-search-log-is-best-effort)
- [Security and privacy](#security-and-privacy): [field authorization](#field-authorization-hides-content-not-existence-or-timing),
  [client IP](#the-client-ip-in-the-search-log), [per-person history](#a-persons-search-history-is-personal-data),
  [rate limit](#the-rate-limit-by-ip-is-loose-by-design-and-only-covers-requests), [authorization](#authorization-is-deny-by-default),
  [tenants](#tenant-isolation-is-enforced-by-the-library-not-the-database)

## Search quality

### Typo tolerance is trigram-based

Typo tolerance is per word, and the similarity a word needs is proportional to its length (see
[Typo tolerance](searching.md#typo-tolerance)). Trigrams compare letters, not meaning, so they cannot tell a
typo from a different word that looks alike:

- `cable` also finds `table` (similarity 0.50, one letter replaced), and `mose` is as close to `monitor` and
  `mower` as to `mouse` (0.40 each): a search for `mose` lists all of them with the same score.
- The threshold keeps a different word at the worst case of a typo out (`mouse` / `monitor`, 0.33), and that
  costs some real typos: a letter replaced in the middle of a 5 to 7 letter word is not tolerated in the results
  (`mpuse`). "Did you mean" can still catch some of them (`mpuse` suggests `mouse`). Two neighbouring letters
  swapped (`mosue`) are found: the typed word of 4 to 15 letters also matches its swapped variants as whole words
  on the typo-tolerant branch, ranked below exact hits ([Typo tolerance](searching.md#typo-tolerance)). Not covered:
  letters swapped that are not neighbours (`moues` is a swap, `muoes` is not), a swap together with another typo in a
  short word, a swap in a code with digits, and any word of a field that is not typo-tolerant.
- It reaches only the words of fuzzy fields (`fuzzy: true`): a misspelled word that occurs only in a
  description or another non-fuzzy field finds nothing. Words shorter than `fuzzy_min_length` (3) must match
  exactly.
- By default (`fuzzy_mode: fallback`) it runs only when exact matching finds fewer than `fallback_below` (5)
  hits, so a typo next to enough exact hits is not corrected. `always` runs it every time, `never` switches
  it off.
- A letter replaced in the middle of a 5 to 7 letter word (`mpuse`) is **not** added to the results of a search. Only the
  [zero-result rescue](searching.md#zero-result-rescue) reaches it, and only when the search found nothing at all, the
  vocabulary has exactly one word one edit away, and the index is not tenant-scoped (no vocabulary there) and the viewer
  is not restricted: then the corrected search answers, flagged (`SearchResult::$correctedFrom`). A tie between two
  candidates, a word with two wrong letters, a code with digits, a search-as-you-type text and a search that found
  something are not rescued, nor is one whose corrected query finds something only because the relaxation drops a word;
  on a tenant-scoped index or for a restricted viewer `mpuse` still finds nothing. A rescued search is logged as a search
  that found hits ([details](#a-rescued-search-is-not-a-zero-result-search-in-the-log)).

What to do: set `fuzzy_similarity` to a number to use one value for every word. That trades the other way: it
finds more typos and lists more look-alikes (the measured trade-off is in
[Typo tolerance](searching.md#typo-tolerance)). Irregular word forms are not typos at all (see
[languages](#stemming-covers-regular-forms-one-language-per-index)).

### A word is matched as a whole word

`LIKE '%phone%'` finds `smartphone`. Fuzzphony matches whole words (after stemming), the beginning of a word
(`phon*`, or `asYouType()`), and a fragment from the middle of a word only through typo tolerance: `phone`
does find `smartphone` and `headphones` that way, but only in a fuzzy field, only while exact matching finds
fewer than `fallback_below` hits, and as a look-alike ranked by similarity, never as a guarantee.

What to do: if one column needs a guaranteed substring match (a part-number fragment), keep a `LIKE` (with a
trigram index) on that column next to Fuzzphony.

### Stop words are ignored, and only a query of nothing else finds them

The stop words of the index language are removed from documents and from queries, so they never narrow a
search (`mouse for gaming` is `mouse gaming`) and a document is not found through them. A query made of
stop words only has nothing left for the full-text branch: with `english`, `who` or `the who` finds nothing
there, not even a document titled "The Who". The typo-tolerant branch searches such a query anyway, because
it works on the normalised text of the typo-tolerant fields, stop words included: `the who`, `who`,
`title:who` and the quoted phrase `"to be or not to be"` find "The Who" and "To be or not to be". It has its
limits:

- Only a query whose every word is a stop word, or a top-level AND of stop words with exclusions. One real word and
  the stop words around it are ignored as before. `the who -rock` and `the who -(rock OR roll)` search `the who` and
  leave out the documents that have `rock` (before 0.9.9 their full-text branch was "everything but rock", and that is
  what came back). A query with an OR at the top level, or an OR or an exclusion inside a positive group (`the OR -rock`,
  `(the -rock) OR who`, `(the OR who) -rock`), is as it always was. An excluded stop word (`the who -the`, `the -who`)
  is dropped, it excludes nothing. Where the stop-word search does not apply (no typo-tolerant search, more than three
  words, a word shorter than `fuzzy_min_length`) such a query is as it always was: `the who -rock` with
  `fuzzy_mode: never` is still "everything but rock".
- Only with typo tolerance: the index has a typo-tolerant field and `fuzzy_mode` is not `never` (the ranking
  profile's `fuzzy` weight is not 0). Without it nothing changes and the query finds nothing.
- Only in the typo-tolerant fields, and as a look-alike, like every typo-tolerant word: `who` ranks "Who" and
  "The Who" first and also lists `whole`. Each word must be at least `fuzzy_min_length` (3) letters long: a
  shorter stop word has to match exactly, which a stop word never does, so `to be or not to be` without
  quotes finds nothing (the quoted phrase is one word of 14 letters and finds it).
- At most three stop words are searched; a query of four or more finds nothing, as it always did. It costs a
  typo-tolerant search, one probe per word with at most `candidate_limit` candidates each, and every word that
  is in most documents keeps all its matches, so the cost grows quickly with the number of words: with
  `the and were there those` all in the title of every one of 100 000 documents, five words took 15 s for a viewer
  restricted to some fields (0.7 s unrestricted). Measured on the same data with the cap: one word 0.12 s (restricted)
  and 0.2 s (unrestricted), two 0.23 and 0.27 s, three 0.36 and 0.42 s (`wireless` alone: 8 ms on another
  catalogue); with `the` in half of the names of 100 000 products, `the` takes 43 ms and three stop words that no
  document has together 80 ms, the empty-result relaxation included. `candidate_limit` applies as always (the total is
  a lower bound).
- The empty-result relaxation works as for any words.

What to do: for names, titles or codes use `language: simple` (no stemming and no stop words), in an index of
their own. See [Languages](languages.md#what-the-language-decides).

### Stemming covers regular forms, one language per index

Stemmers reduce regular endings only: `mice` does not find `mouse`, `went` does not find `go`. An index
analyses its text in one language; a multilingual catalogue is one index per language, searched by the user's
locale. See [Languages](languages.md#limits).

### Ranking of very common words

A search collects at most `candidate_limit` candidates per branch (2000 by default, at most 10 000) and ranks
only those, so `total` is a lower bound when a word matches more documents (`totalIsLowerBound`, shown as
`2000+`; so is the `hits` of the [search log](analytics.md) row). What the candidates are depends on the branch.

**Full-text branch (the words as they are): the best candidates, exact up to a scan budget.** When a word
matches more documents than the limit, the candidates are the best matches by the page's own sort key (text
rank, exact-title and prefix bonuses, boost, recency, then the id), not the first ones the index returns: among
3 000 documents with the word `common`, the one titled exactly `common` comes first at any limit, and on 200 000
products `Wireless`, `wireless` and `Wireless wireless wireless` come before `Wireless keyboard 1010`. Filters,
tenant and field restrictions apply to the selection; a restricted viewer's selection is ordered by the fields
the viewer may see only, so a hidden field never decides which documents are candidates or in which order
([Security](security.md)). A search whose word has fewer matches than the limit is not affected at all: it runs
the same cheap selection as before and returns the same hits.

- **A page past the candidates is exact too.** The search selects the best `offset + limit` candidates when that
  is more than `candidate_limit`, up to 10 000 hits: page 3 of 20 at `candidate_limit: 10` works, and page 400
  of 20 is the last one that does. The total of such a page is `offset + limit` (`+`), so it grows with the page.
  `hasMore()` is **false at the last page of the candidates** (`offset + limit` reaches the candidate window):
  a pager that follows `hasMore()` stops at `candidate_limit` hits (2000 by default); a page asked for directly
  still works. `candidate_limit` is a lower bound of the window, not an upper one: a page of 20 with
  `candidate_limit: 10` selects 20.
- **The scan budget: 100 000 matches for a one-word query** (a constant of the library, not a setting: it bounds
  the cost of a search, and every setting would be one more way to switch it off). The ranking is exact among the
  first 100 000 matches in index order; a word with more matches than that is ranked among those only, so the
  document that would rank first can still be missing when the word is that common (`wireless` has 100 005
  matches on the 1 000 000-row benchmark catalogue and the five documents beyond the budget are not found;
  `ideal` is in every document). The cost of ranking a document grows with the words the query looks up, so the
  budget counts work, not rows: it is divided by the number of words of the query (a query of 16 words scans the
  first 6 250 matches, never fewer than the candidates asked for), and the words the query excludes are not looked up
  for the rank at all (`ideal -a -b ... -o` ranks like `ideal`). The rank of a document is the same either way.
  Measured on 200 000 products, a one-word query costs 0.7 to 1 microseconds per scanned match, 170 to 330 ms in the
  worst case at 1 000 000 rows; the hostile ones (16 words, 15 exclusions, 15 prefixes) cost 170 to 340 ms at 200 000
  rows and 0.4 to 1 s at 1 000 000, about twice the time of the same query before 0.9.8 (the index scan that finds
  the matches is most of it), where an earlier build of 0.9.8 took 2 to 3 s
  ([Benchmarks](benchmarks.md#common-words)).
- **A word under the limit costs what it did** (a rare word: 0.15 ms more to plan, never executed); a word
  over it costs one more pass over its matches: 10 to 38 ms for `wireless` (20 000 matches) on 200 000 products,
  up to 105 ms for the most common word there.
- `min_score` keeps the documents under it out of the candidate slots (the strict branch), so a query that
  needs it still finds its best documents; `total` stays flagged as a lower bound.

**Typo-tolerant branch: the first matches of the trigram index; exact only while a word has no more matches than the limit.**
The typo branch (a word that is misspelled, `fuzzy_mode: always`, or a search that found too little) takes, for each typo-tolerant
word, the documents the trigram index returns, at most `candidate_limit` (the window of the page when that is larger) of them.

- **Under the limit it is exact.** Every match of the typo-tolerant words is a candidate. A document the full-text branch finds
  also keeps the typo score it would have had: an exact word is never ranked below its look-alikes because it fell outside the
  first typo candidates (three documents titled `mousse cake` at the end of 3 000 titles with `mouse` come first for `mousse`).
- **Over the limit it is a lower bound.** A typo word that matches more documents than the limit (a spelling like `mouss` is close
  to 20 000 titles of a 200 000-row catalogue) contributes the first `candidate_limit` of its matches in the table's physical
  order, ranked by the page's sort key. A closer spelling further down the table may not be among them: the page is a full page of
  matches of the word, the same on every run on the same data, but not guaranteed to be the closest ones. That order shows an
  unrestricted viewer nothing it could not read (it may see every field), and a tenant's candidates are chosen among its own
  documents, so the size of another tenant's rows does not change them (a test compares corpora that differ only there).
- **A restricted viewer gets the best candidates instead.** The first matches in physical order are shaped by the size of the
  fields the viewer cannot see, so its typo branch scores the matches it may see, up to a work budget, and keeps the best by the same
  sort key (similarity, bonuses, boost, recency, then the id): exact up to **30 000 matches divided by the number of words of the
  query** when the typo-tolerant texts are short (an internal constant, like the 100 000 of the strict branch), and up to fewer when
  they are long: the budget is about 1.5 MB of typo-tolerant text per statement, counting 100 bytes for every row, taken from the
  stored size of the first matches' text in the fields the viewer may SEE (so 300 characters per document leave about 10 000 matches,
  a visible document of 17 000 words leaves a few hundred), never fewer than the candidates asked for. A hidden field never moves
  it, so what it holds cannot change the candidates; the price is that a long hidden text still costs its recheck time
  ([below](#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field-is-slow)). Beyond the budget the first matches of the index are taken, in
  physical order, as above ([Security](security.md#the-limits-stated-honestly)). The cost of a document of this branch is about 8
  microseconds for a short name and grows with the text that is rechecked.
- **A page past the candidates** selects the window `offset + limit` in this branch too (up to 10 000), like the strict one.
- **Cost.** A typo search costs what the trigram index and the recheck of up to `candidate_limit` matches cost: 25 to 75 ms on
  200 000 products for a typo word that matches 15 000 of them, whatever the limit does to the other branch. A restricted viewer pays
  one more selection (about 0.15 s for `hedphones` restricted to two fields, `batery` on a 12-field index 0.5 s)
  ([Benchmarks](benchmarks.md#typo-words-and-facets-of-a-capped-search)).

**What decides which documents are candidates.** On the full-text branch and for a restricted viewer's typo branch: the text rank,
the bonuses and the similarity of the fields the viewer may read, the id, and the filters, not the physical order of the sidecar table
(which the size of every row, hidden fields included, shapes), up to the budgets above. For everyone else's typo branch above the limit:
the physical order of the table decides which matches are looked at, and the page sort key only orders them.

`exactCounts()` counts everything the query matches, for the total and the facets, but the page of hits is
still the ranked candidates ([Facets](searching.md#facets)).

Why: it keeps the latency of a search bounded however common its words are
([ADR 0005](adr/0005-candidate-limit.md)).

What to do: make the query more specific (more words, a filter, a field scope), or raise `candidate_limit`
(per index or per query, up to the cap of 10 000). Use `exactCounts()` only on queries you control (never wire
it to request input): it reads every match and can take seconds on a large index.

### Facet counts are over the candidates

When the search hit its candidate limit the counts of `facets()` are taken over the very candidates the hits come from (the best
`candidate_limit` documents of the strict branch and, with typo tolerance, the typo branch's candidates; the statement that finds the hits
returns their ids and the facets count them), so a facet shows the documents the page was chosen from, and does not depend on the
order of the table. They are still lower bounds ("120+"; the Live Component prints the `+`) while `total` is a lower bound too, and a
page past the limit (which selects more hits) does not count more facet candidates. A facet that leaves a condition out
(the disjunctive facet of an active filter) selects its own candidates, by the same rule. A filter-only listing with no text counts the
first `candidate_limit` documents by id. With `exactCounts()` every match is counted: in the example above, a limit of 10 gave
4, 3 and 3 for three categories, and the exact counts were 1 001, 1 000 and 1 000. See [Facets](searching.md#facets).

### A restricted viewer's very common word in a hidden typo-tolerant field is slow

A viewer who may not see a typo-tolerant field is still searched through that field's trigram index and the
rows are rejected afterwards, so a word that is in most documents' hidden field makes the probe of a typo-tolerant
search read every row: on 200 000 products with five ordinary words in a hidden field, one such word takes 1.7 s
and five of them 19 s (the same search for a viewer who sees the field takes 0.1 to 0.4 s). Nothing leaks (the
result is the same as for any other hidden content; only the time depends on it, see
[Security](security.md#the-limits-stated-honestly)); it is not a regression of 0.9.8. Planned: a trigram
index on the allowed field's own column, or a work cap on the probe. Until then keep a field that is in most
documents and that some viewers may not see out of `fuzzy: true`.

### Synonyms are expanded on the query, and stop there

- A synonym of a synonym is not followed: with `laptop => notebook` and `notebook => bag`, `laptop` finds
  `notebook` and not `bag`.
- A prefix is not expanded (`tel*` does not look for `television`).
- A member that is a phrase matches only a quoted phrase: `"solid state drive"` finds `ssd`, the same three
  words without quotes do not.
- Only the PostgreSQL engine applies synonyms; a custom engine gets no expansion.

The rest is in [Synonyms](searching.md#synonyms).

### Highlights run the source query, and mark typo matches by word

Highlighting runs your source query for the ids on the page, in the searching session: the role that searches
needs `SELECT` on the source tables, and the snippet shows the row as the source has it now, which in `queue`
mode can be newer than the index.

A hit found only through typo tolerance has its mistyped words marked too (`hedphones` marks `Headphones`),
by a simpler rule than the search's: each word of the field text is compared with the typed word on its own,
with the similarity the search used, so a match that the search found across two words (`head phones` for
`headphones`) is not marked, and a hit can have no mark although it was found. A word found as a swapped-letter variant
(`mosue` finds `mouse`) is marked when it is the variant as a whole word. Only typo-tolerant fields get
these marks (a field that is not `fuzzy` shows ts_headline's snippet). The work is bounded: the marks are made
for the first 200 typo-found hits of a page, of each field only the first 10 000 characters (2000 words) are
looked at, a page spends a budget of 100 000 word comparisons and 4000 distinct words, and a phrase of more than
8 words is not marked; a hit past a bound keeps ts_headline's snippet. A hit that the strict full-text query also matches keeps the highlight ts_headline gives
it, even when a typed word was found in it by typo only. See [Highlighting](searching.md#highlighting).

### Very long texts limit where a phrase is found

PostgreSQL keeps a word's position in a `tsvector` only up to 16 383: a later word gets position 16 383 too. Fuzzphony's
whole-text vector (`tsv`) is the vectors of all fields joined, so after a field of more than about 16 000 words every word of
the fields that follow it is at one position there, and where several words share one position only the highest weight of
them is kept. Phrases and field-scoped words need those positions and weights. Since 0.9.8 that costs a document nothing:
a phrase (`"usb receiver"`, `name:"usb receiver"`, `usb-c`) and a word scoped to a field (`secret:word`) are looked up by
their words and decided by each field's own vector, which the cap does not reach (before 0.9.8 an unrestricted search lost
them after a long field, a restricted one had been fixed in 0.9.7: see the [CHANGELOG](../CHANGELOG.md)).

What still does not work, because the data is not in the index in a usable form:

- a phrase that starts after the 16 383rd word of one field: the field's own vector has capped it. The words are found (a
  plain or scoped word, and the phrase's words together in an AND query), the phrase is not;
- a phrase that reaches from the end of a field of that length into the start of the next one;
- the rank of a document that was found only this way (a phrase or scoped word after a long field): `tsv` has no usable
  positions for it, so the text rank is the best of the first field's and of the later fields' vectors (a scoped word
  keeps its rank, an AND of words in different fields can rank 0 and so come last). A document `tsv` matches keeps the rank it always had.

A phrase or scoped word is looked up by the words alone and then read from `tsv`, and, only for a document whose `tsv` was
capped, from the vectors of the later fields: the sidecar column `tsv_capped` (filled by `fuzzphony:reindex`, added by
`fuzzphony:schema --apply`) says which. Without the column, or for a document written before it (NULL, "not known"), the
later fields are read for every candidate that `tsv` does not match as a phrase: the same results, slower on an index with many
fields (see [UPGRADE](../UPGRADE.md#from-097-to-098)). The flag is true when a position reached 16 383, and also when a lexeme
has 256 or more positions (PostgreSQL keeps at most 256 per lexeme and drops the later field's positions of one that has them:
without this sign a phrase after a field that repeats one of its words would be lost). The doctor warns while the flag is NULL;
after a downgrade to a release without the column and back, reindex (an old refresh function does not touch the flag); the engine
looks the column up once per process, so restart long-running workers after adding or dropping it
(`fuzzphony:schema --apply` says so when it added the column to an existing table).

What to do: do not index the whole of a very long text. Put an excerpt (the first few thousand words, a summary) in the
searched field, or list the long field last (nothing follows it). The limits above hold for a restricted viewer too
(see [Security](security.md) for what a hidden field never decides).

### Scores are not stable

Rely on the order of the hits, not on the numbers. A score includes the recency term, which decays with time:
with a recency half-life of 9 seconds (to make it visible), three searches two seconds apart, with no change
in the data, gave 1.997, 1.923 and 1.860 for the same document. It also depends on the index data, the ranking profile and the PostgreSQL text search configuration,
and can change when Fuzzphony's ranking is tuned. Scores of different indexes cannot be compared (a
[federated search](searching.md#federated-search) merges by rank for that reason).

What to do: do not store a score, compare scores across queries, or threshold on one. `min_score` applies to
relevance only, which has no bonus or recency in it (see [Ranking](ranking.md)).

### Long queries are cut

Text longer than `max_query_length` (256 characters, at most 1 024) is cut, in the middle of a word if need be;
words beyond `max_terms` (16, at most 64) are ignored; parentheses nested deeper than 8 are flattened. Every
cut is a warning in `$result->warnings`, and the search runs on what is left: for an AND query the dropped
words simply stop narrowing it, so a pasted sentence is searched by its first 16 words.

What to do: limit the length of the input box, show the warnings, or raise the limits for a query within the
caps ([thresholds](ranking.md#thresholds)).

### "Did you mean" and completions come from a vocabulary that ages

The suggestions of `didYouMean` and `suggest()` come from a vocabulary of the words of the typo-tolerant
fields, filled by a full reindex and not touched by single-document changes: a word that is new since the last
rebuild is not suggested yet. A tenant-scoped index never suggests anything, and neither does a viewer
restricted to some fields. Words with a digit, prefixes, excluded words and words a synonym expands are not
corrected.

What to do: schedule `fuzzphony:reindex --vocabulary` (nightly is plenty), and set `did_you_mean: false` on an
index whose visibility filters make the existence of a word a secret. See
[Did you mean](searching.md#did-you-mean).

## Data and sync

### Attaching or detaching a partition is not followed

`ALTER TABLE … ATTACH PARTITION` and `DETACH PARTITION` fire no triggers (a PostgreSQL rule): attached rows are
not indexed and detached rows stay in the index, until the next full reindex.

What to do: run `fuzzphony:reindex <index>` afterwards; it also removes the orphaned documents. See
[partitioned tables](sync.md#partitioned-tables).

### What each sync mode sees

- `queue` (the default) is eventually consistent: until the worker has processed the queue a deleted row can
  still appear as a hit (`EntityLoader` skips hits whose row is gone, so a page can have fewer entities than
  its limit). The doctor reports the queue's size and age.
- `orm` sees only changes made through the entity manager: raw SQL writes, joined data and a `TRUNCATE` are not
  followed. `manual` follows nothing; call `refresh()` or `reindex()`.
- `trigger` is read-your-writes, but the `TRUNCATE` of a watched table that is not the index's own table
  resyncs the whole index inside the truncating transaction (42.8 s on 1 000 000 documents, holding an
  `ACCESS EXCLUSIVE` lock). Prefer `queue` for large indexes that watch joined tables.

See [Keeping the index in sync](sync.md).

### One id column per document

A document is identified by one column (`id` by default) of an integer, UUID or string type, and the string
`*` is reserved. A table with a composite key needs a source query that builds one id (a string id type, for
example `a || '-' || b AS id`).

### A full reindex needs room and a session connection

The zero-downtime reindex builds a second copy of the index next to the live one: it needs the disk space, a
role that can create tables in Fuzzphony's schema and owns the index table, and a session connection (it holds
an advisory lock, so not a transaction-pooling PgBouncer). A role or a setup without these reindexes in place
(`--in-place`), and `fuzzphony:schema --apply` refuses to run while a rebuild is building: a deploy that
applies the schema should retry. See [Reindexing](sync.md#reindexing-and-orphan-pruning).

## Operations

### The registry cache in production needs `cache:clear`

With `kernel.debug` off, the Symfony bundle keeps the `#[Searchable]` definitions it discovered in the
`cache.system` pool for **web requests**, because discovering them loads the metadata of every entity. A
change to `#[Searchable]`, `#[SearchField]` or `#[SearchFilter]`, or to a `synonyms_file` an attribute names, is
not seen by the web application until `cache:clear`. The console (`fuzzphony:schema --apply`,
`fuzzphony:reindex`, `fuzzphony:doctor`, the worker, a Messenger consumer) always discovers fresh and never
reads or writes the pool, so it sees current attributes whatever the cache holds. In debug mode nothing is
cached, and YAML indexes are never cached.

What to do: `cache:clear` as part of every deploy, so that the web requests see the same definitions the
schema was applied with. See [Attributes](configuration.md#attributes).

### Text search configuration caches assume the configuration does not change in a process

The engine remembers, per process, which words the text search configuration reduces to nothing (stop words),
the stems of the words of the synonyms and the synonym lookup tables built from them (about 5 000 entries per
configuration, then it starts over). They live as long as the process, and a long-lived worker (FrankenPHP,
RoadRunner, a Messenger consumer) keeps them on purpose: that is what makes its searches cheaper than a PHP-FPM
request's. It is right as long as the configuration and its dictionaries do not change while the process runs.

What to do: after an `ALTER TEXT SEARCH CONFIGURATION` or a changed dictionary, restart the long-running
processes that search, or call `PostgresEngine::resetCaches()` from the application. A PHP-FPM request starts
clean.

### `explain()` and its statement labels are not a stable API

`explain()` and `fuzzphony:search --explain` are for people. The SQL text, the number of statements, their
labels (`full-text`, `fallback: full-text + fuzzy`, `facet: category_id, in_stock`, `did you mean`,
`relaxation probe`, `exact total`, and the same labels with the prefix `rescue: `) and the plan lines change between releases (0.9.5 merged the facet
statements into one). Do not parse them or build a test or an alert on them.

### A rescued search is not a zero-result search in the log

The [zero-result rescue](searching.md#zero-result-rescue) answers a search that found nothing with the results of its
corrected spelling, and the [search log](analytics.md#what-is-logged) records the text the visitor typed with the hits of
the answer it got. So a typo such as `mpuse` is no longer in the zero-result report (`SearchAnalytics::zeroResultQueries()`,
`fuzzphony:analytics --zero`) while the rescue is on: it counts as a search that found hits. The log has no flag or status for a
rescued search (the schema is unchanged). Typos the rescue cannot answer still appear there. To see every text that found nothing
on its own, set `rescue_empty: false`; the counter `fuzzphony.search.rescued` ([Observability](observability.md#instrumentation-points))
counts the rescued searches without their text.

### The search log is best-effort

The [search log](analytics.md) is for trends, not an audit trail. Its rows are written in one batch after the
response; a write that fails (the table is missing, the database is down) is counted in
`fuzzphony.search_log.failed`, logged and dropped, never retried. A long-running worker writes its buffer every
1000 records and on every container reset (between two messages or requests), but a process killed with
`SIGKILL` loses the (at most 1000) records it still holds. The `hits` of a search that hit `candidate_limit` is a
lower bound (see [above](#ranking-of-very-common-words)). Redaction removes what a regular
expression can describe, nothing more, and the live search of the Live Component is not logged unless you ask
for it. The user and the IP of a row exist only for searches made with an actor (`asActor()`; the integrations
do). A log you must not lose is a custom `SearchLog` that writes somewhere durable, which the built-in reports
then do not read.

## Security and privacy

### Field authorization hides content, not existence or timing

Field-level authorization (`granted`, see [Security](security.md)) keeps the content of a hidden field out of
what a restricted viewer can match, rank, highlight, count and be suggested. It is not row-level security (a
viewer who may search an index may find every document of it, within the tenant) and it is not a covert-channel
proof:

- a hidden field's existence is visible (`salary:x` gives no "Unknown field" warning), and a highlight request for it shows nothing;
- timing is not hidden: how long a search takes can depend on how often a word occurs in a hidden field;
- filters and facets are not restricted;
- the vocabulary of unrestricted viewers still holds every word (a restricted viewer gets no suggestions and no "did you mean");
- an exact phrase is read from the fields the viewer may see, as if the hidden ones did not exist: it spans two
  allowed fields as it does for an unrestricted search, and never reaches into a hidden one. With a hidden field
  between two allowed ones a restricted phrase can match where the unrestricted search (which sees the hidden
  text in between) does not. The typo-tolerant similarity of a phrase that spans two fields is still taken per
  field (a typo in it is found less reliably than for an unrestricted viewer);
- `explain()` of a restricted viewer returns the statements and a note instead of the plan, because estimates
  and `ANALYZE` counts would leak how often a word occurs in the hidden fields;
- an index upgraded to layout 2 without a full reindex has empty per-field columns, so a restricted viewer finds
  nothing (it fails closed): reindex after upgrading.

### The client IP in the search log

`search_log.ip` is off by default. When it is on:

- `masked` is a risk reduction, not anonymisation. A masked IPv4 address (`/24`) still narrows a visitor to
  the 254 addresses of that block, and a masked IPv6 address (`/48`) is what a provider usually hands to **one
  customer site**, so it often identifies one household or office (the `/48` rule is fixed; use `off` if that
  matters).
- The address is `Request::getClientIp()`, so it is only as good as `framework.trusted_proxies` and
  `trusted_headers`: behind a proxy or load balancer without them every row carries the proxy's address.
  Fuzzphony never reads `X-Forwarded-For` itself.
- A search that does not come from an HTTP request carries no address: the console (`fuzzphony:search`), a
  worker or a Messenger handler, and any search made without an actor. Anything that is not a valid IP address
  is not stored.

What to do: keep it off unless you use it, prefer `masked`, set the retention and the trusted proxies. See
[The client IP](analytics.md#the-client-ip).

### A person's search history is personal data

`SearchAnalytics::recentSearches()` and `fuzzphony:analytics --recent` list who searched what, including the
refused searches. On a tenant-scoped index they need a tenant or an explicit `allTenants: true`, and a call
that names a tenant never returns another tenant's rows. On an index that is **not** tenant-scoped, a call
without a tenant covers everyone, so name the tenant (the log records the actor's tenant there too). Redaction
is pattern matching, not a guarantee.

What to do: give the report to an operator role only, never pass a tenant or a user from request input without
checking that the requester may see it, keep `retention_days` short and run the purge. See
[Who searched what](analytics.md#who-searched-what) and
[Privacy](analytics.md#privacy-the-log-is-personal-data).

### The rate limit by IP is loose by design and only covers requests

`per: tenant` and `per: user` give an anonymous visitor no key, so they never limit a public search box;
`per: ip` does (the bundle's Live Component and API Platform filter search as an actor that carries the client
address). Its limits:

- **A company network or NAT shares one address among many people**, so they share one bucket. The default
  (a sliding window of 600 searches a minute per address, prepended as `framework.rate_limiter.fuzzphony_ip`)
  is loose on purpose: it stops a script, not a person.
- **Without `framework.trusted_proxies` everyone behind a reverse proxy or CDN shares ONE bucket** (the
  application sees the proxy's address); with it set too broadly, a visitor can name any client. Fuzzphony
  never reads `X-Forwarded-For` itself, `Request::getClientIp()` decides.
- **A call without a request is not limited by IP**: a plain PHP call, a console command, a worker, an actor
  built without `ip:`, and a search made without any `asActor()` (no guard runs then) have no address.
  Fuzzphony cannot limit what it does not see.
- **An IPv6 address is limited by its /64**; an address that embeds an IPv4 address (IPv4-mapped,
  IPv4-compatible, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`) as that IPv4 address. A visitor with a /64 to
  himself is one bucket, which is the point.
- **An address that is no IP address is not limited** (fail open) after the safe normalisations (whitespace,
  a port, brackets, a zone id); only an `ActorResolver` of your own can produce one. It is reported once per
  process (`fuzzphony.search.rate_limit_unkeyed`), never with the value.
- The address is read per request and not stored unless `search_log.ip` says so; the limiter's state lives in
  its cache pool (an HMAC of the key keyed with `kernel.secret`, for the window; rotating the secret resets the buckets), and the default filesystem pool locks and writes a file
  on every search.
- With `authorization` enabled, a request without a Security token is refused before the limit is looked at,
  so `per: ip` is the setting for a public search box, not for a signed-in-only one.
- `explain()` and `suggest()` spend a token too.

What to do: set `trusted_proxies` if you run behind one, raise the limit if your visitors sit behind big NATs,
use APCu or Redis for the limiter's pool, and keep a limiter of your own in front of the endpoint if you need a
stricter or per-route limit. See [Limiting anonymous visitors by IP](security.md#limiting-anonymous-visitors-by-ip).

### Authorization is deny-by-default

With `fuzzphony.authorization.enabled`, the default attribute `FUZZPHONY_SEARCH` with no voter denies every
search, and a request without a Security token is denied too, so only signed-in users can search; the Live
Component and the API Platform filter search as an actor on every index they serve, so every one of them needs
the index attribute granted. See [Authorization](security.md#authorization).

### Tenant isolation is enforced by the library, not the database

A tenant-scoped index is filtered by Fuzzphony's API on every search; a raw SQL query on the sidecar table is
not. A `tenant()` added to an existing index leaves the column empty until `fuzzphony:reindex` (the tenant
searches find nothing for those rows: it fails closed). The vocabulary has no tenant column, so a tenant-scoped
index gives no "did you mean" and no completions.

What to do: add a PostgreSQL row-level security policy on the sidecar table for defense in depth. See
[Multi-tenancy](multi-tenancy.md#what-it-guarantees).

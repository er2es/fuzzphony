# Known limitations

What Fuzzphony does not do, or does less well than you might expect, what happens instead, and what to do about it. Back to the [README](../README.md).

Some of these are limits of the technique (trigrams, PostgreSQL's triggers), some keep the cost of a search bounded, some protect the privacy of a log,
and some are things to do when you deploy. The statements here were checked against the code and the tests.

- [Search quality](#search-quality): [typo tolerance](#typo-tolerance-is-trigram-based), [whole words](#a-word-is-matched-as-a-whole-word), [codes](#codes-with-a-hyphen-before-digits-a-dot-or-a-slash),
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

Typo tolerance is per word, and the similarity a word needs is proportional to its length (see [Typo tolerance](searching.md#typo-tolerance)). Trigrams
compare letters, not meaning, so they cannot tell a typo from a different word that looks alike:

- `cable` also finds `table` (similarity 0.50), and `mose` is as close to `monitor` and `mower` as to `mouse` (0.40 each): a search for `mose` lists all of
  them with the same score.
- The threshold keeps a different word at the worst case of a typo out (`mouse` / `monitor`, 0.33), and that costs some real typos: a letter replaced in
  the middle of a 5 to 7 letter word is not tolerated in the results (`mpuse`; "did you mean" can still catch it). Two neighbouring letters swapped
  (`mosue`) are found, ranked below exact hits. Not covered: letters swapped that are not neighbours (`muoes`), a swap together with another typo in a
  short word, a swap in a code with digits, and any word of a field that is not typo-tolerant.
- It reaches only the words of fuzzy fields (`fuzzy: true`). Words shorter than `typo.min_length` (3) must match exactly.
- By default (`typo.mode: fallback`) it runs only when exact matching finds fewer than `typo.fallback_below` (5) hits, so a typo next to enough exact
  hits is not corrected. `always` runs it every time, `never` switches it off.
- The [zero-result rescue](searching.md#zero-result-rescue) reaches `mpuse` only when the search found nothing at all, the vocabulary has exactly one word one
  edit away, the index is not tenant-scoped and the viewer is not restricted. A tie between two candidates, a word with two wrong letters, a code with
  digits, a search-as-you-type text, a search that found something, and one whose corrected query finds something only because the relaxation drops a word
  are not rescued. A rescued search is logged as a search that found hits ([details](#a-rescued-search-is-not-a-zero-result-search-in-the-log)).

What to do: set `typo.similarity` to a number to use one value for every word (it finds more typos and lists more look-alikes; the trade-off is in
[Typo tolerance](searching.md#typo-tolerance)). Irregular word forms are not typos at all (see [languages](#stemming-covers-regular-forms-one-language-per-index)).

### A word is matched as a whole word

`LIKE '%phone%'` finds `smartphone`. Fuzzphony matches whole words (after stemming), the beginning of a word (`phon*`, or `asYouType()`), and a fragment
from the middle of a word only through typo tolerance: `phone` finds `smartphone` that way, but only in a fuzzy field, only while exact matching finds
fewer than `typo.fallback_below` hits, and as a look-alike, never as a guarantee.

What to do: if one column needs a guaranteed substring match (a part-number fragment), keep a `LIKE` (with a trigram index) on that column next to Fuzzphony.

### Codes with a hyphen before digits, a dot or a slash

`INV-001` is not found on a field that is not marked `code: true` and is not typo tolerant (`INV-2024-0042`, `AB-12/3`, `INV.007` likewise). The query is
split at every character that is not a letter or a digit; PostgreSQL's parser indexes `INV-001` as `inv` and `-001` (a hyphen before digits is a sign),
`INV.007` and `INV/008` as one token each. A fuzzy field finds the code through the trigram fallback, as a look-alike.

What to do: mark the field `code: true` ([Codes and numbers](searching.md#codes-and-numbers)).

### Stop words are ignored, and only a query of nothing else finds them

The stop words of the index language are removed from documents and from queries, so they never narrow a search (`mouse for gaming` is `mouse gaming`)
and a document is not found through them. A query made of stop words only has nothing left for the full-text branch: with `english`, `the who` finds
nothing there, not even a document titled "The Who". The typo-tolerant branch searches such a query anyway, because it works on the normalised text of
the typo-tolerant fields, stop words included: `the who`, `who`, `title:who` and the quoted phrase `"to be or not to be"` find "The Who" and "To be or not
to be". Its limits:

- Only a query whose every word is a stop word, or a top-level AND of stop words with exclusions. One real word and the stop words around it are ignored
  as before. `the who -rock` and `the who -(rock OR roll)` search `the who` and leave out the documents that have `rock`. A query with an OR at the top
  level, or an OR or exclusion inside a positive group (`the OR -rock`, `(the -rock) OR who`), is as it always was. An excluded stop word (`the who -the`)
  is dropped. Where the stop-word search does not apply (no typo-tolerant search, more than three words, a word shorter than `typo.min_length`) such a
  query is as it always was: `the who -rock` with `typo.mode: never` is still "everything but rock".
- Only with typo tolerance: the index has a typo-tolerant field and `typo.mode` is not `never` (the profile's `fuzzy` weight is not 0).
- Only in the typo-tolerant fields, and as a look-alike (`who` ranks "Who" and "The Who" first and also lists `whole`). Each word must be at least
  `typo.min_length` (3) letters: a shorter stop word has to match exactly, which a stop word never does, so `to be or not to be` without quotes finds
  nothing (the quoted phrase is one word of 14 letters and finds it).
- At most three stop words are searched; four or more find nothing. It costs a typo-tolerant search, one probe per word with at most `limits.candidates` candidates each, and every word that is in most documents keeps all its matches (five such words took 15 s for a restricted viewer, three 0.4 s). `limits.candidates` applies as always (the total is a lower bound).

What to do: for names, titles or codes use `language: simple` (no stemming and no stop words), in an index of their own ([Languages](languages.md#what-the-language-decides)).

### Stemming covers regular forms, one language per index

Stemmers reduce regular endings only: `mice` does not find `mouse`, `went` does not find `go`. An index analyses its text in one language; a multilingual
catalogue is one index per language, searched by the user's locale ([Languages](languages.md#limits)).

### Ranking of very common words

A search collects at most `limits.candidates` candidates per branch (2000 by default, at most 10 000) and ranks only those, so `total` is a lower bound
when a word matches more documents (`totalIsLowerBound`, shown as `2000+`; so is the `hits` of the [search log](analytics.md) row).

**Full-text branch: the best candidates, exact up to a scan budget.** When a word matches more documents than the limit, the candidates are the best
matches by the page's own sort key (text rank, exact-title and prefix bonuses, boost, recency, then the id), not the first ones the index returns: among
3 000 documents with the word `common`, the one titled exactly `common` comes first at any limit. Filters, tenant and field restrictions apply to the
selection; a restricted viewer's selection is ordered by the fields the viewer may see only, so a hidden field never decides which documents are
candidates or in which order ([Security](security.md)). A search whose word has fewer matches than the limit is not affected at all.

- **A page past the candidates is exact too.** The search selects the best `offset + limit` candidates when that is more than `limits.candidates`, up to
  10 000 hits (page 400 of 20 is the last that works). The total of such a page is `offset + limit` (`+`). `hasMore()` is **false at the last page of the
  candidates**: a pager that follows it stops at `limits.candidates` hits; a page asked for directly still works. `limits.candidates` is a lower bound of
  the window, not an upper one.
- **The scan budget: 100 000 matches for a one-word query** (a constant of the library, not a setting). The ranking is exact among the first 100 000
  matches in index order; a word with more matches is ranked among those only, so the document that would rank first can be missing when the word is
  that common (`wireless` has 100 005 matches on the 1 000 000-row benchmark catalogue and the five beyond the budget are not found). The budget counts
  work, not rows: it is divided by the number of words of the query (16 words scan the first 6 250 matches, never fewer than the candidates asked for), and
  excluded words are not looked up for the rank. A one-word query costs about 1 microsecond per scanned match, 290 ms for `wireless` (100 005 matches) at 1 000 000 rows ([Benchmarks](benchmarks.md#common-words)).
- **A word under the limit costs what it did**; a word over it costs one more pass over its matches.
- `relevance.min_score` keeps documents under it out of the candidate slots, so a query that needs it still finds its best documents.

**Typo-tolerant branch: the first matches of the trigram index; exact only while a word has no more matches than the limit.** For each typo-tolerant
word the branch takes the documents the trigram index returns, at most `limits.candidates` (the page window when larger).

- **Under the limit it is exact.** Every match is a candidate, and a document the full-text branch finds keeps the typo score it would have had.
- **Over the limit it is a lower bound.** A typo word that matches more documents than the limit contributes the first `limits.candidates` of its matches
  in the table's physical order, ranked by the page's sort key; a closer spelling further down the table may be missing. The page is a full page of
  matches of the word, the same on every run on the same data. An unrestricted viewer may see every field, so that order shows it nothing it could not
  read, and a tenant's candidates are chosen among its own documents (a test compares corpora that differ only in another tenant's rows).
- **A restricted viewer gets the best candidates instead.** The first matches in physical order are shaped by the size of the fields the viewer cannot
  see, so its typo branch scores the matches it may see, up to a work budget, and keeps the best by the same sort key: exact up to **30 000 matches divided
  by the number of words of the query** when the typo-tolerant texts are short, fewer when they are long (about 1.5 MB of visible typo-tolerant text per
  statement, 100 bytes counted per row, never fewer than the candidates asked for). A hidden field never moves the budget; the price is that a long hidden
  text still costs its recheck time ([below](#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field)). Beyond the budget the first matches
  in physical order are taken ([Security](security.md#the-limits-stated-honestly)).
- **A page past the candidates** selects the window `offset + limit` in this branch too (up to 10 000).
- **Cost.** One more selection for a restricted viewer ([Benchmarks](benchmarks.md#typo-words-and-facets-of-a-capped-search)).

On the full-text branch and for a restricted viewer's typo branch the candidates are decided by rank, bonuses, similarity of the visible fields, the id and
the filters, not by the physical order of the table; for everyone else's typo branch above the limit the physical order decides which matches are looked
at, and the sort key only orders them. `exactCounts()` counts everything the query matches, for the total and the facets, but the page of hits is still
the ranked candidates ([Facets](searching.md#facets)).

Why: it keeps the latency of a search bounded however common its words are ([ADR 0005](adr/0005-candidate-limit.md)). What to do: make the query more
specific (more words, a filter, a field scope), or raise `limits.candidates` (per index or per query, up to 10 000). Use `exactCounts()` only on queries
you control (never wire it to request input): it can take seconds on a large index.

### Facet counts are over the candidates

When the search hit its candidate limit the counts of `facets()` are taken over the very candidates the hits come from (the best `limits.candidates`
documents of the strict branch and, with typo tolerance, the typo branch's candidates), so a facet shows the documents the page was chosen from and does
not depend on the order of the table. They are lower bounds ("120+"; the Live Component prints the `+`) while `total` is, and a page past the limit does
not count more facet candidates. A facet that leaves a condition out (the disjunctive facet of an active filter) selects its own candidates, by the same
rule. A filter-only listing with no text counts the first `limits.candidates` documents by id. With `exactCounts()` every match is counted. See [Facets](searching.md#facets).

### A restricted viewer's very common word in a hidden typo-tolerant field

A restricted viewer's words are looked up in an index that also holds the text of the fields it may not see, and the rows that match only there are
rejected afterwards. A word that is in most documents' hidden field then makes a typo-tolerant search read every row.

**What is fast now.** An index with at least one `granted` field has two more indexes over its open fields (the ones without `granted`): a trigram index
over their typo-tolerant text (only when a granted field is typo-tolerant: otherwise it would copy `fz`) and a full-text index over their text
(`fuzzphony:schema --apply` builds them, concurrently; the doctor reports a missing one). A restricted viewer who holds **none** of the granted fields
looks its words up there, so a word the hidden fields are full of costs it nothing ([Benchmarks](benchmarks.md#a-restricted-viewer-whose-hidden-fields-are-full-of-the-words-searched)).
The results are the same with or without them, and an index without a `granted` field has no extra index and no changed statement.

**What stays slow.**

- A viewer who holds **some** of the granted fields: its words are looked up in `fz` and `tsv` as before (an index per combination would multiply the size).
- A word that is common in a field the viewer **can** see costs what it costs an unrestricted viewer.
- A phrase over fields of both classes, or with a synonym alternative, is rechecked on the fields' own columns.
- A viewer restricted by hand (`restrictedToFields()`) on an index with no `granted` field has no open class.
- When no granted field is typo-tolerant the viewer's typos are looked up in `fz`, which may still hold a now-granted field's text in rows an earlier
  definition wrote until the reindex; the recheck removes those rows, they only cost time.
- The open-class indexes are used only when they exist, are valid and are smaller than the index they replace; the engine asks once per process, so after
  an apply built them restart long-running workers (or call `Fuzzphony::resetCaches()`).
- A typo-tolerant search of a word the viewer's own tenant does not have, while other tenants do, can be slow for a restricted viewer. Nothing of the other tenant is returned or counted; the time is the cost.
- Changing which fields are `granted` makes the open-class indexes stale: run `fuzzphony:schema --apply` (it builds the new ones and drops the old with
  `DROP INDEX CONCURRENTLY`; a drop that fails is a warning, run it again); until then the doctor reports the old ones as stale.
- Timing is not hidden ([Security](security.md#the-limits-stated-honestly)).

Nothing about a hidden field changes what a restricted viewer finds, with or without the open-class indexes.

### Synonyms are expanded on the query, and stop there

- A synonym of a synonym is not followed (`laptop => notebook` and `notebook => bag`: `laptop` finds `notebook`, not `bag`).
- A prefix is not expanded (`tel*` does not look for `television`).
- A member that is a phrase matches only a quoted phrase.
- Only the PostgreSQL engine applies synonyms.

The rest is in [Synonyms](searching.md#synonyms).

### Highlights run the source query, and mark typo matches by word

Highlighting runs your source query for the ids on the page, in the searching session: the role that searches needs `SELECT` on the source tables, and the
snippet shows the row as the source has it now, which in `queue` mode can be newer than the index.

A hit found only through typo tolerance has its mistyped words marked too (`hedphones` marks `Headphones`), by a simpler rule than the search's: each word
of the field text is compared with the typed word on its own, so a match the search found across two words (`head phones` for `headphones`) is not marked,
and a hit can have no mark although it was found. A swapped-letter variant is marked when it is the variant as a whole word. Only typo-tolerant fields get
these marks. The work is bounded: marks are made for the first 200 typo-found hits of a page, of each field the first 10 000 characters (2000 words), a
page spends 100 000 word comparisons and 4000 distinct words, and a phrase of more than 8 words is not marked; a hit past a bound keeps ts_headline's
snippet. A hit that the strict full-text query also matches keeps the ts_headline highlight. See [Highlighting](searching.md#highlighting).

### Very long texts limit where a phrase is found

PostgreSQL keeps a word's position in a `tsvector` only up to 16 383: a later word gets position 16 383 too. Fuzzphony's whole-text vector (`tsv`) is the
vectors of all fields joined, so after a field of more than about 16 000 words every word of the fields that follow it is at one position there, and where
several words share one position only the highest weight is kept. A phrase (`"usb receiver"`, `usb-c`) and a word scoped to a field (`secret:word`) are
looked up by their words and decided by each field's own vector, which the cap does not reach, so this costs a normal document nothing.

What still does not work, because the data is not in the index in a usable form:

- a phrase that starts after the 16 383rd word of one field (the words are found, the phrase is not);
- a phrase that reaches from the end of a field of that length into the start of the next one;
- the rank of a document found only this way: the text rank is the best of the first field's and of the later fields' vectors (an AND of words in
  different fields can rank 0 and come last). A document `tsv` matches keeps the rank it always had.

The sidecar column `tsv_capped` (added by `fuzzphony:schema --apply`, filled by `fuzzphony:reindex`) says whether a document's `tsv` was capped: true when a
position reached 16 383, and also when a lexeme has 256 or more positions. The column is required: without it a search or refresh fails with one
`SchemaNotApplied` exception ("missing: tsv_capped"; the doctor reports it too), and applying the schema is the whole remedy. For a document written
before the column (NULL, "not known") the later fields are read for every candidate that `tsv` does not match as a phrase: the same results, slower on an
index with many fields until the reindex. The doctor warns while the flag is NULL; after a downgrade to a release without the column and back, reindex.

What to do: do not index the whole of a very long text. Put an excerpt in the searched field, or list the long field last. The limits hold for a
restricted viewer too ([Security](security.md)).

### Scores are not stable

Rely on the order of the hits, not on the numbers. A score includes the recency term, which decays with time (with a half-life of 9 seconds, three
searches two seconds apart gave 1.997, 1.923 and 1.860 for the same document), depends on the index data, the ranking profile and the text search
configuration, and can change when Fuzzphony's ranking is tuned. Scores of different indexes cannot be compared (a [federated search](searching.md#federated-search)
merges by rank for that reason).

What to do: do not store a score, compare scores across queries, or threshold on one. `relevance.min_score` applies to relevance only, which has no bonus
or recency in it ([Ranking](ranking.md)).

### Long queries are cut

Text longer than `limits.max_query_length` (256 characters, at most 1 024) is cut, in the middle of a word if need be; words beyond `limits.max_terms` (16,
at most 64) are ignored; parentheses nested deeper than 8 are flattened. Every cut is a warning in `$result->warnings`, and the search runs on what is
left (a pasted sentence is searched by its first 16 words).

What to do: limit the length of the input box, show the warnings, or raise the limits within the caps ([thresholds](ranking.md#thresholds)).

### "Did you mean" and completions come from a vocabulary that ages

The suggestions of `didYouMean` and `complete()` come from a vocabulary of the words of the typo-tolerant fields, filled by a full reindex and not
touched by single-document changes: a new word is not suggested yet. A tenant-scoped index never suggests anything, and neither does a viewer restricted
to some fields. Words with a digit, prefixes, excluded words and words a synonym expands are not corrected.

What to do: schedule `fuzzphony:reindex --vocabulary` (nightly is plenty), and set `rescue.did_you_mean: false` (with `rescue.correct_when_empty: false`)
on an index whose visibility filters make the existence of a word a secret. See [Did you mean](searching.md#did-you-mean).

## Data and sync

### Attaching or detaching a partition is not followed

`ALTER TABLE … ATTACH PARTITION` and `DETACH PARTITION` fire no triggers (a PostgreSQL rule): attached rows are not indexed and detached rows stay in the
index, until the next full reindex. What to do: run `fuzzphony:reindex <index>` afterwards; it also removes the orphaned documents. See
[partitioned tables](sync.md#partitioned-tables). The doctor cannot tell that a partition was attached or detached since the last reindex (PostgreSQL records no attach time), so for now there
is no doctor note either; tracking such events may come later.

### What each sync mode sees

- `queue` (the default) is eventually consistent: until the worker has processed the queue a deleted row can still appear as a hit (`EntityLoader` skips
  hits whose row is gone, so a page can have fewer entities than its limit). The doctor reports the queue's size and age.
- `orm` sees only changes made through the entity manager: raw SQL writes, joined data and a `TRUNCATE` are not followed. `manual` follows nothing; call
  `refresh()` or `reindex()`.
- `trigger` is read-your-writes, but the `TRUNCATE` of a watched table that is not the index's own table resyncs the whole index inside the truncating
  transaction (about as long as a full reindex written in place, holding an `ACCESS EXCLUSIVE` lock). Prefer `queue` for large indexes that watch joined tables.

See [Keeping the index in sync](sync.md).

### Connecting a legacy database

Plain tables without entities work (see [Joins, several tables and several indexes](configuration.md#joins-several-tables-and-several-indexes)), but a few
things take more writing than with one entity per index.

- **Several tables in one list: use sources.** A hand-written `UNION ALL` with prefixed ids still works, but its hits carry only a string id and every
  watch is yours to get right. An index with [several sources](configuration.md#several-sources-in-one-index) gives each hit its `kind` and typed id.
- **A watch `key` must be one plain column.** An expression or a composite key is refused ("is not a valid column name"). Key on one column of the group
  (`order_no`) and return all documents of that group with an `ids` query, or key on the column that points at the document.
- **A one-to-many join** that returns several rows for one id makes the id repeat. Declare the field [`many`](configuration.md#several-values-per-document-many),
  or `string_agg(...) ... GROUP BY` in the query, one row per document.
- **Every joined table needs its own watch.** Nothing is derived from the join; a table you do not list is not followed (the doctor warns, see
  [sync](sync.md#the-doctor-finds-the-tables-you-forgot-to-watch)), and a watch without `columns` refreshes on every `UPDATE` of the table. List each
  joined table with an `ids` query (or `key`), and add `columns` where the table is written often ([Column-aware filtering](sync.md#column-aware-filtering)).
- **Overlapping indexes share the triggers of a table (`queue` sync).** A table has one set of four triggers and one change log whatever the number of
  indexes watching it ([Sync](sync.md#one-trigger-per-table-one-change-log)). `sync: trigger` and `trigger_level: row` keep four triggers per index and
  table and refresh every index inside the writing statement. For a hot table prefer `sync: queue` and `columns`.
- **A federated search merges by rank only:** the best hit of each index comes first, whatever its score. Weight an index (`weight:`), or use one
  multi-source index when one common ranking matters more.
- **A database that does not allow triggers follows nothing by itself:** `sync: queue` and `trigger` fail at `schema --apply` (permission denied) and
  create nothing. Use `sync: manual` and a `fuzzphony:reindex` from cron or after an import, or `refresh()` for single documents.

### Several sources in one index

- **The stored key is text:** the sidecar key is `<kind>:<id>` (columns `kind` and `src_id` carry the parts), also for integer ids; `Hit::$id` is
  converted back with the source's `id_type`. Code that reads the sidecar table directly uses `kind` and `src_id`; `refresh('site', ['product:5'])` takes
  the key. There is no migration between the single and the multi-source layout (`schema --apply` then `reindex`).
- **Older Fuzzphony code does not read a multi-source index:** upgrade every process (web, worker, console) before the first one is applied; there is no
  schema-level downgrade.
- **An id repeated inside one source keeps one row.** Make the source's ids unique (`DISTINCT ON`, or aggregate).
- **A watch is per source:** a table a source reads without a `watch` is not followed, and the doctor warns about it (`Watches`). One `watch` per table
  per source; check it with a test that changes a row of each.

### Several values per document

A field or filter declared [`many`](configuration.md#several-values-per-document-many) is indexed from a child table. Things to know:

- **By default it ranks by the whole field, not the best value.** The values are indexed together as one text, so a customer with the matching word in
  one of many values ranks like one matching a longer text; one combined vector keeps every search exactly as fast as for a plain field. Declare the field
  [`rank: best`](configuration.md#several-values-per-document-many) (a partial index on the values table and a count column: `schema --apply`, then a
  reindex for the speed); a document whose words are spread over several values keeps its whole-field rank, and a typo-tolerant match is still scored on
  the whole field. Ranking a candidate with more than one value also reads its values that hold a query word (at most `limit`), so the [scan budget](#ranking-of-very-common-words)
  counts each such field like two more words of the query, and a search whose words are in the field costs about 1.3 to 1.9 times as much
  ([benchmarks](benchmarks.md#ranking-by-the-best-value-rank-best)).
- **A phrase can match across two values** (`"budapest vienna"` matches a customer whose values are `Budapest` and `Vienna`); [`manyMatch('single')`](searching.md#several-values-one-address-manymatch)
  keeps a phrase inside one value.
- **Only the first `limit` values of a document are indexed** (50 by default, in the order of the value, then of the child's primary key). The doctor
  reports the documents over the limit; raise `many.limit` and reindex if you need more. `manyMatch('single')` sees the same values.
- **`manyMatch('single')` reads the values of every candidate:** for each document the search's own words find, it checks the values of the named fields
  that hold a word of the query one by one; with two named fields every pair (up to `limit` x `limit` rows per document; at most 3 fields together), so a
  search whose words match many documents costs more than the default mode ([benchmarks](benchmarks.md#ranking-by-the-best-value-rank-best)). An excluded
  word is judged on the whole document. A typo-tolerant word must be similar to one value or one other field: a typo over a field boundary (`kisanna` for
  the name "Kiss" and the address "Anna utca 1") matches in the default mode only. Name only the field that must be one value
  (`manyMatch('single', 'addresses')`) and keep `limit` near the real number of values.
- **A [`same_row_as`](configuration.md#several-values-per-document-many) filter is coupled only to positive conditions, and only once indexed.** In
  `manyMatch('single')`, `where('city', 'Eger')` / `whereIn` hold for the address that holds the words, but `whereNull('city')` stays per customer; rows
  indexed before the option match the city of any address until a reindex; the filter's own `limit` still caps the per-customer list the search
  pre-filters on, so a city past it is not found even on the right row; a coupled search reads the values of each candidate in the city, and its facet is
  counted in a statement of its own. The city is stored per value row only by the refresh, never rewritten at apply (no long rewrite, no race). Run
  `fuzzphony:reindex` after adding the option (the doctor counts the rows that wait); keep the filter's `limit` at least the field's.
- **Filters on a many field are not hidden by `granted`:** a filter is a separate declaration and filters are never role-gated. Do not declare a many
  filter over a column a viewer may not see.

### One id column per document

A document is identified by one column (`id` by default) of an integer, UUID or string type, and the string `*` is reserved. A table with a composite key
needs a source query that builds one id (a string id type, for example `a || '-' || b AS id`).

### One unreadable document does not stop its index's queue

What happens: a document the source cannot read (a cast that fails on one row, a value too big for the index) makes the batch it is in fail. The worker
retries the batch in halves down to single ids, refreshes everything else and keeps only the failing ids queued, each with its attempt count and the error
class and SQLSTATE; a failing id is tried again after a back-off (60 s, doubling to an hour). The doctor's "Sync queue" check names the failing ids (up to
five, never the row values) and the worker prints only the exception class and SQLSTATE, because the driver's message can carry the source row (treat worker
logs as sensitive). A connection or schema problem (any other SQLSTATE) still stops the index's queue for the back-off. What to do: fix the source row or
the cast; the id leaves the queue at its next try (`fuzzphony:reindex <index>` rebuilds the documents too).

### A long writing transaction delays the sync of every index

What happens: with `queue` sync the triggers append to a shared change log, and an index reads an entry only once its transaction and every older one have
ended (so a transaction that commits late is never skipped). A transaction that wrote something and stays open (a long import in one transaction, a
forgotten `BEGIN` after a write) holds back the processing of changes committed after it, for every index, until it ends; nothing is lost. What to do: keep
writing transactions short, import in batches; the doctor shows the waiting entries and their age. `sync: trigger` is not affected.

### A full reindex needs room and a session connection

The zero-downtime reindex builds a second copy of the index next to the live one: it needs the disk space, a role that can create tables in Fuzzphony's
schema and owns the index table, and a session connection (it holds an advisory lock, so not a transaction-pooling PgBouncer). A role or a setup without
these reindexes in place (`--in-place`), and `fuzzphony:schema --apply` refuses to run while a rebuild is building: a deploy that applies the schema should
retry. See [Reindexing](sync.md#reindexing-and-orphan-pruning).

## Operations

### A search inside your own transaction

A typo-tolerant search sets `pg_trgm` thresholds for its statements. Inside a transaction you opened, it runs them under one savepoint
(`SAVEPOINT fuzzphony_search`, opened at the first threshold), sets each value once, puts your values back once at the end and releases the savepoint: three
or four extra round trips, on `PdoConnection` and the Doctrine DBAL bridge alike. The thresholds are transaction-local, so without putting them back they
would stay on until you commit; the savepoint keeps a failing search from aborting your transaction. What to do: nothing. A search that fails after its first
threshold rolls back to its savepoint and throws `EngineFailure`; your transaction stays usable. A statement that fails before any threshold (the strict
full-text statement of a plain search, which sets none) aborts your transaction, as any failing statement of yours does.

### The registry cache in production needs `cache:clear`

With `kernel.debug` off, the Symfony bundle keeps the `#[Searchable]` definitions it discovered in the `cache.system` pool for **web requests**. A change
to `#[Searchable]`, `#[SearchField]` or `#[SearchFilter]`, or to a `synonyms_file` an attribute names, is not seen by the web application until
`cache:clear`. The console (`fuzzphony:schema --apply`, `fuzzphony:reindex`, `fuzzphony:doctor`, the worker, a Messenger consumer) always discovers fresh
and never reads or writes the pool. In debug mode nothing is cached, and YAML indexes are never cached.

What to do: `cache:clear` as part of every deploy, so that web requests see the same definitions the schema was applied with. See [Attributes](configuration.md#attributes).

### Text search configuration caches assume the configuration does not change in a process

The engine remembers, per process, which words the text search configuration reduces to nothing (stop words), the stems of the synonym words and the
synonym lookup tables built from them (about 5 000 entries per configuration, then it starts over). A long-lived worker (FrankenPHP, RoadRunner, a
Messenger consumer) keeps them on purpose. It is right as long as the configuration and its dictionaries do not change while the process runs.

What to do: after an `ALTER TEXT SEARCH CONFIGURATION` or a changed dictionary, restart the long-running processes that search, or call
`Fuzzphony::resetCaches()`. A PHP-FPM request starts clean.

### `explain()` and its statement labels are not a stable API

`explain()` and `fuzzphony:search --explain` are for people. The SQL text, the number of statements, their labels (`full-text`, `full-text + fuzzy`,
`fallback: full-text + fuzzy`, `browse`, `facet: category_id, in_stock`, `did you mean`, `relaxation probe`, `highlighting`, `exact total`, and the same
labels with the prefix `rescue: `) and the plan lines change between releases. Do not parse them or build a test or an alert on them.

### A rescued search is not a zero-result search in the log

The [zero-result rescue](searching.md#zero-result-rescue) answers a search that found nothing with the results of its corrected spelling, and the
[search log](analytics.md#what-is-logged) records the text the visitor typed with the hits of the answer it got. So a typo such as `mpuse` is no longer in
the zero-result report (`SearchAnalytics::zeroResultQueries()`, `fuzzphony:analytics --zero`) while the rescue is on. The log has no flag for a rescued
search. Typos the rescue cannot answer still appear there. To see every text that found nothing on its own, set `rescue.correct_when_empty: false`; the
counter `fuzzphony.search.rescued` ([Observability](observability.md#instrumentation-points)) counts the rescued searches without their text.

### The search log is best-effort

The [search log](analytics.md) is for trends, not an audit trail. Its rows are written in one batch after the response; a write that fails (the table is
missing, the database is down) is counted in `fuzzphony.search_log.failed`, logged and dropped, never retried. A long-running worker writes its buffer every
1000 records and on every container reset, but a process killed with `SIGKILL` loses the (at most 1000) records it still holds. The `hits` of a search that
hit `limits.candidates` is a lower bound. Redaction removes what a regular expression can describe, nothing more, and the live search of the Live Component
is not logged unless you ask for it. The user of a row is the actor's (named, or the current user the resolver finds; `(system)` for `asSystem()`), and the
IP exists only when the actor carries one (the bundle adds it from the request). A log you must not lose is a custom `SearchLog` that writes somewhere
durable, which the built-in reports then do not read.

## Security and privacy

### Field authorization hides content, not existence or timing

Field-level authorization (`granted`) keeps the content of a hidden field out of what a restricted viewer can match, rank, highlight, count and be
suggested. It is not row-level security and not a covert-channel proof: the existence of a hidden field, timing, filters and facets, and the vocabulary of
unrestricted viewers are not hidden. **What to do:** read [Security: the limits, stated honestly](security.md#the-limits-stated-honestly) (the one place that
lists them) and do not put a secret where only a field-level rule protects it from being inferred.

### The client IP in the search log

`search_log.ip` is off by default. When it is on:

- `masked` is a risk reduction, not anonymisation: a masked IPv4 address (`/24`) still narrows a visitor to 254 addresses, and a masked IPv6 address
  (`/48`) is what a provider usually hands to **one customer site**, so it often identifies one household or office (the `/48` rule is fixed; use `off` if
  that matters).
- The address is `Request::getClientIp()`, so it is only as good as `framework.trusted_proxies` and `trusted_headers`: behind a proxy or load balancer
  without them every row carries the proxy's address. Fuzzphony never reads `X-Forwarded-For` itself.
- A search that does not come from an HTTP request (the console, a worker, a Messenger handler) carries no address. Anything that is not a valid IP
  address is not stored.

What to do: keep it off unless you use it, prefer `masked`, set the retention and the trusted proxies. See [The client IP](analytics.md#the-client-ip).

### A person's search history is personal data

`SearchAnalytics::recentSearches()` and `fuzzphony:analytics --recent` list who searched what, including the refused searches. On a tenant-scoped index they
need a tenant or an explicit `allTenants: true`, and a call that names a tenant never returns another tenant's rows. On an index that is **not**
tenant-scoped, a call without a tenant covers everyone, so name the tenant. Redaction is pattern matching, not a guarantee.

What to do: give the report to an operator role only, never pass a tenant or a user from request input without checking that the requester may see it,
keep `retention_days` short and run the purge. See [Who searched what](analytics.md#who-searched-what) and [Privacy](analytics.md#privacy-the-log-is-personal-data).

### The rate limit by IP is loose by design and only covers requests

`per: tenant` and `per: user` give an anonymous visitor no key, so they never limit a public search box; `per: ip` does (the bundle's Live Component and API
Platform filter search as an actor that carries the client address). Its limits:

- **A company network or NAT shares one address among many people**, so they share one bucket. The default (a sliding window of 600 searches a minute per
  address, prepended as `framework.rate_limiter.fuzzphony_ip`) is loose on purpose: it stops a script, not a person.
- **Without `framework.trusted_proxies` everyone behind a reverse proxy or CDN shares ONE bucket**; with it set too broadly, a visitor can name any client.
  `Request::getClientIp()` decides.
- **A call without a request is not limited by IP** (a plain PHP call, a console command, a worker, an actor built without `ip:`): Fuzzphony cannot limit
  what it does not see.
- **An IPv6 address is limited by its /64**; an address that embeds an IPv4 address (IPv4-mapped, IPv4-compatible, NAT64, 6to4) as that IPv4 address.
- **An address that is no IP address is not limited** (fail open) after the safe normalisations (whitespace, a port, brackets, a zone id); only an
  `ActorResolver` of your own can produce one. It is reported once per process (`fuzzphony.search.rate_limit_unkeyed`), never with the value.
- The address is read per request and not stored unless `search_log.ip` says so; the limiter's state lives in its cache pool (an HMAC of the key keyed with
  `kernel.secret`; rotating the secret resets the buckets), and the default filesystem pool locks and writes a file on every search.
- With `authorization` enabled, a request without a Security token is refused before the limit is looked at, so `per: ip` is the setting for a public
  search box, not for a signed-in-only one. `explain()` and `complete()` spend a token too.

What to do: set `trusted_proxies` if you run behind one, raise the limit if your visitors sit behind big NATs, use APCu or Redis for the limiter's pool, and
keep a limiter of your own in front of the endpoint for a stricter or per-route limit. See [Limiting anonymous visitors by IP](security.md#limiting-anonymous-visitors-by-ip).

### Authorization is deny-by-default

With `fuzzphony.authorization.enabled`, the default attribute `FUZZPHONY_SEARCH` with no voter denies every search, and a request without a Security token
is denied too, so only signed-in users can search; the Live Component and the API Platform filter search as an actor on every index they serve, so each
needs the index attribute granted. See [Authorization](security.md#authorization).

### Tenant isolation is enforced by the library, not the database

A tenant-scoped index is filtered by Fuzzphony's API on every search; a raw SQL query on the sidecar table is not. A `tenant()` added to an existing index
leaves the column empty until `fuzzphony:reindex` (the tenant searches find nothing for those rows: it fails closed). The vocabulary has no tenant column,
so a tenant-scoped index gives no "did you mean" and no completions.

What to do: add a PostgreSQL row-level security policy on the sidecar table for defense in depth. See [Multi-tenancy](multi-tenancy.md#what-it-guarantees).

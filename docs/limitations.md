# Known limitations

What Fuzzphony does not do, or does less well than you might expect, what happens instead, and what to do
about it. Back to the [README](../README.md).

Some of these are limits of the technique (trigrams, PostgreSQL's triggers), some keep the cost of a search
bounded, some protect the privacy of a log, and some are things to do when you deploy. Each entry says what
happens, why, and what to do. The statements here were checked against the code and the tests.

- [Search quality](#search-quality): [typo tolerance](#typo-tolerance-is-trigram-based), [whole words](#a-word-is-matched-as-a-whole-word),
  [stop words](#stop-words-are-ignored-and-only-a-query-of-nothing-else-finds-them), [languages](#stemming-covers-regular-forms-one-language-per-index),
  [candidate limit](#ranking-is-approximate-beyond-candidate_limit), [facet counts](#facet-counts-are-over-the-candidates),
  [synonyms](#synonyms-are-expanded-on-the-query-and-stop-there), [highlights](#highlights-run-the-source-query-and-mark-typo-matches-by-word),
  [scores](#scores-are-not-stable), [query limits](#long-queries-are-cut),
  [did you mean](#did-you-mean-and-completions-come-from-a-vocabulary-that-ages)
- [Data and sync](#data-and-sync): [partitions](#attaching-or-detaching-a-partition-is-not-followed), [sync modes](#what-each-sync-mode-sees),
  [document ids](#one-id-column-per-document), [full reindex](#a-full-reindex-needs-room-and-a-session-connection)
- [Operations](#operations): [registry cache](#the-registry-cache-in-production-needs-cacheclear),
  [process caches](#text-search-configuration-caches-assume-the-configuration-does-not-change-in-a-process),
  [explain()](#explain-and-its-statement-labels-are-not-a-stable-api), [search log](#the-search-log-is-best-effort)
- [Security and privacy](#security-and-privacy): [field authorization](#field-authorization-hides-content-not-existence-or-timing),
  [client IP](#the-client-ip-in-the-search-log), [per-person history](#a-persons-search-history-is-personal-data),
  [rate limit](#the-rate-limit-counts-tenants-and-users-not-addresses), [authorization](#authorization-is-deny-by-default),
  [tenants](#tenant-isolation-is-enforced-by-the-library-not-the-database)

## Search quality

### Typo tolerance is trigram-based

Typo tolerance is per word, and the similarity a word needs is proportional to its length (see
[Typo tolerance](searching.md#typo-tolerance)). Trigrams compare letters, not meaning, so they cannot tell a
typo from a different word that looks alike:

- `cable` also finds `table` (similarity 0.50, one letter replaced), and `mose` is as close to `monitor` and
  `mower` as to `mouse` (0.40 each): a search for `mose` lists all of them with the same score.
- The threshold keeps a different word at the worst case of a typo out (`mouse` / `monitor`, 0.33), and that
  costs some real typos: a letter replaced in the middle of a 5 to 7 letter word, or two letters swapped, may
  not be tolerated (`mpuse`, `mosue`; the same swap at the end of a word, `torhc`, is). "Did you mean" can
  still catch some of them (`mpuse` suggests `mouse`).
- It reaches only the words of fuzzy fields (`fuzzy: true`): a misspelled word that occurs only in a
  description or another non-fuzzy field finds nothing. Words shorter than `fuzzy_min_length` (3) must match
  exactly.
- By default (`fuzzy_mode: fallback`) it runs only when exact matching finds fewer than `fallback_below` (5)
  hits, so a typo next to enough exact hits is not corrected. `always` runs it every time, `never` switches
  it off.

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

- Only a query whose every word is a stop word. One real word and the stop words around it are ignored as
  before, and so is a query that excludes a real word (`the who -rock`: its full-text branch is "everything
  but rock", which is left as it is).
- Only with typo tolerance: the index has a typo-tolerant field and `fuzzy_mode` is not `never` (the ranking
  profile's `fuzzy` weight is not 0). Without it nothing changes and the query finds nothing.
- Only in the typo-tolerant fields, and as a look-alike, like every typo-tolerant word: `who` ranks "Who" and
  "The Who" first and also lists `whole`. Each word must be at least `fuzzy_min_length` (3) letters long: a
  shorter stop word has to match exactly, which a stop word never does, so `to be or not to be` without
  quotes finds nothing (the quoted phrase is one word of 14 letters and finds it).
- It costs a typo-tolerant search: one probe per word, at most `candidate_limit` candidates each, so the work
  is bounded even when a stop word is in half of the names. Measured on 100 000 products with `the` in half of
  the names: 43 ms for `the` and 109 ms for five stop words (a plain word such as `wireless` takes 8 ms);
  `candidate_limit` applies as always (the total is a lower bound).
- An excluded stop word (`the -who`) is still dropped. The empty-result relaxation works as for any words.

What to do: for names, titles or codes use `language: simple` (no stemming and no stop words), in an index of
their own. See [Languages](languages.md#what-the-language-decides).

### Stemming covers regular forms, one language per index

Stemmers reduce regular endings only: `mice` does not find `mouse`, `went` does not find `go`. An index
analyses its text in one language; a multilingual catalogue is one index per language, searched by the user's
locale. See [Languages](languages.md#limits).

### Ranking is approximate beyond `candidate_limit`

Each branch of a search (full text, typo-tolerant) collects at most `candidate_limit` candidates (2000 by
default, at most 10 000) and ranks only those. When a word matches more documents than that, the candidates
are the first matches PostgreSQL's index returns, **not the best ones**, so the document that would rank first
can be missing from the results altogether, not only ranked lower. Measured: among 3 000 documents with the
word `common`, one titled exactly `common` (which earns the exact-title bonus) added last is not in the results
at the default limit. In the typo-tolerant branch 3 000 weaker look-alikes crowd out the closest spelling the
same way. On a catalogue of 200 000 products a plain word such as `wireless` already hits the limit (see
[Benchmarks](benchmarks.md)).

`total` is then a lower bound (`totalIsLowerBound`, shown as `2000+`), and so is the `hits` of the
[search log](analytics.md) row. `exactCounts()` counts everything the query matches, for the total and the
facets, but the page of hits is still the ranked candidates.

Why: it keeps the latency of a search independent of how common its words are
([ADR 0005](adr/0005-candidate-limit.md)).

What to do: make the query more specific (more words, a filter, a field scope), or raise `candidate_limit`
(per index or per query, up to the cap of 10 000). Use `exactCounts()` only on queries you control (never wire
it to request input): it reads every match and can take seconds on a large index.

### Facet counts are over the candidates

The counts of `facets()` are taken over the same candidates as `total`: when the search hit its candidate
limit they are lower bounds ("120+"; the Live Component prints the `+`). With `exactCounts()` every match is
counted: in the example above, a limit of 10 gave 4, 3 and 3 for three categories, and the exact counts were
1 001, 1 000 and 1 000. See [Facets](searching.md#facets).

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
`headphones`) is not marked, and a hit can have no mark although it was found. Only typo-tolerant fields get
these marks (a field that is not `fuzzy` shows ts_headline's snippet), and only the first 2000 words of a
field are looked at. A hit that the strict full-text query also matches keeps the highlight ts_headline gives
it, even when a typed word was found in it by typo only. See [Highlighting](searching.md#highlighting).

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
`relaxation probe`, `exact total`) and the plan lines change between releases (0.9.5 merged the facet
statements into one). Do not parse them or build a test or an alert on them.

### The search log is best-effort

The [search log](analytics.md) is for trends, not an audit trail. Its rows are written in one batch after the
response; a write that fails (the table is missing, the database is down) is counted in
`fuzzphony.search_log.failed`, logged and dropped, never retried. A long-running worker writes its buffer every
1000 records and on every container reset (between two messages or requests), but a process killed with
`SIGKILL` loses the (at most 1000) records it still holds. The `hits` of a search that hit `candidate_limit` is a
lower bound (see [above](#ranking-is-approximate-beyond-candidate_limit)). Redaction removes what a regular
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

### The rate limit counts tenants and users, not addresses

A search without an actor, or with an actor that has no key (`per: user` and no user), is not limited, so an
anonymous public search box has no limit from Fuzzphony. `explain()` and `suggest()` spend a token too.

What to do: put a limiter of your own, by IP, in front of the controller. See
[the rate limit](security.md#the-rate-limit).

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

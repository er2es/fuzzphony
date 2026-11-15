# Benchmarks

How Fuzzphony compares with a naive `ILIKE`, and how to measure it yourself. Back to the [README](../README.md).

## Method

`benchmarks/seed.sql` generates a catalogue (products × brands × categories). `benchmarks/run.php` compares a naive `ILIKE` with Fuzzphony: the first ("cold")
run and the median of the next 5 ("warm"), 20 results each.

```bash
FUZZPHONY_BENCH_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony_bench;user=fuzzphony;password=fuzzphony" php benchmarks/run.php --setup
```

`benchmarks/run.php` drops and reseeds the `bench_*` tables, so it refuses to run unless `FUZZPHONY_BENCH_DSN`'s database name contains "bench" or "test":
point it at a disposable database, never a real one. The [Benchmark workflow](https://github.com/er2es/fuzzphony/actions/workflows/benchmark.yml) runs it on
every push to `main` and publishes the current table in its job summary.

## Example run

200 000 products, PostgreSQL 17 in a local container, one laptop; the output of `run.php --markdown`:

| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) |
|---|---|---:|---:|---:|---:|
| plain word | `wireless` | 6.5 / 1.3 ms | 20 | 458.4 / 35.3 ms | 20 (2000+) |
| two words | `wireless mouse` | 3.7 / 3.1 ms | 20 | 28.1 / 10.4 ms | 20 (1666) |
| accent | `creme` | 190.0 / 162.6 ms | **0** | 489.3 / 43.1 ms | 20 (2000+) |
| typo | `hedphones` | 178.4 / 171.7 ms | **0** | 71.0 / 41.8 ms | 20 (2000+) ~ |
| stemming | `drills` | 172.9 / 171.1 ms | **0** | 37.9 / 39.8 ms | 20 (2000+) |
| phrase + exclusion | `"noise cancelling" -headphones` | 1.8 / 1.1 ms | 20 | 91.9 / 84.5 ms | 20 (2000+) |
| filter + text | `kettle` | 2.3 / 1.8 ms | 20 | 20.1 / 15.8 ms | 20 (2000+) |

`~` means the typo-tolerant fallback ran. The first ("cold") run reads the table from disk; a word that matches more documents than `limits.candidates`
costs one more pass (see [Common words](#common-words)), which is most of the 35 to 45 ms of the rows with a total of `2000+`. Single-digit milliseconds
on a shared laptop are noise.

The paths that cost more than a plain word (`run.php` prints them below the table; the long text is a second index with five typo-tolerant copies of the
description, about 300 characters of typo-tolerant text a document), warm:

| path | query | ms |
|---|---|---:|
| typo, a filter and a facet | `hedphones` | 76 |
| typo, restricted viewer (name, brand) | `hedphones` | 159 |
| typo, 16 mistyped words OR | `hedphones OR kettel OR ...` | 260 |
| typo, page 101 (offset 2000) | `hedphones` | 48 (20 hits) |
| strict, page 101 | `wireless` | 43 |
| typo, long typo-tolerant text | `batery` | 539 |
| the same, restricted viewer (name, description) | `batery` | 1 100 |
| an exact word beside look-alikes | `mousse` | 42 (exact matches first) |

A restricted viewer pays for the ordered selection that keeps hidden fields out of the candidates (see [Known limitations](limitations.md#ranking-of-very-common-words));
everyone else's typo search costs what it did. On a database where some documents hold thousands of words in a field the viewer cannot see (3 000
documents, 60 of them with 17 000 words, `limits.candidates` 100) the restricted `comon` takes about 1 s: a hidden text is rechecked in full.

## Round trips

A search is mostly waiting on the network when the database is not on the same machine, so the number of statements it sends matters as much as the time
PostgreSQL spends on them. Measured on a 100 000-row catalogue, PostgreSQL 17, through `PdoConnection`:

| case | statements |
|---|---:|
| plain word (`wireless`), two words | 1 |
| plain word with highlight | 2 |
| plain word with 2 facets (also with an active filter) | 3 |
| typo, fallback (`hedphones`) | 8 |
| typo, 2 words (`wireles mose`) | 10 |
| no hit, did-you-mean (`zzqxy`) | 8 |
| `typo.mode: always` (`wireles`) | 7 |
| relaxed empty result (`wireless zzqxyk`) | 11 |

A typo-tolerant search runs everything from its first statement that sets a similarity threshold in one transaction that the engine opens; the threshold is
set per statement, once when two statements want the same value, and the check that the vocabulary table is readable is asked once per process. Inside a
transaction of your own the statements share one savepoint and your values are put back once ([limitations](limitations.md#a-search-inside-your-own-transaction)).
Multiply by your network latency: on a database 1 ms away the round trips alone are 4 ms for a plain word and 12 ms for the word with two facets.

Count them yourself on a disposable database: `ALTER SYSTEM SET log_statement = 'all'; SELECT pg_reload_conf();`, run one search, and count the logged
lines (a `BEGIN`, `COMMIT`, simple statement or `execute ...`). Reset with `ALTER SYSTEM SET log_statement = 'none'`.

## Common words

A word that matches more documents than `limits.candidates` (2000) costs one more pass: the full-text branch scores the matches and keeps the best ones by
the key the page is ordered by (see [Ranking](ranking.md#which-candidates-are-ranked)), over at most 100 000 matches (the scan budget). A word under the
limit costs what it always did (about 0.15 ms more to plan). Median of 9 to 15 runs of the warm statement through `PostgresEngine`, 20 hits, PostgreSQL 17
in a container with the default `shared_buffers`; the catalogue is the benchmark's, with ten planted documents (`Wireless`, `wireless`,
`Wireless wireless wireless`, ...) that are the best matches and sit at the end of the table. "Before" is the earlier cheap selection
(the first matches the index returned), "after" the ranked candidates (current):

| case | 200 000 rows before / after | 1 000 000 rows before / after |
|---|---:|---:|
| rare word (`1234`) | 1.2 / 1.4 ms | 1.4 / 1.8 ms |
| medium (`wireless noise`) | 11 / 20 ms | 17 / 64 ms |
| common (`wireless`: 20 005 / 100 005 matches) | 10 / 38 ms | 23 / 290 ms |
| very common (`ideal`: every document) | 15 / 104 ms | 50 / 170 ms |
| two common words (`ideal gaming`) | 14 / 97 ms | 33 / 178 ms |
| common word and `in_stock` filter | 10 / 37 ms | 23 / 237 ms |
| common word with two facets | 20 / 50 ms | 46 / 275 ms |
| page 5 (offset 80) | 10 / 37 ms | 21 / 239 ms |
| page 101 (offset 2000, exact) | 10 / 38 ms | 21 / 242 ms |
| restricted viewer (name, brand), `wireless` | 12 / 46 ms | 26 / 279 ms |
| typo-tolerant (`wireles`) | 47 / 49 ms | 87 / 91 ms |

**Queries that look up many words.** The cost of ranking a document grows with the words of the query, so the scan budget is divided by their number (see
[Known limitations](limitations.md#ranking-of-very-common-words)) and the excluded words are not looked up for the rank. Median of 3 runs, `typo.mode: never`:

| query | 200 000 rows | 1 000 000 rows |
|---|---:|---:|
| `ideal` | 105 ms | 178 ms |
| `ideal` and 15 exclusions | 172 ms | 502 ms |
| 16 common words OR | 197 ms | 400 ms |
| the same, restricted viewer | 241 ms | 583 ms |
| `ideal` written 16 times | 291 ms | 813 ms |
| `ideal` and 15 prefixes OR | 343 ms | 960 ms |
| 4 common words OR | 48 ms | 110 ms |

What is left is mostly the index scan that finds the matches, which the budget does not shorten, plus the ranking of up to 100 000 matches. The scan
budget is what bounds the 1 000 000-row column (without it `ideal` costs about 950 ms there); the cost is about 1 microsecond per scanned match for one
word. A restricted viewer's search of a word that is common in a field the viewer cannot see is slow for a different reason
([Known limitations](limitations.md#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field)). The figures are warm; the first run after a
restart reads the table from disk.

### Typo words and facets of a capped search

A typo word that matches more documents than `limits.candidates` (`mouss` is 20 000 look-alikes on 200 000 products) contributes the first
`limits.candidates` matches of its trigram index, cheap and a lower bound ([Known limitations](limitations.md#ranking-of-very-common-words)); a full-text
match that is not among them keeps its typo score. The facets of a capped search count the ids the statement of the hits returns. Median of 7 runs, warm,
`limits.candidates` 2000, 200 000 products:

| search | ms |
|---|---:|
| `mouss`, fallback / always | 47 / 43 |
| `wireles`, fallback / always | 51 / 52 |
| `hedphones`, fallback | 48 |
| `wireles mose` (two typo words) | 92 |
| `mouss`, restricted viewer (name, brand) | 141 |
| `mouss` with a facet / and an active filter | 46 / 67 |
| `screwdrivr 1234` (two words, under the limit) | 37 |
| `wireless`, `typo.mode: always` | 94 |
| `ideal`, `typo.mode: always` | 131 |
| `wireless` with a facet / and an active filter (strict) | 46 / 79 |
| a filter-only listing with a facet | 2.4 |
| 12 typo-tolerant-field index (`bench12`), `batery` | 208 |
| the same, restricted viewer (name, description) | 487 |
| the same index, the reversed phrase `"office ideal"` | 274 |

`wireless` in `typo.mode: always` pays for the typo score of its 2 000 full-text candidates (rescored when the typo branch is capped, about 10 microseconds
each); the restricted viewer pays for the ordered selection, about 8 microseconds per scored match for a short name and more for a long text. The typo
statement of a word under the limit is the one it always was.

## A restricted viewer whose hidden fields are full of the words searched

An index with four typo-tolerant fields: `name` and `category` open, `brand` and `description` granted (`benchmarks/run.php`, the `bench_granted` index; the
description is full of very common words). A viewer who holds neither granted field looks its words up in the two open-class indexes instead of `fz` / `tsv`
(see [Known limitations](limitations.md#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field)). Median of five runs after one warm-up in
`typo.mode: always` (one run when it takes longer than 8 s), PostgreSQL in a container on a shared development machine with the default 128 MB of shared
buffers; indicative, not a benchmark of the hardware.

| viewer and search | 200 000 rows | 1 000 000 rows |
|---|---:|---:|
| name + category, `battery` (hidden) | 4.6 ms | 5.5 ms |
| the same, 3 hidden words | 172 ms | 387 ms |
| the same, 5 hidden words | 186 ms | 394 ms |
| the same, phrase `"battery life"` | 4.6 ms | 5.5 ms |
| the same, rare word `lenvo` | 4.4 ms | 5.5 ms |
| the same, typo + hidden word | 7.3 ms | 8.6 ms |
| the same, a common word the viewer sees (`kitchen`) | 193 ms | 477 ms |
| the same, 3 words the viewer sees | 91 ms | 542 ms |
| + brand (some granted fields), 3 words | 10 943 ms | 95 814 ms (same statement, noise) |
| + brand, phrase / `kitchen` | 2 259 / 971 ms | 12 845 / 1 167 ms |
| name + category + description (brand hidden): `keyb*` | 1 072 ms | 463 ms |
| the same, phrase / typo | 1 427 / 757 ms | 1 462 / 1 623 ms |
| unrestricted, typo / 3 common words | 1 174 / 638 ms | 1 453 / 955 ms |

A viewer who holds a granted field has the statements it always had (byte for byte); the differences in those rows are the machine's. Size and build time
(`fuzzphony:schema --apply`, `CREATE INDEX CONCURRENTLY`, both indexes):

| | 200 000 | 1 000 000 |
|---|---:|---:|
| existing indexes (pkey, filters, `fz`, `tsv`) | 55 MB | 180 MB |
| open-class trigram index / full-text index | 11 MB / 2.7 MB | 52 MB / 10 MB |
| growth | +25 % | +34 % |
| build time | 2 s | 12.5 s |

The indexes are used only when both exist, are valid and each is smaller than the one it replaces; an index with no granted field has none. The trigram one
is built only when a granted field is fuzzy (otherwise it would hold the trigrams of `fz`). On 200 000 ERP customers:

| Index (no granted field fuzzy) | open-class trigram index not built | table + indexes with it | without it | restricted viewer, median ms with / without |
|---|---:|---:|---:|---:|
| `people2` (`name`, `contact` fuzzy; `address`, `email` granted) | 12.2 MB | 171.0 MB | 158.8 MB | 57.0 / 29.8 (`name`), 79.3 / 38.1 (`name`, `contact`) |
| `mails` (`name` fuzzy; `email`, `tax_no` granted) | 7.7 MB | 101.3 MB | 93.6 MB | 28.7 / 24.9 |

Results, scores, totals, facets and highlights are identical for every viewer (299 or 274 searches per viewer); an index with a fuzzy granted field
(`people`) keeps both open-class indexes and every statement.

## Reading the numbers

The two columns do different work. `ILIKE … LIMIT 20` returns the first 20 rows the scan reaches, unranked, and cannot exclude words: its 20 hits for the
exclusion query include headphones. So `ILIKE` is fastest when the word is common, and finds nothing for accents, typos or other word forms, after
scanning the whole table. Fuzzphony ranks every result and handles those cases in about 10 to 90 ms on 200 000 products (a typo word 25 to 75 ms; a wide
index of long typo-tolerant text 0.2 to 0.6 s). Measure on your own data. The demo's Speed view runs this benchmark in the browser, and
[demo/README.md](../demo/README.md#measured) has results on 200 000 and 500 000 rows.

## What each option costs in disk

Measured on the demo database: 499 978 products, the `catalog` index (fields `name`, `brand`, `category`, `description`; `name` and `brand` fuzzy; five
filters). The sidecar table is 342 MB: 265 MB of heap (about 530 bytes a row) and 77 MB of indexes. Sizes in MB are PostgreSQL's (1 MB = 1 048 576 bytes).

| What | Option | Per row | On 499 978 rows |
|---|---|---|---|
| `tsv` column | the combined full-text vector of all fields (always) | 151 B | 72 MB |
| `t_name`, `t_brand`, `t_category`, `t_description` | one full-text vector per field (exact field scoping, restricted viewers) | 48 + 31 + 20 + 98 = 197 B | 94 MB |
| `fz` column | the trigram text of the fuzzy fields (`fuzzy: true`) | 32 B | 15 MB |
| `z_brand` | one trigram text per fuzzy field (`z_name` is stored empty, see below) | 11 B | 5 MB |
| `exact` column | the exact-match text | 21 B | 10 MB |
| index on `fz` | the trigram index behind typo tolerance | | 31 MB |
| index on `tsv` | the full-text index | | 13 MB |
| primary key | | | 11 MB |
| five filter indexes | one btree per filter | | 23 MB |
| open-class indexes | only for an index with a `granted` field: a trigram and a full-text index over the open fields | | +25 to 34% of the two indexes they sit beside |

Reading it: the stored `tsv` costs about 28% of the heap row, and a SQL-function variant that avoids it made common searches 25 to 49% slower
([why it stays](architecture.md#why-the-combined-vector-is-stored)). The per-field columns are what make field scoping exact and a restricted viewer's search
as fast as an unrestricted one. A field with `fuzzy: false` has no `z_` column, and an index without `fuzzy` fields has neither `fz` nor its index. The
open-class indexes are built only when a field is `granted`.

A per-field column whose value another column of the row holds is stored empty: `z_<field>` of the primary field (= `exact`, so the catalogue above stores
no `z_name` text), of the only fuzzy field (= `fz`), and `t_<field>` of an index of one field (= `tsv`); a short text `field_map` (`z_name=exact`) says which
rows may be read that way ([architecture](architecture.md#sidecar-table)). Results are unchanged and the timings within noise. On 200 000 ERP customers, the
index tables after a full reindex (heap + TOAST + indexes), with and without the saving:

| Index | Stored empty | without | with |
|---|---|---|---|
| `cust` (one field, `name` fuzzy) | `t_name`, `z_name` | 47 MB (33 MB heap) | 43 MB (29 MB heap) |
| `people` (`name` A fuzzy, two fuzzy many fields, `email`) | `z_name` | 210 MB (130 MB heap) | 210 MB (130 MB heap) |
| `orders` (`number` A code, `customer_name` fuzzy) | `z_customer_name` | 59 MB (35 MB heap) | 60 MB (35 MB heap) |
| `mails` (`name` fuzzy, two granted fields) | `z_name` | 105 MB (60 MB heap) | 105 MB (59 MB heap) |

A one-field index saves about 10%; where only a short `z_` copy goes, the map text eats what it saves. The backfill after a definition change took 3.2 s for the 200 000 `cust` rows; the apply then runs `VACUUM (ANALYZE)` on the table, which makes the space reusable, and only `fuzzphony:reindex` gives it back.

## Several values per document (`many`)

100 000 customers with 3 addresses each, the same data as a `many` field (child table, values table) and as a `string_agg` single field; median of 25
searches, limit 20, typo-tolerant fields, PostgreSQL 17 in Docker, `sync: trigger`.

| Search | many | string_agg |
|---|---|---|
| strict, common word (`budapest`, 2 000 matches) | 21.7 ms | 20.2 ms |
| strict, two words | 20.0 ms | 21.0 ms |
| rare word | 83.6 ms | 78.7 ms |
| typo (`budapset`) | 132 ms | 135 ms |
| typo, two words | 111 ms | 110 ms |

The searches read the same sidecar columns, so they cost the same. The price is on the write side and in size: refreshing one customer takes 1.38 ms against
1.21 ms (it also rewrites its rows of the values table), a full reindex 7.9 s against 6.6 s, and the values table is 37 MB next to the 50 MB sidecar.
Indexes without a `many` field are unchanged: the generated DDL is byte-identical and 1 854 searches compare identical with the previous release.

### Ranking by the best value (`rank: best`)

In one line: a search whose words are in a `rank: best` field costs **1.3 to 1.9 times** a `rank: whole` search (on 54 000 matches of a common word about
50 ms more), a search whose words are elsewhere 1.0 to 1.15 times, and the match set, total and facets never change. It is the price of ranking each value of
every candidate; the option is per field, so only the fields where it matters pay it.

200 000 customers of the demo's ERP data (`demo/sql/erp.sql`, 1.5 addresses each, each address one value `zip city, street`), one index (name, address as a
granted many field, contacts as a many field, email) searched through `rank: whole` and `rank: best` definitions of the same tables; median of 30 warm
searches, limit 20, PostgreSQL 17 in Docker on a laptop. The partial GIN index of the field is 8.5 MB next to the 58 MB values table (300 000 values).

| Search | whole | best | best / whole |
|---|---|---|---|
| `kiss` (no word in the field) | 27.6 ms | 28.8 ms | 1.05 |
| `nagy anna budapest` | 2.1 ms | 2.3 ms | 1.05 |
| `fo utca szeged` (980 matches) | 9.3 ms | 15.5 ms | 1.67 |
| `fo utca szeged`, restricted to name + address | 10.1 ms | 16.0 ms | 1.58 |
| `kossuth lajos utca` (13 333 matches) | 86.5 ms | 118 ms | 1.36 |
| `address:szeged` (12 745 matches) | 41.8 ms | 80.3 ms | 1.92 |
| `"petofi sandor"` | 51.5 ms | 91.3 ms | 1.77 |
| `budapest` (53 923 matches) | 96.4 ms | 145 ms | 1.51 |
| `kossuth la`, as you type | 130 ms | 174 ms | 1.34 |
| `szgeed fo`, typo | 53.4 ms | 54.7 ms | 1.02 |

A search whose words are not in the field costs the same (the field's own vector says so before the values are read), and so does a document with one value
(its stored count says so). Every other full-text candidate costs a primary-key probe of the values table and one ranking per matching value, about as much
as two more words of the query, which is what the scan budget counts for such a field. A typo-tolerant match is ranked on the whole field and costs nothing
more. Each match is ranked once: the cheap selection of the first matches and the ordered selection read one scan.
Since 0.12.7 a multi-word search (no phrase, not OR only) does not rank a value that lacks one of its words when no other field holds a query word (such a value cannot give a cover): `fo utca szeged` 15.8 to 12.6 ms, `kossuth lajos utca` 7.5 % less, `petofi sandor` (every value holds both words) 1 to 3 % more; results identical. Ranking only the best candidates with the whole-field rank as a bound is not exact and is not used.

`manyMatch('single')` on the same data (default mode = `rank: best` above, measured in the same run; median of 20):

| Search | best | single | single / best | hits best / single |
|---|---|---|---|---|
| `kiss` (no word in the field) | 34.0 ms | 34.3 ms | 1.01 | 2 000 / 2 000 |
| `fo utca szeged` | 16.9 ms | 14.2 ms | 0.84 | 980 / 588 |
| `address:szeged address:kossuth` | 16.0 ms | 14.1 ms | 0.88 | 1 374 / 982 |
| `budapest` (one word: no check needed) | 149 ms | 153 ms | 1.03 | 2 000 / 2 000 |
| `kossuth lajos utca` | 145 ms | 166 ms | 1.14 | 2 000 / 2 000 |
| `"petofi sandor"` | 114 ms | 150 ms | 1.32 | 2 000 / 2 000 |
| `kossuth la`, as you type | 195 ms | 223 ms | 1.15 | 2 000 / 2 000 |
| `szgeed fo`, typo | 56.3 ms | 65.0 ms | 1.15 | 1 372 / 980 |

The full-text check costs nothing for a document with one value or whose words are all in other fields (the empty row is tried first), and nothing for a
query of one word; the typo check always reads the values (a typo word must not match over a field boundary). Only the values that hold a word of the query
take part, so a second named field whose values hold none adds almost nothing (1 000 documents of 50 addresses and 50 tags, `alpha beta` where no address
holds both: address + tag 48 ms, address alone 33 ms). Otherwise it reads the candidate's values of the field once more; a search that keeps fewer documents
can be faster than the default mode, because fewer are ranked.

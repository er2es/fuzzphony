# Benchmarks

How Fuzzphony compares with a naive `ILIKE`, and how to measure it yourself. Back to the
[README](../README.md).

## Method

`benchmarks/seed.sql` generates a catalogue (products × brands × categories).
`benchmarks/run.php` compares a naive `ILIKE` with Fuzzphony: the first ("cold") run and the median
of the next 5 ("warm"), 20 results each.

```bash
FUZZPHONY_BENCH_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony_bench;user=fuzzphony;password=fuzzphony" php benchmarks/run.php --setup
```

`benchmarks/run.php` drops and reseeds the `bench_*` tables, so it refuses to run unless
`FUZZPHONY_BENCH_DSN`'s database name contains "bench" or "test" (case-insensitive substring
check) -- point it at a disposable database, never a real one.

The [Benchmark workflow](https://github.com/er2es/fuzzphony/actions/workflows/benchmark.yml) runs
it on every push to `main` and publishes the current table in its job summary.

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

`~` means the typo-tolerant fallback ran. The first ("cold") run reads the table from disk; a word that matches more
documents than `candidate_limit` costs one more pass (see [Common words](#common-words)), which is most of the 35 to 45 ms
of the rows with a total of `2000+`. The typo-tolerant statement rechecks each word against the similarity of its own length;
on 200 000 rows the plans keep using the trigram indexes. Single-digit milliseconds on a shared laptop are noise.

The paths that cost more than a plain word (`run.php` prints them below the table; the long text is a second index with five typo-tolerant
copies of the description, about 300 characters of typo-tolerant text a document), 0.9.8 and now, warm:

| path | query | 0.9.8 | now |
|---|---|---:|---:|
| typo, a filter and a facet | `hedphones` | 79 ms | 76 ms |
| typo, restricted viewer (name, brand) | `hedphones` | 41 ms | 159 ms |
| typo, 16 mistyped words OR | `hedphones OR kettel OR ...` | 238 ms | 260 ms |
| typo, page 101 (offset 2000) | `hedphones` | 46 ms, **0 hits** | 48 ms, 20 hits |
| strict, page 101 | `wireless` | 33 ms | 43 ms |
| typo, long typo-tolerant text | `batery` | 483 ms | 539 ms |
| the same, restricted viewer (name, description) | `batery` | 358 ms | 650 ms |
| an exact word beside look-alikes | `mousse` | 45 ms | 42 ms (exact matches first) |

A restricted viewer pays for the ordered selection that keeps hidden fields out of the candidates (see
[Known limitations](limitations.md#ranking-of-very-common-words)); everyone else's typo search costs what it did. On a database
where some documents hold thousands of words in a field the viewer cannot see (3 000 documents, 60 of them with 17 000 words,
`candidate_limit` 100) the restricted `comon` takes 0.45 s, 0.1 s in 0.9.8, and without the work budget 4 s.

## Round trips

A search is mostly waiting on the network when the database is not on the same machine, so the number of
statements it sends matters as much as the time PostgreSQL spends on them. Measured on a 100 000-row catalogue,
PostgreSQL 17, a local container (latency about 0), through `PdoConnection`:

| case | before 0.9.5 | now |
|---|---:|---:|
| plain word (`wireless`) | 5 | 1 |
| two words | 5 | 1 |
| plain word with highlight | 8 | 2 |
| plain word with 2 facets | 15 | 3 |
| plain word, 2 facets and an active filter | 15 | 3 |
| typo, fallback (`hedphones`) | 35 | 8 |
| typo, 2 words (`wireles mose`) | 43 | 10 |
| no hit, did-you-mean (`zzqxy`) | 35 | 8 |
| `fuzzy_mode: always` (`wireles`) | n/a | 7 |
| relaxed empty result (`wireless zzqxyk`) | n/a | 11 |

Before, PDO prepared every statement on the server (Parse, Bind/Execute, `DEALLOCATE`) and every search ran inside
a `BEGIN` / `COMMIT`. Since 0.9.7 a typo-tolerant search runs everything from its first statement that sets a similarity
threshold (the per-word candidate probes, the ranked statement, the relaxation, the facets and the did-you-mean lookup) in
one transaction that the engine opens, instead of one per statement (0.9.6 measured: 14, 18, 14, `always` 13, relaxed 18). The
threshold is still set per statement, once when two statements want the same value, and the check that the vocabulary table
is readable is asked once per process. Inside a transaction of your own nothing changes. Multiply the difference by your network latency to know what it saves: on a local database
the time per search was a little lower in every run we made (a plain word 11.6 to 8.5 ms, `wireles mose` 85.8 to
74.7 ms on one laptop; single runs of 5 repetitions, so treat them as indications, not as a promise). On a database
1 ms away the saved round trips alone are 4 ms for a plain word and 12 ms for the word with two facets.

Count them yourself on a disposable database: `ALTER SYSTEM SET log_statement = 'all'; SELECT pg_reload_conf();`, run
one search, and read the server log: each logged line is one `BEGIN`, `COMMIT`, `DEALLOCATE`, simple statement or
`execute <unnamed>` / `execute pdo_stmt_...` (the last form is a prepared statement, and its Parse is not logged, so
it is one more round trip). Reset with `ALTER SYSTEM SET log_statement = 'none'`.

## Common words

A word that matches more documents than `candidate_limit` (2000) costs one more pass: the full-text branch scores the
matches and keeps the best ones by the key the page is ordered by (see [Ranking](ranking.md#which-candidates-are-ranked)),
over at most 100 000 matches (the scan budget). A word under the limit costs what it always did (planning takes about 0.15 ms
more). Median of 9 to 15 runs of the warm statement through `PostgresEngine`, 20 hits, one laptop, PostgreSQL 17 in a
container with the default `shared_buffers`; the catalogue is the benchmark's, with ten planted documents (`Wireless`,
`wireless`, `Wireless wireless wireless`, ...) that are the best matches and sit at the end of the table:

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
| page 101 (offset 2000, now exact) | 10 / 38 ms | 21 / 242 ms |
| restricted viewer (name, brand), `wireless` | 12 / 46 ms | 26 / 279 ms |
| typo-tolerant (`wireles`) | 47 / 49 ms | 87 / 91 ms |

**Queries that look up many words.** The cost of ranking a document grows with the words of the query, so the scan budget is
divided by their number (see [Known limitations](limitations.md#ranking-of-very-common-words)) and the excluded words are not
looked up for the rank. Median of 3 runs, `fuzzy_mode: never`; the columns are 0.9.7, the first 0.9.8 build (a budget of rows, not of work)
and this one:

| query (200 000 / 1 000 000 rows) | 0.9.7 | first 0.9.8 build | now |
|---|---:|---:|---:|
| `ideal` | 15 / 56 ms | 108 / 161 ms | 105 / 178 ms |
| `ideal` and 15 exclusions | 118 / 275 ms | 3 270 / 3 350 ms | 172 / 502 ms |
| 16 common words OR | 69 / 178 ms | 1 600 / 1 630 ms | 197 / 400 ms |
| the same, restricted viewer | 88 / 258 ms | 1 780 / 1 870 ms | 241 / 583 ms |
| `ideal` written 16 times | 118 / 370 ms | 2 060 / 2 300 ms | 291 / 813 ms |
| `ideal` and 15 prefixes OR | 127 / 443 ms | 2 160 / 2 590 ms | 343 / 960 ms |
| 4 common words OR | 15 / 48 ms | 112 / 165 ms | 48 / 110 ms |

What is left is mostly the index scan that finds the matches (a bitmap of every document that has one of the words), which the
budget does not shorten, plus the ranking of up to 6 250 to 100 000 matches.

The scan budget is what bounds the 1 000 000-row column: without it `ideal` costs 160 ms at 200 000 rows and about
950 ms at 1 000 000, and `ideal gaming` 510 ms. The cost is about 1 microsecond per scanned match for one word, so the
budget is a trade between the worst case and the share of matches that are ranked: at 1 000 000 rows a budget of 10 000
costs 60 to 115 ms, 50 000 about 160 to 240 ms, 100 000 (the one used) 170 to 330 ms, 200 000 300 to 630 ms (these four were measured while other jobs ran on the machine: indicative). With the
planted documents at the end of the table, 100 000 misses the five that sit beyond the 100 000 matches of `wireless`
and 200 000 finds them. A restricted viewer's search of a word that is common in a field the viewer cannot see is slow for a
different reason that this does not change (see [Known limitations](limitations.md#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field-is-slow)).

The first run after a restart reads the table from disk and is much slower (seconds at 1 000 000 rows for a word that is
spread over every page); the figures are warm. Both columns were measured on the same machine in the same session, but
single-digit milliseconds on a shared laptop are noise.

### Typo words and facets of a capped search

A typo word that matches more documents than `candidate_limit` (`mouss` is 20 000 look-alikes on 200 000 products) contributes
the first `candidate_limit` matches of its trigram index, cheap and a lower bound ([Known limitations](limitations.md#ranking-of-very-common-words));
a full-text match that is not among them keeps its typo score. The facets of a capped search count the ids the statement of the
hits returns. Median of 7 runs, warm, `candidate_limit` 2000, 200 000 products, 0.9.8 -> now:

| search | 0.9.8 | now |
|---|---:|---:|
| `mouss`, fallback / always | 44 / 36 ms | 47 / 43 ms |
| `wireles`, fallback / always | 54 / 53 ms | 51 / 52 ms |
| `hedphones`, fallback | 46 ms | 48 ms |
| `wireles mose` (two typo words) | 90 ms | 92 ms |
| `mouss`, restricted viewer (name, brand) | 44 ms | 141 ms |
| `mouss` with a facet / and an active filter | 68 / 70 ms | 46 / 67 ms |
| `screwdrivr 1234` (two words, under the limit) | 39 ms | 37 ms |
| `wireless`, `fuzzy_mode: always` | 73 ms | 94 ms |
| `ideal`, `fuzzy_mode: always` | 132 ms | 131 ms |
| `wireless` with a facet / and an active filter (strict) | 48 / 47 ms | 46 / 79 ms |
| a filter-only listing with a facet | 2.1 ms | 2.4 ms |
| 12 typo-tolerant-field index (`bench12`), `batery` | 189 ms | 208 ms |
| the same, restricted viewer (name, description) | 218 ms | 498 ms |
| the same index, the reversed phrase `"office ideal"` | 541 ms | 274 ms |

`wireless` in `fuzzy_mode: always` pays for the typo score of its 2 000 full-text candidates (they are rescored when the typo branch is
capped, about 10 microseconds each); the restricted viewer pays for the ordered selection, about 8 microseconds per scored match for a
short name and more for a long text (the work budget in the known limitations). The typo statement of a word under the limit is the one
it always was.

## Reading the numbers

The two columns do different work. `ILIKE … LIMIT 20` returns the first 20 rows the scan reaches,
unranked, and cannot exclude words: its 20 hits for the exclusion query include headphones. So
`ILIKE` is fastest when the word is common, and finds nothing for accents, typos or other word
forms, after scanning the whole table. Fuzzphony ranks every result and handles those cases in
about 10 to 90 ms on 200 000 products (a typo word 25 to 75 ms; a wide index of long typo-tolerant text 0.2 to 0.6 s).

Measure on your own data. The demo's Speed view runs this benchmark in the browser, and
[demo/README.md](../demo/README.md#measured) has results on 200 000 and 500 000 rows.

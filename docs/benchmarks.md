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

200 000 products, PostgreSQL 16, a small cloud VM; the output of `run.php --markdown`:

| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) |
|---|---|---:|---:|---:|---:|
| plain word | `wireless` | 1.7 / 0.6 ms | 20 | 17.9 / 11.1 ms | 20 (2000+) |
| two words | `wireless mouse` | 4.1 / 3.7 ms | 20 | 19.9 / 13.1 ms | 20 (1666) |
| accent | `creme` | 257.6 / 251.6 ms | **0** | 11.9 / 10.4 ms | 20 (2000+) |
| typo | `hedphones` | 248.1 / 252.6 ms | **0** | 24.2 / 20.7 ms | 20 (2000+) ~ |
| stemming | `drills` | 342.0 / 257.1 ms | **0** | 11.9 / 10.6 ms | 20 (2000+) |
| phrase + exclusion | `"noise cancelling" -headphones` | 0.6 / 0.5 ms | 20 | 24.3 / 23.2 ms | 20 (2000+) |
| filter + text | `kettle` | 0.8 / 0.7 ms | 20 | 12.8 / 11.3 ms | 20 (2000+) |

`~` means the typo-tolerant fallback ran.

The table was measured before 0.7. Since 0.7 the typo-tolerant statement also rechecks each word
against the similarity of its own length; on 200 000 rows (`wireles mouse`, `hedphones`, `mose` in
`fuzzy_mode: always`, measured separately from this script) the plans keep using the trigram
indexes and the statements run within roughly 0.9 to 1.4 times the time of a flat `fuzzy_similarity`.
Re-run `benchmarks/run.php` on your own data to compare.

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

## Reading the numbers

The two columns do different work. `ILIKE … LIMIT 20` returns the first 20 rows the scan reaches,
unranked, and cannot exclude words: its 20 hits for the exclusion query include headphones. So
`ILIKE` is fastest when the word is common, and finds nothing for accents, typos or other word
forms, after scanning the whole table. Fuzzphony ranks every result and handles those cases in
about 10–25 ms.

Measure on your own data. The demo's Speed view runs this benchmark in the browser, and
[demo/README.md](../demo/README.md#measured) has results on 200 000 and 500 000 rows.

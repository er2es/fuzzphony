# Benchmarks

How Fuzzphony compares with a naive `ILIKE`, and how to measure it yourself. Back to the
[README](../README.md).

## Method

`benchmarks/seed.sql` generates a catalogue (products × brands × categories).
`benchmarks/run.php` compares a naive `ILIKE` with Fuzzphony: the first ("cold") run and the median
of the next 5 ("warm"), 20 results each.

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

## Reading the numbers

The two columns do different work. `ILIKE … LIMIT 20` returns the first 20 rows the scan reaches,
unranked, and cannot exclude words: its 20 hits for the exclusion query include headphones. So
`ILIKE` is fastest when the word is common, and finds nothing for accents, typos or other word
forms, after scanning the whole table. Fuzzphony ranks every result and handles those cases in
about 10–25 ms.

Measure on your own data. The demo has a Benchmark page, and
[demo/README.md](../demo/README.md#measured) has results on 200 000 and 500 000 rows.

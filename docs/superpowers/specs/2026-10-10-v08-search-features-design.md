# v0.8: Search features: design

Status: implemented in one pull request (the 0.7 work was three). Implements `docs/roadmap.md`'s "v0.8:
Search features": search-as-you-type, facets, federated search.

## Decisions (agreed 2026-10-10)

- **`suggest()` completes words, from the vocabulary.** The roadmap's `suggest()` is word completion on the 0.7
  vocabulary; the live dropdown also shows hits with the existing prefix search. Only the last word is completed,
  the words before it (and a `-`) stay. A tenant-scoped index suggests nothing (the vocabulary has no tenant
  column). A prefix index (`text_pattern_ops`) on the vocabulary is added by `schema --apply`; it works without.
  The vocabulary's age is still not tracked: the docs recommend a scheduled `--vocabulary` rebuild.
- **Facets are disjunctive and approximate by default.** A facet leaves out the conditions on its own filter
  (the tenant's stay, and the tenant filter cannot be a facet). Counts are over the candidates (lower bounds when
  `totalIsLowerBound`); `exactCounts()` counts every match, for the total and the facets. It is a builder method,
  not a threshold: a threshold override can only tighten the cost limits (see `Thresholds::MAX_CANDIDATE_LIMIT`).
  One statement per facet, built from the same CTEs as the search without the candidate `LIMIT` when exact.
  Float and datetime filters cannot be facets. Ranges (price buckets) are not in 0.8.
- **Federated search merges by reciprocal rank fusion.** Scores of different indexes are not comparable, so a
  hit's merged score is `weight / (60 + rank)`. Each index is searched on its own through the normal builder
  (the `configure` closure carries its filters, tenant, highlights), for `offset + limit` hits (at most 1000), so
  it needs nothing from an engine. Not in 0.8: federated facets (each index's own result carries its facets).
- **Engine SPI:** the optional `Engine\Suggestions`; facets travel in `SearchQuery`, so a custom engine that
  ignores them returns none. `Capability::Facets` and `Suggest`.
- **Surfaces:** the Live Component (`facets`, `suggestions`), `fuzzphony:search` (`--facet`, `--exact`,
  `--suggest`), and the demo: built into the existing search boxes and pages, not a new page.

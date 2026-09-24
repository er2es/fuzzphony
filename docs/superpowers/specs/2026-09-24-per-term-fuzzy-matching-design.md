# Per-term fuzzy matching — design

Status: approved (design), not yet implemented. A correctness fix to the core ranking SQL,
found through the demo. Independent of the v1.0 roadmap items (observability, security,
federated search).

## Problem

When the strict full-text branch finds fewer than `fallbackBelow` documents (typically because
one query word is a typo), the engine runs a second, fuzzy statement. That statement collapses
the whole query into **one** trigram check:

```sql
fuzzy AS (
    SELECT s.id, word_similarity(q.norm, s.fz) AS r_fuzzy
    FROM fuzzphony_catalog s CROSS JOIN q
    WHERE q.norm <% s.fz ...
)
```

`q.norm` is every positive word of the query joined into one string (`'wireles mice'`), and
`s.fz` is the document's fuzzy fields joined into one string. A per-word constraint is lost:
one long, common word ("wireless", present in ~10% of the catalogue) is enough to reach the
similarity threshold, whatever the other words are. The strict branch does this correctly
(`'wireles' & 'mice'`), the fuzzy branch does not. AND/OR/NOT structure, phrases, and field
scoping are all discarded in the fuzzy path (only top-level exclusions are re-applied, through
a separate `excl` tsquery).

### Measured on the demo catalogue (200 000 products, `fuzzy_similarity` 0.3)

Query `wireles mice`:

| variant | hits | of which a mouse |
|---|---|---|
| current (whole query as one blob) | 20 000 | 1 666 (8%) |
| per-term (`(exact OR fuzzy) AND (exact OR fuzzy)`) | **1 666** | **1 666 (100%)** |

The per-term variant keeps every true match (recall unchanged) and drops the 18 334 unrelated
products (chairs, drills, kettles, …).

## Goal and acceptance criterion

Every query word must be satisfied individually, exactly or fuzzily, combined through the
query's real AND/OR/NOT structure — the same structure the strict tsquery already has.

**Acceptance test (from the demo):** on the demo catalogue, `wireles mice` returns 1 666 hits
and every hit is a `Wireless mouse ####` product. No chair, drill, kettle, keyboard or other
noun appears.

## Non-goals

- Per-field trigram columns (`fz_name`, `fz_brand`, …). Follow-up spec, see "Known limitation".
- Changing threshold defaults, the strict full-text branch, or `ts_rank_cd` scoring.
- Fuzzy matching of negated terms (`-cable` stays exact-only).
- Irregular plurals ("mice" ↔ "mouse"): a stemmer limitation, not a matching-structure one.
  The acceptance test works because the demo's `category` field literally contains "Mice".

## Design

### `FuzzyQueryCompiler` (new, `Fuzzphony\Engine\Postgres\Sql`)

Walks the AST like `TsQueryCompiler`, and returns a small immutable `FuzzyMatch` holding two
SQL fragments and one flag:

- `predicate`: a boolean SQL expression for the `WHERE` of the fuzzy candidate CTE.
- `score`: a numeric SQL expression for `r_fuzzy`.
- `hasFuzzyLeaf`: whether at least one leaf can match fuzzily (else the fuzzy statement adds
  nothing over the strict one and the engine skips it).

Every user-derived string (per-leaf tsquery and per-leaf normalised needle) is a bound
parameter, exactly as today. Nothing user-derived is inlined.

### Leaves

| node | predicate | score |
|---|---|---|
| `Term` | `tsv @@ to_tsquery(cfg, :tsq) OR :needle <% fz` | `GREATEST(word_similarity(:needle, fz), CASE WHEN tsv @@ tsq THEN 1 ELSE 0 END)` |
| `Term` with `prefix` | same; the tsquery carries `:*`, the needle is the prefix text | same |
| `Phrase` | `<->` tsquery `OR` the whole phrase (words joined by a space) as one needle | same |
| `FieldScoped` | exact side scoped to the field's weight class (as today); fuzzy side uses the whole `fz` | same |
| `Not` | `NOT (tsv @@ to_tsquery(cfg, :tsq))`, exact only | none |

The exact side reuses `TsQueryCompiler` for the leaf, so lexeme reduction, weight labels and
prefix handling stay in one place. The fuzzy side of a leaf is included only when the index
has fuzzy fields and `mb_strlen(needle) >= fuzzyMinLength` (default 3), so short words stay
exact-only. A leaf whose tsquery is empty (a stop word) is dropped, as in `TsQueryCompiler`.

The exact side scores 1.0, so a term found in a non-fuzzy field (for example `category`) is not
penalised for being absent from `fz`.

### Nodes

- `AllOf` → `AND` of the non-null child predicates. Score: the **mean** of the positive
  children's scores.
- `AnyOf` → `OR`. Score: the **maximum** of the children's scores.
- One remaining child collapses to itself; no remaining children → `null`.
- `Not` does not contribute to the score.

This replaces the separate `$exclusions` / `excl` mechanism: negation is now applied wherever
it appears in the tree, not only at the top level.

### Wiring

`SearchSqlBuilder::ranked()` (`@internal`) replaces `string $plain, bool $withFuzzy` and
`?string $exclusions` with `string $plain, ?FuzzyMatch $fuzzy`. `$plain` stays only for
`q.norm`, which the exact/prefix bonuses still use. The fuzzy CTE becomes:

```sql
fuzzy AS (
    SELECT s.id, (<score>)::double precision AS r_fuzzy
    FROM <table> s CROSS JOIN q
    WHERE (<predicate>) AND <filters>
    LIMIT <candidateLimit>
)
```

`PostgresEngine::execute()` builds the `FuzzyMatch` once per query. `fuzzyEligible` keeps its
current terms (index has fuzzy fields, `profile->fuzzy > 0`, mode is not `never`) and gains
`$fuzzy->hasFuzzyLeaf`, replacing the whole-query length check. The `never` / `fallback` /
`always` modes, `fallbackBelow`, the strict `fts` CTE, `cand`, and `set_config` of
`pg_trgm.word_similarity_threshold` are unchanged.

### Ranking-value impact

For a document that also matches strictly, `r_fuzzy` was a blob-level `word_similarity`; it is
now the per-term score (1.0 when every term matches exactly). Scores of strict matches in
`always` mode therefore shift slightly, and the ordering of fuzzy-only matches improves
(a document matching both words outranks one matching a single word). Existing tests that
pin orderings must pass or be updated deliberately, never loosened.

## Risk: index usage — Task 0 is a spike, before anything else

`(tsv @@ A OR x <% fz) AND (tsv @@ B OR y <% fz)` mixes two GIN indexes (`tsv`, trigram on
`fz`) inside one `WHERE`. PostgreSQL may or may not combine them with `BitmapOr` /
`BitmapAnd`, particularly when the values come from the `q` CTE. This must be measured with
`EXPLAIN (ANALYZE, BUFFERS)` on the 200 000 and 1 000 000 row demo catalogues, in this order:

1. Parameters inlined in the `WHERE`.
2. Per-leaf values as columns of the `q` CTE.
3. Fallback rewrite: each leaf as `s.id IN (SELECT id … exact UNION ALL SELECT id … fuzzy)`,
   with no `LIMIT` inside the leaf, combined by `AND` / `OR` outside.

**Pass criteria:** the plan uses the GIN indexes (no sequential scan of the sidecar table) and
the hybrid statement takes at most 2× the time of today's fuzzy statement on the same data.
The first placement that passes is the design; if none does, this spec is reopened rather than
shipped slower. The spike's numbers are recorded in the plan.

`LIMIT candidateLimit` without `ORDER BY` in the candidate CTEs is unchanged, but now applies
only to rows that already satisfy the full boolean predicate, which is exactly what the
alternatives (per-term CTEs intersected afterwards, or post-filtering blob candidates) cannot
guarantee.

## Known limitation (accepted): field-scoped fuzzy

`fz` is one string of all fuzzy fields. A field-scoped term (`name:sony`) therefore cannot be
restricted to its field in the fuzzy path: on the demo catalogue `name:sony` returns 20 000
Sony-brand products, none with "sony" in the name. This is today's behaviour, kept on purpose.
`name:kettel` (a typo) returns 16 670 kettles, so scoped typo tolerance keeps working.
Restoring exact scoping needs per-field trigram columns, a schema change (new columns and
indexes, `schema --apply` and a reindex, doctor drift checks, more storage). It is a separate
follow-up spec, and this fix does not depend on it.

## Testing

- **Unit, `FuzzyQueryCompilerTest`:** SQL and parameter output for every node type; nested
  `AllOf`/`AnyOf`; `Not` inside a group; phrase; prefix; field-scoped; a leaf below
  `fuzzyMinLength`; a stop-word leaf dropped; `hasFuzzyLeaf` false when nothing is fuzzy;
  user text never appears in the SQL, only in parameters.
- **Unit, `SearchSqlBuilderTest`:** updated for the new `ranked()` signature.
- **Conformance and integration (real PostgreSQL):** a fixture where two words must both be
  present. A typo on one word must not return documents lacking the other; an `OR` group; a
  nested `NOT`, which the fuzzy path never honoured before; a phrase; `never` / `fallback` /
  `always` still behave; ordering: both words outrank one word.
- **Acceptance test:** the demo query above (`wireles mice` → 1 666 hits, all `Wireless mouse`),
  run against a re-seeded demo database.
- **Performance:** `benchmarks/run.php` before and after, recorded with the plan.
- **Docs:** README (Query syntax, Ranking, Thresholds) states the per-term semantics and the
  field-scoped limitation; CHANGELOG notes that fuzzy result sets get narrower and that
  strict-match scores shift slightly in `always` mode.

## Open questions for the plan

- Which of the three placements passes the spike (decided by measurement, not opinion).
- Exact class shape of `FuzzyMatch` and whether `FuzzyQueryCompiler` owns parameter
  registration or receives the `ParameterBag`; either is fine, settled while writing the plan.

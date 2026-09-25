# Empty-result relaxation — design

Status: approved (design), not yet implemented. Follow-up to
`2026-09-24-per-term-fuzzy-matching-design.md`, prompted by that change's independent review.

## Problem

Per-term fuzzy matching requires every query word to match, exactly or by trigram similarity.
Trigram matching only sees fields flagged `fuzzy: true` (in the demo: `name` and `brand`, not
`description`). A misspelled word that lives only in a non-fuzzy field therefore cannot be
satisfied by any document, and the whole query returns **nothing**.

Demo catalogue, `wireless mouse aluminum` (the description of a product reads
`A wireless mouse with aluminium body, ideal for the office.`; "aluminium" is in no name or brand):

| | before per-term fuzzy | after per-term fuzzy |
|---|---|---|
| `wireless mouse aluminium` (correct) | 238 hits | 238 hits |
| `wireless mouse aluminum` (typo / US spelling) | all 1 666 wireless mice, the word silently ignored | **0 hits** |

## Decision (user)

When a multi-word query finds nothing, ignore the words that match nothing in the whole
searched set and **tell the user which words were ignored**. Exact and typo'd queries that
already return results are untouched.

## Behaviour

Relaxation runs only when all of these hold after the normal pipeline (strict, then fuzzy per
the mode) has produced **zero** hits:

- relaxation is enabled (new threshold, below), and
- the query has at least two positive leaves (`Term`, `Phrase`, `FieldScoped`), and
- at least one positive leaf is **individually unsatisfiable**, and at least one is satisfiable.

A leaf is *unsatisfiable* when no document of the searched set matches it on its own, using the
same per-leaf condition the search uses: exact tsquery, or, when the fuzzy branch is eligible
for that leaf, exact-or-trigram. "The searched set" includes the caller's filter conditions and
the tenant condition, so the probe can never reveal that a word exists in another tenant's data.

The probe is one statement: per positive leaf, `EXISTS (SELECT 1 FROM sidecar s WHERE <leaf
condition> AND <filters/tenant> LIMIT 1)`; each `EXISTS` uses the GIN indexes and stops at the
first row.

If some leaves are unsatisfiable, they are removed from the AST (a removed leaf behaves like a
dropped stop word: it disappears from its `AllOf` / `AnyOf`; a `Not` is left alone) and the
normal pipeline runs **once more** on the reduced query. There is no second relaxation. The
result carries:

- a warning, in `SearchResult::$warnings`, that is safe to show to users, for example
  `No results for all words; ignored words that match nothing: "aluminum".`, and
- `interpretedAs` showing the reduced query (`(wireless AND mouse)`).

Not relaxed, by design:

- Every word is satisfiable but no document has them all (`mouse kettle`: both exist, no
  product is both). That is a correct empty result; the AND is honoured.
- A single-word query, or a query whose leaves are all unsatisfiable (nothing to keep).
- A query that already returned at least one hit.

## Configuration

New threshold `relax_when_empty` (`Thresholds::$relaxWhenEmpty`, bool, default `true`),
overridable per query (`->thresholds(['relax_when_empty' => false])`), settable in YAML
(`thresholds:`), and round-tripped by the exporters and loaders exactly like the other
thresholds. It is independent of `fuzzy_mode` (relaxation drops words, it is not typo
tolerance), so it also applies when `fuzzy_mode` is `never`.

## Cost and safety

Only zero-hit multi-word queries pay: one probe statement plus, if it finds unsatisfiable
words, one more normal search. Bound parameters only, no user text in SQL, same as the rest.
`explain()` includes the extra statements, labelled.

## Testing

- Unit: the leaf-removal step on every AST shape (`AllOf`, `AnyOf`, nested, `Phrase`,
  `FieldScoped`, `Not` untouched, all leaves removed, single leaf); the warning text; threshold
  parsing, validation, export round trip.
- Conformance/integration (real PostgreSQL): the acceptance case (below); a typo'd
  non-fuzzy-field word is ignored and reported; `mouse kettle` is NOT relaxed and returns
  nothing; a query with hits is not relaxed; the probe respects a tenant condition and a filter
  (a word that only exists in another tenant is still reported as unsatisfiable and dropped,
  and its existence is not otherwise observable); `relax_when_empty: false` restores the empty
  result; `fuzzy_mode: never` still relaxes.
- **Acceptance (demo catalogue):** `wireless mouse aluminum` returns the 1 666 wireless mice and
  a warning naming "aluminum"; `wireless mouse aluminium` returns 238 hits with no warning;
  `wireles mice` still returns exactly 1 666 hits with no warning.

## Docs

README (Query syntax / Thresholds tables, and remove the "finds nothing" wording from the fuzzy
section), CHANGELOG `## Unreleased`.

## Out of scope

Marking `description` fuzzy, per-field trigram columns, "did you mean" suggestions, and
returning the dropped words as a structured field (the warning text and `interpretedAs` are
enough for now).

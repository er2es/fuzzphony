# v0.7: Relevance — design

Status: approved (design), not yet implemented. Implements `docs/roadmap.md`'s "v0.7: Relevance":
length-aware typo tolerance, synonyms, "did you mean" (with the vocabulary table 0.8's `suggest()`
reuses). Three pull requests, in this order: (1) length-aware typo tolerance, (2) synonyms,
(3) vocabulary and did-you-mean.

## Goal

Better match quality without new infrastructure: `mouse` stops matching `monitor`, domain synonyms
work (`tv` ↔ `television`), and a word that matches nothing gets a spelling suggestion
(`hedphones` → "headphones?"). PostgreSQL only, no dictionary files on the database server.

## Part 1: Length-aware typo tolerance (Breaking)

- `Thresholds::$fuzzySimilarity` becomes `?float`, default `null` = proportional to the word's
  length. One typo is tolerated from 4 letters and two from 8 (`Thresholds::TYPOS_BY_LENGTH`); a
  typo changes at most three of a word's n + 1 trigrams, so t typos leave a trigram similarity of at
  least (n + 1 - 3t) / (n + 1 + 3t) (measured exactly this on generated typos of 60 words, for
  1 and 2 typos, by the length of the *query* word), and a word needs that plus a slack of 0.03,
  which keeps a different word at exactly the worst case out (`mouse` / `monitor` is 0.333, the
  worst case for five letters). Words below 4 letters need 0.6. n is the length of the *normalised*
  word, measured in PostgreSQL (`fuzzphony_norm`, so `ß` counts as `ss`), a phrase without its
  spaces. `Thresholds::similarityFor()` is the same rule in PHP, `lowestSimilarity()` (0.23) is
  what the session threshold is set to.
  Measured trade-off on 130 demo words: one-typo recall 81% (the three fixed bands: 69%), two-typo
  recall 91% (89%), false matches per correctly spelled word 0.56 (0.24) for 4 to 7 letters and 0.45
  (0.42) from 8. `mose` finds `mouse` and, as close to it, `monitor` and `mower`; the fuzzy search only
  runs as a fallback by default and ranks them lower.
- An explicit number (`fuzzy_similarity: 0.3`, per index or per query, or `--threshold` on the
  command line) stays flat, as today; `null` returns to proportional. Only the default changes.
- `pg_trgm`'s `<%` uses one session-wide threshold. `PostgresEngine` sets it to the lowest similarity
  (so GIN still finds the candidates) and `FuzzyQueryCompiler` adds a per-word
  `word_similarity(needle, column) >= <CASE on the length of the normalised word>` check next to `<%`.
  The same check applies in the score, in the relaxation probe and in field-scoped words. A flat
  value adds no check.
- `fuzzy_min_length` is unchanged. The doctor's risky-threshold check still accepts a flat value.
- Docs: `limitations.md` loses "Typo tolerance is lenient" (README limitation and link too),
  `ranking.md` thresholds table, CHANGELOG **Breaking**, UPGRADE "From 0.6 to 0.7".

## Part 2: Synonyms (no reindex)

- Per index: YAML `synonyms:`, builder `->synonyms(...)`, `#[Searchable(synonyms: ...)]`. Two forms:
  a mutual group (`[tv, television]`) and a one-way rule (`laptop => notebook`). A multi-word member
  is a phrase.
- Expansion is on the query side, on the AST, before the compilers run: `Term` →
  `AnyOf(term, synonyms…)`. Matching is on the normalised form (accents, stemming applied), so
  `TVs` finds the group of `tv`. A one-way rule expands only its left side.
- A synonym matches exactly; typo tolerance applies only to the user's own word. It scores like an
  exact match (`AnyOf` = max). `interpretedAs` shows the expansion. A negated word excludes its
  synonyms too (`-tv` excludes `television`).
- The definition validator reports an empty group, a member in two groups, and a one-way rule that
  loops. Synonyms are trusted developer input, never built from user input.
- No dictionary files on the server and no table: the definition holds them, so changing them needs
  no `schema --apply` and no reindex (but the definition hash in `fuzzphony_meta` ignores them).

## Part 3: Vocabulary and did-you-mean (sidecar layout 3)

- New table `fuzzphony_<index>__vocab (word text PRIMARY KEY, freq int)` with a trigram GIN index,
  built from the index's normalised fuzzy-field words. Layout step 2→3 runs in `schema --apply`
  (guarded `DO` block, in `--dump-migration` too). A full reindex builds it with the shadow rebuild
  and swaps it in (the swap renames it with the index table); the in-place path rebuilds it at the
  end. It is not updated on sync, only by a full reindex, and `fuzzphony:reindex --vocabulary`
  rebuilds just this table.
- The doctor gets a "Vocabulary" check: missing (a warning with the fix), older than the documents'
  definition hash. Every check has a fix (CONTRIBUTING rule 4).
- Did-you-mean runs only when the result is empty, after relaxation, or when a word matched
  nothing. For each such word it takes the closest vocabulary word (trigram similarity, ties by
  frequency) above the active threshold. New `SearchResult::$didYouMean` (`?string`): the whole
  corrected query, plain text (escape it in HTML). It suggests; it never re-runs the search by
  itself. Threshold `did_you_mean` (default `true`) switches it off.
- ADR 0008 gets a short addendum (the vocabulary table is part of the rebuild's swap). The wizard
  and the demo show the suggestion; 0.8's `suggest()` reads this table.

## Demo (last step of the milestone, after PR 3)

Everything relevant in 0.7 is shown in the demo: the did-you-mean suggestion ("Did you mean
headphones?", linked to the corrected search) wherever a result can be empty (the Compare page, the
Playground, the Languages page), the synonyms of the demo's indexes (a few `catalog` groups such as
`tv ↔ television`, shown in `interpretedAs`), and length-aware tolerance (`mouse` no longer lists
monitors, one-click example). Decided when the demo is done, by what reads better: rework the
existing pages (new one-click examples, a suggestion line in the result header), or add a new menu
item (for example "Relevance") that walks through the three features side by side with the old
behaviour (flat `fuzzy_similarity: 0.3`). `demo/README.md` and the demo's page table are updated
with it. PR 1 and PR 2 only touch the demo where a flag or default would otherwise break it.

## Public API and compatibility

- Breaking: the `fuzzy_similarity` default, `Thresholds::$fuzzySimilarity` is nullable, sidecar
  layout 3 (apply, then one full reindex for the vocabulary).
- Additive: `IndexBuilder::synonyms()`, `Searchable::$synonyms`, `SearchResult::$didYouMean`,
  threshold `did_you_mean`, `ReindexOptions::$vocabularyOnly`, `Engine` gets vocabulary methods; a
  custom engine that has none returns `null` from the suggestion method (did-you-mean stays null).
  New classes are `@internal` unless listed in `docs/architecture.md` and `PublicApiTest`.

## Testing

TDD per part; each new behaviour starts as a conformance test (`tests/Conformance`). 100% line
coverage of `src/`. Unit tests for the band selection, synonym expansion and the validator;
integration tests for `mouse`/`monitor`, per-word thresholds with field scoping and relaxation,
synonym groups and one-way rules, the layout 2→3 step, vocabulary swap during a rebuild, the
doctor check and did-you-mean. Process (streamlined SDD): per-task review only for the vocabulary
swap, Infection once at the end of each PR's branch, Sonnet by default, Opus for the final review.

## Non-goals

No language-specific synonym or stop-word dictionaries, no synonym management UI or table, no
automatic re-search with the suggestion, no phonetic matching, no `suggest()` (0.8).

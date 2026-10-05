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
  length. The rule lives in the `@internal` `Fuzzphony\Core\Ranking\TypoCurve` (not in the 1.0
  public API): one typo is tolerated from 4 letters and two from 8; a missing, extra or replaced
  letter changes at most three of a word's n + 1 trigrams, so t typos leave a trigram similarity of
  at least (n + 1 - 3t) / (n + 1 + 3t) (measured exactly this on generated typos of 60 words, for
  1 and 2 typos, by the length of the *query* word), and a word needs that plus a slack of 0.03,
  which keeps a different word at exactly the worst case out (`mouse` / `monitor` is 0.333, the
  worst case for five letters). Words below 4 letters need 0.6; a prefix gets one letter of
  allowance (its last trigram cannot match inside a longer word). n is the length of the
  *normalised* word, measured in PostgreSQL (`fuzzphony_norm`, so `ß` counts as `ss`), a phrase
  without its spaces.
  Honest trade-off (measured on generated single-letter typos of 24 words, against today's flat
  0.3): a 5 to 7 letter word finds about 70 to 80% of its typos (flat: 92 to 100%), the lost ones
  being a letter replaced in the middle and two letters swapped (a swap changes four trigrams);
  look-alikes drop about five times; 4-letter words behave as before (`mose` finds `mouse`,
  `monitor` and `mower` alike, 0.40 each); 8 letters and more lose nothing.
- An explicit number (`fuzzy_similarity: 0.3`, per index or per query, or `--threshold` on the
  command line) stays flat, as today; `null` returns to proportional. Only the default changes.
- `pg_trgm`'s `<%` uses one session-wide threshold per statement. `PostgresEngine` sets it to the
  lowest similarity any positive word of *that statement* needs (`FuzzyQueryCompiler::
  lowestSimilarity()`; a non-ASCII word may change length when normalised, so its neighbouring
  lengths count), so GIN returns no more candidates than necessary (a fixed 0.23 was measured 5x to
  500x slower for short words). `FuzzyQueryCompiler` adds a per-word `word_similarity(needle, column)
  >= q.th<n>` check next to `<%`, where the threshold is a `q` column computed once in SQL from the
  normalised word; a word below it does not score (an OR cannot be lifted by a non-matching word).
  The same applies in the relaxation probe and in field-scoped words. A flat value adds no check.
- `fuzzy_min_length` is unchanged. The doctor's risky-threshold check still accepts a flat value.
- Docs: `limitations.md` replaces "Typo tolerance is lenient" with "Typo tolerance is trigram-based" (and the README bullet),
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

- Breaking: the `fuzzy_similarity` default, `Thresholds::$fuzzySimilarity` is nullable, a custom
  engine must apply the same length rule; with the vocabulary as sidecar layout 3 also apply, then
  one full reindex (see Open decisions: the 2026-10-02 spec keeps layout 2).
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

## Decisions (reconciled with the 2026-10-02 spec)

An earlier spec, `docs/superpowers/specs/2026-10-02-v07-relevance-design.md` on branch
`v07-relevance`, covers the same milestone. The maintainer settled every difference on 2026-10-05
(this spec's parts 2 and 3 are read with these decisions; where they differ from the text above,
these win):

| Topic | Decision |
|---|---|
| Length rule | proportional to the word's length, default on, Breaking (part 1, implemented); replaces the old spec's fixed curve and `fuzzy_length_aware` flag |
| Vocabulary table | additive: created by `fuzzphony:schema --apply`, sidecar layout stays 2, no forced reindex; the doctor warns while it is empty |
| Filling it | by the full reindex (`fuzzphony:reindex --vocabulary` rebuilds just this table) and by the worker; never per write |
| Engine interface | an optional interface plus a `Capability`, not a new required `Engine` method (no Breaking for custom engines) |
| Did-you-mean trigger | fewer hits than `fallback_below` and a positive word that is not in the vocabulary |
| Did-you-mean ranking | trigram top-K candidates from the vocabulary, then edit distance, then frequency (raw trigram ranking suggests `most` for `mose`) |
| Doctor "Vocabulary" | error when the table is missing, warning when it is empty or older than the documents' definition hash |
| Synonym forms | groups (`[tv, television]`) and one-way rules (`laptop => notebook`), inline in YAML / builder / attribute |
| Synonyms file | not in 0.7; inline first, a file can follow without breaking anything |
| Synonym matching | on the stemmed, accent-folded form (PostgreSQL's text configuration, one extra round trip per search on an index that has synonyms), so `TVs` finds the `tv` group; a multi-word member matches only a quoted phrase |
| Synonym alternatives | expanded as `AnyOf(original, alternative, ...)` on the query; the alternatives are flagged as implied (they are skipped by the exact / prefix bonus words and by the relaxation warning) and are matched like any query word, typo tolerance included; the old spec's exact-only wrapper node is not built |

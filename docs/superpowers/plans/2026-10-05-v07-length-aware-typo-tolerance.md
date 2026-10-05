# v0.7 PR 1: Length-aware typo tolerance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `mouse` stops matching `monitor`: the default trigram similarity depends on the word's length, an explicit number stays flat.

**Architecture:** `Thresholds::$fuzzySimilarity` becomes `?float` (`null` = by length). `Thresholds` answers two questions: the similarity a word of a given length needs, and the lowest similarity in use (what the session's `pg_trgm.word_similarity_threshold` is set to, so GIN still finds every candidate). `FuzzyQueryCompiler` adds a per-word `word_similarity(...) >= t` recheck next to `<%` whenever a word's threshold is above the lowest.

**Tech Stack:** PHP 8.4, PostgreSQL 15–18 `pg_trgm`, PHPUnit, PHPStan max, php-cs-fixer.

**Spec:** [docs/superpowers/specs/2026-10-05-v07-relevance-design.md](../specs/2026-10-05-v07-relevance-design.md), "Part 1".

Streamlined process (memory: sdd-process-streamlined): no per-task review (nothing here is locking or destructive), tests + phpstan + cs + 100% coverage per task, Infection once at the end of the branch, one final whole-branch review.

## Global Constraints

- Bands by length of the normalised word, spaces removed (the same length `fuzzy_min_length` checks): 3–4 → 0.6, 5–7 → 0.45, 8+ → 0.3. Task 2 step 1 confirms them by measurement before they are fixed.
- An explicit `fuzzy_similarity` number (per index or per query) is flat, as before; `null` returns to length-aware. Only the default changes.
- `fuzzy_min_length` unchanged. PostgreSQL only. The session threshold is restored as before (`withSimilarityThreshold()` untouched).
- Every change updates README / CHANGELOG / UPGRADE / docs in the same PR (memory: always-update-docs). 100% line coverage of `src/`. PHPStan level max. `main` is protected: branch → PR; push only when tests + PHPStan are green.
- Branch: `v07-length-aware-typo-tolerance` (from `v07-relevance-spec` or `main` after the spec PR). Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- A flat explicit value (`fuzzy_similarity: 0.3`) gives exactly today's results and today's SQL (no extra recheck).
- A phrase (`"wireles headphones"`) uses the length of its words joined without spaces, same as the `fuzzy_min_length` check.
- A field-scoped word (`brand:sonny`) gets the same per-word threshold on its field's own column.
- The empty-result relaxation probe uses the same per-word threshold as the search (a word the search rejects is not "kept" by the probe).
- `null` and numbers round-trip through YAML export (`ArrayExporter`), `->thresholds(['fuzzy_similarity' => null])`, the CLI override and the doctor (no `%.2f` of null).
- Mixed-length queries (`mouse wireles`): the 5-letter word is strict, the 7-letter one tolerant, and both are checked against their own threshold in one statement.

---

### Task 1: `Thresholds` — nullable similarity and the length bands

**Files:**
- Modify: `src/Core/Ranking/Thresholds.php`
- Modify: `src/Core/Wizard/Export/ArrayExporter.php:96` (null is not exported, so an index on the default stays on the default)
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php:658-665` (null → say "by word length"; the "very tolerant" warning only for an explicit value below 0.2)
- Test: `tests/Unit/Core/Ranking/ThresholdsTest.php` (+ the exporter's and inspector's existing tests)

**Interfaces:**
- Produces: `Thresholds::$fuzzySimilarity: ?float = null`; `Thresholds::similarityFor(int $length): float` (explicit value → that value; else the band for `$length`); `Thresholds::lowestSimilarity(): float` (explicit value, or 0.3 — the lowest band). Public constants `Thresholds::LENGTH_BANDS = [[8, 0.3], [5, 0.45], [3, 0.6]]` documented as "min length → similarity"; a length below 3 returns the strictest band.
- Consumes: nothing.

- [ ] **Step 1: Failing tests** in `ThresholdsTest`: default is `null`; `similarityFor` for lengths 3, 4 → 0.6, 5, 7 → 0.45, 8, 20 → 0.3, 1 → 0.6; an explicit 0.5 returns 0.5 for every length; `lowestSimilarity()` is 0.3 for `null` and the number otherwise; `with(['fuzzy_similarity' => null])` resets to null; `with(['fuzzy_similarity' => 'lots'])` still throws "must be a number"; the constructor still rejects `0.0` and `1.5` but accepts `null`.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Unit/Core/Ranking/ThresholdsTest.php` — expect failures.
- [ ] **Step 3:** Implement. `with()`: `null` for `fuzzy_similarity` is allowed (`is_numeric($value) ? (float) $value : ($value === null ? null : throw …)`); keep the `$violations` check for non-null values only.
- [ ] **Step 4:** Exporter + inspector tests first (an exporter test: a `null` similarity is absent from the array; an inspector test: default shows `similarity=by length`, an explicit `0.10` still warns "very tolerant"), then the changes.
- [ ] **Step 5:** `composer qa` (cs, PHPStan, unit) green; commit "Make fuzzy_similarity length-aware by default in Thresholds".

---

### Task 2: Engine and compiler — per-word threshold

**Files:**
- Modify: `src/Engine/Postgres/Sql/FuzzyQueryCompiler.php` (`leaf()`: the per-word recheck)
- Modify: `src/Engine/Postgres/PostgresEngine.php:509,523,585` (and the `explain` path) — use `$thresholds->lowestSimilarity()` where `fuzzySimilarity` was passed to `run()`
- Test: `tests/Unit/Postgres/` compiler/SQL tests (find the existing `FuzzyQueryCompiler` / `SearchSqlBuilder` tests), `tests/Integration/PostgresEngineTest.php`, `tests/Conformance/EngineConformanceTestCase.php`, `tests/Integration/EmptyResultRelaxationTest.php`

**Interfaces:**
- Consumes: `Thresholds::similarityFor()`, `Thresholds::lowestSimilarity()` from Task 1.
- Produces: for a leaf whose `similarityFor(length)` is above `lowestSimilarity()`, the predicate gains `AND <schema>.word_similarity(norm, column) >= <t>` (a float literal, never a bound user value) on the trigram side, and the score's trigram part is `GREATEST(word_similarity, exact)` unchanged. Flat or lowest-band words produce exactly today's SQL.

- [ ] **Step 1: Calibrate.** On the test database, run `SELECT word_similarity(a, b)` for the pairs `mouse/monitor`, `mouse/mower`, `mose/mouse`, `wireles/wireless`, `hedphones/headphones`, `kettel/kettle`, `drils/drills`, `ergonomc/ergonomic`, `logitec/logitech`. Confirm the bands keep every typo above its band and push `mouse/monitor` below 0.45; if not, adjust `LENGTH_BANDS` (and the Global Constraints line and spec Part 1) and say so in the commit message. Record the measured numbers in the PR description.
- [ ] **Step 2: Failing tests.**
  - Unit (SQL text): a 5-letter word's predicate contains `word_similarity(` `>= 0.45`; an 8-letter word has no recheck; with `new Thresholds(fuzzySimilarity: 0.3)` (flat) no word has a recheck and the SQL equals today's snapshot; a phrase uses the joined length; a field-scoped word's recheck names its `z_<field>` column.
  - Integration: on the products fixture, `mouse` in `fuzzy_mode: always` no longer returns monitors; `wireles mouse` still returns wireless mice (typo in the long word) and not monitors; `mose` (typo in a short word) still finds `mouse`; with `->thresholds(['fuzzy_similarity' => 0.3])` the old tolerant result returns; `brand:sonny` still finds the Sony brand through its field column.
  - Relaxation probe: a query `kettle mouze` (typo `mouze` within its band) is not relaxed; one whose word only matches below its band is reported as ignored.
  - Conformance: add the `mouse`/`monitor` expectation to `EngineConformanceTestCase` so every engine meets it.
- [ ] **Step 3:** Run them; expect failures.
- [ ] **Step 4:** Implement the recheck in `leaf()` (both the unscoped and the scoped branch), computing the length with the same expression `needle()` uses (extract a small private `length(string $needle): int`), and switch the engine's three `fuzzySimilarity` uses to `lowestSimilarity()`.
- [ ] **Step 5:** Re-run the demo-catalogue benchmark smoke (`benchmarks/run.php --setup` on a bench DB) and note the timings in the PR: the recheck must not turn the GIN plan into a sequential scan (EXPLAIN on `wireles mouse` at the catalogue size). `composer qa` + `composer test:all` green, 100% coverage on the changed files; commit "Check each fuzzy word against the similarity of its length".

---

### Task 3: Docs, demo and release notes

**Files:**
- Modify: `CHANGELOG.md` (Unreleased: **Breaking** — the default similarity is by word length, results of typo-tolerant queries change; **Changed** — the doctor wording, `Thresholds::$fuzzySimilarity` nullable), `UPGRADE.md` ("From 0.6 to 0.7": step "Typo tolerance is stricter for short words; pin `fuzzy_similarity: 0.3` to keep the old behaviour; tests that pinned old fuzzy result sets may need new expectations"), `docs/ranking.md` (thresholds table: `fuzzy_similarity` default "by word length: 0.6 / 0.45 / 0.3", explicit value flat), `docs/searching.md` (typo tolerance section), `docs/limitations.md` (remove "Typo tolerance is lenient" and the intro sentence's mention), `README.md` (remove that limitation bullet), `docs/roadmap.md` (mark length-aware as shipped in the v0.7 section, keep v0.7 "current" only once the milestone ships), `docs/configuration.md` if it lists thresholds, `demo/README.md` thresholds row (`fuzzy_similarity` default by length) and the demo playground (`demo/src/Twig/Components/Playground.php:167`: the slider default stays 0.3 flat; add a "by length" option or a note — pick the smaller change), `tests/Unit/PublicApiTest.php` untouched (no new public class).
- Test: docs-only; run `composer qa` once more.

- [ ] **Step 1:** Make the edits above; grep `docs/` and `README.md` for `0.3`, "lenient" and "monitor" to catch stale statements.
- [ ] **Step 2:** `composer qa`, `composer validate --strict`; commit "Document length-aware typo tolerance".
- [ ] **Step 3:** Once per branch: `composer mutation` on the changed lines (`--git-diff-lines` as the CI does); fix escaped mutants in this PR. Then the final whole-branch review (Opus), open the PR to `main`, merge after `ci-ok`. Do not tag; v0.7.0 is tagged after PR 3.

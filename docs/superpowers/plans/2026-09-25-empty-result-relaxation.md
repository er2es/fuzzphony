# Empty-result Relaxation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a multi-word query returns zero hits, drop the words that match nothing in the whole searched set (filters and tenant included), run the normal pipeline once more, and say which words were ignored. Acceptance: on the demo-shaped catalogue (200 000 rows) `wireless mouse aluminum` returns the 1 666 wireless mice plus a warning naming "aluminum"; `wireless mouse aluminium` 238 hits and `wireles mice` 1 666 hits, both without a warning; `mouse kettle` stays empty.

**Architecture:** Engine-agnostic AST helpers in a new `Fuzzphony\Core\Query\Relaxation` (positive leaves in order, leaf removal that treats a removed leaf like a dropped stop word, the user-facing warning). `FuzzyQueryCompiler::leafConditions()` compiles the per-leaf condition the search uses (exact tsquery, or exact-or-trigram when the fuzzy branch is eligible) as `q` columns plus one predicate per leaf. `SearchSqlBuilder::probe()` wraps them into ONE statement of per-leaf `EXISTS (SELECT 1 FROM sidecar s WHERE <leaf> AND <filters/tenant> LIMIT 1)`. `PostgresEngine::execute()` moves the strict/fuzzy statements into a private `pipeline()` so it can run it once more on the reduced AST. New threshold `relax_when_empty` (default `true`).

**Tech Stack:** PHP 8.4, PostgreSQL 17, PHPUnit 12, PHPStan (max + strict-rules), php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-09-25-empty-result-relaxation-design.md`

## Global Constraints

- `vendor/bin/phpunit --testsuite=unit`, `composer stan`, `composer cs` green after every task.
- Integration suite whenever SQL or the engine changes, against a throwaway PostgreSQL 17 (`docker run -d --name fz-relax-pg -e POSTGRES_DB=fuzzphony -e POSTGRES_USER=fuzzphony -e POSTGRES_PASSWORD=fuzzphony -p 5442:5432 postgres:17`, then `CREATE EXTENSION pg_trgm; CREATE EXTENSION unaccent;`): `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5442;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --testsuite=integration`.
- `mixed` is narrowed through `Fuzzphony\Core\Support\Coerce`; named arguments in constructors/withers.
- Every user-derived value is a bound parameter. No `composer install/update/require`. Do not touch `demo/`, the demo containers, or the files another agent owns (schema generator, reindexer, `Fuzzphony.php`, reindex command, inspector).
- Commit per task with explicit paths; README/CHANGELOG each in their own small commit right after re-reading them. Do not push.

## Plan-time decisions

1. **Stop words are not "unsatisfiable".** A leaf whose tsquery the text configuration reduces to nothing is already ignored by the search; reporting "for" as a word that matches nothing would be wrong. Before probing, the engine asks PostgreSQL which leaf tsqueries are empty (the existing `emptyQueries()` round trip, unlabelled like today) and only probes the rest. Relaxation needs at least two probed leaves. Side effect: `the ab` never probes, so `testNoFuzzyStatementWhenOnlyStopWordsCouldMatchFuzzily` stays untouched.
2. **Fuzzy eligibility of a leaf** = the pipeline's fuzzy branch was eligible for the query (`$fuzzyRoot !== null`: fuzzy fields, profile `fuzzy > 0`, `fuzzy_mode` not `never`, at least one fuzzy leaf) and the leaf itself can match fuzzily (length >= `fuzzy_min_length`). Then the leaf condition is `(s.tsv @@ q.ft<n> OR q.fn<n> <% s.fz)` exactly as in the fuzzy branch, run with the same `pg_trgm.word_similarity_threshold`; otherwise `s.tsv @@ q.ft<n>`.
3. **Leaf identity.** Removal compares node instances (`===`), so `mouse mouse` with one unsatisfiable copy cannot happen by accident (identical words get the same answer anyway).
4. **Trigger** = `total === 0` of the final statement of the pipeline (a page past the end has `total > 0` and is not relaxed); browse and exclusion-only queries never relax.
5. **Warning label of a leaf:** its words as typed (`alu*` for a prefix, `usb receiver` for a phrase, `name:foo` for a scoped word), each in double quotes, comma-separated:
   `No results for all words; ignored words that match nothing: "aluminum", "zzz".`
6. **Statement labels** in `explain()`: `relaxation probe`, then the second pipeline's labels prefixed `relaxed: ` (e.g. `relaxed: full-text`, `relaxed: fallback: full-text + fuzzy`). The plan shown is still the last statement's.
7. **Threshold parsing:** `relax_when_empty` accepts a bool, or `true/false/1/0/yes/no/on/off` (so `fuzzphony:search --threshold relax_when_empty=false` works); anything else is an `InvalidDefinition`.
8. **`BuilderExporter` exports no thresholds at all today**, so "round-tripped like every other threshold" means nothing to add there; `ArrayExporter` (and through it `YamlExporter`) export it when it differs from the default.

---

### Task 1: `Relaxation` AST helpers

**Files:** Create `src/Core/Query/Relaxation.php`; test `tests/Unit/Core/Query/RelaxationTest.php`.

- [ ] **Step 1: failing tests** (parse with `QueryParser`, then):

```php
yield 'and' => ['wireless mouse aluminum', ['aluminum'], '(wireless AND mouse)'];
yield 'or keeps the other branch' => ['(foo | mouse) wireless', ['foo'], '(mouse AND wireless)'];
yield 'nested' => ['wireless (mouse | (foo bar))', ['foo'], '(wireless AND (mouse OR bar))'];
yield 'phrase' => ['"usb receiver" mouse', ['"usb receiver"'], 'mouse'];
yield 'field scoped' => ['name:foo mouse', ['name:foo'], 'mouse'];
yield 'not untouched' => ['mouse foo -cable', ['foo'], '(mouse AND NOT cable)'];
yield 'all removed' => ['foo bar', ['foo', 'bar'], null];
yield 'single leaf' => ['foo', ['foo'], null];
```

plus `positiveLeaves()` skips negated words, and `warning()` renders `No results for all words; ignored words that match nothing: "aluminum".` / `"alu*", "usb receiver", "name:foo"`.

- [ ] **Step 2:** `vendor/bin/phpunit --filter RelaxationTest` → FAIL (class not found).
- [ ] **Step 3: implement**

```php
final class Relaxation
{
    /** @return list<Term|Phrase|FieldScoped> */
    public static function positiveLeaves(Node $node): array
    {
        return match (true) {
            $node instanceof Term, $node instanceof Phrase, $node instanceof FieldScoped => [$node],
            $node instanceof AllOf, $node instanceof AnyOf => array_merge(...array_map(self::positiveLeaves(...), $node->nodes)),
            default => [],
        };
    }

    /** @param list<Node> $remove */
    public static function without(Node $node, array $remove): ?Node
    {
        if (in_array($node, $remove, true)) {
            return null;
        }
        if (!$node instanceof AllOf && !$node instanceof AnyOf) {
            return $node; // leaves not removed, and Not, stay as they are
        }
        $kept = array_values(array_filter(array_map(static fn(Node $n): ?Node => self::without($n, $remove), $node->nodes), static fn(?Node $n): bool => $n !== null));

        return match (count($kept)) {
            0 => null,
            1 => $kept[0],
            default => $node instanceof AllOf ? new AllOf($kept) : new AnyOf($kept),
        };
    }

    /** @param non-empty-list<Term|Phrase|FieldScoped> $ignored */
    public static function warning(array $ignored): string { /* see decision 5 */ }
}
```

- [ ] **Step 4:** unit + stan + cs green. **Step 5:** commit.

### Task 2: `relax_when_empty` threshold

**Files:** `src/Core/Ranking/Thresholds.php`, `src/Core/Wizard/Export/ArrayExporter.php`; tests `tests/Unit/Core/Ranking/ThresholdsTest.php`, `tests/Unit/Core/Wizard/ExportersTest.php`, `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php`.

- [ ] **Step 1: failing tests:** default `true`; `with(['relax_when_empty' => false])` and `'false'` → false; `'maybe'` → `InvalidDefinition` naming the key; array export of a definition with it `false` contains `'relax_when_empty' => false` and reloads equal; YAML export contains `      relax_when_empty: false`; the array loader reads it from `thresholds:`.
- [ ] **Step 2:** FAIL. **Step 3:** add `public bool $relaxWhenEmpty = true` (last constructor parameter), the `relax_when_empty` key in `with()` (parsed with `filter_var(..., FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)` for strings/ints), and the key in `ArrayExporter::thresholds()`.
- [ ] **Step 4:** green. **Step 5:** commit.

### Task 3: probe SQL (`FuzzyQueryCompiler::leafConditions()`, `SearchSqlBuilder::probe()`)

**Files:** `src/Engine/Postgres/Sql/FuzzyQueryCompiler.php`, `src/Engine/Postgres/Sql/SearchSqlBuilder.php`; tests `tests/Unit/Postgres/FuzzyQueryCompilerTest.php`, `tests/Unit/Postgres/SearchSqlBuilderTest.php`.

- [ ] **Step 1: failing tests:** `leafConditions([wireless, alu], $params, [], fuzzy: true)` gives `(s.tsv @@ q.ft0 OR q.fn1 <% s.fz)` / `(s.tsv @@ q.ft2 OR q.fn3 <% s.fz)` and four `q` columns; `fuzzy: false` gives `s.tsv @@ q.ft0` / `s.tsv @@ q.ft1`; a stop-word leaf yields `null`. `probe()` pins the statement:

```sql
WITH q AS MATERIALIZED (SELECT to_tsquery('english_unaccent'::regconfig, :p0) AS ft0, ...)
SELECT
    EXISTS (SELECT 1 FROM "fuzzphony_products" AS s WHERE s.tsv @@ q.ft0 AND s."brand_id" = :p2 LIMIT 1) AS l0,
    EXISTS (SELECT 1 FROM "fuzzphony_products" AS s WHERE s.tsv @@ q.ft1 AND s."brand_id" = :p3 LIMIT 1) AS l1
FROM q
```

and no user text appears in the SQL.
- [ ] **Step 2:** FAIL. **Step 3:** implement:

```php
/** @param list<Node> $leaves @param list<string> $emptyQueries @return array{predicates: list<string|null>, columns: list<string>} */
public function leafConditions(array $leaves, ParameterBag $params, array $emptyQueries, bool $fuzzy): array
{
    $this->columns = [];
    $predicates = [];
    foreach ($leaves as $leaf) {
        $tsquery = $this->exact($leaf, $emptyQueries);
        $predicates[] = match (true) {
            $tsquery === null => null,
            $fuzzy => $this->leaf($leaf, $params, $emptyQueries)['predicate'] ?? null,
            default => $this->matches($tsquery, $params),
        };
    }

    return ['predicates' => $predicates, 'columns' => $this->columns];
}
```

`probe()` builds `q` from the columns and one `EXISTS (...) AS l<i>` (or `NULL AS l<i>` for a dropped leaf) per leaf, each with its own `FilterCompiler::compile()` (fresh placeholders).
- [ ] **Step 4:** green. **Step 5:** commit.

### Task 4: engine: pipeline extraction + relaxation

**Files:** `src/Engine/Postgres/PostgresEngine.php`; tests `tests/Conformance/EngineConformanceTestCase.php`, `tests/Integration/PostgresEngineTest.php`, `tests/Integration/TenantScopingTest.php`.

- [ ] **Step 1: failing integration tests** (fixture: 1 Wireless mouse "Silent wireless mouse for the office", 2 Gaming mouse, 3 Wireless headphones "Noise cancelling ...", 4 Mouse pad, 5 torch "Kitchen torch for caramelising"):
  - `wireless mouse offfice` (typo of a description-only word) → `[1]`, warning `... "offfice".`, `interpretedAs` `(wireless AND mouse)`.
  - `mouse torch` (both exist, never together) → empty, no warning, no probe statement... (the probe runs and finds both satisfiable: labels `['full-text', 'fallback: full-text + fuzzy', 'relaxation probe']`).
  - `wireless mouse` has hits → labels without a probe.
  - `relax_when_empty: false` → empty, no warning, no probe.
  - `fuzzy_mode: never` still relaxes (`wireless mouse zzzqqq` → `[1]`).
  - tenant: tenant 3 (Sony) searching `wireless gaming` ("gaming" only exists for tenant 2) → `[3]` with `gaming` reported, and never an id outside tenant 3; filter `in_stock = false` + `wireless mouse` → "mouse" is unsatisfiable under that filter → `[3]`.
  - explain labels `relaxation probe` and `relaxed: ...`.
- [ ] **Step 2:** FAIL. **Step 3:** move the statement building of `execute()` into `pipeline(IndexDefinition, ?Node $root, list<Condition>, RankingProfile, Thresholds, SearchQuery, string $labelPrefix): array{rows, statements, usedFuzzy, threshold, tsquery, fuzzy: bool, warnings}` (unchanged SQL and order), then in `execute()`:

```php
if ($thresholds->relaxWhenEmpty && $root !== null && self::total($run['rows']) === 0) {
    $leaves = Relaxation::positiveLeaves($root);
    // stop words are ignored by the search already; never report them
    ...
    if (count($probed) >= 2) {
        $probe = ['label' => 'relaxation probe'] + $builder->probe($probed, $run['fuzzy'], $conditions, $thresholds, $empty);
        $row = $this->run($probe, $run['fuzzy'] ? $thresholds->fuzzySimilarity : null)[0];
        $unsatisfiable = [leaves whose l<i> is false];
        if ($unsatisfiable !== [] && count($unsatisfiable) < count($probed)) {
            $root = Relaxation::without($root, $unsatisfiable);
            $run = $this->pipeline(..., 'relaxed: ');
            $warnings[] = Relaxation::warning($unsatisfiable);
        }
    }
}
```

- [ ] **Step 4:** unit, stan, cs, integration green. **Step 5:** commit (engine first, promptly).

### Task 5: acceptance on 200 000 rows

- [ ] `docker cp benchmarks/seed.sql fz-relax-pg:/seed.sql`; `CREATE DATABASE fuzzbench` + extensions; `MSYS_NO_PATHCONV=1 docker exec fz-relax-pg psql -U fuzzphony -d fuzzbench -v rows=200000 -f /seed.sql`.
- [ ] Scratch PHP script (scratchpad, not committed) building the demo-shaped index (`name` A fuzzy, `brand` B fuzzy, `category` C, `description` D, boost/recency as the demo), schema apply + reindex + `ANALYZE`, then the four queries and `EXPLAIN (ANALYZE, BUFFERS)` of the probe statement.
- [x] Record the results below.

**Results (PostgreSQL 17, 200 000 rows, demo-shaped index, fallback mode, `min_score` 0.01):**

| query | total | warning | notes |
|---|---:|---|---|
| `wireless mouse aluminum` | 1 666 | `No results for all words; ignored words that match nothing: "aluminum".` | `interpretedAs` `(wireless AND mouse)`; all 1 666 hits are `Wireless mouse ####`, the same ids as `wireless mouse`; statements `full-text`, `fallback: full-text + fuzzy`, `relaxation probe`, `relaxed: full-text`; ~100 ms end to end |
| `wireless mouse aluminium` | 238 | none | unchanged, ~10 ms |
| `wireles mice` | 1 666 | none | unchanged, fuzzy |
| `mouse kettle` | 0 | none | the probe runs and finds both words; ~55-65 ms (was ~27 ms: probe + stop-word lookup) |

**Probe plan (added decision 9).** As first written (`EXISTS (... LIMIT 1)`, default planner settings) the probe took **600-770 ms**: `LIMIT 1` makes the planner pick a sequential scan that stops at the first match, which is instant for the common words and reads all 200 000 rows, evaluating `<%` row by row, for `aluminum`, the very word the probe is looking for. With `enable_seqscan = off` it used the GIN bitmaps (13-15 ms) but with an `in_stock = false` filter it walked the btree index of the filter instead (100-180 ms, 28 571 rows removed by filter). The shipped probe runs with `enable_seqscan` and `enable_indexscan` off (transaction-local, like the similarity threshold; `explain()` applies the same settings), leaving only bitmap scans: `BitmapOr(GIN tsv, GIN fz)`, `BitmapAnd` with the filter's btree when there is one. Median of 5 warm runs, `EXPLAIN (ANALYZE, BUFFERS)`: **14-25 ms unfiltered** (188 shared buffers; the unmatched word's `EXISTS` 0.04 ms, the common words 6-8 ms each for building their bitmaps), `in_stock = false` 28 ms, `brand_id = 3` 19 ms, `price < 5000` 20 ms, `category_id = 1` 19 ms.

### Task 6: docs

- [ ] README: Query syntax (replace "the whole query finds nothing rather than ignoring that word" with the relaxation), Thresholds table row `relax_when_empty`. CHANGELOG `## Unreleased`: added entry, and fix the "finds nothing" claim. Each file re-read right before editing and committed on its own.

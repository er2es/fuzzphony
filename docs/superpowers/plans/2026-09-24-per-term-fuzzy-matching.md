# Per-term Fuzzy Matching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the typo-tolerant (fuzzy) branch satisfy every query word individually, exactly or fuzzily, combined through the query's real AND / OR / NOT structure, instead of one trigram check of the whole query against the whole `fz` blob. Acceptance: on the demo catalogue `wireles mice` returns exactly 1 666 hits, all `Wireless mouse ####`.

**Architecture:** A new `@internal` `FuzzyQueryCompiler` (`src/Engine/Postgres/Sql/`) walks the AST like `TsQueryCompiler` and produces a `FuzzyMatch` value object: a boolean `predicate` for the fuzzy CTE's `WHERE`, and a numeric `score` for `r_fuzzy`. Each leaf becomes `(s.tsv @@ to_tsquery(cfg, :tsq) OR fuzzphony_norm(:needle) <% s.fz)` inline in the `WHERE` (placement A of the spec; see Task 0), so PostgreSQL combines the GIN(tsv) and GIN(fz trigram) indexes with `BitmapOr` / `BitmapAnd`. The exact side reuses `TsQueryCompiler` for the leaf (lexemes, weight labels, prefix `:*`). `SearchSqlBuilder::ranked()` takes `?Node $fuzzyRoot` instead of `bool $withFuzzy` + `?string $exclusions`; the compiler registers its parameters in the statement's own `ParameterBag`. `PostgresEngine::execute()` adds `hasFuzzyLeaf()` to fuzzy eligibility (replacing the whole-query length check), and the top-level-only `excl` mechanism disappears because `Not` is compiled in place at any depth.

**Tech Stack:** PHP 8.4, PostgreSQL 15+ (measured on 17), PHPUnit 12, PHPStan (max + strict-rules), php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-09-24-per-term-fuzzy-matching-design.md`

## Global Constraints

- `vendor/bin/phpunit --testsuite=unit`, `composer stan` and `composer cs` green after every task (zero PHPStan errors over `src/` and `tests/`).
- Integration suite after every task that touches SQL or the engine, against a throwaway PostgreSQL 17 (`docker run -d --name fz-perterm-pg -e POSTGRES_USER=fuzzphony -e POSTGRES_PASSWORD=fuzzphony -e POSTGRES_DB=fuzzphony -p 5436:5432 postgres:17`, then `CREATE EXTENSION pg_trgm; CREATE EXTENSION unaccent;`): `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5436;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --testsuite=integration`.
- Narrowing `mixed` goes through `Fuzzphony\Core\Support\Coerce`, never a bare cast.
- Every user-derived string is a bound parameter; nothing user-derived is inlined. Native prepared statements cannot reuse a placeholder name, so every occurrence of a leaf value (predicate and score) is registered with `$params->add()` separately.
- No `composer install/update/require` in the shared checkout. The demo containers (`demo-db-1`, `demo-app-1`) are read-only: `fuzzphony:search` and read-only `psql` only. Do not touch `demo/`.
- Commit per task, staging explicit paths only (never `git add -A`). Do not push.

## Review Focus

- **Recall must not drop for true matches.** The per-term predicate is a strict superset per leaf of the strict tsquery (`exact OR fuzzy`), so every document the strict branch finds also satisfies the fuzzy predicate. The union with the `fts` CTE is unchanged.
- **Stop words.** The strict tsquery silently drops stop words inside PostgreSQL (`'mouse' & 'for'` = `'mouse'`). A per-term fuzzy predicate that required `'for'` to match fuzzily would return *nothing* on the demo (whose `fz` holds only name + brand). Leaves the configuration reduces to nothing must be dropped the same way (Task 3).
- **Index usage.** No sequential scan of the sidecar table; `BitmapAnd`/`BitmapOr` over the two GIN indexes. Proven at 1 000 000 rows in Task 0.
- **Parameter uniqueness.** One placeholder per occurrence; `SearchSqlBuilderTest::testOnlyUserInputIsBound` pins the names.
- **Negation at any depth** is honoured by the fuzzy branch (the old path only re-applied top-level exclusions).

## Plan-time decisions (the spec's open questions)

1. **Placement:** A (parameters inline in the `WHERE`). Measured on the 200 000-row demo: 15.8 ms, `BitmapAnd` of two `BitmapOr` (tsv GIN + fz trigram GIN), versus 73.5 ms for the old fuzzy statement. B (values as `q` columns) 19.2 ms with the same plan; C (semi-joins) far worse (74k buffers). `fuzzphony_norm` is `IMMUTABLE`, so `fuzzphony_norm(:p) <% s.fz` is an index qual. Confirmed at 1 000 000 rows in Task 0.
2. **Shape:** `FuzzyQueryCompiler(IndexDefinition, Thresholds)` with `hasFuzzyLeaf(Node, list<string> $emptyQueries = []): bool` (pure AST walk), `leafQueries(Node): list<string>`, and `compile(Node, ParameterBag, list<string> $emptyQueries = []): ?FuzzyMatch` registering parameters in the caller's bag. `FuzzyMatch` is `final readonly` with `predicate` and `score`. The spec's `hasFuzzyLeaf` flag lives on the compiler (a pure AST question the engine asks before any SQL is built), not on `FuzzyMatch`.
3. **Stop words (addition to the spec):** the spec says "a leaf whose tsquery is empty (a stop word) is dropped". Whether a word is a stop word depends on the PostgreSQL text-search configuration, which PHP cannot know. Folding `numnode(to_tsquery(cfg, :p)) = 0` into the predicate works only while PostgreSQL uses custom plans (a generic plan would turn every `OR` into a non-indexable filter and a sequential scan). Instead, right before a fuzzy statement is built, the engine asks PostgreSQL once which leaf tsqueries are empty (`SELECT ... WHERE numnode(to_tsquery(cfg, q)) = 0` over `unnest(:queries)`), and passes that list to `hasFuzzyLeaf()` / `compile()`. One extra round trip, only when the fuzzy branch is about to run.
4. **Needle length:** `mb_strlen` of the needle without spaces (same rule the old whole-query check used), so the phrase `"a b"` counts 2 letters.
5. **Score of a non-fuzzy leaf** (below `fuzzyMinLength`, or no fuzzy fields): `CASE WHEN exact THEN 1 ELSE 0 END`, so it still takes part in the mean.
6. **`q` CTE:** the fuzzy CTE no longer joins `q` (nothing in it reads `q`); `q.tsq` / `q.norm` stay for `fts` and the exact/prefix bonuses. `$plain` stays for `q.norm` only.
7. `NodeInspector::topLevelExclusions()` becomes unused by the engine. It is public Core API, so it is kept (docblock updated), not removed.

---

### Task 0: scale confirmation (1 000 000 rows) — run after Task 2, before the engine ships the new statement

The 200 000-row numbers above were measured with hand-written SQL. This task measures the SQL the compiler *actually* generates.

- [ ] **Step 1:** seed the throwaway container: `docker cp benchmarks/seed.sql fz-perterm-pg:/seed.sql`, `CREATE DATABASE fuzzbench` (+ `pg_trgm`, `unaccent`), then `MSYS_NO_PATHCONV=1 docker exec fz-perterm-pg psql -U fuzzphony -d fuzzbench -v rows=1000000 -f /seed.sql`.
- [ ] **Step 2:** build the index exactly like the benchmark: `FUZZPHONY_BENCH_DSN="pgsql:host=127.0.0.1;port=5436;dbname=fuzzbench;user=fuzzphony;password=fuzzphony" php benchmarks/run.php --setup-only` (schema apply + reindex + `ANALYZE`).
- [ ] **Step 3:** with a scratch script using `SearchSqlBuilder::ranked()` (new) and the pre-change statement text, run `EXPLAIN (ANALYZE, BUFFERS)` through PDO (same prepared-statement path as production, `set_config('pg_trgm.word_similarity_threshold', '0.3', true)` in the same transaction) for: `wireles mice`, an OR query (`wireles | kettel`), a NOT query (`wireles -mouse`), and a common + common query (`wireless mouse`, forced fuzzy). Best of 5 warm runs.
- [ ] **Step 4:** PASS = no `Seq Scan` on `fuzzphony_bench` in any plan, GIN indexes used, and new time <= 2x the old fuzzy statement. If it fails: stop, record the numbers, reopen the spec.

**Results:** _(recorded below after the run)_

---

### Task 1: `FuzzyMatch` + `FuzzyQueryCompiler`

**Files:**
- Create: `src/Engine/Postgres/Sql/FuzzyMatch.php`, `src/Engine/Postgres/Sql/FuzzyQueryCompiler.php`
- Test: `tests/Unit/Postgres/FuzzyQueryCompilerTest.php`

- [ ] **Step 1: Write the failing tests.** `FuzzyQueryCompilerTest` pins the full SQL of one leaf and all four parameters (`p0` tsquery, `p1` needle for the predicate, `p2` needle, `p3` tsquery for the score), then checks structure through a `shorthand()` helper that substitutes bound values and abbreviates leaves (`fz[tsquery|needle]`, `sim[needle|tsquery]`, `ts[tsquery]`, `hit[tsquery]`). Cases:

```php
yield 'every word must match on its own' => ['wireles mice', "(fz['wireles'|wireles] AND fz['mice'|mice])", "((sim[wireles|'wireles'] + sim[mice|'mice']) / 2)"];
yield 'or' => ['mouse | trackpad', "(fz['mouse'|mouse] OR fz['trackpad'|trackpad])", "GREATEST(sim[mouse|'mouse'], sim[trackpad|'trackpad'])"];
yield 'negation is exact only and does not score' => ['mouse -cable', "(fz['mouse'|mouse] AND NOT (ts['cable']))", "sim[mouse|'mouse']"];
yield 'negation nested in a group' => ['(mouse -cable) | trackpad', "((fz['mouse'|mouse] AND NOT (ts['cable'])) OR fz['trackpad'|trackpad])", "GREATEST(sim[mouse|'mouse'], sim[trackpad|'trackpad'])"];
yield 'nested groups' => ['wireless (mouse | "usb receiver")', "(fz['wireless'|wireless] AND (fz['mouse'|mouse] OR fz[('usb' <-> 'receiver')|usb receiver]))", "((sim[wireless|'wireless'] + GREATEST(sim[mouse|'mouse'], sim[usb receiver|('usb' <-> 'receiver')])) / 2)"];
yield 'phrase is one needle' => ['"noise cancelling"', "fz[('noise' <-> 'cancelling')|noise cancelling]", '...'];
yield 'prefix keeps :* on the exact side, the prefix text is the needle' => ['ergnoo*', "fz['ergnoo':*|ergnoo]", '...'];
yield 'field scope: exact side weighted, fuzzy side on the whole fz column' => ['name:sony', "fz['sony':A|sony]", '...'];
yield 'words below fuzzyMinLength stay exact' => ['ab mouse', "(ts['ab'] AND fz['mouse'|mouse])", "((hit['ab'] + sim[mouse|'mouse']) / 2)"];
```

plus: stop-word leaves dropped (`compile(parse('mouse for gamng'), $params, ["'for'"])`), a leaf without letters/digits dropped, `compile()` returns null for `Not` alone and for an all-stop-word query, user text never in the SQL (only in parameters), `hasFuzzyLeaf()` true/false cases (below min length, only negated, stop words, higher `fuzzyMinLength`, index without fuzzy fields), `leafQueries()` de-duplicated in order.

- [ ] **Step 2:** `vendor/bin/phpunit --filter FuzzyQueryCompilerTest` → FAIL (class not found).
- [ ] **Step 3: Implement.** `FuzzyMatch`:

```php
final readonly class FuzzyMatch
{
    public function __construct(
        /** Boolean expression for the WHERE of the fuzzy candidate CTE. */
        public string $predicate,
        /** Numeric expression (0..1) used as r_fuzzy. */
        public string $score,
    ) {}
}
```

`FuzzyQueryCompiler` (core of it):

```php
private function leaf(Node $node, ParameterBag $params, array $empty): ?array
{
    $tsquery = $this->exact($node, $empty);            // TsQueryCompiler output, null when dropped
    if ($tsquery === null) {
        return null;
    }
    $needle = $this->needle($node);                    // lexemes joined; null when too short / no fuzzy fields
    if ($needle === null) {
        return ['predicate' => $this->matches($tsquery, $params), 'score' => sprintf('CASE WHEN %s THEN 1 ELSE 0 END', $this->matches($tsquery, $params))];
    }

    // each occurrence is bound separately: native prepares cannot reuse a placeholder name
    return [
        'predicate' => sprintf('(%s OR %s <%% s.fz)', $this->matches($tsquery, $params), $this->norm($needle, $params)),
        'score' => sprintf('GREATEST(word_similarity(%s, s.fz), CASE WHEN %s THEN 1 ELSE 0 END)', $this->norm($needle, $params), $this->matches($tsquery, $params)),
    ];
}
```

`group()` joins child predicates with `AND` / `OR`; score = `((a + b) / n)` over scored children for `AllOf`, `GREATEST(a, b)` for `AnyOf`; one child collapses to itself; none → null. `Not` → `NOT (s.tsv @@ to_tsquery(cfg, :p))`, score null. `matches()` = `s.tsv @@ to_tsquery('<config>'::regconfig, :p)`, `norm()` = `fuzzphony_norm(:p)`.

- [ ] **Step 4:** tests pass; `composer stan`, `composer cs` green.
- [ ] **Step 5:** commit `src/Engine/Postgres/Sql/FuzzyMatch.php src/Engine/Postgres/Sql/FuzzyQueryCompiler.php tests/Unit/Postgres/FuzzyQueryCompilerTest.php`.

---

### Task 2: `SearchSqlBuilder::ranked()` takes the fuzzy root

**Files:**
- Modify: `src/Engine/Postgres/Sql/SearchSqlBuilder.php` (`ranked()`)
- Modify: `src/Engine/Postgres/PostgresEngine.php` (call sites only, minimal: pass `$alwaysFuzzy ? $root : null` / `$root`), so the tree stays green
- Test: `tests/Unit/Postgres/SearchSqlBuilderTest.php`

- [ ] **Step 1: Update the tests deliberately.**
  - `testOnlyUserInputIsBound`: `ranked("'mouse'", 'mouse', new Term('mouse'), $conditions, ...)` now binds `p0 "'mouse'"` (q.tsq), `p1 'mouse'` (q.norm), `p2 500` (fts filter), `p3 "'mouse'"`, `p4 'mouse'` (fuzzy predicate), `p5 'mouse'`, `p6 "'mouse'"` (fuzzy score), `p7 500` (fuzzy filter). Assert `fuzzphony_norm(:p4) <% s.fz` and that `q.norm <% s.fz` is gone.
  - `testExclusionsAlsoFilterFuzzyCandidates` → `testNegationIsCompiledIntoTheFuzzyPredicateAtAnyDepth`: root parsed from `(mouse -cable) | trackpad`; assert `AND NOT (s.tsv @@ to_tsquery(` inside the fuzzy CTE and `"'cable'"` among the params; no `excl` anywhere.
  - `testTextOnlyHasNoFuzzyBranch`: `ranked(..., null, ...)` → no `fuzzy AS (`, `0 AS fuzzy_n`.
  - new `testStopWordsAreDroppedFromTheFuzzyBranch`: `emptyQueries: ["'for'"]` → `'for'` not bound.
- [ ] **Step 2:** FAIL (signature).
- [ ] **Step 3: Implement.** New signature:

```php
/**
 * @param list<Condition> $conditions
 * @param Node|null       $fuzzyRoot    the parsed query when the fuzzy branch runs, else null
 * @param list<string>    $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
 */
public function ranked(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $limit, int $offset, array $emptyQueries = []): array
```

The fuzzy CTE, compiled at the point it is built so parameters keep their order:

```php
$fuzzy = $fuzzyRoot === null ? null : (new FuzzyQueryCompiler($this->index, $thresholds))->compile($fuzzyRoot, $params, $emptyQueries);
if ($fuzzy !== null) {
    $ctes[] = sprintf(
        "fuzzy AS (\n    SELECT s.id, (%s)::double precision AS r_fuzzy\n    FROM %s AS s\n    WHERE %s AND %s\n    LIMIT %d\n)",
        $fuzzy->score, $table, $fuzzy->predicate, $filters->compile($conditions, $params), $candidates,
    );
}
```

The `excl` column of `q` and the `$withFuzzy && $plain !== ''` guard disappear.
- [ ] **Step 4:** unit + stan + cs + integration green (engine behaviour already per-term through the minimal call-site change).
- [ ] **Step 5:** commit.

---

### Task 3: engine wiring — eligibility, stop words, no more `excl`

**Files:**
- Modify: `src/Engine/Postgres/PostgresEngine.php` (`execute()`, new private `emptyQueries()`)
- Modify: `src/Core/Query/Ast/NodeInspector.php` (docblock of `topLevelExclusions()` only)
- Test: `tests/Conformance/EngineConformanceTestCase.php`, `tests/Integration/PostgresEngineTest.php`

- [ ] **Step 1: Failing tests (real PostgreSQL).** Conformance (engine-agnostic, `Capability::Fuzzy`):
  - `testATypoOnOneWordStillRequiresTheOtherWord`: `wireles headphones` → exactly `[3]` (old blob path also returned 1, the other "Wireless" product).
  - `testFuzzyOrGroup`: `(headphnoes | torhc)` → `[3, 5]` in any order, `usedFuzzy`.
  - `testNegationNestedInAGroupIsHonouredByTheFuzzyBranch`: `wireles (mouse -silent)` → `[]`-free of 1: product 1 ("Silent wireless mouse") excluded, product 2? (no "wireles") … see test code.
  - `testFuzzyPhrase`: `"wireles headphones"` → `[3]`.
  - `testBothWordsOutrankOneWord` (always mode): `wireles mouse` ranks 1 above 4 / 2.
  - `testStopWordsDoNotBlockTypoTolerance`: `headphnoes for` → `[3]` (with "for" required fuzzily this returned nothing).
  - Integration: `never` mode never goes fuzzy; `fallback` goes fuzzy only below `fallbackBelow`; `always` goes fuzzy on the first statement; statements carry no `excl`.
- [ ] **Step 2:** FAIL where the behaviour is new (stop words, ranking).
- [ ] **Step 3: Implement.**

```php
$fuzzy = new FuzzyQueryCompiler($index, $thresholds);
$fuzzyRoot = $root !== null
    && $index->hasFuzzy()
    && $profile->fuzzy > 0.0
    && $thresholds->fuzzyMode !== FuzzyMode::Never
    && $fuzzy->hasFuzzyLeaf($root) ? $root : null;
```

Before each fuzzy statement: `$empty = $this->emptyQueries($index, $fuzzy->leafQueries($fuzzyRoot));` and run it only if `$fuzzy->hasFuzzyLeaf($fuzzyRoot, $empty)`.

```php
private function emptyQueries(IndexDefinition $index, array $queries): array
{
    // SELECT t.q FROM unnest(CAST(:queries AS text[])) AS t(q) WHERE numnode(to_tsquery('<cfg>'::regconfig, t.q)) = 0
}
```

`$exclusions` / `topLevelExclusions()` are no longer used by the engine.
- [ ] **Step 4:** unit + integration + stan + cs green.
- [ ] **Step 5:** commit.

---

### Task 4: acceptance test on the demo (read-only)

- [ ] `MSYS_NO_PATHCONV=1 docker exec demo-app-1 sh -c "cd /app/demo && php bin/console fuzzphony:search catalog 'wireles mice' --limit 50"` → exactly 1,666 hits.
- [ ] Every id is a `Wireless mouse ####`: `SELECT count(*) FILTER (WHERE name ~ '^Wireless mouse \d+$'), count(*) FROM bench_product WHERE id IN (...)` through `docker exec demo-db-1 psql` (read-only), and the total checked with the per-term SQL.
- [ ] Sanity: `wireless mouse`, `hedphones`, `ergnoo*`, `wireless -mouse`.

### Task 5: benchmarks before / after

- [ ] Before: `git archive bdef356 | tar -x -C <scratch>/before`, `composer install` inside the scratch dir, `php benchmarks/run.php --markdown` against the 1M-row `fuzzbench` database. After: the same from the checkout. Record both below.

### Task 6: docs

- [ ] README: Query syntax / Ranking / Thresholds — per-term fuzzy semantics, stop words, the field-scoped limitation (`name:sony`).
- [ ] CHANGELOG `## Unreleased`: fuzzy result sets get narrower; `r_fuzzy` of strict matches shifts in `always` mode; one extra stop-word lookup before a fuzzy statement.

---

## Results

_(filled in while executing)_

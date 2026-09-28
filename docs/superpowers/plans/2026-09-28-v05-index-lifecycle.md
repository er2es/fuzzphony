# v0.5 Index lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the index's life safe in production: a full reindex is built next to the live index and swapped in atomically (zero downtime), a `TRUNCATE` in queue mode queues one rebuild job instead of every id, `brand:x` searches the `brand` field only (full text and typos), `TRUNCATE` of a single partition is followed, and `schema --apply` upgrades an older sidecar layout (layout 1 → 2) while the doctor also checks the shared (`*`) version row.

**Architecture:** Everything stays in the PostgreSQL engine behind the `Engine` SPI; `Reindexer` stays engine-agnostic and drives four new SPI methods (`beginRebuild`, `refreshShadow`, `finishRebuild`, `abortRebuild`) plus `rebuildRequested` for the worker. A new internal `Fuzzphony\Engine\Postgres\ShadowRebuild` runs the rebuild: a second table `fuzzphony_<index>__next` filled by a second static refresh function, a change log `fuzzphony_<index>__changes` fed by a row trigger on the live table while the rebuild runs, a catch-up from that log, and one transaction under `ACCESS EXCLUSIVE` that refreshes the last logged ids and swaps the tables. Every new DDL string comes from `PostgresSchemaGenerator`, every name from `Names`. Field scoping adds per-field columns (`t_<field>`, `z_<field>`) filled by the refresh function and checked, next to the existing GIN-indexed `tsv` / `fz`, by both query compilers.

**Tech Stack:** PHP 8.4, PostgreSQL 15+ (tests on 17/18), PHPUnit 11.5 / 12, PHPStan 2 (level max + strict rules), php-cs-fixer (PER-CS 2.0), Infection, Symfony 7.4/8, Doctrine DBAL 4.

**Spec:** `docs/superpowers/specs/2026-09-28-v05-index-lifecycle-design.md` (rulings R1–R6 and the 7-step implementation order are binding; the deviations are listed and argued under "Plan-time decisions" below). Read both before starting any task.

## Global Constraints

- PHP 8.4, `declare(strict_types=1);` in every file.
- `composer stan` (PHPStan level max + phpstan-strict-rules, paths `src` and `tests`) reports **0 errors** after every task.
- `composer cs` (php-cs-fixer dry run) is clean after every task; fix with `composer cs:fix`.
- **100% line coverage of `src/`** after every task; no new `@codeCoverageIgnore`.
- New and changed tests must **kill the mutants of the lines they cover**; the PR's `mutation / diff` job runs `vendor/bin/infection --threads=4 --only-covering-test-cases --show-mutations=0 --git-diff-lines --git-diff-base=main`. Assert exact SQL, exact strings, exact values and both branches of every condition you add.
- Every breaking change goes under `### Breaking` in `CHANGELOG.md` `## [Unreleased]` **and** into `UPGRADE.md` `## From 0.4 to 0.5` (Task 1 creates it above `## From 0.3 to 0.4`) **in the same task** that makes the change. Docs describing changed behaviour (`README.md`, `docs/*.md`, `demo/README.md`) change in the same task too.
- Withers construct through the constructor; `mixed` is narrowed with `Fuzzphony\Core\Support\Coerce` (or a `(bool)` cast of a PostgreSQL boolean, as the existing code does); never an unchecked cast.
- Every user-derived value in SQL is a bound parameter; `Connection` placeholders are named and each is used exactly once. Every Fuzzphony database object name comes from `Names` (schema-qualified SQL from the non-`Name` methods, bare names from the `…Name` methods).
- New public classes / public constructor parameters go into `PublicApiTest::PUBLIC` and `docs/architecture.md` "Public API"; new classes that are not public API are tagged `@internal`. (This plan adds no public class; it adds one internal class, `ShadowRebuild`, so `PublicApiTest` counts 121 classes from Task 3 on.)
- Commits: explicit paths only (`git add <paths>`), never `git add -A` / `.`, never `--amend`, never `--no-verify`; short imperative subject; the message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Do not push.
- Implementers work in the git worktree and the prepared PostgreSQL container the controller supplies (`FUZZPHONY_TEST_DSN` is set in that environment). Never run `composer update` / `composer require`, and never `composer install` in the shared checkout `D:\xampp_php7\htdocs\fuzzphony`. Any throwaway container you start yourself is removed with `docker rm -fv <name>`; never prune volumes.
- **The gate** (run before every task's commit; all of it must pass):

  ```bash
  vendor/bin/phpunit --testsuite=unit
  vendor/bin/phpunit --testsuite=integration
  composer stan
  composer cs
  php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --only-summary-for-coverage-text   # Lines: 100.00% (or XDEBUG_MODE=coverage)
  vendor/bin/infection --threads=4 --only-covering-test-cases --show-mutations=0 --git-diff-lines --git-diff-base=main   # no escaped mutant on changed lines
  ```

  The integration suite needs `FUZZPHONY_TEST_DSN`; without it the integration tests are skipped, which does **not** count as passing.

## Plan-time decisions (spec gaps and contradictions, resolved)

1. **Catch-up from a change log, not from `indexed_at` (R1).** The spec's catch-up ("re-refresh every id whose live row has `indexed_at >= build start`, prune the shadow's orphans, then repeat the delta under the lock") has four defects: (a) `indexed_at` has no index, so both catch-ups, including the one under `ACCESS EXCLUSIVE`, scan the whole live table: that is a search outage proportional to the index size; (b) a row deleted between the orphan prune and the lock has no live row any more, so no timestamp finds it; (c) `indexed_at = now()` is the *writer's transaction start*, so a trigger-mode write in a transaction that began before the threshold but committed after the catch-up read is missed; (d) a resumed run (`--from`) would need the original start time persisted somewhere. Resolution: `beginRebuild()` creates a log table `fuzzphony_<index>__changes (id PRIMARY KEY)` and a row trigger `fuzzphony_track_<index>` on the **live** table (`AFTER INSERT OR UPDATE OR DELETE`, inserting the id, `ON CONFLICT DO NOTHING`). Every change to the live table while the rebuild runs is logged in the writer's own transaction. `finishRebuild()` refreshes logged ids into the shadow in batches (`DELETE … RETURNING` + the shadow refresh function in one statement, so a failure keeps them logged), then takes `ACCESS EXCLUSIVE` on the live table: that waits for every transaction that wrote it, so the log is complete; it refreshes the (tiny) rest and swaps in the same transaction. Deletions are logged like any change (the shadow refresh deletes ids the source no longer returns), so no orphan prune of the shadow is needed; the log survives a crash, so resume needs no stored start time. Consequence: `finishRebuild(IndexDefinition $index): void` has no `$startedAt` parameter. Recorded as ADR 0008 (Task 3).
2. **The swap drops the live table instead of renaming it to `__old` first (R1).** Inside the one swap transaction, "rename live → `__old`, …, drop `__old`" and "drop live, …" are indistinguishable to every other session, and dropping first frees the live index and constraint names for the renames. No `__old` names are needed.
3. **Engine SPI signatures (R1, R2).** The spec's `beginRebuild(IndexDefinition): void` cannot express resume ("continue an existing shadow if there is one, else run in place") nor the in-place fallback of decision 5. Final SPI:
   - `beginRebuild(IndexDefinition $index, bool $resume = false): bool` — takes the rebuild lock (throws `InvalidArgument` "A rebuild of "<index>" is already running." when another session holds it); a full run (`$resume` false) discards a leftover rebuild and starts an empty one; returns **false, with the lock released,** when the run must go in place (resume without a leftover rebuild, or decision 5);
   - `refreshShadow(IndexDefinition $index, array $ids): int`;
   - `finishRebuild(IndexDefinition $index): void` (catch-up, swap, release the lock);
   - `abortRebuild(IndexDefinition $index, bool $keepShadow = false): void` (release the lock; discard the rebuild unless `$keepShadow`);
   - `rebuildRequested(IndexDefinition $index): bool` (R2: the worker asks whether a `'*'` job is queued; a fifth method the spec's worker needs because `Worker` only knows the engine).
4. **The advisory lock lives in the engine (R1).** `Reindexer` must stay engine-agnostic, so `PostgresEngine::beginRebuild()` takes `pg_try_advisory_lock(hashtext(:key))` with `:key = 'fuzzphony:' || schema || '.' || index` (spec), and `finishRebuild()` / `abortRebuild()` release it. Only runs that build a shadow take it; an in-place run is the idempotent 0.4 upsert and takes no lock. The lock is session-level, so the reindex needs a session connection (not a transaction-pooling PgBouncer); documented.
5. **In-place fallback when a shadow cannot be built.** A shadow build is DDL: `CREATE` on Fuzzphony's schema, ownership of the live sidecar (to drop / rename it) and the 0.5 functions from `schema --apply`. A least-privilege worker role (the demo's `fuzzphony_app`, "no DDL") would otherwise crash on every rebuild job, and a reindex right after upgrading the code but before `schema --apply` would fail. Resolution: `beginRebuild()` checks `has_schema_privilege(schema, 'CREATE') AND pg_has_role(sidecar owner, 'USAGE') AND` both rebuild functions exist; if not, it returns false and the run goes in place, as in 0.4. `fuzzphony:reindex` prints why ("Rebuilt in place: this role cannot build …").
6. **`prune: false` always runs in place.** A swap drops every document the reindexing session cannot see, which is exactly what `prune: false` / `--no-prune` exists to prevent.
7. **An empty source discards the rebuild.** A full run whose source returns no row keeps the live index (the empty shadow is discarded, the lock released, `pruneSkippedEmptySource: true`), unless `pruneEmpty`, which swaps in the empty index. Same safety rule as 0.4's pruning.
8. **`ReindexResult::$pruned` is null for a swapped run.** Orphans go with the old table; counting them would cost a full anti-join. `swapped: true` says what happened; the CLI prints "Built next to the live index and swapped in …". Listed under Breaking.
9. **Grants, owner and the change log's privileges.** A new table belongs to the reindexing role and has no grants, so after a naive swap an application role could no longer read the index, and while the rebuild runs, a writer (the queue worker, a trigger-mode writer) would fail on the log table. Resolution: `beginRebuild()` grants `INSERT` on the log to every role holding `INSERT`, `UPDATE` or `DELETE` on the live table (from `aclexplode(coalesce(relacl, acldefault('r', relowner)))`, so the owner is included); the swap copies every non-owner ACL entry of the live table onto the shadow (keeping `WITH GRANT OPTION`) and gives it the live table's owner.
10. **`'*'` is cleared by `beginRebuild()`, not by the worker's claim (R2).** The spec's claim (`DELETE … RETURNING … SKIP LOCKED` by the worker) loses the job when the rebuild then fails or when another run holds the lock, and deleting it after the rebuild would also delete a job queued *during* it. Resolution: a full `beginRebuild()` deletes the index's `'*'` row right after taking the lock (the rebuild starting now reads the source after that `TRUNCATE`); a job queued while it runs stays for the next cycle. The worker only asks `rebuildRequested()`, runs `Reindexer` with `new ReindexOptions(pruneEmpty: true)` (the trigger established the `TRUNCATE`, so an empty source is intended), and on `InvalidArgument` (another session holds the lock) leaves the job for the next cycle and goes on with the queued ids. A rebuild counts as one processed item. In place (decision 5) the job is cleared too. Consequence: a rebuild job reads the source through `sourceIds()` in the worker's session, like `fuzzphony:reindex`, so the worker's `search_path` must see the source tables (0.4's queue processing only called the refresh function, which pins its own `search_path`); documented, and `DedicatedSchemaTest` sets it.
11. **Exact field scoping on the full-text side (R3).** The strict branch matches one tsquery for the whole query (`s.tsv @@ q.tsq`), so a per-leaf check cannot simply be appended with `AND` (an `OR` sibling or a negation would be wrong). Resolution: `t_<field>` stores the field's own **weighted** tsvector (identical to that field's part of `tsv`), so one weighted leaf tsquery serves both checks; `FuzzyQueryCompiler::scope()` compiles the query's exact-only boolean tree (AND / OR / NOT over the leaves, a known field's leaf as `(s.tsv @@ q.x AND s."t_<field>" @@ q.x)`, stop words dropped like the fuzzy branch does), and `SearchSqlBuilder` adds it to the fts CTE next to `s.tsv @@ q.tsq`, which still finds the candidates through GIN(tsv). `TsQueryCompiler` drops `NOT <known field>:<word>` from the whole tsquery (a weight label there would also exclude other fields of that weight); the recheck applies the exclusion exactly. The engine fetches the stop-word list (one round trip, `emptyQueries()`) when the query has a known field scope. Ranking still uses `ts_rank_cd(tsv, q.tsq)` (spec).
12. **A scoped word of a field that is not fuzzy stays exact-only (R3).** "A scoped typo matches only its field": a non-fuzzy field has no trigram text, so it has no typo matching.
13. **Per-field column names (R3).** `Names::fieldVectorName('brand') = 't_brand'`, `fieldFuzzyName() = 'z_brand'`, through `Names::limit()` as the spec asks (a field name is at most 48 bytes, so the limit never shortens one; filter columns do not use `limit()` at all, contrary to the spec's "like the filter columns"). Unquoted names for `columns()`, quoted ones (`fieldVector()`, `fieldFuzzy()`) for SQL.
14. **The layout bump and its step land in Task 1 (R4).** A runner with no step is untestable under the 100% floor, so Task 1 sets `LAYOUT_VERSION = 2` and adds the 1 → 2 step (clears `documents_hash`); Task 2 adds the columns that give the step its reason. The step is one guarded `DO` block per target layout, emitted right before the meta upsert (it is transactional, so it runs in the apply transaction, before the non-transactional upsert). It nests the `SELECT` inside `IF to_regclass(meta) IS NOT NULL` (plpgsql plans an `IF` expression as a whole, so an `AND` would fail on a missing table).
15. **Shared-row check (R5).** Named "Shared objects", emitted as the first check of the version group (right before "Schema version") in every report. A missing meta table counts as "no row" (warning, apply). When the role cannot read `fuzzphony_meta`, the existing single warning covers both.
16. **Partition triggers reuse the parent's `_trn` trigger name (R6).** Trigger names are per table, so `Names::triggerName($index, $watch, '_trn')` names the trigger on every partition too. The `DO` block is emitted for every watch (it loops over nothing for a plain table: `pg_partition_tree()` returns no row); a sync mode without triggers (and `drop()`) emits the same loop with `DROP TRIGGER IF EXISTS`. Truncating a partitioned parent now also fires each partition's trigger: harmless in queue mode (`'*'` is `ON CONFLICT DO NOTHING`), a repeated resync in trigger mode; documented in `docs/sync.md`.
17. **The doctor's rebuild check (R1)** appears only while `fuzzphony_<index>__next` exists: a warning ("did not finish") when no session holds the rebuild lock (probed with `pg_try_advisory_xact_lock` in a transaction, released at its end), an ok line "a full reindex is building …" when one does. Two new function checks report missing rebuild functions ("Rebuild refresh function", "Rebuild change log function").
18. **The shadow's secondary indexes are built after the bulk load** (plain `CREATE INDEX IF NOT EXISTS`, then `ANALYZE`), at the start of `finishRebuild()`: faster than maintaining GIN indexes during the load, and the table is not live, so nothing waits. Their names are the live names plus `__next` (`Names::shadowIndexName()`), and the swap renames them back, together with the primary key constraint (`fuzzphony_<index>_pkey`, PostgreSQL's own default name for the live one).
19. **Split of the spec's 7 steps:** one task per step. Step 3 (engine side of the swap) and step 4 (reindexer, command, crash, doctor) stay separate tasks as the spec orders them; the advisory lock is implemented in Task 3 because it is part of `beginRebuild()`'s contract, and exercised end to end in Task 4.

## Review Focus

The five inputs the spec implies but no ruling tests, most likely to bite first; each has a test in the owning task:

1. **A role that writes the live index while a rebuild runs** (the queue worker, a trigger-mode writer, both usually not the reindexing role): its write must not fail on the change log. → Task 3, `ShadowSwapTest::testARoleThatWritesTheLiveIndexKeepsWorkingDuringARebuild`.
2. **An application role granted access to the sidecar**: it can still read (and a writer still write) the index after the swap, and the owner is kept. → Task 3, `ShadowSwapTest::testTheSwappedTableKeepsTheGrantsAndTheOwner`.
3. **A least-privilege reindex or worker role** (no `CREATE` on the schema, not the owner; the demo's worker): the run falls back to in place instead of failing, and the CLI says so. → Task 3, `ShadowSwapTest::testARoleThatCannotBuildNextToTheLiveIndexGoesInPlace`; Task 4, `ReindexCommandTest::testARoleThatCannotBuildNextToTheLiveIndexRebuildsInPlaceAndSaysSo`.
4. **A field-scoped stop word (`brand:the mouse`) and a scoped exclusion (`mouse -brand:logitech`)**: the stop word is ignored like any stop word (no empty result), and the exclusion removes only documents with the word in that field. → Task 2, `FieldScopingTest::testAScopedStopWordIsIgnoredLikeAnyStopWord` and `testAnExcludedScopedWordOnlyExcludesItsField`.
5. **A session that used the live refresh function before the swap** (cached plpgsql plans, e.g. a long-running worker or the same connection): its next write lands in the new live table. → Task 3, `ShadowSwapTest::testTheLiveRefreshFunctionWritesTheNewTableAfterTheSwap`.

---

## File Structure

| File | Task | Responsibility |
|---|---|---|
| `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` | 1, 2, 3, 5, 6 | layout 2 + step runner; per-field columns in `columns()` and the refresh function; the rebuild's DDL (`beginRebuild()`, `shadowIndexes()`, `swap()`, `discardRebuild()`, `clearRebuildRequest()`), the shadow refresh and track functions, `drop()`; the `'*'` job in the TRUNCATE branch; partition triggers |
| `src/Engine/Postgres/Schema/Fingerprint.php` | 1 | `documents()` includes the layout |
| `src/Engine/Postgres/Schema/Names.php` | 2, 3 | `fieldVector*`, `fieldFuzzy*`; `shadow*`, `changes*`, `shadowRefreshFunction*`, `trackFunction*`, `shadowIndexName()`, `index()`, `rebuildLockKey()` |
| `src/Engine/Postgres/Inspection/PostgresInspector.php` | 1, 3, 4, 5, 6 | "Shared objects"; rebuild function checks; "Rebuild"; queue check names the job; partitions |
| `src/Engine/Postgres/Sql/TsQueryCompiler.php` | 2 | `hasFieldScope()`; drops excluded known-field words from the whole tsquery |
| `src/Engine/Postgres/Sql/FuzzyQueryCompiler.php` | 2 | per-field exact and trigram checks; `scope()` |
| `src/Engine/Postgres/Sql/SearchSqlBuilder.php` | 2 | `ranked(..., ?Node $scopedRoot = null)` adds the recheck to the fts CTE |
| `src/Engine/Postgres/ShadowRebuild.php` (new, `@internal`) | 3, 5 | lock, capability check, begin / refresh / finish (catch-up + swap) / abort |
| `src/Engine/Postgres/PostgresEngine.php` | 2, 3, 5 | field-scope pipeline; the SPI methods; `processQueue()` skips `'*'`; `rebuildRequested()` |
| `src/Core/Engine/Engine.php` | 3, 5 | the new SPI methods |
| `src/Core/Sync/Reindexer.php`, `ReindexOptions.php`, `ReindexResult.php` | 4 | shadow build, `inPlace`, `swapped`, crash handling |
| `src/Core/Sync/Worker.php` | 5 | runs a requested rebuild before the queued ids |
| `src/Core/Fuzzphony.php` | 4 | `reindex()` docblock |
| `src/Bundle/Command/ReindexCommand.php` | 4 | `--in-place`, swap / fallback messages |
| `tests/Unit/Postgres/RecordingConnection.php` (new) | 3 | a `Connection` that records statements and answers from a script |
| `tests/Unit/Postgres/ShadowRebuildTest.php` (new) | 3, 5 | exact statement sequences of `ShadowRebuild` |
| `tests/Integration/LayoutUpgradeTest.php` (new) | 1, 2 | layout-1 installs |
| `tests/Integration/FieldScopingTest.php` (new) | 2 | exact field scoping end to end |
| `tests/Integration/ShadowSwapTest.php` (new) | 3 | the engine-level swap |
| `tests/Integration/ZeroDowntimeReindexTest.php` (new) | 4, 5 | reindex through `Fuzzphony` under writes, crash, resume, concurrency |
| `tests/Integration/PartitionSyncTest.php` (new) | 6 | partitioned watched tables |
| `docs/adr/0008-shadow-rebuild-with-a-change-log.md` (new) | 3 | decision 1 |
| existing tests, docs, `CHANGELOG.md`, `UPGRADE.md`, `demo/` | every task | as listed per task |

---

### Task 1: Layout step runner, layout-aware documents hash, shared-row doctor check (R4, R5)

**Files:**
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`LAYOUT_VERSION` at lines 26-31, `index()` at line 138, new `layoutSteps()`)
- Modify: `src/Engine/Postgres/Schema/Fingerprint.php` (`documents()`, lines 201-211)
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php` (`schemaVersion()`, lines 82-126; new `sharedObjects()`, `layout()`)
- Test: `tests/Unit/Postgres/SchemaGeneratorTest.php`, `tests/Unit/Postgres/FingerprintTest.php`, `tests/Integration/MetaTableTest.php`
- Create: `tests/Integration/LayoutUpgradeTest.php`
- Docs: `docs/architecture.md` ("Sidecar table"), `docs/commands.md` (doctor list), `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: nothing new.
- Produces: `PostgresSchemaGenerator::LAYOUT_VERSION === 2`; an index plan statement described `'Layout step to 2: the per-field columns of existing documents stay empty until a full reindex'`, right before the meta upsert; `Fingerprint::documents()` covers the layout; doctor check name `'Shared objects'`; `UPGRADE.md` section `## From 0.4 to 0.5` (later tasks append numbered items to it); `CHANGELOG.md` `## [Unreleased]` with `### Breaking` and `### Added` (later tasks append to them and add `### Changed` between them when needed).

- [ ] **Step 1: Write the failing unit tests**

In `tests/Unit/Postgres/SchemaGeneratorTest.php` add:

```php
    public function testALayoutStepRunsOnlyForAnIndexStoredWithAnOlderLayout(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products())->statements;
        $steps = array_values(array_filter($statements, static fn(Statement $s): bool => str_starts_with($s->description, 'Layout step')));

        self::assertCount(1, $steps);
        self::assertTrue($steps[0]->transactional, 'in the apply transaction, so before the non-transactional meta upsert');
        self::assertSame('Layout step to 2: the per-field columns of existing documents stay empty until a full reindex', $steps[0]->description);
        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_meta\"') IS NOT NULL THEN IF (SELECT layout_version FROM \"public\".\"fuzzphony_meta\" WHERE index_name = 'products') < 2 THEN UPDATE \"public\".\"fuzzphony_meta\" SET documents_hash = NULL WHERE index_name = 'products'; END IF; END IF; END \$fuzzphony\$",
            $steps[0]->sql,
        );
        self::assertSame($steps[0], $statements[count($statements) - 2], 'right before the meta upsert, also in --dump-migration');
    }
```

In the same file change the first assertion of `testApplyRecordsTheIndexLayoutAndDefinitionAfterTheConcurrentIndexBuilds()` to `self::assertSame(2, PostgresSchemaGenerator::LAYOUT_VERSION);`, and in the private `upsert()` helper `VALUES ('%s', 1, '%s', %s, now())` to `VALUES ('%s', 2, '%s', %s, now())`.

In `tests/Unit/Postgres/FingerprintTest.php`, `testTheHashesAreStable()`, replace the documents hash. The layout is now an input; the value is the SHA-256 of the 0.4 JSON with `"layout":2` appended as the last key (computed for this plan):

```php
        self::assertSame('66eeef32e4f48e272df8b2b5dd7949f4486c73d7011c80063f0f1e143b6d49e1', Fingerprint::documents(Indexes::products()));
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/FingerprintTest.php`
Expected: FAIL (no layout step; `LAYOUT_VERSION` is 1; the documents hash is still `5b860d…`).

- [ ] **Step 3: Implement the step runner and the layout bump**

In `PostgresSchemaGenerator.php` replace the `LAYOUT_VERSION` docblock and value:

```php
    /**
     * The sidecar layout this version generates, recorded in fuzzphony_meta: 1 = the 0.4 layout,
     * 2 = per-field columns (0.5). A layout change bumps it and adds its step to layoutSteps(),
     * with a test.
     */
    public const int LAYOUT_VERSION = 2;
```

In `index()`, right before `$statements[] = $this->recordApply($index->name, …);`, insert:

```php
        foreach ($this->layoutSteps($index) as $target => [$step, $why]) {
            $statements[] = new Statement(sprintf(
                'DO %1$s BEGIN IF to_regclass(%2$s) IS NOT NULL THEN IF (SELECT layout_version FROM %3$s WHERE index_name = %4$s) < %5$d THEN %6$s END IF; END IF; END %1$s',
                self::TAG,
                Sql::string($this->names->meta()),
                $this->names->meta(),
                Sql::string($index->name),
                $target,
                $step,
            ), sprintf('Layout step to %d: %s', $target, $why));
        }
```

Add after `recordApply()`:

```php
    /**
     * The sidecar layout upgrade steps, by the layout each one leads to. schema --apply runs a
     * step for an index whose stored layout (fuzzphony_meta) is older, in the apply transaction,
     * before the meta upsert records the new layout; a missing version table or row runs none.
     * The check is SQL (a DO block), so the plan stays database-free and --dump-migration contains
     * the steps. New columns come from the plan's ADD COLUMN IF NOT EXISTS, not from a step. The
     * SELECT sits in its own IF: plpgsql plans an IF expression as a whole, so
     * "to_regclass(...) IS NOT NULL AND (SELECT ...)" would fail on a missing table.
     *
     * @return array<int, array{string, string}> target layout => [the step's SQL, why it exists]
     */
    private function layoutSteps(IndexDefinition $index): array
    {
        return [
            2 => [
                sprintf('UPDATE %s SET documents_hash = NULL WHERE index_name = %s;', $this->names->meta(), Sql::string($index->name)),
                'the per-field columns of existing documents stay empty until a full reindex',
            ],
        ];
    }
```

Replace `Fingerprint::documents()`:

```php
    public static function documents(IndexDefinition $index): string
    {
        return self::hash([
            'source' => self::source($index),
            'fields' => self::fields($index),
            'filters' => self::filters($index),
            'text' => [$index->text->language, $index->text->unaccent],
            'boost' => $index->boostColumn,
            'recency' => $index->recencyColumn,
            // documents built for an older sidecar layout never match
            'layout' => PostgresSchemaGenerator::LAYOUT_VERSION,
        ]);
    }
```

- [ ] **Step 4: Run the unit tests again**

Run: `vendor/bin/phpunit tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/FingerprintTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing integration tests (shared row, layout-1 upgrade)**

In `tests/Integration/MetaTableTest.php`:
- in `testApplyWritesTheRowAndOnlyAFullReindexTheDocumentsHash()` replace `self::assertSame(1, Coerce::int($this->row('*')['layout_version']));` with `self::assertSame(PostgresSchemaGenerator::LAYOUT_VERSION, Coerce::int($this->row('*')['layout_version']));`;
- in `testTheDoctorReportsEachKindOfDrift()` replace `"Layout 0 is older than this library's layout 1."` with `"Layout 0 is older than this library's layout 2."` and `'Layout 99 was applied by a newer Fuzzphony (9.0.0); this library knows layout 1.'` with `'Layout 99 was applied by a newer Fuzzphony (9.0.0); this library knows layout 2.'`;
- add:

```php
    public function testTheDoctorChecksTheSharedObjectsRow(): void
    {
        $this->context->applySchemaAndReindex();
        $connection = $this->context->connection;
        $check = $this->check('Shared objects');
        self::assertSame(CheckStatus::Ok, $check->status);
        self::assertStringStartsWith('layout 2, applied by ', $check->message);

        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1 WHERE index_name = '*'");
        $check = $this->check('Shared objects');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame("Layout 1 is older than this library's layout 2.", $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $check->fix);

        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 99, library_version = '9.0.0' WHERE index_name = '*'");
        $check = $this->check('Shared objects');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('Layout 99 was applied by a newer Fuzzphony (9.0.0); this library knows layout 2.', $check->message);
        self::assertSame('Upgrade fuzzphony/fuzzphony to 9.0.0 or later.', $check->fix);

        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 2, definition_hash = 'x' WHERE index_name = '*'");
        $check = $this->check('Shared objects');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame("Fuzzphony's schema or the extension schema changed since the last apply.", $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $check->fix);

        $connection->execute("DELETE FROM fuzzphony_meta WHERE index_name = '*'");
        $check = $this->check('Shared objects');
        self::assertSame(CheckStatus::Warning, $check->status);
        self::assertSame('No version record for the shared objects: built before 0.4, or never applied.', $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $check->fix);
        self::assertSame(CheckStatus::Ok, $this->check('Schema version')->status, 'the index row is read on its own');

        $connection->execute('DROP TABLE fuzzphony_meta');
        self::assertSame(CheckStatus::Warning, $this->check('Shared objects')->status, 'an install from before 0.4');
    }

    public function testTheSharedObjectsCheckComesRightBeforeTheSchemaVersionOncePerReport(): void
    {
        $this->context->applySchemaAndReindex();
        $names = array_map(static fn(Check $c): string => $c->name, $this->context->fuzzphony->inspect('products')->checks);
        $shared = array_search('Shared objects', $names, true);

        self::assertIsInt($shared);
        self::assertSame('Schema version', $names[$shared + 1]);
        self::assertCount(1, array_keys($names, 'Shared objects', true));
    }
```

(`MetaTableTest` already imports `Check`, `CheckStatus`, `Coerce` and `PostgresSchemaGenerator`.)

Create `tests/Integration/LayoutUpgradeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Integration\Command\CommandTestCase;
use PHPUnit\Framework\TestCase;

/** schema --apply upgrades an index built with an older sidecar layout (the step runner). */
final class LayoutUpgradeTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase(); // fixtures reset, "products" (manual sync)
        $this->context->applySchemaAndReindex();
    }

    public function testALayout1IndexIsUpgradedOnceAndAsksForAReindex(): void
    {
        $connection = $this->context->connection;
        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1 WHERE index_name IN ('products', '*')");
        self::assertNotNull($this->row()['documents_hash'], 'the full reindex recorded its documents');
        self::assertSame(CheckStatus::Error, $this->check('Schema version')->status);

        $this->context->fuzzphony->schema()->apply($connection);

        $row = $this->row();
        self::assertSame(PostgresSchemaGenerator::LAYOUT_VERSION, Coerce::int($row['layout_version']));
        self::assertNull($row['documents_hash'], 'the step cleared it');
        self::assertSame(CheckStatus::Ok, $this->check('Schema version')->status);
        self::assertSame(CheckStatus::Ok, $this->check('Shared objects')->status);
        self::assertSame(CheckStatus::Warning, $this->check('Documents')->status);

        $this->context->fuzzphony->reindex('products');
        $this->context->fuzzphony->schema()->apply($connection);

        self::assertSame(Fingerprint::documents($this->context->fuzzphony->registry()->get('products')), $this->row()['documents_hash'], 'a second apply runs no step');
        self::assertSame(CheckStatus::Ok, $this->check('Documents')->status);
    }

    public function testTheStepRunsNothingWithoutAVersionRowOrTable(): void
    {
        $connection = $this->context->connection;
        $steps = array_values(array_filter(
            $this->context->engine->indexSchema($this->context->fuzzphony->registry()->get('products'))->statements,
            static fn(Statement $s): bool => str_starts_with($s->description, 'Layout step'),
        ));
        self::assertCount(1, $steps);

        $connection->execute("DELETE FROM fuzzphony_meta WHERE index_name = 'products'");
        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1, documents_hash = 'kept' WHERE index_name = '*'");
        $connection->execute($steps[0]->sql);
        self::assertSame('kept', $connection->fetchValue("SELECT documents_hash FROM fuzzphony_meta WHERE index_name = '*'"), 'only the index row is ever touched');

        $connection->execute('DROP TABLE fuzzphony_meta');
        $connection->execute($steps[0]->sql); // a pre-0.4 install: no table, no step, no error
        self::assertNull($connection->fetchValue("SELECT to_regclass('fuzzphony_meta')"));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return $this->context->connection->fetchAll("SELECT * FROM fuzzphony_meta WHERE index_name = 'products'")[0]
            ?? self::fail('no meta row for "products"');
    }

    private function check(string $name): Check
    {
        return array_find($this->context->fuzzphony->inspect('products')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}
```

- [ ] **Step 6: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/MetaTableTest.php tests/Integration/LayoutUpgradeTest.php`
Expected: FAIL: no "Shared objects" check.

- [ ] **Step 7: Implement the "Shared objects" check**

In `PostgresInspector.php` add `use Fuzzphony\Core\Inspection\CheckStatus;`. In `schemaVersion()`, keep everything up to and including the `$denied` branch, and replace the rest of the method (from `$row = $exists` to its closing brace) with:

```php
        $rows = [];
        if ($exists) {
            $found = $this->connection->fetchAll(
                sprintf("SELECT index_name, layout_version, definition_hash, documents_hash, library_version FROM %s WHERE index_name IN (:index, '*')", $this->names->meta()),
                ['index' => $index->name],
            );
            foreach ($found as $row) {
                $rows[Coerce::str($row['index_name'])] = $row;
            }
        }
        $checks = [$this->sharedObjects($rows['*'] ?? null)];
        $row = $rows[$index->name] ?? null;
        if ($row === null) {
            $checks[] = Check::warning('Schema version', 'No version record: built before 0.4, or never applied.', self::APPLY);

            return $checks;
        }
        $checks[] = $this->layout('Schema version', $row);
        $checks[] = Coerce::str($row['definition_hash']) === Fingerprint::definition($index)
            ? Check::ok('Definition', 'unchanged since the last apply')
            : Check::error('Definition', 'The definition changed since the last apply.', self::APPLY);
        $checks[] = Coerce::str($row['documents_hash']) === Fingerprint::documents($index)
            ? Check::ok('Documents', 'built from the current definition')
            : Check::warning('Documents', 'The documents were built from another definition, or not fully reindexed since 0.4.', sprintf('bin/console fuzzphony:reindex %s', $index->name));

        return $checks;
    }

    /**
     * The "*" row: the layout of the shared objects (queue, normaliser, text configurations) and
     * where they live (Fingerprint::shared()).
     *
     * @param array<string, mixed>|null $row
     */
    private function sharedObjects(?array $row): Check
    {
        if ($row === null) {
            return Check::warning('Shared objects', 'No version record for the shared objects: built before 0.4, or never applied.', self::APPLY);
        }
        $layout = $this->layout('Shared objects', $row);
        if ($layout->status !== CheckStatus::Ok) {
            return $layout;
        }

        return Coerce::str($row['definition_hash']) === Fingerprint::shared($this->names)
            ? $layout
            : Check::error('Shared objects', "Fuzzphony's schema or the extension schema changed since the last apply.", self::APPLY);
    }

    /** @param array<string, mixed> $row */
    private function layout(string $name, array $row): Check
    {
        $layout = Coerce::int($row['layout_version']);
        $current = PostgresSchemaGenerator::LAYOUT_VERSION;
        $by = Coerce::str($row['library_version']);

        return match (true) {
            $layout < $current => Check::error($name, sprintf('Layout %d is older than this library\'s layout %d.', $layout, $current), self::APPLY),
            $layout > $current => Check::error($name, sprintf('Layout %d was applied by a newer Fuzzphony (%s); this library knows layout %d.', $layout, $by, $current), sprintf('Upgrade fuzzphony/fuzzphony to %s or later.', $by)),
            default => Check::ok($name, sprintf('layout %d, applied by %s', $layout, $by)),
        };
    }
```

(The `$denied` branch is unchanged: a role that cannot read the table gets the one warning, covering both rows.)

- [ ] **Step 8: Run the integration tests again**

Run: `vendor/bin/phpunit tests/Integration/MetaTableTest.php tests/Integration/LayoutUpgradeTest.php tests/Integration/Command/DoctorCommandTest.php`
Expected: PASS.

- [ ] **Step 9: Update the docs**

`docs/architecture.md`, "Sidecar table": after the paragraph that starts "`fuzzphony_meta` (same schema) records", add:

```markdown
`fuzzphony:schema --apply` upgrades an index built with an older sidecar layout: each layout step
is a guarded `DO` block in the index's plan that runs only while the stored layout is older (so
`--dump-migration` contains it too), before the meta row records the new layout. The `*` row
records the shared objects (queue, normaliser, text configurations); the doctor's "Shared objects"
check compares it.
```

`docs/commands.md`, doctor list: insert before the item that starts "- the schema version:":

```markdown
- the shared objects' version row (`*` in `fuzzphony_meta`): a warning when it is missing, an error
  when its layout is older or newer than this library's, or when the schema or extension schema
  changed since the last apply;
```

`CHANGELOG.md`, under `## [Unreleased]`:

```markdown
**After upgrading, run `fuzzphony:schema --apply`, then one full `fuzzphony:reindex`.** See
[UPGRADE.md](UPGRADE.md#from-04-to-05).

### Breaking

- The sidecar layout is 2. `fuzzphony:schema --apply` upgrades a layout-1 index (a guarded step in
  the index's plan, also in `--dump-migration`) and clears its documents record, so the doctor's
  "Documents" check asks for one full `fuzzphony:reindex`. The documents hash now includes the
  layout.

### Added

- The layout step runner: `fuzzphony:schema --apply` upgrades an index built with an older sidecar
  layout.
- Doctor: a "Shared objects" check of the shared objects' version row (`*` in `fuzzphony_meta`): a
  warning when it is missing, an error when its layout is older or newer than the library's, or
  when the schema or extension schema changed since the last apply.
```

`UPGRADE.md`: insert above `## From 0.3 to 0.4`:

```markdown
## From 0.4 to 0.5

1. **Apply, then reindex.** Run `fuzzphony:schema --apply`: it upgrades every index to sidecar
   layout 2. Then run one full `fuzzphony:reindex`; until then the doctor's "Documents" check is a
   warning (so `fuzzphony:doctor --strict` fails in CI). With Doctrine Migrations,
   `fuzzphony:schema --dump-migration` contains the upgrade step.

```

- [ ] **Step 10: Run the gate**

Run the gate (Global Constraints). Expected: all green; no escaped mutant on the changed lines.

- [ ] **Step 11: Commit**

```bash
git add src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/Schema/Fingerprint.php src/Engine/Postgres/Inspection/PostgresInspector.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/FingerprintTest.php tests/Integration/MetaTableTest.php tests/Integration/LayoutUpgradeTest.php docs/architecture.md docs/commands.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Add the layout step runner and the shared version row check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Per-field columns and exact field scoping, layout 2 (R3)

**Files:**
- Modify: `src/Engine/Postgres/Schema/Names.php` (new `fieldVectorName()`, `fieldVector()`, `fieldFuzzyName()`, `fieldFuzzy()`)
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`columns()`, `refreshFunction()`, `tsvectorExpression()`, `fuzzyExpression()`; new `fieldVectorExpression()`, `fieldFuzzyExpression()`)
- Modify: `src/Engine/Postgres/Sql/TsQueryCompiler.php` (`node()`, new `hasFieldScope()`, `knownScope()`, class docblock)
- Modify: `src/Engine/Postgres/Sql/FuzzyQueryCompiler.php` (`leaf()`, `needle()`, `matches()`, `column()`, `node()`, `leafConditions()`, new `scope()`, `scopedField()`, class docblock)
- Modify: `src/Engine/Postgres/Sql/SearchSqlBuilder.php` (`ranked()`)
- Modify: `src/Engine/Postgres/PostgresEngine.php` (`pipeline()`, lines 397-453)
- Test: `tests/Unit/Postgres/NamesTest.php`, `tests/Unit/Postgres/SchemaGeneratorTest.php`, `tests/Unit/Postgres/TsQueryCompilerTest.php`, `tests/Unit/Postgres/FuzzyQueryCompilerTest.php`, `tests/Unit/Postgres/SearchSqlBuilderTest.php`, `tests/Integration/LayoutUpgradeTest.php`
- Create: `tests/Integration/FieldScopingTest.php`
- Docs: `docs/searching.md`, `docs/configuration.md`, `docs/limitations.md`, `README.md`, `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: `PostgresSchemaGenerator::LAYOUT_VERSION === 2` and the layout step (Task 1).
- Produces:
  - `Names::fieldVectorName(string $field): string` (`'t_brand'`), `Names::fieldVector(string $field): string` (`'"t_brand"'`), `Names::fieldFuzzyName(string $field): string` (`'z_brand'`), `Names::fieldFuzzy(string $field): string` (`'"z_brand"'`);
  - sidecar columns `t_<field> tsvector NOT NULL DEFAULT ''` (every field) and `z_<field> text NOT NULL DEFAULT ''` (fuzzy fields), right after `exact` in `columns()`;
  - `TsQueryCompiler::hasFieldScope(Node $node): bool`;
  - `FuzzyQueryCompiler::scope(Node $node, ParameterBag $params, array $emptyQueries = []): ?array` returning `array{predicate: string, columns: list<string>}|null`;
  - `SearchSqlBuilder::ranked(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $limit, int $offset, array $emptyQueries = [], ?Node $scopedRoot = null): array`.

- [ ] **Step 1: Write the failing unit tests (names, columns, refresh function)**

`tests/Unit/Postgres/NamesTest.php`, add to `testObjectNames()`:

```php
        self::assertSame('t_brand', $names->fieldVectorName('brand'));
        self::assertSame('"t_brand"', $names->fieldVector('brand'));
        self::assertSame('z_brand', $names->fieldFuzzyName('brand'));
        self::assertSame('"z_brand"', $names->fieldFuzzy('brand'));
```

and to `testLongNamesAreCutToThePostgresLimitAndStayUnique()`:

```php
        $long = str_repeat('f', 62);
        self::assertSame(63, strlen($names->fieldVectorName($long)));
        self::assertStringStartsWith('t_ff', $names->fieldVectorName($long));
        self::assertSame(63, strlen($names->fieldFuzzyName($long)));
        self::assertStringStartsWith('z_ff', $names->fieldFuzzyName($long));
```

(`$names` there is `new Names()`; if that test names its instance differently, use its variable.)

`tests/Unit/Postgres/SchemaGeneratorTest.php`: replace the expected list of `testSidecarColumnsFollowTheDefinition()`:

```php
        self::assertSame(
            ['id', 'tsv', 'fz', 'exact', 't_name', 'z_name', 't_brand', 'z_brand', 't_description', 'boost', 'recency_at', 'f_price', 'f_in_stock', 'f_published_at', 'f_brand_id', 'indexed_at'],
            array_keys((new PostgresSchemaGenerator())->columns(Indexes::products())),
        );
```

and add:

```php
    public function testTheRefreshFunctionFillsThePerFieldColumns(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products())->toSql();

        self::assertStringContainsString('ALTER TABLE "public"."fuzzphony_products" ADD COLUMN IF NOT EXISTS "t_brand" tsvector NOT NULL DEFAULT \'\'', $sql);
        self::assertStringContainsString('ALTER TABLE "public"."fuzzphony_products" ADD COLUMN IF NOT EXISTS "z_brand" text NOT NULL DEFAULT \'\'', $sql);
        self::assertStringNotContainsString('"z_description"', $sql, 'description is not fuzzy');
        self::assertStringContainsString(
            'INSERT INTO "public"."fuzzphony_products" AS s ("id", "tsv", "fz", "exact", "t_name", "z_name", "t_brand", "z_brand", "t_description", "boost", "recency_at", "f_price", "f_in_stock", "f_published_at", "f_brand_id", "indexed_at")',
            $sql,
        );
        self::assertStringContainsString(
            "setweight(to_tsvector('\"public\".\"fuzzphony_english\"'::regconfig, coalesce(doc.\"fld_brand\"::text, '')), 'B'),\n        coalesce(\"public\".\"fuzzphony_norm\"(doc.\"fld_brand\"::text), ''),\n        setweight(to_tsvector('\"public\".\"fuzzphony_english\"'::regconfig, coalesce(doc.\"fld_description\"::text, '')), 'D'),\n        doc.fz_boost::double precision",
            $sql,
            'each field its own weighted vector (the same as its part of tsv) and, when fuzzy, its normalised text',
        );
        self::assertStringContainsString('"t_brand" = EXCLUDED."t_brand"', $sql);
        self::assertStringContainsString('"z_brand" = EXCLUDED."z_brand"', $sql);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: FAIL (`fieldVectorName()` undefined; columns list lacks `t_*` / `z_*`).

- [ ] **Step 3: Implement the names and the columns**

`Names.php`, after `indexName()`:

```php
    /** The sidecar column with one field's own weighted tsvector (layout 2): a field-scoped word is checked against it. */
    public function fieldVectorName(string $field): string
    {
        return self::limit('t_' . $field);
    }

    public function fieldVector(string $field): string
    {
        return Sql::ident($this->fieldVectorName($field));
    }

    /** The sidecar column with one fuzzy field's normalised text (layout 2): a field-scoped typo is checked against it. */
    public function fieldFuzzyName(string $field): string
    {
        return self::limit('z_' . $field);
    }

    public function fieldFuzzy(string $field): string
    {
        return Sql::ident($this->fieldFuzzyName($field));
    }
```

`PostgresSchemaGenerator.php` (add `use Fuzzphony\Core\Definition\FieldDefinition;`). In `columns()`, after the `'exact'` entry of the literal array, before the boost column:

```php
        foreach ($index->fields as $field) {
            $columns[$this->names->fieldVectorName($field->name)] = "tsvector NOT NULL DEFAULT ''";
            if ($field->fuzzy) {
                $columns[$this->names->fieldFuzzyName($field->name)] = "text NOT NULL DEFAULT ''";
            }
        }
```

In `refreshFunction()`, right after the `$values = [...]` line:

```php
        foreach ($index->fields as $field) {
            $columns[] = $this->names->fieldVectorName($field->name);
            $values[] = $this->fieldVectorExpression($index, $field);
            if ($field->fuzzy) {
                $columns[] = $this->names->fieldFuzzyName($field->name);
                $values[] = sprintf("coalesce(%s, '')", $this->fieldFuzzyExpression($field));
            }
        }
```

Replace `tsvectorExpression()` and `fuzzyExpression()` and add the two per-field helpers (the output of the two existing methods stays byte-identical):

```php
    private function tsvectorExpression(IndexDefinition $index): string
    {
        return implode("\n            || ", array_map(
            fn(FieldDefinition $field): string => $this->fieldVectorExpression($index, $field),
            $index->fields,
        ));
    }

    /** One field's part of tsv, and its own t_<field> column. */
    private function fieldVectorExpression(IndexDefinition $index, FieldDefinition $field): string
    {
        return sprintf(
            "setweight(to_tsvector(%s, coalesce(doc.%s::text, '')), '%s')",
            $this->names->regconfig($index->text),
            Sql::ident('fld_' . $field->name),
            $field->weight->value,
        );
    }

    private function fuzzyExpression(IndexDefinition $index): string
    {
        $fields = $index->fuzzyFields();
        if ($fields === []) {
            return "''";
        }

        return sprintf("coalesce(concat_ws(' ', %s), '')", implode(', ', array_map($this->fieldFuzzyExpression(...), $fields)));
    }

    /** One fuzzy field's normalised text: its part of fz, and (coalesced) its own z_<field> column. */
    private function fieldFuzzyExpression(FieldDefinition $field): string
    {
        return sprintf('%s(doc.%s::text)', $this->names->normFunction(), Sql::ident('fld_' . $field->name));
    }
```

- [ ] **Step 4: Run them again**

Run: `vendor/bin/phpunit tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing compiler and builder tests**

`tests/Unit/Postgres/TsQueryCompilerTest.php`, add to `cases()`:

```php
        yield 'an excluded word of a known field is left to the field check' => ['mouse -brand:logitech', "'mouse'"];
        yield 'an excluded word of an unknown field stays' => ['mouse -colour:red', "('mouse' & !'red')"];
```

and add:

```php
    public function testFieldScopeDetection(): void
    {
        $compiler = new TsQueryCompiler(Indexes::products());
        $cases = [
            'brand:sony' => true,
            'mouse -brand:sony' => true,
            '(mouse | name:pad) cable' => true,
            'mouse' => false,
            'colour:red' => false,
            'mouse -cable' => false,
        ];
        foreach ($cases as $query => $expected) {
            $root = (new QueryParser())->parse($query)->root;
            self::assertNotNull($root);
            self::assertSame($expected, $compiler->hasFieldScope($root), $query);
        }
    }
```

`tests/Unit/Postgres/FuzzyQueryCompilerTest.php`:
- delete the `structures()` data set `'field scope: exact side weighted, fuzzy side on the whole fz column'` (the dedicated tests below replace it);
- in `testLeafConditionsWithoutTheFuzzyBranchAreExactOnly()` replace the predicates assertion with `self::assertSame(['s.tsv @@ q.ft0', '(s.tsv @@ q.ft1 AND s."t_name" @@ q.ft1)'], $conditions['predicates']);`;
- add:

```php
    public function testAFieldScopedWordMatchesOnlyItsFieldOnBothSides(): void
    {
        $params = new ParameterBag();
        $match = $this->compiler()->compile(self::parse('brand:razr'), $params);

        self::assertNotNull($match);
        self::assertSame(["to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS ft0", '"public"."fuzzphony_norm"(:p1) AS fn1'], $match->columns);
        // GIN(tsv) / GIN(fz) find the candidates, the field's own columns decide
        self::assertSame('((s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) OR (q.fn1 OPERATOR("public".<%) s.fz AND q.fn1 OPERATOR("public".<%) s."z_brand"))', $match->predicate);
        self::assertSame('GREATEST("public".word_similarity(q.fn1, s."z_brand"), CASE WHEN (s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) THEN 1.0 ELSE 0.0 END)', $match->score);
        self::assertSame(['p0' => "'razr':B", 'p1' => 'razr'], $params->all());
    }

    public function testAScopedWordOfAFieldThatIsNotFuzzyIsExactOnly(): void
    {
        $match = $this->compiler()->compile(self::parse('description:office mouse'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('((s.tsv @@ q.ft0 AND s."t_description" @@ q.ft0) AND (s.tsv @@ q.ft1 OR q.fn2 OPERATOR("public".<%) s.fz))', $match->predicate);
        self::assertFalse($this->compiler()->hasFuzzyLeaf(self::parse('description:office')));
    }

    public function testAnExcludedScopedWordIsCheckedAgainstItsField(): void
    {
        $match = $this->compiler()->compile(self::parse('mouse -brand:logitech'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('((s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz) AND NOT ((s.tsv @@ q.ft2 AND s."t_brand" @@ q.ft2)))', $match->predicate);
    }

    public function testAnUnknownFieldStillSearchesEveryField(): void
    {
        $match = $this->compiler()->compile(self::parse('colour:reds'), new ParameterBag());

        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate);
    }

    public function testTheScopeIsTheExactOnlyQueryWithItsOwnColumns(): void
    {
        $params = new ParameterBag();
        $compiler = $this->compiler();

        self::assertSame([
            'predicate' => '((s.tsv @@ q.sft0 AND s."t_brand" @@ q.sft0) OR s.tsv @@ q.sft1)',
            'columns' => [
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p0) AS sft0",
                "to_tsquery('\"public\".\"fuzzphony_english\"'::regconfig, :p1) AS sft1",
            ],
        ], $compiler->scope(self::parse('brand:sony | mouse'), $params));
        self::assertSame(['p0' => "'sony':B", 'p1' => "'mouse'"], $params->all());
        self::assertNull($compiler->scope(self::parse('brand:the'), new ParameterBag(), ["'the':B"]), 'a stop word leaves nothing to check');

        $match = $compiler->compile(self::parse('mouse'), new ParameterBag());
        self::assertNotNull($match);
        self::assertSame('(s.tsv @@ q.ft0 OR q.fn1 OPERATOR("public".<%) s.fz)', $match->predicate, 'compile() afterwards is typo-tolerant again, with its own column names');
    }
```

`tests/Unit/Postgres/SearchSqlBuilderTest.php`, add:

```php
    public function testAFieldScopedQueryRechecksTheFieldInTheFullTextBranch(): void
    {
        $root = (new QueryParser())->parse('brand:razr')->root;
        self::assertNotNull($root);
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'razr':B", 'razr', $root, [], new RankingProfile(), new Thresholds(), 10, 0, [], $root);

        // q.tsq, q.norm, the recheck (q.sft0), then the fuzzy branch's own values (q.ft0, q.fn1)
        self::assertSame(['p0' => "'razr':B", 'p1' => 'razr', 'p2' => "'razr':B", 'p3' => "'razr':B", 'p4' => 'razr'], $statement['params']);
        self::assertStringContainsString('WHERE s.tsv @@ q.tsq AND (s.tsv @@ q.sft0 AND s."t_brand" @@ q.sft0) AND TRUE', $statement['sql']);
        self::assertStringContainsString('WHERE ((s.tsv @@ q.ft0 AND s."t_brand" @@ q.ft0) OR (q.fn1 OPERATOR("public".<%) s.fz AND q.fn1 OPERATOR("public".<%) s."z_brand")) AND TRUE', $statement['sql']);
        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32)", $statement['sql'], 'ranked by the weighted tsv, like an unscoped word');
    }

    public function testWithoutAScopeTheFullTextBranchIsUnchanged(): void
    {
        $statement = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0);

        self::assertStringContainsString("WHERE s.tsv @@ q.tsq AND TRUE\n", $statement['sql']);
        self::assertStringNotContainsString('sft', $statement['sql']);
    }
```

- [ ] **Step 6: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/TsQueryCompilerTest.php tests/Unit/Postgres/FuzzyQueryCompilerTest.php tests/Unit/Postgres/SearchSqlBuilderTest.php`
Expected: FAIL (`hasFieldScope()` / `scope()` undefined, `ranked()` has no tenth parameter, predicates without `t_*` / `z_*`).

- [ ] **Step 7: Implement the compilers**

`TsQueryCompiler.php`: in the class docblock, after the `name:mouse keyb*` example line, add:

```php
 * A word scoped to a field the index has keeps that field's weight label here; the search also
 * checks it against the field's own column (FuzzyQueryCompiler::scope()). An excluded one is left
 * out of this tsquery entirely: "!'sony':B" would also exclude every other field of weight B.
```

Replace `node()`:

```php
    private function node(Node $node, string $weights): ?string
    {
        return match (true) {
            $node instanceof Term => $this->term($node, $weights),
            $node instanceof Phrase => $this->sequence(array_merge(...array_map(self::lexemes(...), $node->words)), $weights, false),
            $node instanceof FieldScoped => $this->scoped($node),
            $node instanceof AllOf => $this->group($node->nodes, ' & ', $weights),
            $node instanceof AnyOf => $this->group($node->nodes, ' | ', $weights),
            $node instanceof Not && $this->knownScope($node->node) => null,
            $node instanceof Not => ($inner = $this->node($node->node, $weights)) === null ? null : '!' . $inner,
            default => null,
        };
    }
```

Add after `warnings()`:

```php
    /** True when the query has a word (included or excluded) scoped to a field the index has. */
    public function hasFieldScope(Node $node): bool
    {
        return match (true) {
            $node instanceof FieldScoped => $this->knownScope($node),
            $node instanceof AllOf, $node instanceof AnyOf => array_any($node->nodes, $this->hasFieldScope(...)),
            $node instanceof Not => $this->hasFieldScope($node->node),
            default => false,
        };
    }

    private function knownScope(Node $node): bool
    {
        return $node instanceof FieldScoped && $this->index->field($node->field) !== null;
    }
```

`FuzzyQueryCompiler.php` (add `use Fuzzphony\Core\Definition\FieldDefinition;`). In the class docblock replace the sentence "A field-scoped word matches fuzzily against the whole fz column (every fuzzy field): fz is one string, so the field cannot be enforced on the fuzzy side (known limitation)." with:

```php
 * A word scoped to a field the index has is checked against that field's own columns on the
 * candidates GIN(tsv) / GIN(fz) find: "s.t_<field> @@" on the exact side, "<% s.z_<field>" on the
 * fuzzy side (a field that is not fuzzy stays exact-only). An unknown field searches every field.
```

Add two properties after `$columns`:

```php
    /** scope(): every leaf exact only. */
    private bool $exactOnly = false;
    /** Prefix of the q column names, so scope()'s columns never clash with compile()'s. */
    private string $prefix = '';
```

Add after `compile()`:

```php
    /**
     * The exact-only condition of a query with a field-scoped word, for the strict (full-text)
     * branch: the query's AND / OR / NOT over its words, each matched exactly, a known field's
     * word against that field's own tsvector, stop words dropped. SearchSqlBuilder puts it next to
     * "s.tsv @@ q.tsq", which still finds the candidates through GIN(tsv). Its q columns are named
     * sft<n>. Null when nothing is left to check (every word a stop word).
     *
     * @param list<string> $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return array{predicate: string, columns: list<string>}|null
     */
    public function scope(Node $node, ParameterBag $params, array $emptyQueries = []): ?array
    {
        $this->columns = [];
        $this->exactOnly = true;
        $this->prefix = 's';
        $compiled = $this->node($node, $params, $emptyQueries);
        $this->exactOnly = false;
        $this->prefix = '';

        return $compiled === null ? null : ['predicate' => $compiled['predicate'], 'columns' => $this->columns];
    }
```

In `leafConditions()` replace `default => $this->matches($tsquery, $params),` with `default => $this->matches($leaf, $tsquery, $params),`.

In `node()` replace the `Not` arm's `$this->matches($tsquery, $params)` with `$this->matches($node->node, $tsquery, $params)`.

Replace the body of `leaf()` (keep its docblock, `@param list<string> $empty` / `@return array{predicate: string, score: string, partial: bool}|null`):

```php
    private function leaf(Node $node, ParameterBag $params, array $empty): ?array
    {
        $tsquery = $this->exact($node, $empty);
        if ($tsquery === null) {
            return null;
        }
        $exact = $this->matches($node, $tsquery, $params);
        $needle = $this->exactOnly ? null : $this->needle($node);
        if ($needle === null) {
            return ['predicate' => $exact, 'score' => sprintf('CASE WHEN %s THEN 1.0 ELSE 0.0 END', $exact), 'partial' => false];
        }
        $norm = $this->column('fn', sprintf('%s(%s)', $this->names->normFunction(), $params->add($needle)));
        $schema = $this->names->extension();
        $field = $this->scopedField($node);
        if ($field === null) {
            return [
                'predicate' => sprintf('(%s OR %s OPERATOR(%s.<%%) s.fz)', $exact, $norm, $schema),
                'score' => sprintf('GREATEST(%s.word_similarity(%s, s.fz), CASE WHEN %s THEN 1.0 ELSE 0.0 END)', $schema, $norm, $exact),
                'partial' => false,
            ];
        }
        $column = 's.' . $this->names->fieldFuzzy($field->name);

        return [
            'predicate' => sprintf('(%1$s OR (%2$s OPERATOR(%3$s.<%%) s.fz AND %2$s OPERATOR(%3$s.<%%) %4$s))', $exact, $norm, $schema, $column),
            'score' => sprintf('GREATEST(%s.word_similarity(%s, %s), CASE WHEN %s THEN 1.0 ELSE 0.0 END)', $schema, $norm, $column, $exact),
            'partial' => false,
        ];
    }
```

In `needle()` replace the `return` line:

```php
        $field = $this->scopedField($node);

        return $this->index->hasFuzzy() && ($field === null || $field->fuzzy) && mb_strlen(str_replace(' ', '', $needle)) >= $this->thresholds->fuzzyMinLength ? $needle : null;
```

Replace `matches()` and `column()`, and add `scopedField()`:

```php
    private function matches(Node $node, string $tsquery, ParameterBag $params): string
    {
        $query = $this->column('ft', sprintf('to_tsquery(%s, %s)', $this->names->regconfig($this->index->text), $params->add($tsquery)));
        $field = $this->scopedField($node);

        return $field === null
            ? 's.tsv @@ ' . $query
            : sprintf('(s.tsv @@ %1$s AND s.%2$s @@ %1$s)', $query, $this->names->fieldVector($field->name));
    }

    /** Adds a q column (ft<n> = tsquery, fn<n> = needle; scope(): sft<n>) and returns the reference to it. */
    private function column(string $prefix, string $expression): string
    {
        $name = $this->prefix . $prefix . count($this->columns);
        $this->columns[] = $expression . ' AS ' . $name;

        return 'q.' . $name;
    }

    /** The field a leaf is scoped to, when the index has it. */
    private function scopedField(Node $node): ?FieldDefinition
    {
        return $node instanceof FieldScoped ? $this->index->field($node->field) : null;
    }
```

`SearchSqlBuilder::ranked()`: add the parameter `?Node $scopedRoot = null` after `array $emptyQueries = []` and its docblock line `@param Node|null $scopedRoot the parsed query when it has a word scoped to a known field (rechecked in the full-text branch), else null`. Replace the fuzzy-compile line and the fts CTE:

```php
        $compiler = new FuzzyQueryCompiler($this->index, $thresholds, $this->names);
        // A word scoped to a known field is rechecked against the field's own column (q.sft<n>).
        $scope = $scopedRoot === null ? null : $compiler->scope($scopedRoot, $params, $emptyQueries);
        if ($scope !== null) {
            array_push($q, ...$scope['columns']);
        }
        // Per-term fuzzy branch: every word is satisfied exactly or fuzzily, through the query's
        // own AND / OR / NOT. Its per-word values are extra q columns.
        $fuzzy = $fuzzyRoot === null ? null : $compiler->compile($fuzzyRoot, $params, $emptyQueries);
```

```php
        if ($tsquery !== null) {
            $ctes[] = sprintf(
                "fts AS (\n    SELECT s.id, ts_rank_cd('%s'::real[], s.tsv, q.tsq, 32)::double precision AS r_text\n    FROM %s AS s CROSS JOIN q\n    WHERE s.tsv @@ q.tsq%s AND %s\n    LIMIT %d\n)",
                self::tsRankWeights($profile),
                $table,
                $scope !== null ? ' AND ' . $scope['predicate'] : '',
                $filters->compile($conditions, $params),
                $candidates,
            );
            $branches[] = 'SELECT id, r_text, 0::double precision AS r_fuzzy FROM fts';
        }
```

`PostgresEngine::pipeline()`: replace the opening block

```php
        $warnings = [];
        $tsquery = null;
        if ($root !== null) {
            $compiler = new TsQueryCompiler($index);
            $tsquery = $compiler->compile($root);
            $warnings = $compiler->warnings();
        }
```

with

```php
        $warnings = [];
        $tsquery = null;
        $scopedRoot = null;
        if ($root !== null) {
            $compiler = new TsQueryCompiler($index);
            $tsquery = $compiler->compile($root);
            $warnings = $compiler->warnings();
            // a word scoped to a field the index has is rechecked against that field's own column
            $scopedRoot = $compiler->hasFieldScope($root) ? $root : null;
        }
```

replace `$emptyQueries = null;` with

```php
        // the recheck drops stop words as the strict tsquery does, so it needs them up front
        $emptyQueries = $scopedRoot === null ? null : $this->emptyQueries($index, $fuzzy->leafQueries($scopedRoot));
```

and append `, $scopedRoot` as the last argument of both `$builder->ranked(...)` calls.

- [ ] **Step 8: Run the unit tests again**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 9: Write the failing integration tests**

Create `tests/Integration/FieldScopingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** brand:x searches the brand field only, on the full-text side and on the typo-tolerant side. */
final class FieldScopingTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_shared_b, fuzzphony_shared_b__next, fuzzphony_shared_b__changes CASCADE');
        // brand and description share weight B
        $sharedB = IndexDefinition::builder('shared_b')
            ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'B')
            ->language('english')
            ->sync('manual')
            ->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual'), $sharedB]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('products');
        $this->fuzzphony->reindex('shared_b');
    }

    public function testAScopedWordSearchesOnlyItsFieldNotTheWholeWeightGroup(): void
    {
        self::assertEqualsCanonicalizing([1, 3], $this->ids('shared_b', 'wireless', 'never'));
        self::assertSame([], $this->ids('shared_b', 'brand:wireless', 'never'), 'description shares weight B, but it is not the brand');
        self::assertSame([1], $this->ids('shared_b', 'description:wireless', 'never'));
        self::assertEqualsCanonicalizing([3, 5], $this->ids('shared_b', 'brand:sony', 'never'));
    }

    public function testAScopedWordNoLongerFallsBackToAnotherFuzzyField(): void
    {
        self::assertSame([], $this->ids('products', 'name:sony'), 'no name has "sony"; the brand field is not searched, not even by typo tolerance');
        self::assertSame([], $this->ids('products', 'name:logitech'));
        self::assertEqualsCanonicalizing([3, 5], $this->ids('products', 'brand:sony'));
    }

    public function testAScopedTypoMatchesOnlyItsField(): void
    {
        self::assertSame([2], $this->ids('products', 'brand:razr'));
        self::assertSame([], $this->ids('products', 'name:razr'), 'Razer is a brand, not a name');
        self::assertSame([3], $this->ids('products', 'name:hedphones'));
        self::assertSame([], $this->ids('products', 'brand:hedphones'));
    }

    public function testAnExcludedScopedWordOnlyExcludesItsField(): void
    {
        self::assertEqualsCanonicalizing([1, 3], $this->ids('shared_b', 'wireless -brand:silent', 'never'), 'product 1 has "silent" in its description (weight B too), not in its brand');
        self::assertSame([2], $this->ids('products', 'mouse -brand:logitech', 'never'));
    }

    public function testAScopedStopWordIsIgnoredLikeAnyStopWord(): void
    {
        // same documents; the order may differ, since the exact / prefix bonuses compare the plain words ("the mouse")
        self::assertEqualsCanonicalizing($this->ids('products', 'mouse', 'never'), $this->ids('products', 'brand:the mouse', 'never'));
        self::assertEqualsCanonicalizing($this->ids('products', 'mouse'), $this->ids('products', 'brand:the mouse'), 'with typo tolerance too');
        self::assertNotSame([], $this->ids('products', 'brand:the mouse'));
    }

    public function testAnOrOfScopedWordsKeepsEachToItsField(): void
    {
        self::assertEqualsCanonicalizing([2, 3, 5], $this->ids('products', 'brand:sony | name:gaming', 'never'));
    }

    public function testAnUnknownFieldStillSearchesEverywhereWithAWarning(): void
    {
        $result = $this->fuzzphony->in('products')->query('colour:mouse')->thresholds(['fuzzy_mode' => 'never'])->get();

        self::assertEqualsCanonicalizing([1, 2, 4], $result->ids());
        self::assertContains('Unknown field "colour"; searched in all fields instead.', $result->warnings);
    }

    public function testAScopedWordRanksLikeTheSameUnscopedWord(): void
    {
        $scoped = $this->fuzzphony->in('products')->query('brand:razer')->thresholds(['fuzzy_mode' => 'never'])->get()->hits[0] ?? self::fail('no hit');
        $plain = $this->fuzzphony->in('products')->query('razer')->thresholds(['fuzzy_mode' => 'never'])->get()->hits[0] ?? self::fail('no hit');

        self::assertSame(2, $scoped->id);
        self::assertSame($plain->breakdown->textRank, $scoped->breakdown->textRank);
    }

    /** @return list<int|string> */
    private function ids(string $index, string $query, string $fuzzyMode = 'fallback'): array
    {
        return $this->fuzzphony->in($index)->query($query)->thresholds(['fuzzy_mode' => $fuzzyMode])->get()->ids();
    }
}
```

`tests/Integration/LayoutUpgradeTest.php`, add:

```php
    public function testALayout1SidecarGetsThePerFieldColumns(): void
    {
        $connection = $this->context->connection;
        $connection->execute('ALTER TABLE fuzzphony_products DROP COLUMN t_name, DROP COLUMN z_name, DROP COLUMN t_brand, DROP COLUMN z_brand, DROP COLUMN t_description');
        $connection->execute("UPDATE fuzzphony_meta SET layout_version = 1 WHERE index_name IN ('products', '*')");
        self::assertSame(CheckStatus::Error, $this->check('Sidecar columns')->status);

        $this->context->fuzzphony->schema()->apply($connection);

        self::assertSame(CheckStatus::Ok, $this->check('Sidecar columns')->status);
        self::assertSame('', $connection->fetchValue('SELECT z_brand FROM fuzzphony_products WHERE id = 2'), 'empty until the reindex');
        self::assertSame(CheckStatus::Warning, $this->check('Documents')->status);

        $this->context->fuzzphony->reindex('products');

        self::assertSame('razer', $connection->fetchValue('SELECT z_brand FROM fuzzphony_products WHERE id = 2'));
        self::assertSame([2], $this->context->fuzzphony->in('products')->query('brand:razer')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
    }
```

- [ ] **Step 10: Run the integration tests**

Run: `vendor/bin/phpunit tests/Integration/FieldScopingTest.php tests/Integration/LayoutUpgradeTest.php`
Expected: PASS (they exercise Steps 3 and 7; if one fails, the implementation is wrong, not the test: re-check the SQL of Steps 3 and 7 against the unit tests).

Then run the whole suite: `vendor/bin/phpunit`. Expected: PASS; the conformance test `testFieldScope` (`brand:razer` → `[2]`) still passes.

- [ ] **Step 11: Update the docs**

`docs/searching.md` and `README.md`, query syntax table: replace the row

`| \`brand:logitech\`, \`name:"mx master"\` | only in one field (per weight group) |`

with

`| \`brand:logitech\`, \`name:"mx master"\` | only in that field, typos included (an unknown field searches every field, with a warning) |`

`docs/configuration.md`, the sidecar paragraph: replace "It holds a weighted `tsvector`, a normalised text for trigram matching, typed filter columns and the ranking inputs," with "It holds a weighted `tsvector`, a normalised text for trigram matching, the same two per field (for field-scoped words: one `tsvector` per field, one text per fuzzy field), typed filter columns and the ranking inputs,".

`docs/limitations.md`: delete the sections `## Field scoping works per weight group` and `## Field scoping is exact-only on the typo-tolerant side` entirely.

`README.md`, "Known limitations": delete the bullet that starts "- [Field scoping](…#field-scoping-works-per-weight-group)" (three lines).

`CHANGELOG.md`, `### Breaking`, add:

```markdown
- Field-scoped queries are exact: `brand:x` searches the `brand` field only, on the full-text and
  on the typo-tolerant side. 0.4 searched every field of the same weight, and any fuzzy field once
  typo tolerance ran, so results of field-scoped queries change (`name:sony` no longer returns
  Sony-brand products). A scoped word of a field that is not fuzzy is matched exactly only. The
  index table stores one `tsvector` per field and one normalised text per fuzzy field (roughly one
  more copy of the indexed text).
```

`UPGRADE.md`, `## From 0.4 to 0.5`, append:

```markdown
2. **Field-scoped queries are exact.** `brand:x` no longer matches other fields of the same
   weight, and a scoped typo no longer matches another fuzzy field. If you relied on the old
   behaviour, search without the field prefix. The index table grows by roughly one more copy of
   the indexed text; the new columns are filled by the reindex of step 1 (until then field-scoped
   words find nothing in documents indexed before the upgrade).
```

- [ ] **Step 12: Run the gate**

Run the gate (Global Constraints). Expected: all green; no escaped mutant on the changed lines.

- [ ] **Step 13: Commit**

```bash
git add src/Engine/Postgres/Schema/Names.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/Sql/TsQueryCompiler.php src/Engine/Postgres/Sql/FuzzyQueryCompiler.php src/Engine/Postgres/Sql/SearchSqlBuilder.php src/Engine/Postgres/PostgresEngine.php tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/TsQueryCompilerTest.php tests/Unit/Postgres/FuzzyQueryCompilerTest.php tests/Unit/Postgres/SearchSqlBuilderTest.php tests/Integration/FieldScopingTest.php tests/Integration/LayoutUpgradeTest.php docs/searching.md docs/configuration.md docs/limitations.md README.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Scope field-scoped words to their field exactly

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: Shadow table, rebuild SPI and the swap (R1, engine side)

**Files:**
- Modify: `src/Core/Engine/Engine.php` (four new methods)
- Modify: `src/Engine/Postgres/Schema/Names.php` (rebuild names)
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`index()`, `drop()`, `refreshFunction()`; new `trackFunction()`, `shadowTable()`, `beginRebuild()`, `shadowIndexes()`, `swap()`, `discardRebuild()`)
- Create: `src/Engine/Postgres/ShadowRebuild.php`
- Modify: `src/Engine/Postgres/PostgresEngine.php` (constructor, four new methods)
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php` (`inspect()`: two function checks)
- Create: `tests/Unit/Postgres/RecordingConnection.php`, `tests/Unit/Postgres/ShadowRebuildTest.php`, `tests/Integration/ShadowSwapTest.php`
- Test: `tests/Unit/Postgres/NamesTest.php`, `tests/Unit/Postgres/SchemaGeneratorTest.php`, `tests/Unit/Postgres/PostgresEngineGuardTest.php`, `tests/Unit/PublicApiTest.php`, `tests/Integration/PostgresTestCase.php`, `tests/Integration/DedicatedSchemaTest.php`
- Create: `docs/adr/0008-shadow-rebuild-with-a-change-log.md`
- Docs: `docs/architecture.md`, `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: `PostgresSchemaGenerator::columns()` with the per-field columns (Task 2).
- Produces (SPI, used by Task 4's `Reindexer` and Task 5's `Worker`):
  - `Engine::beginRebuild(IndexDefinition $index, bool $resume = false): bool` — throws `Fuzzphony\Core\Exception\InvalidArgument` with message `A rebuild of "<index>" is already running.`
  - `Engine::refreshShadow(IndexDefinition $index, array $ids): int` (`@param list<int|string> $ids`)
  - `Engine::finishRebuild(IndexDefinition $index): void`
  - `Engine::abortRebuild(IndexDefinition $index, bool $keepShadow = false): void`
- Produces (Postgres internals): `Names::shadowName()`, `shadow()`, `changesName()`, `changes()`, `shadowRefreshFunctionName()`, `shadowRefreshFunction()`, `trackFunctionName()`, `trackFunction()` (all `(IndexDefinition $index): string`), `Names::shadowIndexName(string $liveName): string`, `Names::index(string $name): string`, `Names::rebuildLockKey(IndexDefinition $index): string`; generator `beginRebuild()`, `swap()`, `discardRebuild()` (`(IndexDefinition): string`), `shadowIndexes(IndexDefinition): list<string>`; `ShadowRebuild` (`begin(IndexDefinition, bool): bool`, `refresh(IndexDefinition, list<int|string>): int`, `finish(IndexDefinition): void`, `abort(IndexDefinition, bool): void`); doctor checks `'Rebuild refresh function'` and `'Rebuild change log function'`.

- [ ] **Step 1: Write the failing name and generator tests**

`tests/Unit/Postgres/NamesTest.php`, add to `testObjectNames()`:

```php
        self::assertSame('fuzzphony_products__next', $names->shadowName($index));
        self::assertSame('"public"."fuzzphony_products__next"', $names->shadow($index));
        self::assertSame('fuzzphony_products__changes', $names->changesName($index));
        self::assertSame('"public"."fuzzphony_products__changes"', $names->changes($index));
        self::assertSame('fuzzphony_refresh_products__next', $names->shadowRefreshFunctionName($index));
        self::assertSame('"public"."fuzzphony_refresh_products__next"', $names->shadowRefreshFunction($index));
        self::assertSame('fuzzphony_track_products', $names->trackFunctionName($index));
        self::assertSame('"public"."fuzzphony_track_products"', $names->trackFunction($index));
        self::assertSame('fuzzphony_products_tsv__next', $names->shadowIndexName('fuzzphony_products_tsv'));
        self::assertSame('"public"."fuzzphony_products_tsv"', $names->index('fuzzphony_products_tsv'));
        self::assertSame('fuzzphony:public.products', $names->rebuildLockKey($index));
        self::assertSame('fuzzphony:fz.products', (new Names(schema: 'fz'))->rebuildLockKey($index));
```

and to `testLongNamesAreCutToThePostgresLimitAndStayUnique()` (a 48-character index name, the longest allowed):

```php
        $longest = IndexDefinition::builder(str_repeat('x', 48))->fromTable('t')->field('title')->build();
        $rebuild = [
            $names->shadowName($longest),
            $names->changesName($longest),
            $names->shadowRefreshFunctionName($longest),
            $names->trackFunctionName($longest),
            $names->shadowIndexName($names->indexName($longest, 'pkey')),
        ];
        foreach ($rebuild as $name) {
            self::assertLessThanOrEqual(63, strlen($name), $name);
        }
        self::assertNotSame($names->sidecarName($longest), $names->shadowName($longest));
        self::assertCount(5, array_unique($rebuild));
```

`tests/Unit/Postgres/SchemaGeneratorTest.php`:
- in `testTruncateBranchComesFirstAndNeverTouchesRowsOrTransitionTables()` replace `if (!str_contains($statement->sql, 'RETURNS trigger')) {` with `if (!str_contains($statement->sql, 'RETURNS trigger') || str_contains($statement->sql, 'fuzzphony_track_')) {` (the change log function is a trigger function without a TRUNCATE branch);
- in `testGeneratedFunctionsPinTheirSearchPath()` add:

```php
        self::assertSame(2, substr_count($sql, "RETURNS integer\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$"), 'the live and the rebuild refresh function');
        self::assertStringContainsString("RETURNS trigger\nLANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$fuzzphony\$", $sql, 'the change log function embeds no developer SQL');
```

- in `testDropRemovesEveryTriggerAndFunctionAndForgetsQueuedItems()` add:

```php
        self::assertStringContainsString('DROP TABLE IF EXISTS "public"."fuzzphony_products__next"', $sql);
        self::assertStringContainsString('DROP TABLE IF EXISTS "public"."fuzzphony_products__changes"', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS "public"."fuzzphony_refresh_products__next"(bigint[])', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS "public"."fuzzphony_track_products"()', $sql);
```

- add:

```php
    public function testTheRebuildHasItsOwnRefreshFunctionAndAChangeLogFunction(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products())->toSql();

        self::assertStringContainsString('CREATE OR REPLACE FUNCTION "public"."fuzzphony_refresh_products__next"(p_ids bigint[]) RETURNS integer', $sql);
        self::assertStringContainsString('INSERT INTO "public"."fuzzphony_products__next" AS s ("id", "tsv"', $sql);
        self::assertStringContainsString('DELETE FROM "public"."fuzzphony_products__next" AS s', $sql);
        self::assertStringContainsString(<<<'SQL'
            CREATE OR REPLACE FUNCTION "public"."fuzzphony_track_products"() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $fuzzphony$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    INSERT INTO "public"."fuzzphony_products__changes" (id) VALUES (OLD.id) ON CONFLICT DO NOTHING;
                ELSE
                    INSERT INTO "public"."fuzzphony_products__changes" (id) VALUES (NEW.id) ON CONFLICT DO NOTHING;
                END IF;
                RETURN NULL;
            END
            $fuzzphony$
            SQL, $sql);
    }

    public function testBeginRebuildStartsAnEmptyRebuildAndLogsTheLiveTable(): void
    {
        $sql = (new PostgresSchemaGenerator())->beginRebuild(Indexes::products());

        self::assertStringStartsWith(
            "DO \$fuzzphony\$\nDECLARE\n    r record;\nBEGIN\n    DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__next\";\n    DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__changes\";\n    CREATE TABLE \"public\".\"fuzzphony_products__changes\" (id bigint PRIMARY KEY);\n",
            $sql,
        );
        // every role that may write the live table may write the log (its trigger runs as the writer)
        self::assertStringContainsString("FROM pg_class AS c, aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) AS a", $sql);
        self::assertStringContainsString("WHERE c.oid = '\"public\".\"fuzzphony_products\"'::regclass AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE') LOOP", $sql);
        self::assertStringContainsString("EXECUTE format('GRANT INSERT ON %s TO %s', '\"public\".\"fuzzphony_products__changes\"', CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(r.grantee)) END);", $sql);
        self::assertStringContainsString("CREATE TABLE \"public\".\"fuzzphony_products__next\" (\n    \"id\" bigint NOT NULL,\n    \"tsv\" tsvector NOT NULL,\n", $sql);
        self::assertStringContainsString("    \"indexed_at\" timestamptz NOT NULL DEFAULT now(),\n    CONSTRAINT \"fuzzphony_products_pkey__next\" PRIMARY KEY (\"id\")\n)", $sql);
        self::assertStringEndsWith(
            "    CREATE OR REPLACE TRIGGER \"fuzzphony_track_products\" AFTER INSERT OR UPDATE OR DELETE ON \"public\".\"fuzzphony_products\" FOR EACH ROW EXECUTE FUNCTION \"public\".\"fuzzphony_track_products\"();\nEND\n\$fuzzphony\$",
            $sql,
        );
    }

    public function testTheRebuildGetsItsIndexesAfterTheLoadUnderTemporaryNames(): void
    {
        $statements = (new PostgresSchemaGenerator())->shadowIndexes(Indexes::products());

        self::assertCount(7, $statements); // tsv, trigram, 4 filters, ANALYZE
        self::assertSame('CREATE INDEX IF NOT EXISTS "fuzzphony_products_tsv__next" ON "public"."fuzzphony_products__next" USING gin (tsv)', $statements[0]);
        self::assertSame('CREATE INDEX IF NOT EXISTS "fuzzphony_products_fz__next" ON "public"."fuzzphony_products__next" USING gin (fz "public".gin_trgm_ops)', $statements[1]);
        self::assertSame('CREATE INDEX IF NOT EXISTS "fuzzphony_products_f_brand_id__next" ON "public"."fuzzphony_products__next" ("f_brand_id")', $statements[5]);
        self::assertSame('ANALYZE "public"."fuzzphony_products__next"', $statements[6]);
    }

    public function testTheSwapKeepsGrantsAndOwnerAndRestoresTheLiveNames(): void
    {
        $sql = (new PostgresSchemaGenerator())->swap(Indexes::products());

        self::assertStringContainsString("WHERE c.oid = '\"public\".\"fuzzphony_products\"'::regclass AND a.grantee <> c.relowner LOOP", $sql);
        self::assertStringContainsString("EXECUTE format('GRANT %s ON %s TO %s%s', r.privilege_type, '\"public\".\"fuzzphony_products__next\"', r.grantee, CASE WHEN r.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END);", $sql);
        self::assertStringContainsString("IF v_owner <> quote_ident(current_user) THEN\n        EXECUTE format('ALTER TABLE %s OWNER TO %s', '\"public\".\"fuzzphony_products__next\"', v_owner);", $sql);
        self::assertStringEndsWith(<<<'SQL'
                DROP TABLE "public"."fuzzphony_products";
                ALTER TABLE "public"."fuzzphony_products__next" RENAME TO "fuzzphony_products";
                ALTER TABLE "public"."fuzzphony_products" RENAME CONSTRAINT "fuzzphony_products_pkey__next" TO "fuzzphony_products_pkey";
                ALTER INDEX "public"."fuzzphony_products_tsv__next" RENAME TO "fuzzphony_products_tsv";
                ALTER INDEX "public"."fuzzphony_products_fz__next" RENAME TO "fuzzphony_products_fz";
                ALTER INDEX "public"."fuzzphony_products_f_price__next" RENAME TO "fuzzphony_products_f_price";
                ALTER INDEX "public"."fuzzphony_products_f_in_stock__next" RENAME TO "fuzzphony_products_f_in_stock";
                ALTER INDEX "public"."fuzzphony_products_f_published_at__next" RENAME TO "fuzzphony_products_f_published_at";
                ALTER INDEX "public"."fuzzphony_products_f_brand_id__next" RENAME TO "fuzzphony_products_f_brand_id";
                DROP TABLE "public"."fuzzphony_products__changes";
            END
            $fuzzphony$
            SQL, $sql);
    }

    public function testDiscardingARebuildRemovesItsTablesAndTheTrigger(): void
    {
        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_products\"') IS NOT NULL THEN DROP TRIGGER IF EXISTS \"fuzzphony_track_products\" ON \"public\".\"fuzzphony_products\"; END IF; DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__next\"; DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__changes\"; END \$fuzzphony\$",
            (new PostgresSchemaGenerator())->discardRebuild(Indexes::products()),
        );
    }
```

(The nowdoc in `testTheSwapKeepsGrantsAndOwnerAndRestoresTheLiveNames()` is indented so that its body lines start with 4 spaces after PHP strips the closing marker's indentation, like the generator's output.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: FAIL (undefined methods).

- [ ] **Step 3: Implement the names and the generator**

`Names.php`, after `syncFunction()`:

```php
    /** The table a full reindex builds next to the live one, swapped in when it is complete. */
    public function shadowName(IndexDefinition $index): string
    {
        return self::limit($this->sidecarName($index) . '__next');
    }

    public function shadow(IndexDefinition $index): string
    {
        return $this->qualify($this->shadowName($index));
    }

    /** The ids of the live documents that changed while a full reindex runs. */
    public function changesName(IndexDefinition $index): string
    {
        return self::limit($this->sidecarName($index) . '__changes');
    }

    public function changes(IndexDefinition $index): string
    {
        return $this->qualify($this->changesName($index));
    }

    public function shadowRefreshFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'refresh_' . $index->name . '__next');
    }

    public function shadowRefreshFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->shadowRefreshFunctionName($index));
    }

    /** The trigger function (and the trigger on the live table) that fills the change log. */
    public function trackFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'track_' . $index->name);
    }

    public function trackFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->trackFunctionName($index));
    }

    /** The name an index (or the primary key) of the rebuild table has until the swap gives it $liveName. */
    public function shadowIndexName(string $liveName): string
    {
        return self::limit($liveName . '__next');
    }

    /** An index of one of Fuzzphony's tables, qualified: ALTER INDEX needs the schema. */
    public function index(string $name): string
    {
        return $this->qualify($name);
    }

    /** The advisory lock key of an index's full rebuild (pg_advisory_lock(hashtext(key))). */
    public function rebuildLockKey(IndexDefinition $index): string
    {
        return 'fuzzphony:' . $this->schema . '.' . $index->name;
    }
```

`PostgresSchemaGenerator.php`:

In `index()` replace `$statements[] = new Statement($this->refreshFunction($index), 'Builds / removes documents by id');` with:

```php
        $statements[] = new Statement($this->refreshFunction($index), 'Builds / removes documents by id');
        $statements[] = new Statement($this->refreshFunction($index, shadow: true), 'Builds / removes documents by id in the table a full reindex builds next to the live one');
        $statements[] = new Statement($this->trackFunction($index), 'Logs which documents change while a full reindex runs');
```

In `drop()` insert right after the `'Remove sidecar table'` statement:

```php
        $statements[] = new Statement(sprintf('DROP TABLE IF EXISTS %s', $this->names->shadow($index)), 'Remove a rebuild that did not finish');
        $statements[] = new Statement(sprintf('DROP TABLE IF EXISTS %s', $this->names->changes($index)), 'Remove its change log');
        $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)), 'Remove the rebuild refresh function');
        $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s()', $this->names->trackFunction($index)), 'Remove the change log function');
```

Change `refreshFunction()`'s signature to `private function refreshFunction(IndexDefinition $index, bool $shadow = false): string`, its first line to `$table = $shadow ? $this->names->shadow($index) : $this->names->sidecar($index);`, and its first `sprintf` argument (`$this->names->refreshFunction($index),`) to `$shadow ? $this->names->shadowRefreshFunction($index) : $this->names->refreshFunction($index),`. Its docblock gets one more sentence: "With $shadow, the same function for the table a full reindex builds (Names::shadow()): two static functions keep the SQL plan-cached, no EXECUTE per batch."

Add after `refreshFunction()`:

```php
    /**
     * Logs the id of every live document that changes while a full reindex runs (a row trigger
     * on the live table, created by beginRebuild(), gone with the old table after the swap). It
     * runs as the writer, in the writer's transaction; beginRebuild() grants the log to them.
     */
    private function trackFunction(IndexDefinition $index): string
    {
        return sprintf(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION %1$s() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS %3$s
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        INSERT INTO %2$s (id) VALUES (OLD.id) ON CONFLICT DO NOTHING;
                    ELSE
                        INSERT INTO %2$s (id) VALUES (NEW.id) ON CONFLICT DO NOTHING;
                    END IF;
                    RETURN NULL;
                END
                %3$s
                SQL,
            $this->names->trackFunction($index),
            $this->names->changes($index),
            self::TAG,
        );
    }

    /**
     * Starts a full rebuild next to the live table, in one statement: drops a leftover one,
     * creates the change log (writable by every role that may write the live table) and the
     * empty rebuild table (the current layout, without secondary indexes: shadowIndexes() adds
     * them after the load), and starts logging the live table. CREATE TRIGGER waits for the
     * transactions writing the live table, so every later change is logged.
     */
    public function beginRebuild(IndexDefinition $index): string
    {
        return sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                BEGIN
                    DROP TABLE IF EXISTS %2$s;
                    DROP TABLE IF EXISTS %3$s;
                    CREATE TABLE %3$s (id %4$s PRIMARY KEY);
                    FOR r IN SELECT DISTINCT a.grantee
                             FROM pg_class AS c, aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) AS a
                             WHERE c.oid = %5$s::regclass AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE') LOOP
                        EXECUTE format('GRANT INSERT ON %%s TO %%s', %6$s, CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(r.grantee)) END);
                    END LOOP;
                    %7$s;
                    CREATE OR REPLACE TRIGGER %8$s AFTER INSERT OR UPDATE OR DELETE ON %9$s FOR EACH ROW EXECUTE FUNCTION %10$s();
                END
                %1$s
                SQL,
            self::TAG,
            $this->names->shadow($index),
            $this->names->changes($index),
            Types::id($index->idType),
            Sql::string($this->names->sidecar($index)),
            Sql::string($this->names->changes($index)),
            $this->shadowTable($index),
            Sql::ident($this->names->trackFunctionName($index)),
            $this->names->sidecar($index),
            $this->names->trackFunction($index),
        );
    }

    /** The rebuild table: the current layout (columns()), its primary key named for the swap. */
    private function shadowTable(IndexDefinition $index): string
    {
        $columns = $this->columns($index);
        $columns['id'] = Types::id($index->idType) . ' NOT NULL';
        $definitions = array_map(static fn(string $name, string $type): string => sprintf('    %s %s', Sql::ident($name), $type), array_keys($columns), $columns);
        $definitions[] = sprintf('    CONSTRAINT %s PRIMARY KEY (%s)', Sql::ident($this->names->shadowIndexName($this->names->indexName($index, 'pkey'))), Sql::ident('id'));

        return sprintf("CREATE TABLE %s (\n%s\n)", $this->names->shadow($index), implode(",\n", $definitions));
    }

    /**
     * The rebuild table's secondary indexes, built once it is loaded (faster than maintaining
     * them during the load; the table is not live, so nothing waits), and fresh statistics.
     *
     * @return list<string>
     */
    public function shadowIndexes(IndexDefinition $index): array
    {
        $statements = [];
        foreach ($this->indexes($index) as $name => $definition) {
            $statements[] = sprintf('CREATE INDEX IF NOT EXISTS %s ON %s %s', Sql::ident($this->names->shadowIndexName($name)), $this->names->shadow($index), $definition);
        }
        $statements[] = sprintf('ANALYZE %s', $this->names->shadow($index));

        return $statements;
    }

    /**
     * Swaps the rebuild in (run under ACCESS EXCLUSIVE on both tables): it gets the live table's
     * grants and owner (it was created by the reindexing role), the live table goes (with its
     * change log trigger), the rebuild takes the live name, its primary key and indexes the live
     * names. plpgsql resolves tables by name: the drop invalidates the cached plans of the live
     * refresh function, which writes the new table from its next call.
     */
    public function swap(IndexDefinition $index): string
    {
        $sidecar = $this->names->sidecar($index);
        $renames = '';
        foreach (array_keys($this->indexes($index)) as $name) {
            $renames .= sprintf("\n    ALTER INDEX %s RENAME TO %s;", $this->names->index($this->names->shadowIndexName($name)), Sql::ident($name));
        }
        $key = $this->names->indexName($index, 'pkey');

        return sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                    v_owner text;
                BEGIN
                    FOR r IN SELECT a.privilege_type, a.is_grantable, CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(a.grantee)) END AS grantee
                             FROM pg_class AS c, aclexplode(c.relacl) AS a
                             WHERE c.oid = %2$s::regclass AND a.grantee <> c.relowner LOOP
                        EXECUTE format('GRANT %%s ON %%s TO %%s%%s', r.privilege_type, %3$s, r.grantee, CASE WHEN r.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END);
                    END LOOP;
                    SELECT quote_ident(pg_get_userbyid(relowner)) INTO v_owner FROM pg_class WHERE oid = %2$s::regclass;
                    IF v_owner <> quote_ident(current_user) THEN
                        EXECUTE format('ALTER TABLE %%s OWNER TO %%s', %3$s, v_owner);
                    END IF;
                    DROP TABLE %4$s;
                    ALTER TABLE %5$s RENAME TO %6$s;
                    ALTER TABLE %4$s RENAME CONSTRAINT %7$s TO %8$s;%9$s
                    DROP TABLE %10$s;
                END
                %1$s
                SQL,
            self::TAG,
            Sql::string($sidecar),
            Sql::string($this->names->shadow($index)),
            $sidecar,
            $this->names->shadow($index),
            Sql::ident($this->names->sidecarName($index)),
            Sql::ident($this->names->shadowIndexName($key)),
            Sql::ident($key),
            $renames,
            $this->names->changes($index),
        );
    }

    /** Discards a rebuild: the change log trigger, the rebuild table and the log. */
    public function discardRebuild(IndexDefinition $index): string
    {
        return sprintf(
            'DO %1$s BEGIN IF to_regclass(%2$s) IS NOT NULL THEN DROP TRIGGER IF EXISTS %3$s ON %4$s; END IF; DROP TABLE IF EXISTS %5$s; DROP TABLE IF EXISTS %6$s; END %1$s',
            self::TAG,
            Sql::string($this->names->sidecar($index)),
            Sql::ident($this->names->trackFunctionName($index)),
            $this->names->sidecar($index),
            $this->names->shadow($index),
            $this->names->changes($index),
        );
    }
```

- [ ] **Step 4: Run them again**

Run: `vendor/bin/phpunit tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing `ShadowRebuild` unit tests**

Create `tests/Unit/Postgres/RecordingConnection.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;

/** @internal Records every statement and transaction boundary; fetchValue() / execute() answer from a script (which may throw). */
final class RecordingConnection implements Connection
{
    /** @var list<array{string, array<string, scalar|null>}> */
    public array $log = [];

    /** @param \Closure(string, array<string, scalar|null>): mixed $answer */
    public function __construct(private readonly \Closure $answer) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->log[] = [$sql, $params];

        return [];
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $this->log[] = [$sql, $params];

        return ($this->answer)($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->log[] = [$sql, $params];
        ($this->answer)($sql, $params);

        return 0;
    }

    public function transactional(callable $callback): mixed
    {
        $this->log[] = ['BEGIN', []];
        $result = $callback($this);
        $this->log[] = ['COMMIT', []];

        return $result;
    }
}
```

Create `tests/Unit/Postgres/ShadowRebuildTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\ShadowRebuild;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The exact statements of a rebuild, in order: lock, start, catch-up, swap, release. */
final class ShadowRebuildTest extends TestCase
{
    private const string LOCK = 'SELECT pg_try_advisory_lock(hashtext(:key))';
    private const string UNLOCK = 'SELECT pg_advisory_unlock(hashtext(:key))';
    private const array KEY = ['key' => 'fuzzphony:public.products'];
    private const string POSSIBLE = "SELECT has_schema_privilege(:schema, 'CREATE')\n   AND pg_has_role(c.relowner, 'USAGE')\n   AND to_regprocedure(:refresh) IS NOT NULL\n   AND to_regprocedure(:track) IS NOT NULL\nFROM pg_class AS c\nWHERE c.oid = to_regclass(:sidecar)";
    private const array POSSIBLE_PARAMS = [
        'schema' => 'public',
        'refresh' => '"public"."fuzzphony_refresh_products__next"(bigint[])',
        'track' => '"public"."fuzzphony_track_products"()',
        'sidecar' => '"public"."fuzzphony_products"',
    ];
    private const string LEFT_OVER = 'SELECT to_regclass(:shadow) IS NOT NULL AND to_regclass(:changes) IS NOT NULL';
    private const array LEFT_OVER_PARAMS = ['shadow' => '"public"."fuzzphony_products__next"', 'changes' => '"public"."fuzzphony_products__changes"'];

    public function testAFullRunTakesTheLockAndStartsTheRebuild(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => true);
        $generator = new PostgresSchemaGenerator();

        self::assertTrue((new ShadowRebuild($connection, $generator))->begin(Indexes::products(), false));
        self::assertSame([
            [self::LOCK, self::KEY],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [$generator->beginRebuild(Indexes::products()), []],
        ], $connection->log, 'the lock is kept for the rest of the run');
    }

    public function testAnotherRunHoldingTheLockFailsFast(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => false);

        try {
            (new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false);
            self::fail('InvalidArgument expected');
        } catch (InvalidArgument $e) {
            self::assertSame('A rebuild of "products" is already running.', $e->getMessage());
        }
        self::assertSame([[self::LOCK, self::KEY]], $connection->log);
    }

    public function testWithoutTheRightsOrTheFunctionsTheRunGoesInPlaceAndReleasesTheLock(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => $sql === self::LOCK ? true : null);

        self::assertFalse((new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false));
        self::assertSame([
            [self::LOCK, self::KEY],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [self::UNLOCK, self::KEY],
        ], $connection->log);
    }

    public function testAResumedRunContinuesALeftOverRebuildOrGoesInPlace(): void
    {
        $leftOver = new RecordingConnection(static fn(string $sql): mixed => true);
        self::assertTrue((new ShadowRebuild($leftOver, new PostgresSchemaGenerator()))->begin(Indexes::products(), true));
        self::assertSame([[self::LOCK, self::KEY], [self::LEFT_OVER, self::LEFT_OVER_PARAMS]], $leftOver->log, 'nothing is recreated, the lock is kept');

        $none = new RecordingConnection(static fn(string $sql): mixed => $sql === self::LOCK);
        self::assertFalse((new ShadowRebuild($none, new PostgresSchemaGenerator()))->begin(Indexes::products(), true));
        self::assertSame([[self::LOCK, self::KEY], [self::LEFT_OVER, self::LEFT_OVER_PARAMS], [self::UNLOCK, self::KEY]], $none->log);
    }

    public function testAFailureWhileStartingReleasesTheLock(): void
    {
        $connection = new RecordingConnection(static function (string $sql): mixed {
            if (str_starts_with($sql, "DO \$fuzzphony\$\nDECLARE")) {
                throw new \RuntimeException('boom');
            }

            return true;
        });

        try {
            (new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false);
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame([self::UNLOCK, self::KEY], $connection->log[array_key_last($connection->log)]);
    }

    public function testFinishCatchesUpInBatchesThenRefreshesTheRestAndSwapsUnderTheLock(): void
    {
        $taken = [5_000, 7, 3];
        $connection = new RecordingConnection(static function (string $sql) use (&$taken): mixed {
            return str_starts_with($sql, 'WITH batch') ? array_shift($taken) : null;
        });
        $generator = new PostgresSchemaGenerator();

        (new ShadowRebuild($connection, $generator))->finish(Indexes::products());

        $catchUp = static fn(string $where): string => sprintf(
            "WITH batch AS (\n    DELETE FROM \"public\".\"fuzzphony_products__changes\"%s\n    RETURNING id\n), refreshed AS (\n    SELECT \"public\".\"fuzzphony_refresh_products__next\"(ARRAY(SELECT id FROM batch)) AS written\n)\nSELECT (SELECT count(*) FROM batch) FROM refreshed",
            $where,
        );
        $limited = $catchUp(' WHERE id IN (SELECT id FROM "public"."fuzzphony_products__changes" ORDER BY id LIMIT :limit)');
        self::assertSame([
            ...array_map(static fn(string $sql): array => [$sql, []], $generator->shadowIndexes(Indexes::products())),
            ['BEGIN', []], [$limited, ['limit' => 5_000]], ['COMMIT', []],
            ['BEGIN', []], [$limited, ['limit' => 5_000]], ['COMMIT', []],
            ['BEGIN', []],
            ['LOCK TABLE "public"."fuzzphony_products", "public"."fuzzphony_products__next" IN ACCESS EXCLUSIVE MODE', []],
            [$catchUp(''), []],
            [$generator->swap(Indexes::products()), []],
            ['COMMIT', []],
            [self::UNLOCK, self::KEY],
        ], $connection->log);
    }

    public function testAbortDiscardsTheRebuildUnlessItIsKeptForResuming(): void
    {
        $generator = new PostgresSchemaGenerator();
        $keep = new RecordingConnection(static fn(string $sql): mixed => true);
        (new ShadowRebuild($keep, $generator))->abort(Indexes::products(), true);
        self::assertSame([[self::UNLOCK, self::KEY]], $keep->log);

        $discard = new RecordingConnection(static fn(string $sql): mixed => true);
        (new ShadowRebuild($discard, $generator))->abort(Indexes::products(), false);
        self::assertSame([[$generator->discardRebuild(Indexes::products()), []], [self::UNLOCK, self::KEY]], $discard->log);
    }

    public function testRefreshWritesTheRebuildTable(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => '2');
        $rebuild = new ShadowRebuild($connection, new PostgresSchemaGenerator());

        self::assertSame(0, $rebuild->refresh(Indexes::products(), []));
        self::assertSame([], $connection->log, 'no ids, no statement');
        self::assertSame(2, $rebuild->refresh(Indexes::products(), [1, 2]));
        self::assertSame([['SELECT "public"."fuzzphony_refresh_products__next"(CAST(:ids AS bigint[]))', ['ids' => '{1,2}']]], $connection->log);
    }
}
```

- [ ] **Step 6: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/ShadowRebuildTest.php`
Expected: FAIL: class `ShadowRebuild` not found.

- [ ] **Step 7: Implement `ShadowRebuild`, the SPI and the engine methods**

Create `src/Engine/Postgres/ShadowRebuild.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The zero-downtime reindex of PostgresEngine (ADR 0008). A full rebuild fills a second
 * table (Names::shadow()) through its own refresh function while searches keep reading the live
 * one. A row trigger logs every change to the live table meanwhile (Names::changes()). finish()
 * refreshes the logged ids into the rebuild in batches, then takes ACCESS EXCLUSIVE on the live
 * table, which waits for every transaction that wrote it (so the log is complete), refreshes the
 * rest and swaps the tables in the same transaction. One rebuild per index at a time: a
 * session-level advisory lock, held from begin() until finish() or abort().
 */
final class ShadowRebuild
{
    /** Logged ids refreshed per statement while catching up outside the swap lock. */
    private const int CATCH_UP_BATCH = 5_000;

    private readonly Names $names;

    public function __construct(
        private readonly Connection $connection,
        private readonly PostgresSchemaGenerator $schema,
    ) {
        $this->names = $schema->names();
    }

    /**
     * Takes the lock and starts (or, with $resume, continues) the rebuild; false, with the lock
     * released, when the run must write the live index in place.
     */
    public function begin(IndexDefinition $index, bool $resume): bool
    {
        if (!(bool) $this->connection->fetchValue('SELECT pg_try_advisory_lock(hashtext(:key))', ['key' => $this->names->rebuildLockKey($index)])) {
            throw new InvalidArgument(sprintf('A rebuild of "%s" is already running.', $index->name));
        }
        try {
            $shadow = $resume ? $this->leftOver($index) : $this->possible($index);
            if ($shadow && !$resume) {
                $this->connection->execute($this->schema->beginRebuild($index));
            }
        } catch (\Throwable $e) {
            $this->unlock($index);

            throw $e;
        }
        if (!$shadow) {
            $this->unlock($index);
        }

        return $shadow;
    }

    /** @param list<int|string> $ids */
    public function refresh(IndexDefinition $index, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Coerce::int($this->connection->fetchValue(
            sprintf('SELECT %s(CAST(:ids AS %s[]))', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
            ['ids' => Sql::arrayLiteral(array_values($ids))],
        ));
    }

    public function finish(IndexDefinition $index): void
    {
        foreach ($this->schema->shadowIndexes($index) as $sql) {
            $this->connection->execute($sql);
        }
        do {
            $taken = $this->connection->transactional(fn(Connection $c): int => $this->catchUp($c, $index, self::CATCH_UP_BATCH));
        } while ($taken === self::CATCH_UP_BATCH);
        $this->connection->transactional(function (Connection $c) use ($index): void {
            // waits for every transaction that wrote the live table: after it, the log is complete
            $c->execute(sprintf('LOCK TABLE %s, %s IN ACCESS EXCLUSIVE MODE', $this->names->sidecar($index), $this->names->shadow($index)));
            $this->catchUp($c, $index, null);
            $c->execute($this->schema->swap($index));
        });
        $this->unlock($index);
    }

    public function abort(IndexDefinition $index, bool $keepShadow): void
    {
        if (!$keepShadow) {
            $this->connection->execute($this->schema->discardRebuild($index));
        }
        $this->unlock($index);
    }

    /** A resumed run continues the rebuild a failed run left behind. */
    private function leftOver(IndexDefinition $index): bool
    {
        return (bool) $this->connection->fetchValue(
            'SELECT to_regclass(:shadow) IS NOT NULL AND to_regclass(:changes) IS NOT NULL',
            ['shadow' => $this->names->shadow($index), 'changes' => $this->names->changes($index)],
        );
    }

    /**
     * Whether this role can build next to the live table (create tables in Fuzzphony's schema;
     * drop and replace the live one: its owner or a member of the owning role) and schema --apply
     * created the rebuild's functions. NULL (no live table) counts as no.
     */
    private function possible(IndexDefinition $index): bool
    {
        return (bool) $this->connection->fetchValue(
            <<<'SQL'
                SELECT has_schema_privilege(:schema, 'CREATE')
                   AND pg_has_role(c.relowner, 'USAGE')
                   AND to_regprocedure(:refresh) IS NOT NULL
                   AND to_regprocedure(:track) IS NOT NULL
                FROM pg_class AS c
                WHERE c.oid = to_regclass(:sidecar)
                SQL,
            [
                'schema' => $this->names->schema,
                'refresh' => sprintf('%s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
                'track' => $this->names->trackFunction($index) . '()',
                'sidecar' => $this->names->sidecar($index),
            ],
        );
    }

    /** Refreshes up to $limit logged ids (null: all) into the rebuild, in one statement: a failed refresh keeps them logged. */
    private function catchUp(Connection $c, IndexDefinition $index, ?int $limit): int
    {
        $changes = $this->names->changes($index);
        $sql = sprintf(
            <<<'SQL'
                WITH batch AS (
                    DELETE FROM %1$s%2$s
                    RETURNING id
                ), refreshed AS (
                    SELECT %3$s(ARRAY(SELECT id FROM batch)) AS written
                )
                SELECT (SELECT count(*) FROM batch) FROM refreshed
                SQL,
            $changes,
            $limit === null ? '' : sprintf(' WHERE id IN (SELECT id FROM %s ORDER BY id LIMIT :limit)', $changes),
            $this->names->shadowRefreshFunction($index),
        );

        return Coerce::int($c->fetchValue($sql, $limit === null ? [] : ['limit' => $limit]));
    }

    private function unlock(IndexDefinition $index): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock(hashtext(:key))', ['key' => $this->names->rebuildLockKey($index)]);
    }
}
```

`src/Core/Engine/Engine.php`, after `recordReindex()`:

```php
    /**
     * Starts a full rebuild next to the live index, which searches keep reading until
     * finishRebuild() swaps the rebuild in (zero-downtime reindex). Takes the index's rebuild
     * lock for the whole run and throws InvalidArgument when another run holds it. A new run
     * ($resume false) discards a leftover rebuild and starts an empty one; a resumed run
     * continues a leftover one. Returns false, with the lock released, when the run must write
     * the live index in place instead: $resume without a leftover rebuild, or an engine or a
     * role that cannot build next to the live index.
     */
    public function beginRebuild(IndexDefinition $index, bool $resume = false): bool;

    /**
     * refresh() into the rebuild beginRebuild() started.
     *
     * @param list<int|string> $ids
     *
     * @return int number of documents written
     */
    public function refreshShadow(IndexDefinition $index, array $ids): int;

    /** Catches the rebuild up with the changes made to the live index meanwhile, swaps it in atomically and releases the lock. */
    public function finishRebuild(IndexDefinition $index): void;

    /** Releases the rebuild lock and discards the rebuild, unless $keepShadow (a failed run keeps it, so a resumed run can continue it). */
    public function abortRebuild(IndexDefinition $index, bool $keepShadow = false): void;
```

`PostgresEngine.php`: add the constant `private const string REBUILD_HINT = 'Run "fuzzphony:schema --apply" and "fuzzphony:doctor".';`, the property `private readonly ShadowRebuild $rebuild;`, in the constructor after `$this->schema = …` the line `$this->rebuild = new ShadowRebuild($connection, $this->schema);`, and after `recordReindex()`:

```php
    public function beginRebuild(IndexDefinition $index, bool $resume = false): bool
    {
        return $this->guard('rebuild', fn(): bool => $this->rebuild->begin($index, $resume), self::REBUILD_HINT);
    }

    public function refreshShadow(IndexDefinition $index, array $ids): int
    {
        return $this->guard('rebuild', fn(): int => $this->rebuild->refresh($index, $ids), self::REBUILD_HINT);
    }

    public function finishRebuild(IndexDefinition $index): void
    {
        $this->guard('rebuild', function () use ($index): null {
            $this->rebuild->finish($index);

            return null;
        }, self::REBUILD_HINT);
    }

    public function abortRebuild(IndexDefinition $index, bool $keepShadow = false): void
    {
        $this->guard('rebuild', function () use ($index, $keepShadow): null {
            $this->rebuild->abort($index, $keepShadow);

            return null;
        }, self::REBUILD_HINT);
    }
```

`tests/Unit/Postgres/PostgresEngineGuardTest.php`, add to `operations()`:

```php
        yield 'rebuild' => ['rebuild', 'Run "fuzzphony:schema --apply" and "fuzzphony:doctor".', $always, static fn(PostgresEngine $e): mixed => $e->refreshShadow(Indexes::products(), [1])];
```

`tests/Unit/PublicApiTest.php`: `self::assertCount(120, self::classes());` becomes `self::assertCount(121, self::classes());` (the new class is `@internal`; `PUBLIC` stays at 69).

- [ ] **Step 8: Run the unit tests again**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 9: Write the failing integration tests**

`tests/Integration/DedicatedSchemaTest.php`, `testEveryObjectLivesInTheConfiguredSchema()`: the expected function list gains the two rebuild functions:

```php
        self::assertSame(['fuzzphony_norm', 'fuzzphony_refresh_products', 'fuzzphony_refresh_products__next', 'fuzzphony_sync_products__fz_brand', 'fuzzphony_sync_products__fz_product', 'fuzzphony_track_products'], $functions);
```

`tests/Integration/PostgresTestCase.php`, `createFixtures()`: the first statement becomes

```php
        $connection->execute('DROP TABLE IF EXISTS fz_product, fz_brand, fuzzphony_products, fuzzphony_products__next, fuzzphony_products__changes, fuzzphony_queue, fuzzphony_meta CASCADE');
```

Create `tests/Integration/ShadowSwapTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rebuild SPI of the PostgreSQL engine: build next to the live table, catch up, swap. */
final class ShadowSwapTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
    }

    protected function tearDown(): void
    {
        // PHPUnit keeps finished test objects (and their connections) alive: never leave a rebuild lock behind
        $this->connection->fetchValue('SELECT pg_advisory_unlock_all()');
    }

    public function testTheSwapKeepsTheLiveNamesAndLeavesNothingBehind(): void
    {
        $index = $this->install('manual');

        $this->rebuild($index);

        $expected = [...array_keys((new PostgresSchemaGenerator())->indexes($index)), 'fuzzphony_products_pkey'];
        sort($expected);
        self::assertSame($expected, array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'fuzzphony_products' ORDER BY indexname COLLATE \"C\"",
        ), 'indexname')));
        self::assertSame('fuzzphony_products_pkey', $this->connection->fetchValue("SELECT conname FROM pg_constraint WHERE conrelid = 'fuzzphony_products'::regclass AND contype = 'p'"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__changes')"));
        self::assertSame([], $this->connection->fetchAll("SELECT 1 FROM pg_trigger WHERE tgrelid = 'fuzzphony_products'::regclass AND NOT tgisinternal"), 'the change log trigger went with the old table');
        self::assertCount(5, $this->documents());
        $other = PostgresTestCase::connect();
        self::assertTrue((bool) $other->fetchValue("SELECT pg_try_advisory_lock(hashtext('fuzzphony:public.products'))"), 'the lock is released');
        $other->fetchValue('SELECT pg_advisory_unlock_all()');
        foreach ($this->engine->inspect($index)->checks as $check) {
            if (str_starts_with($check->name, 'Index ') || str_starts_with($check->name, 'Rebuild')) {
                self::assertSame(CheckStatus::Ok, $check->status, $check->name . ': ' . $check->message);
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function writers(): iterable
    {
        yield 'trigger sync' => ['trigger', false];
        yield 'queue sync, worker during the build' => ['queue', true];
        yield 'queue sync, worker after the swap' => ['queue', false];
    }

    #[DataProvider('writers')]
    public function testChangesMadeDuringTheBuildReachTheSwappedIndex(string $sync, bool $workerDuringTheBuild): void
    {
        $index = $this->install($sync);

        $this->rebuild($index, function () use ($index, $workerDuringTheBuild): void {
            // ids 1 and 2 are in the rebuild already, 3 to 5 are not
            $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
            $this->connection->execute("UPDATE fz_brand SET name = 'Razor' WHERE id = 2");
            $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
            $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
            if ($workerDuringTheBuild) {
                self::assertGreaterThan(0, $this->engine->processQueue($index, 100));
            }
        });
        (new Worker($this->engine))->runOnce([$index]); // what is still queued goes into the new live table
        $swapped = $this->documents();

        $this->engine->refresh($index, [1, 2, 3, 4, 5, 6]); // a fresh refresh of every id, in place
        self::assertSame($this->documents(), $swapped, 'the swapped index equals a fresh rebuild');
        self::assertSame(['1', '2', '3', '5', '6'], array_column($swapped, 'id'));
        self::assertSame(0, $this->engine->queueSize($index));
    }

    public function testASearchDuringTheBuildSeesTheCompleteLiveIndex(): void
    {
        $index = $this->install('manual');
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));

        $this->rebuild($index, function () use ($fuzzphony): void {
            self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')), 'the rebuild is half done');
            self::assertCount(5, $fuzzphony->in('products')->get()->ids(), 'searches read the complete live index');
        });
    }

    public function testTheLiveRefreshFunctionWritesTheNewTableAfterTheSwap(): void
    {
        $index = $this->install('trigger');
        $this->connection->execute("UPDATE fz_product SET name = 'Wireless mouse 2' WHERE id = 1"); // this session has run (and cached) the live refresh function

        $this->rebuild($index);
        $this->connection->execute("UPDATE fz_product SET name = 'Vertical mouse' WHERE id = 1");

        self::assertSame('vertical mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'));
    }

    public function testTheSwappedTableKeepsTheGrantsAndTheOwner(): void
    {
        $index = $this->install('manual');
        $reader = 'fz_reader_' . getmypid(); // roles are cluster-wide; parallel (Infection) runs must not share one
        $owner = 'fz_owner_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s, %s', $reader, $owner));
        $this->connection->execute(sprintf('CREATE ROLE %s', $reader));
        $this->connection->execute(sprintf('CREATE ROLE %s', $owner));
        try {
            $this->connection->execute(sprintf('GRANT SELECT ON fuzzphony_products TO %s WITH GRANT OPTION', $reader));
            $this->connection->execute(sprintf('GRANT UPDATE ON fuzzphony_products TO %s', $reader));
            $this->connection->execute(sprintf('ALTER TABLE fuzzphony_products OWNER TO %s', $owner));

            $this->rebuild($index);

            $can = fn(string $privilege): bool => (bool) $this->connection->fetchValue('SELECT has_table_privilege(:role, :table, :privilege)', ['role' => $reader, 'table' => 'fuzzphony_products', 'privilege' => $privilege]);
            self::assertTrue($can('SELECT WITH GRANT OPTION'));
            self::assertTrue($can('UPDATE'));
            self::assertFalse($can('UPDATE WITH GRANT OPTION'));
            self::assertFalse($can('INSERT'));
            self::assertSame($owner, $this->connection->fetchValue("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'fuzzphony_products'::regclass"));
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s, %s', $reader, $owner));
            $this->connection->execute(sprintf('DROP ROLE %s, %s', $reader, $owner));
        }
    }

    public function testARoleThatWritesTheLiveIndexKeepsWorkingDuringARebuild(): void
    {
        $index = $this->install('queue');
        $writer = 'fz_writer_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s', $writer));
        $this->connection->execute(sprintf('CREATE ROLE %s', $writer));
        try {
            $this->connection->execute(sprintf('GRANT SELECT ON fz_product, fz_brand TO %s', $writer));
            $this->connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products, fuzzphony_queue TO %s', $writer));

            $this->rebuild($index, function () use ($index, $writer): void {
                $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
                $this->connection->execute(sprintf('SET ROLE %s', $writer));
                try {
                    self::assertSame(1, $this->engine->processQueue($index, 100), 'the worker role may write the change log');
                } finally {
                    $this->connection->execute('RESET ROLE');
                }
            });

            self::assertSame('silent office mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'));
            $this->connection->execute(sprintf('SET ROLE %s', $writer));
            try {
                self::assertSame(5, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products')), 'and still reads the new table');
            } finally {
                $this->connection->execute('RESET ROLE');
            }
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s', $writer));
            $this->connection->execute(sprintf('DROP ROLE %s', $writer));
        }
    }

    public function testASecondRebuildFailsFastAndTheLockIsFreedAfterwards(): void
    {
        $index = $this->install('manual');
        $other = new PostgresEngine(PostgresTestCase::connect());
        self::assertTrue($this->engine->beginRebuild($index));

        try {
            $other->beginRebuild($index);
            self::fail('InvalidArgument expected');
        } catch (InvalidArgument $e) {
            self::assertSame('A rebuild of "products" is already running.', $e->getMessage());
        }

        $this->engine->abortRebuild($index);
        self::assertTrue($other->beginRebuild($index));
        $other->abortRebuild($index);
    }

    public function testAbortKeepsTheRebuildForResumingOrDiscardsIt(): void
    {
        $index = $this->install('manual');
        self::assertTrue($this->engine->beginRebuild($index));
        self::assertSame(2, $this->engine->refreshShadow($index, [1, 2]));

        $this->engine->abortRebuild($index, keepShadow: true);
        self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')));
        self::assertSame(1, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname = 'fuzzphony_track_products'")), 'changes are still logged');

        self::assertTrue($this->engine->beginRebuild($index, resume: true), 'a resumed run continues it');
        self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')), 'as it was');

        $this->engine->abortRebuild($index);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__changes')"));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname = 'fuzzphony_track_products'")));
        self::assertFalse($this->engine->beginRebuild($index, resume: true), 'nothing left to resume: in place');
        self::assertTrue($this->engine->beginRebuild($index), 'and the lock was released');
        $this->engine->abortRebuild($index);
    }

    public function testARoleThatCannotBuildNextToTheLiveIndexGoesInPlace(): void
    {
        $index = $this->install('manual');
        $role = 'fz_noddl_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $this->connection->execute(sprintf('CREATE ROLE %s', $role));
        try {
            $this->connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products TO %s', $role));
            $this->connection->execute(sprintf('SET ROLE %s', $role));
            try {
                self::assertFalse($this->engine->beginRebuild($index), 'no CREATE on the schema, not the owner');
            } finally {
                $this->connection->execute('RESET ROLE');
            }
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s', $role));
            $this->connection->execute(sprintf('DROP ROLE %s', $role));
        }
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
        self::assertTrue($this->engine->beginRebuild($index), 'the lock was released');
        $this->engine->abortRebuild($index);

        $this->connection->execute('DROP FUNCTION fuzzphony_refresh_products__next(bigint[])'); // upgraded, not applied yet
        self::assertFalse($this->engine->beginRebuild($index));
        $check = array_find($this->engine->inspect($index)->checks, static fn(Check $c): bool => $c->name === 'Rebuild refresh function') ?? self::fail('no check');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('"public"."fuzzphony_refresh_products__next"(bigint[]) is missing.', $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $check->fix);
    }

    private function install(string $sync): IndexDefinition
    {
        $index = Indexes::products($sync);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        return $index;
    }

    /** Rebuilds through the SPI in batches of two; $afterFirstBatch runs once, between the first and the second batch. */
    private function rebuild(IndexDefinition $index, ?\Closure $afterFirstBatch = null): void
    {
        self::assertTrue($this->engine->beginRebuild($index));
        $after = null;
        while (($ids = $this->engine->sourceIds($index, $after, 2)) !== []) {
            $this->engine->refreshShadow($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($afterFirstBatch !== null) {
                $afterFirstBatch();
                $afterFirstBatch = null;
            }
        }
        $this->engine->finishRebuild($index);
    }

    /** @return list<array<string, mixed>> every column but indexed_at, as text */
    private function documents(): array
    {
        $columns = array_diff(array_keys((new PostgresSchemaGenerator())->columns(Indexes::products())), ['indexed_at']);

        return $this->connection->fetchAll(sprintf(
            'SELECT %s FROM fuzzphony_products ORDER BY id',
            implode(', ', array_map(static fn(string $c): string => sprintf('%1$s::text AS %1$s', Sql::ident($c)), $columns)),
        ));
    }
}
```

- [ ] **Step 10: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/ShadowSwapTest.php`
Expected: FAIL: `testARoleThatCannotBuildNextToTheLiveIndexGoesInPlace` finds no "Rebuild refresh function" check (every other test passes already, which is fine: they pin Step 7).

- [ ] **Step 11: Add the doctor's function checks**

`PostgresInspector::inspect()`, right after the `'Refresh function'` check:

```php
        $checks[] = $this->function(
            sprintf('%s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
            'Rebuild refresh function',
        );
        $checks[] = $this->function($this->names->trackFunction($index) . '()', 'Rebuild change log function');
```

- [ ] **Step 12: Run the integration tests again**

Run: `vendor/bin/phpunit tests/Integration/ShadowSwapTest.php tests/Integration/Command/DoctorCommandTest.php`
Expected: PASS.

- [ ] **Step 13: Docs**

Create `docs/adr/0008-shadow-rebuild-with-a-change-log.md`:

```markdown
# 8. Zero-downtime reindex: a shadow table caught up from a change log

Status: accepted (0.5)

## Context
A full reindex upserted into the live index table: while it ran, searches saw a mix of old and
new documents, and orphans stayed findable until the final prune. Building a second table and
swapping it in fixes that, but changes made to the source while the second table is built must
reach it too. Catching up by `indexed_at` (the rows the live table refreshed since the build
started) needs a scan of the whole live table (the column has no index), once more under the swap
lock; misses deletions made after the last orphan prune; misses writes of transactions that
started before the threshold (`now()` is the transaction start); and a resumed build would need
its start time stored somewhere.

## Decision
`beginRebuild()` creates `fuzzphony_<index>__next` (the current layout), a change log
`fuzzphony_<index>__changes (id PRIMARY KEY)` and a row trigger on the live table that logs the id
of every document written or deleted there, in the writer's own transaction. The reindex fills the
rebuild through a second static refresh function. `finishRebuild()` builds the rebuild's indexes,
refreshes logged ids into it in batches (each batch taken and refreshed in one statement), then,
in one transaction, takes `ACCESS EXCLUSIVE` on the live table (this waits for every transaction
that wrote it, so the log is complete), refreshes the remaining logged ids, copies grants and owner,
drops the live table and renames the rebuild, its primary key and indexes to the live names. A
session-level advisory lock allows one rebuild per index. A role that cannot do the DDL, and a
reindex with `prune: false`, run in place as before.

## Consequences
+ Searches never see a half-built index; the lock is held for the last few logged ids and the renames.
+ Deletions are caught up like any change; a crashed rebuild keeps logging, so `--from` can resume it.
− A second copy of the index on disk while it runs; a row trigger on the live table during a rebuild.
− The reindexing role needs `CREATE` on Fuzzphony's schema and ownership of the index table, and a
  session connection (the advisory lock).
```

`docs/architecture.md`, "Engine": append to the paragraph: "A full reindex is driven through the engine too (`beginRebuild()`, `refreshShadow()`, `finishRebuild()`, `abortRebuild()`), so `Reindexer` stays engine-agnostic; the PostgreSQL engine builds next to the live table and swaps it in ([ADR 0008](adr/0008-shadow-rebuild-with-a-change-log.md))."

`CHANGELOG.md`, `### Breaking`, add:

```markdown
- `Engine` has four new methods for the zero-downtime reindex: `beginRebuild(IndexDefinition $index,
  bool $resume = false): bool`, `refreshShadow(IndexDefinition $index, array $ids): int`,
  `finishRebuild(IndexDefinition $index): void` and `abortRebuild(IndexDefinition $index, bool
  $keepShadow = false): void`. Custom engines must implement them; an engine that cannot build
  next to the live index returns `false` from `beginRebuild()` (the reindex then runs in place) and
  leaves the others empty.
```

`UPGRADE.md`, `## From 0.4 to 0.5`, append:

```markdown
3. **Custom engines** implement `beginRebuild()`, `refreshShadow()`, `finishRebuild()` and
   `abortRebuild()`. The minimal implementation keeps the 0.4 behaviour:
   `public function beginRebuild(IndexDefinition $index, bool $resume = false): bool { return false; }`
   (the reindex then writes in place) and empty bodies for the other three (`refreshShadow()`
   returns `0`).
```

- [ ] **Step 14: Run the gate**

Run the gate. Expected: all green; no escaped mutant on the changed lines.

- [ ] **Step 15: Commit**

```bash
git add src/Core/Engine/Engine.php src/Engine/Postgres/Schema/Names.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/ShadowRebuild.php src/Engine/Postgres/PostgresEngine.php src/Engine/Postgres/Inspection/PostgresInspector.php tests/Unit/Postgres/RecordingConnection.php tests/Unit/Postgres/ShadowRebuildTest.php tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/PostgresEngineGuardTest.php tests/Unit/PublicApiTest.php tests/Integration/PostgresTestCase.php tests/Integration/DedicatedSchemaTest.php tests/Integration/ShadowSwapTest.php docs/adr/0008-shadow-rebuild-with-a-change-log.md docs/architecture.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Build a full rebuild next to the live index and swap it in

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: Reindexer and command on the shadow build, crash handling, doctor warning (R1)

**Files:**
- Modify: `src/Core/Sync/Reindexer.php` (whole class)
- Modify: `src/Core/Sync/ReindexOptions.php` (`inPlace`, docblock), `src/Core/Sync/ReindexResult.php` (`swapped`)
- Modify: `src/Core/Fuzzphony.php` (`reindex()` docblock)
- Modify: `src/Bundle/Command/ReindexCommand.php` (`--in-place`, messages)
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php` (new `rebuild()` check)
- Test: `tests/Unit/Core/Sync/ReindexerTest.php`, `tests/Unit/Core/Sync/ReindexOptionsTest.php`, `tests/Integration/ReindexPruningTest.php`, `tests/Integration/Command/ReindexCommandTest.php`, `tests/Conformance/EngineConformanceTestCase.php`
- Create: `tests/Integration/ZeroDowntimeReindexTest.php`
- Docs: `docs/sync.md` ("Reindexing and orphan pruning"), `docs/commands.md`, `README.md`, `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: `Engine::beginRebuild()`, `refreshShadow()`, `finishRebuild()`, `abortRebuild()` (Task 3) with exactly the Task 3 signatures; the InvalidArgument message `A rebuild of "<index>" is already running.`
- Produces:
  - `new ReindexOptions(int $batchSize = 5_000, int|string|null $resumeAfter = null, bool $prune = true, bool $pruneEmpty = false, ?\Closure $onBatch = null, bool $inPlace = false)`;
  - `new ReindexResult(int $written, ?int $pruned = null, bool $pruneSkippedEmptySource = false, bool $swapped = false)`;
  - `Reindexer::run(IndexDefinition $index, ReindexOptions $options): ReindexResult` (signature unchanged) — Task 5's `Worker` calls it with `new ReindexOptions(pruneEmpty: true)`;
  - `fuzzphony:reindex --in-place`; doctor check `'Rebuild'`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Core/Sync/ReindexOptionsTest.php`: in `testDefaults()` add `self::assertFalse($options->inPlace);`; in `testResultDefaults()` add `self::assertFalse($result->swapped);`.

`tests/Unit/Core/Sync/ReindexerTest.php`: the existing tests stay (a mocked `beginRebuild()` returns `false`, so they exercise the in-place path). Add:

```php
    public function testAFullRunBuildsNextToTheLiveIndexAndSwapsItIn(): void
    {
        $engine = $this->engine([[1, 2], [3]]);
        $engine->expects(self::once())->method('beginRebuild')->with(self::anything(), false)->willReturn(true);
        $engine->expects(self::exactly(2))->method('refreshShadow')->willReturnOnConsecutiveCalls(2, 1);
        $engine->expects(self::never())->method('refresh');
        $engine->expects(self::never())->method('pruneOrphans');
        $engine->expects(self::once())->method('finishRebuild');
        $engine->expects(self::never())->method('abortRebuild');
        $engine->expects(self::once())->method('recordReindex');
        $progress = [];

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(
            batchSize: 2,
            onBatch: static function (int $processed, int|string $lastId) use (&$progress): void {
                $progress[] = [$processed, $lastId];
            },
        ));

        self::assertSame([[2, 2], [3, 3]], $progress);
        self::assertSame(3, $result->written);
        self::assertTrue($result->swapped);
        self::assertNull($result->pruned, 'the orphans went with the old index');
        self::assertFalse($result->pruneSkippedEmptySource);
    }

    public function testInPlaceOrWithoutPruningNoRebuildIsStarted(): void
    {
        foreach ([new ReindexOptions(inPlace: true), new ReindexOptions(prune: false)] as $options) {
            $engine = $this->engine([[1]]);
            $engine->expects(self::never())->method('beginRebuild');
            $engine->expects(self::once())->method('refresh')->willReturn(1);

            self::assertFalse((new Reindexer($engine))->run(Indexes::products(), $options)->swapped);
        }
    }

    public function testWhenTheEngineCannotBuildNextToTheLiveIndexTheRunGoesInPlace(): void
    {
        $engine = $this->engine([[1]]);
        $engine->expects(self::once())->method('beginRebuild')->willReturn(false);
        $engine->expects(self::once())->method('refresh')->willReturn(1);
        $engine->expects(self::never())->method('refreshShadow');
        $engine->expects(self::once())->method('pruneOrphans')->willReturn(2);

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());

        self::assertFalse($result->swapped);
        self::assertSame(2, $result->pruned);
    }

    public function testAResumedRunContinuesALeftOverRebuildAndSwapsEvenWithNothingLeft(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('beginRebuild')->with(self::anything(), true)->willReturn(true);
        $engine->expects(self::once())->method('sourceIds')->with(self::anything(), 7, 5_000)->willReturn([]);
        $engine->expects(self::once())->method('finishRebuild');
        $engine->expects(self::never())->method('abortRebuild');
        $engine->expects(self::once())->method('recordReindex'); // the rebuild was started by a full run

        self::assertTrue((new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(resumeAfter: 7))->swapped);
    }

    public function testAnEmptySourceDiscardsTheRebuildUnlessPruneEmpty(): void
    {
        $engine = $this->engine([[]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), false);
        $engine->expects(self::never())->method('finishRebuild');
        $engine->expects(self::never())->method('recordReindex');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());

        self::assertFalse($result->swapped);
        self::assertTrue($result->pruneSkippedEmptySource);

        $forced = $this->engine([[]]);
        $forced->method('beginRebuild')->willReturn(true);
        $forced->expects(self::never())->method('abortRebuild');
        $forced->expects(self::once())->method('finishRebuild');
        $forced->expects(self::once())->method('recordReindex');

        self::assertTrue((new Reindexer($forced))->run(Indexes::products(), new ReindexOptions(pruneEmpty: true))->swapped);
    }

    public function testAFailedBuildKeepsTheRebuildForResumingAndRethrows(): void
    {
        $engine = $this->engine([[1, 2]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('refreshShadow')->willReturn(2);
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), true);
        $engine->expects(self::never())->method('finishRebuild');
        $engine->expects(self::never())->method('recordReindex');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('killed');

        (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(batchSize: 2, onBatch: static function (): void {
            throw new \RuntimeException('killed');
        }));
    }

    public function testAFailedSwapAlsoKeepsTheRebuild(): void
    {
        $engine = $this->engine([[1]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('finishRebuild')->willThrowException(new \RuntimeException('deadlock'));
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), true);
        $engine->expects(self::never())->method('recordReindex');

        $this->expectExceptionMessage('deadlock');

        (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());
    }

    public function testAFailureToReleaseTheRebuildDoesNotHideTheFirstError(): void
    {
        $engine = $this->engine([[1]]);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('finishRebuild')->willThrowException(new \RuntimeException('first'));
        $engine->method('abortRebuild')->willThrowException(new \RuntimeException('second'));

        $this->expectExceptionMessage('first');

        (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Core/Sync`
Expected: FAIL (unknown named parameter `inPlace`, no `swapped`, `beginRebuild` never called).

- [ ] **Step 3: Implement options, result and the reindexer**

`ReindexOptions.php`, replace the class docblock and add the parameter (last, so positional callers keep working):

```php
/**
 * How Fuzzphony::reindex() runs. A full run builds the index next to the live one and swaps it in,
 * so searches never see a half-built index; it writes the live index in place instead (as before
 * 0.5) with inPlace, with prune: false, or when the engine or the role cannot build next to it.
 * Pruning is relative to what THIS session sees: where it sees fewer rows than the application
 * (row-level security, a query source using current_setting(), another search_path), pass
 * prune: false. A full run whose source returns no row keeps the live index unless pruneEmpty is
 * set (an empty source is far more likely a visibility problem than intent).
 */
final readonly class ReindexOptions
{
    /** @param (\Closure(int $processed, int|string $lastId): void)|null $onBatch called after every batch */
    public function __construct(
        public int $batchSize = 5_000,
        /** Resume after this source id (printed while running): continues the rebuild a failed run left behind, else writes in place without pruning. */
        public int|string|null $resumeAfter = null,
        public bool $prune = true,
        public bool $pruneEmpty = false,
        public ?\Closure $onBatch = null,
        /** Write the live index directly: no second copy on disk, but searches see a mix of old and new documents while it runs. */
        public bool $inPlace = false,
    ) {
```

`ReindexResult.php`:

```php
/** What a reindex did. */
final readonly class ReindexResult
{
    public function __construct(
        /** Documents written. */
        public int $written,
        /** Orphaned documents removed in place; null when pruning did not run in place (resumed run, prune: false, empty source, or a swap: the orphans went with the old index). */
        public ?int $pruned = null,
        /** True when a full run found no source row and therefore kept the index (see ReindexOptions::$pruneEmpty). */
        public bool $pruneSkippedEmptySource = false,
        /** True when the run was built next to the live index and swapped in. */
        public bool $swapped = false,
    ) {}
}
```

Replace `Reindexer.php`'s class (docblock included):

```php
/**
 * @internal Batched, resumable reindex using keyset pagination over source ids.
 *
 * A full run builds next to the live index through the engine (Engine::beginRebuild()) and swaps
 * the result in; the documents the source no longer returns (orphans) go with the old index. A
 * failed run keeps its rebuild (the engine releases the lock), so a run resumed after the last
 * printed id continues it. The run writes the live index in place instead, as before 0.5, with
 * ReindexOptions::$inPlace, with $prune = false (a swap would drop what this session cannot see),
 * and when the engine says so (a role that cannot build next to the live index, or a resumed run
 * without a rebuild to continue). In place, each batch is an idempotent upsert, a full run finally
 * removes the orphans and a resumed run leaves them alone.
 *
 * Either way, what is dropped is relative to what THIS session sees (see ReindexOptions). A full
 * run that found no source row keeps the live index unless ReindexOptions::$pruneEmpty is set: an
 * empty source is far more likely a visibility problem than intent.
 */
final class Reindexer
{
    public function __construct(private readonly Engine $engine) {}

    public function run(IndexDefinition $index, ReindexOptions $options): ReindexResult
    {
        $resumed = $options->resumeAfter !== null;
        if ($options->inPlace || !$options->prune || !$this->engine->beginRebuild($index, $resumed)) {
            return $this->inPlace($index, $options);
        }
        try {
            [$written, $seen] = $this->batches($index, $options, $this->engine->refreshShadow(...));
            if (!$resumed && $seen === 0 && !$options->pruneEmpty) {
                $this->engine->abortRebuild($index);

                return new ReindexResult($written, pruneSkippedEmptySource: true);
            }
            $this->engine->finishRebuild($index);
        } catch (\Throwable $e) {
            try {
                $this->engine->abortRebuild($index, keepShadow: true);
            } catch (\Throwable) {
                // the first failure is the one to report
            }

            throw $e;
        }
        $this->engine->recordReindex($index);

        return new ReindexResult($written, swapped: true);
    }

    private function inPlace(IndexDefinition $index, ReindexOptions $options): ReindexResult
    {
        [$written, $seen] = $this->batches($index, $options, $this->engine->refresh(...));
        if ($options->resumeAfter !== null || !$options->prune) {
            $result = new ReindexResult($written);
        } elseif ($seen === 0 && !$options->pruneEmpty) {
            $result = new ReindexResult($written, pruneSkippedEmptySource: true);
        } else {
            $result = new ReindexResult($written, $this->engine->pruneOrphans($index, $options->batchSize));
        }
        // a resumed run covers part of the source; an empty one is likely a visibility problem (as for pruning)
        if ($options->resumeAfter === null && ($seen > 0 || $options->pruneEmpty)) {
            $this->engine->recordReindex($index);
        }

        return $result;
    }

    /**
     * @param \Closure(IndexDefinition, list<int|string>): int $refresh
     *
     * @return array{int, int} documents written, source ids seen
     */
    private function batches(IndexDefinition $index, ReindexOptions $options, \Closure $refresh): array
    {
        $written = 0;
        $seen = 0;
        $after = $options->resumeAfter;
        do {
            $ids = $this->engine->sourceIds($index, $after, $options->batchSize);
            if ($ids === []) {
                break;
            }
            $seen += count($ids);
            $written += $refresh($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($options->onBatch !== null) {
                ($options->onBatch)($written, $after);
            }
        } while (count($ids) === $options->batchSize);

        return [$written, $seen];
    }
}
```

`Fuzzphony::reindex()` docblock becomes:

```php
    /**
     * Rebuilds the whole index from the source, next to the live one, and swaps it in when it is
     * complete; the documents the source no longer returns go with the old index. See
     * ReindexOptions for writing in place, resuming and pruning.
     */
```

- [ ] **Step 4: Run the unit tests again**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 5: Write the failing integration tests**

Create `tests/Integration/ZeroDowntimeReindexTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fuzzphony::reindex() builds next to the live index: writes during the build, a crash, resume, a second run. */
final class ZeroDowntimeReindexTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fz_note, fz_hidden, fuzzphony_noted, fuzzphony_noted__next, fuzzphony_noted__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_note (id bigint PRIMARY KEY, product_id bigint NOT NULL, note text NOT NULL)');
        $this->connection->execute('CREATE TABLE fz_hidden (product_id bigint PRIMARY KEY)');
        $this->connection->execute("INSERT INTO fz_note VALUES (1, 1, 'fragile'), (2, 3, 'fragile'), (3, 4, 'refurbished')");
        $this->connection->execute('INSERT INTO fz_hidden VALUES (5)');
    }

    protected function tearDown(): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock_all()');
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'queue sync' => ['queue'];
        yield 'trigger sync' => ['trigger'];
    }

    #[DataProvider('modes')]
    public function testWritesDuringTheBuildEndUpInTheSwappedIndex(string $sync): void
    {
        $index = $this->notedIndex($sync);
        $fuzzphony = $this->install($index);
        $batches = 0;

        $result = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, onBatch: function () use (&$batches, $fuzzphony, $index, $sync): void {
            if ($batches++ > 0) {
                return;
            }
            self::assertCount(4, $fuzzphony->in('noted')->get()->ids(), 'searches read the complete live index');
            $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
            $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
            $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
            $this->connection->execute("INSERT INTO fz_note VALUES (4, 3, 'discontinued')");
            $this->connection->execute('TRUNCATE fz_hidden'); // product 5 becomes visible
            if ($sync === 'queue') {
                $this->engine->processQueue($index, 100);
            }
        }));

        self::assertTrue($result->swapped);
        self::assertNull($result->pruned);
        (new Worker($this->engine))->runOnce([$index]); // whatever is still queued
        $swapped = $this->documents($index);

        self::assertFalse($fuzzphony->reindex('noted', new ReindexOptions(inPlace: true))->swapped);
        self::assertSame($this->documents($index), $swapped, 'the swapped index equals a fresh in-place rebuild');
        self::assertSame(['1', '2', '3', '5', '6'], array_column($swapped, 'id'));
        self::assertSame(0, $this->engine->queueSize($index));
    }

    public function testACrashKeepsTheLiveIndexAndTheDoctorWarnsUntilTheRunIsResumed(): void
    {
        $index = $this->notedIndex('trigger');
        $fuzzphony = $this->install($index);
        $before = $this->documents($index);

        $last = $this->crash($fuzzphony);

        self::assertSame(2, $last);
        self::assertSame($before, $this->documents($index), 'the live index is untouched');
        $check = $this->check($fuzzphony, 'Rebuild');
        self::assertSame(CheckStatus::Warning, $check->status);
        self::assertSame('A rebuild of "noted" did not finish: "public"."fuzzphony_noted__next" is left over, and every change to the index is logged for it. Resume it with --from (the last id it printed), or run a full reindex, which starts over.', $check->message);
        self::assertSame('bin/console fuzzphony:reindex noted', $check->fix);

        $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1"); // id 1 is in the rebuild already: logged
        $resumed = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, resumeAfter: $last));

        self::assertTrue($resumed->swapped);
        self::assertSame(2, $resumed->written, 'only the rest: 3 and 4');
        self::assertSame('silent office mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_noted WHERE id = 1'), 'caught up from the log');
        self::assertCount(4, $fuzzphony->in('noted')->get()->ids());
        self::assertNull(array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === 'Rebuild'));
    }

    public function testAFullRunAfterACrashStartsOver(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony);

        $result = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2));

        self::assertTrue($result->swapped);
        self::assertSame(4, $result->written);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"));
    }

    public function testASecondRunFailsFastWhileOneIsRunning(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony); // leaves a rebuild behind, as a running one would have
        $other = PostgresTestCase::connect();
        $other->fetchValue("SELECT pg_advisory_lock(hashtext('fuzzphony:public.noted'))");
        try {
            try {
                $fuzzphony->reindex('noted');
                self::fail('InvalidArgument expected');
            } catch (InvalidArgument $e) {
                self::assertSame('A rebuild of "noted" is already running.', $e->getMessage());
            }
            $check = $this->check($fuzzphony, 'Rebuild');
            self::assertSame(CheckStatus::Ok, $check->status);
            self::assertSame('a full reindex is building the index next to the live one', $check->message);
        } finally {
            $other->fetchValue('SELECT pg_advisory_unlock_all()');
        }
    }

    /** Fails a full run after its first batch (ids 1 and 2); returns the last id it printed. */
    private function crash(Fuzzphony $fuzzphony): int|string
    {
        $last = null;
        try {
            $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, onBatch: static function (int $done, int|string $lastId) use (&$last): void {
                $last = $lastId;

                throw new \RuntimeException('killed');
            }));
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('killed', $e->getMessage());
        }

        return $last ?? self::fail('no batch ran');
    }

    /** Joined table fz_note (LEFT JOIN) and an anti-join on fz_hidden, both watched. */
    private function notedIndex(string $sync): IndexDefinition
    {
        return IndexDefinition::builder('noted')
            ->fromQuery(<<<'SQL'
                SELECT p.id, p.name, n.note
                FROM fz_product p LEFT JOIN fz_note n ON n.product_id = p.id
                WHERE NOT EXISTS (SELECT 1 FROM fz_hidden h WHERE h.product_id = p.id)
                SQL)
            ->watch('fz_product')
            ->watch('fz_note', 'SELECT :id', 'product_id')
            ->watch('fz_hidden', 'SELECT :id', 'product_id')
            ->field('name', 'A')
            ->field('note', 'B')
            ->sync($sync)
            ->build();
    }

    private function install(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    /** @return list<array<string, mixed>> every column but indexed_at, as text */
    private function documents(IndexDefinition $index): array
    {
        $columns = array_diff(array_keys((new PostgresSchemaGenerator())->columns($index)), ['indexed_at']);

        return $this->connection->fetchAll(sprintf(
            'SELECT %s FROM fuzzphony_noted ORDER BY id',
            implode(', ', array_map(static fn(string $c): string => sprintf('%1$s::text AS %1$s', Sql::ident($c)), $columns)),
        ));
    }

    private function check(Fuzzphony $fuzzphony, string $name): Check
    {
        return array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}
```

Update the existing tests the new default changes:

`tests/Integration/ReindexPruningTest.php`: replace `testPrunesByDefaultAndReportsHowMany()` and the second half of `testASourceWithoutRowsIsNotPrunedUnlessForced()`:

```php
    public function testAFullRunSwapsAndLeavesTheOrphansBehind(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4'); // manual sync: 4 is an orphan

        $result = $this->context->fuzzphony->reindex('products');

        self::assertTrue($result->swapped);
        self::assertNull($result->pruned, 'it went with the old index');
        self::assertSame(4, $this->indexed());
    }

    public function testInPlaceItPrunesAndReportsHowMany(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4');

        $result = $this->context->fuzzphony->reindex('products', new ReindexOptions(inPlace: true));

        self::assertFalse($result->swapped);
        self::assertSame(1, $result->pruned);
        self::assertSame(4, $this->indexed());
    }
```

and in `testASourceWithoutRowsIsNotPrunedUnlessForced()` after the first block add `self::assertFalse($result->swapped); self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"), 'the empty rebuild is discarded');`, and replace the forced block's `self::assertSame(5, $forced->pruned);` with `self::assertTrue($forced->swapped);`.

`tests/Integration/Command/ReindexCommandTest.php`:

```php
    public function testAFullRunSwapsInTheRebuiltIndex(): void
    {
        $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Built next to the live index and swapped in: searches never saw a partial index, and documents the source no longer returns went with the old one.', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }

    public function testInPlaceRemovesOrphansAndSaysHowMany(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--in-place' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('1 orphaned document(s) removed', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }

    public function testARoleThatCannotBuildNextToTheLiveIndexRebuildsInPlaceAndSaysSo(): void
    {
        $connection = $this->context->connection;
        $role = 'fz_reindexer_' . getmypid();
        $connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $connection->execute(sprintf('CREATE ROLE %s', $role));
        try {
            $connection->execute(sprintf('GRANT SELECT ON fz_product, fz_brand TO %s', $role));
            $connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products, fuzzphony_meta TO %s', $role));
            $connection->execute(sprintf('SET ROLE %s', $role));
            try {
                $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);
            } finally {
                $connection->execute('RESET ROLE');
            }
        } finally {
            $connection->execute(sprintf('DROP OWNED BY %s', $role));
            $connection->execute(sprintf('DROP ROLE %s', $role));
        }

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('1 orphaned document(s) removed', $this->tester->getDisplay());
        self::assertStringContainsString("Rebuilt in place: this role cannot build the index next to the live one (it needs CREATE on Fuzzphony's schema and ownership of the index table), or fuzzphony:schema --apply has not run since the upgrade.", $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }
```

Delete the old `testAFullRunRemovesOrphansAndSaysHowMany()`. Add `self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());` to `testAResumedRunLeavesOrphansAlone()`, `testNoPruneKeepsWhatTheSessionCannotSee()` and `testASourceThatReturnsNothingIsNotPrunedWithoutPruneEmpty()`. In `testPruneEmptyWipesTheIndexOfAnEmptySource()` replace `'5 orphaned document(s) removed'` with `'Built next to the live index and swapped in'`.

`tests/Conformance/EngineConformanceTestCase.php`, `testFullReindexPrunesOrphansButAResumedOneDoesNot()`: replace `self::assertSame(1, $full->pruned);` with `self::assertTrue($full->swapped || $full->pruned === 1, 'swapped in without the orphan, or pruned in place');`.

- [ ] **Step 6: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/ZeroDowntimeReindexTest.php tests/Integration/Command/ReindexCommandTest.php`
Expected: FAIL: no "Rebuild" check; `--in-place` is not an option; the swap message is missing.

- [ ] **Step 7: Implement the command and the doctor check**

`ReindexCommand::configure()`, after the `--from` option:

```php
            ->addOption('in-place', null, InputOption::VALUE_NONE, 'Write the live index directly: no second copy on disk, but searches see a mix of old and new documents while it runs')
```

`execute()`: add `$inPlace = $input->getOption('in-place') === true;` next to `$noPrune`, pass `inPlace: $inPlace,` to `new ReindexOptions(...)`, and replace the `match` output with:

```php
            $io->writeln(match (true) {
                $result->swapped => '  Built next to the live index and swapped in: searches never saw a partial index, and documents the source no longer returns went with the old one.',
                $result->pruned !== null => sprintf('  %s orphaned document(s) removed (no longer in the source)', number_format($result->pruned)),
                $result->pruneSkippedEmptySource => '  <comment>The source returned no rows for this session, so nothing was pruned (row-level security or search_path? a TRUNCATE is handled by its trigger). Use --prune-empty to remove every indexed document anyway.</comment>',
                $noPrune => '  <comment>Pruning skipped (--no-prune).</comment>',
                default => '  <comment>Orphaned documents are only removed by a full run (without --from).</comment>',
            });
            if (!$result->swapped && !$inPlace && !$noPrune && !is_string($from) && !$result->pruneSkippedEmptySource) {
                $io->writeln('  <comment>Rebuilt in place: this role cannot build the index next to the live one (it needs CREATE on Fuzzphony\'s schema and ownership of the index table), or fuzzphony:schema --apply has not run since the upgrade.</comment>');
            }
```

`PostgresInspector::inspect()`: in the `else` branch (sidecar exists), after `array_push($checks, ...$this->sidecarIndexes($index));`, add `array_push($checks, ...$this->rebuild($index));`, and add the method:

```php
    /**
     * A rebuild table exists: a full reindex is building next to the live index (it holds the
     * rebuild lock), or one failed and left it behind, and a trigger still logs every change for it.
     *
     * @return list<Check>
     */
    private function rebuild(IndexDefinition $index): array
    {
        $shadow = $this->names->shadow($index);
        if (!$this->regclass($shadow)) {
            return [];
        }
        // a transaction-level probe: released when this transaction ends, never kept
        $free = (bool) $this->connection->transactional(fn(Connection $c): mixed => $c->fetchValue(
            'SELECT pg_try_advisory_xact_lock(hashtext(:key))',
            ['key' => $this->names->rebuildLockKey($index)],
        ));

        return [$free
            ? Check::warning(
                'Rebuild',
                sprintf('A rebuild of "%s" did not finish: %s is left over, and every change to the index is logged for it. Resume it with --from (the last id it printed), or run a full reindex, which starts over.', $index->name, $shadow),
                sprintf('bin/console fuzzphony:reindex %s', $index->name),
            )
            : Check::ok('Rebuild', 'a full reindex is building the index next to the live one')];
    }
```

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: PASS. Every full `reindex()` in the integration suite now swaps; tests that asserted the 0.4 in-place `pruned` count were updated in Step 5. If another test fails on a `pruned` count, it is the same change: assert `swapped` (and the resulting documents) instead, or pass `inPlace: true` where the test is about in-place pruning.

- [ ] **Step 9: Update the docs**

`docs/sync.md`: replace the section `## Reindexing and orphan pruning` (up to `## Messenger (orm mode)`) with:

```markdown
## Reindexing and orphan pruning

`fuzzphony:reindex` rebuilds every document, in batches, with progress, resumable.

A full run builds the new index next to the live one (`fuzzphony_<index>__next`) and swaps it in
at the end: searches read the complete old index until then, never a half-built one. Changes made
meanwhile reach the live index as usual (trigger or queue sync) and are logged; the reindex catches
up with them, then swaps inside one short transaction that holds an `ACCESS EXCLUSIVE` lock on the
live table (writers and searches wait for the last few logged changes and the renames). Documents
the source no longer returns (orphans) go with the old table. The index keeps its grants and owner.

A full run needs room for a second copy of the index while it runs, a role that can create tables
in Fuzzphony's schema and owns the index table (or is a member of its owner), and a session
connection (it holds an advisory lock: not a transaction-pooling PgBouncer). One rebuild per index
runs at a time; a second one fails right away. A role without those rights, or an install where
`fuzzphony:schema --apply` has not run since the upgrade, reindexes in place, and
`fuzzphony:reindex` says so.

`--in-place` (`new ReindexOptions(inPlace: true)`) writes the live index directly, as before 0.5:
no second copy, but searches see a mix of old and new documents while it runs. It finishes by
removing orphans in batches and reports how many.

A run that fails or is killed leaves its rebuild behind, and every change to the live index is
logged for it: `fuzzphony:doctor` warns ("a rebuild of … did not finish"). Resume it with `--from`
(the last id it printed), or run a full reindex, which starts over. `--from` without a leftover
rebuild writes in place and covers only part of the source, so it never removes anything.

What a full run drops is relative to what the reindexing session can see. When that session sees
fewer rows than your application (row-level security on the source, a query source using
`current_setting(...)`, a different `search_path` for the CLI user), the difference disappears
from the index. Pass `--no-prune` (or `new ReindexOptions(prune: false)` to
`$fuzzphony->reindex()`) there; it always writes in place.

A full run whose source returns no row at all keeps the index, and says so
(`ReindexResult::$pruneSkippedEmptySource`). That is far more likely a visibility problem than
intent (a real `TRUNCATE` is handled by its trigger). `--prune-empty`
(`new ReindexOptions(pruneEmpty: true)`) empties the index anyway.

```

`docs/commands.md`: replace the `fuzzphony:reindex` table row with

```markdown
| `fuzzphony:reindex [index] [--batch=5000] [--from=id] [--in-place] [--no-prune] [--prune-empty]` | rebuild next to the live index and swap it in (zero downtime; `--in-place` writes the live index directly), resumable, with progress; see [Reindexing](sync.md#reindexing-and-orphan-pruning) |
```

and add to the doctor list, after the "queue backlog and age" item:

```markdown
- a full reindex that did not finish (its rebuild table is left over; fixed by resuming it with
  `--from` or by a full `fuzzphony:reindex`), or one that is running;
```

`README.md`, Quickstart: replace `bin/console fuzzphony:reindex           # backfill, batched and resumable` with `bin/console fuzzphony:reindex           # built next to the live index, swapped in; resumable`.

`CHANGELOG.md`, `### Breaking`, add:

```markdown
- A full `fuzzphony:reindex` / `Fuzzphony::reindex()` builds the index next to the live one and
  swaps it in (zero-downtime reindex). It needs disk for a second copy of the index while it runs,
  and a role with `CREATE` on Fuzzphony's schema that owns the index table (otherwise it runs in
  place, as before, and the command says so). After a swap `ReindexResult::$pruned` is `null` (the
  orphans went with the old index) and the new `ReindexResult::$swapped` is `true`. A second full
  reindex of the same index while one runs fails with `InvalidArgument`.
```

and to `### Added`:

```markdown
- `ReindexOptions::$inPlace` and `fuzzphony:reindex --in-place` write the live index directly (the
  0.4 behaviour: no second copy on disk).
- Doctor: a "Rebuild" check reports a full reindex that did not finish (with how to resume it), and
  one that is running.
```

`UPGRADE.md`, `## From 0.4 to 0.5`, append:

```markdown
4. **A full reindex builds next to the live index.** `fuzzphony:reindex` and
   `Fuzzphony::reindex()` fill `fuzzphony_<index>__next` and swap it in when it is complete, so
   searches never see a half-built index. Plan for disk space for a second copy of the index
   while it runs. The reindexing role needs `CREATE` on Fuzzphony's schema and must own the index
   table (or be a member of its owner), and the connection must be a session (the run holds an
   advisory lock; not a transaction-pooling PgBouncer). A role without those rights reindexes in
   place, as in 0.4, and the command says so; `--in-place` / `new ReindexOptions(inPlace: true)`
   asks for that explicitly, and `--no-prune` always runs in place. After a swap
   `ReindexResult::$pruned` is `null` (the orphans went with the old index): check
   `ReindexResult::$swapped`. A second full reindex of the same index while one runs throws
   `InvalidArgument`. A run that fails leaves a rebuild behind (the doctor warns): resume it with
   `--from`, or run a full reindex again.
```

- [ ] **Step 10: Run the gate**

Run the gate. Expected: all green; no escaped mutant on the changed lines.

- [ ] **Step 11: Commit**

```bash
git add src/Core/Sync/Reindexer.php src/Core/Sync/ReindexOptions.php src/Core/Sync/ReindexResult.php src/Core/Fuzzphony.php src/Bundle/Command/ReindexCommand.php src/Engine/Postgres/Inspection/PostgresInspector.php tests/Unit/Core/Sync/ReindexerTest.php tests/Unit/Core/Sync/ReindexOptionsTest.php tests/Integration/ZeroDowntimeReindexTest.php tests/Integration/ReindexPruningTest.php tests/Integration/Command/ReindexCommandTest.php tests/Conformance/EngineConformanceTestCase.php docs/sync.md docs/commands.md README.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Reindex next to the live index and swap it in

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: One rebuild job after a `TRUNCATE` in queue mode, and the worker (R2)

**Files:**
- Modify: `src/Core/Engine/Engine.php` (`rebuildRequested()`)
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`truncateBranch()`, new `clearRebuildRequest()`)
- Modify: `src/Engine/Postgres/ShadowRebuild.php` (`begin()` clears the request)
- Modify: `src/Engine/Postgres/PostgresEngine.php` (`processQueue()` skips `'*'`, new `rebuildRequested()`)
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php` (`queue()`)
- Modify: `src/Core/Sync/Worker.php`
- Test: `tests/Unit/Postgres/SchemaGeneratorTest.php`, `tests/Unit/Postgres/ShadowRebuildTest.php`, `tests/Unit/Postgres/PostgresEngineGuardTest.php`, `tests/Unit/Core/Sync/WorkerTest.php`, `tests/Integration/TruncateSyncTest.php`, `tests/Integration/Command/WorkerCommandTest.php`, `tests/Integration/DedicatedSchemaTest.php`
- Docs: `docs/sync.md` ("TRUNCATE"), `docs/limitations.md`, `docs/commands.md`, `README.md`, `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: `Reindexer::run()` and `ReindexOptions(pruneEmpty: true)` (Task 4); `ShadowRebuild::begin()` (Task 3); the queue table `(index_name, doc_id, queued_at)`.
- Produces: `Engine::rebuildRequested(IndexDefinition $index): bool`; the queue row `(index_name, '*')` as the rebuild job; generator `clearRebuildRequest(IndexDefinition): string`; `Worker::runOnce()` counts a rebuild as one item.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Postgres/SchemaGeneratorTest.php`:
- `testTruncatingATableSourceEmptiesTheIndexOnlyWhenTheSourceIsReallyEmpty()`: replace the queue-mode resync fragment `sprintf($resync, "INSERT INTO \"public\".\"fuzzphony_queue\" (index_name, doc_id)\n")` with

```php
            . sprintf($resync, "INSERT INTO \"public\".\"fuzzphony_queue\" (index_name, doc_id) VALUES ('articles', '*')\n            ON CONFLICT (index_name, doc_id) DO NOTHING;"),
```

- replace the queue half of `testTruncatingAnotherWatchedTableResyncsEveryDocument()` with:

```php
        $queue = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();
        self::assertStringContainsString(
            "IF TG_OP = 'TRUNCATE' THEN\n        INSERT INTO \"public\".\"fuzzphony_queue\" (index_name, doc_id) VALUES ('products', '*')\n        ON CONFLICT (index_name, doc_id) DO NOTHING;\n        RETURN NULL;\n    END IF;",
            $queue,
            'one rebuild job instead of every document id',
        );
        self::assertStringNotContainsString("SELECT 'products', t.id::text", $queue);
        self::assertStringNotContainsString('DELETE FROM "public"."fuzzphony_products";', $queue, 'a query source is never emptied wholesale');
```

- add:

```php
    public function testAFullRebuildTakesThePendingRequestWhenAllowedTo(): void
    {
        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_queue\"') IS NOT NULL THEN IF has_table_privilege('\"public\".\"fuzzphony_queue\"', 'SELECT') AND has_table_privilege('\"public\".\"fuzzphony_queue\"', 'DELETE') THEN DELETE FROM \"public\".\"fuzzphony_queue\" WHERE index_name = 'products' AND doc_id = '*'; END IF; END IF; END \$fuzzphony\$",
            (new PostgresSchemaGenerator())->clearRebuildRequest(Indexes::products()),
        );
    }
```

`tests/Unit/Postgres/ShadowRebuildTest.php`: a full run (`$resume` false) now clears the request right after taking the lock. In `testAFullRunTakesTheLockAndStartsTheRebuild()` the expected log becomes

```php
        self::assertSame([
            [self::LOCK, self::KEY],
            [$generator->clearRebuildRequest(Indexes::products()), []],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [$generator->beginRebuild(Indexes::products()), []],
        ], $connection->log, 'the lock is kept for the rest of the run');
```

and in `testWithoutTheRightsOrTheFunctionsTheRunGoesInPlaceAndReleasesTheLock()` (create a `$generator = new PostgresSchemaGenerator();` there and pass it to `ShadowRebuild`):

```php
        self::assertSame([
            [self::LOCK, self::KEY],
            [$generator->clearRebuildRequest(Indexes::products()), []],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [self::UNLOCK, self::KEY],
        ], $connection->log, 'an in-place run covers the request too');
```

`testAResumedRunContinuesALeftOverRebuildOrGoesInPlace()` stays as it is: a resumed run never takes the request (its rebuild may predate the `TRUNCATE`).

`tests/Unit/Postgres/PostgresEngineGuardTest.php`, add to `operations()`:

```php
        yield 'rebuild request' => ['queue processing', 'Run "fuzzphony:schema --apply" and check "fuzzphony:doctor".', $always, static fn(PostgresEngine $e): mixed => $e->rebuildRequested(Indexes::products())];
```

`tests/Unit/Core/Sync/WorkerTest.php` (add `use Fuzzphony\Core\Exception\InvalidArgument;`):

```php
    public function testARequestedRebuildRunsFirstAndCountsAsOneItem(): void
    {
        $index = Indexes::products();
        $calls = [];
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'requested';

            return true;
        });
        $engine->expects(self::once())->method('beginRebuild')->with($index, false)->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'rebuild';

            return true;
        });
        $engine->expects(self::once())->method('sourceIds')->with($index, null, 5_000)->willReturn([]);
        // the TRUNCATE established that the rows are gone: an empty source empties the index (pruneEmpty)
        $engine->expects(self::once())->method('finishRebuild');
        $engine->expects(self::never())->method('abortRebuild');
        $engine->method('processQueue')->willReturnCallback(static function () use (&$calls): int {
            $calls[] = 'queue';

            return 0;
        });

        self::assertSame(1, (new Worker($engine))->runOnce([$index]));
        self::assertSame(['requested', 'rebuild', 'queue'], $calls);
    }

    public function testARebuildAnotherRunHoldsIsLeftForTheNextCycle(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('rebuildRequested')->willReturn(true);
        $engine->method('beginRebuild')->willThrowException(new InvalidArgument('A rebuild of "products" is already running.'));
        $engine->expects(self::exactly(2))->method('processQueue')->willReturnOnConsecutiveCalls(4, 0);

        self::assertSame(4, (new Worker($engine))->runOnce([Indexes::products()]));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/ShadowRebuildTest.php tests/Unit/Postgres/PostgresEngineGuardTest.php tests/Unit/Core/Sync/WorkerTest.php`
Expected: FAIL (`clearRebuildRequest()` / `rebuildRequested()` undefined; the truncate branch still queues every id).

- [ ] **Step 3: Implement**

`Engine.php`, after `queueSize()`:

```php
    /**
     * Whether a full rebuild of the index was requested ("queue" mode: a TRUNCATE that needs a
     * full resync queues one such job instead of every document id). The worker runs it before
     * the queued ids. A full rebuild that starts afterwards (beginRebuild() without $resume, or a
     * full in-place run it falls back to) takes the request.
     */
    public function rebuildRequested(IndexDefinition $index): bool;
```

`PostgresSchemaGenerator::truncateBranch()`: replace the queue arm of `$resync`:

```php
        $resync = $index->sync === SyncMode::Trigger
            ? sprintf('PERFORM %s(ARRAY(%s));', $this->names->refreshFunction($index), $ids)
            : sprintf(
                "INSERT INTO %s (index_name, doc_id) VALUES (%s, '*')
        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                $this->names->queue(),
                Sql::string($index->name),
            );
```

and in its docblock replace "in `queue` mode by queueing all those ids" wording (the second bullet) with: "- Any other watched table (or a source that is not empty): its rows are gone, so the affected documents cannot be told apart. Trigger mode resyncs every document that is indexed or that the source now returns, inside the truncating transaction (expensive on a big index). Queue mode queues one full-rebuild job, the queue row (index, '*'), which the worker runs next to the live index; processQueue() never takes it."

Add after `discardRebuild()`:

```php
    /**
     * Takes a pending full-rebuild request (the "*" queue row, see truncateBranch()): the full
     * rebuild starting now reads the source after that TRUNCATE. A request queued while it runs
     * stays for the next one. Skipped without the queue table, or without the rights to clear it.
     */
    public function clearRebuildRequest(IndexDefinition $index): string
    {
        return sprintf(
            'DO %1$s BEGIN IF to_regclass(%2$s) IS NOT NULL THEN IF has_table_privilege(%2$s, \'SELECT\') AND has_table_privilege(%2$s, \'DELETE\') THEN DELETE FROM %3$s WHERE index_name = %4$s AND doc_id = \'*\'; END IF; END IF; END %1$s',
            self::TAG,
            Sql::string($this->names->queue()),
            $this->names->queue(),
            Sql::string($index->name),
        );
    }
```

`ShadowRebuild::begin()`: at the top of the `try` block add

```php
            if (!$resume) {
                // this full rebuild covers a pending request ("*"), also when it falls back to in place
                $this->connection->execute($this->schema->clearRebuildRequest($index));
            }
```

`PostgresEngine::processQueue()`: in the inner `SELECT index_name, doc_id FROM %1$s` add the condition, so it reads

```sql
                        SELECT index_name, doc_id FROM %1$s
                        WHERE index_name = :index AND doc_id <> '*'
```

(`'*'` is never cast to the id type: it is filtered out before `doc_id::%3$s`.) Add after `queueSize()`:

```php
    public function rebuildRequested(IndexDefinition $index): bool
    {
        return $this->guard('queue processing', fn(): bool => (bool) $this->connection->fetchValue(
            sprintf("SELECT EXISTS (SELECT 1 FROM %s WHERE index_name = :index AND doc_id = '*')", $this->names->queue()),
            ['index' => $index->name],
        ), 'Run "fuzzphony:schema --apply" and check "fuzzphony:doctor".');
    }
```

`PostgresInspector::queue()`: replace from `$row = $this->connection->fetchAll(` to the end of the method with

```php
        $row = $this->connection->fetchAll(
            sprintf("SELECT count(*) AS n, coalesce(bool_or(doc_id = '*'), false) AS rebuild, coalesce(extract(epoch FROM now() - min(queued_at)), 0)::bigint AS age FROM %s WHERE index_name = :index", $this->names->queue()),
            ['index' => $index->name],
        )[0];
        $size = Coerce::int($row['n']);
        $age = Coerce::int($row['age']);
        $waiting = sprintf('%d item(s) waiting%s', $size, (bool) $row['rebuild'] ? ', one of them a full rebuild (queued by a TRUNCATE)' : '');

        return match (true) {
            $size > $options->maxQueueBacklog || ($size > 0 && $age > $options->maxQueueAgeSeconds) => Check::warning(
                'Sync queue',
                sprintf('%s, oldest %ds: is the worker running?', $waiting, $age),
                'bin/console fuzzphony:worker   (or from cron: bin/console fuzzphony:worker --once)',
            ),
            default => Check::ok('Sync queue', $waiting),
        };
```

`Worker.php` (add `use Fuzzphony\Core\Exception\InvalidArgument;`): replace the constructor and `runOnce()`, and add `rebuildIfRequested()`:

```php
    private bool $stop = false;
    private readonly Reindexer $reindexer;

    public function __construct(private readonly Engine $engine)
    {
        $this->reindexer = new Reindexer($engine);
    }

    /**
     * Processes every index until all queues are empty. A full rebuild a TRUNCATE requested runs
     * first and counts as one item.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return int processed items
     */
    public function runOnce(array $indexes, int $batchSize = 500): int
    {
        $total = 0;
        foreach ($indexes as $index) {
            $total += $this->rebuildIfRequested($index);
            while (($processed = $this->engine->processQueue($index, $batchSize)) > 0) {
                $total += $processed;
            }
        }

        return $total;
    }

    /**
     * A TRUNCATE that needs a full resync queued one rebuild. It runs like a full reindex, next to
     * the live index; the TRUNCATE established that the rows are gone, so an empty source empties
     * the index (pruneEmpty). While another run holds the index's rebuild lock the request stays
     * for the next cycle: that run may have read the table before the TRUNCATE.
     */
    private function rebuildIfRequested(IndexDefinition $index): int
    {
        if (!$this->engine->rebuildRequested($index)) {
            return 0;
        }
        try {
            $this->reindexer->run($index, new ReindexOptions(pruneEmpty: true));
        } catch (InvalidArgument) {
            return 0;
        }

        return 1;
    }
```

- [ ] **Step 4: Run the unit tests again**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 5: Update and add the integration tests**

`tests/Integration/TruncateSyncTest.php` (add `use Fuzzphony\Core\Inspection\Check;` and `use Fuzzphony\Core\Sync\ReindexOptions;`):
- `queued()` returns the raw ids: `@return list<string>`, mapping `static fn(array $row): string => Coerce::str($row['doc_id'])`;
- `converge()` returns what the worker processed: `private function converge(IndexDefinition $index): int { return (new Worker($this->engine))->runOnce([$index]); }`;
- `setUp()`'s `DROP TABLE` statement also drops `fuzzphony_items__next, fuzzphony_items__changes, fuzzphony_noted__next, fuzzphony_noted__changes, fuzzphony_inherited__next, fuzzphony_inherited__changes`;
- `testTruncatingAJoinedTableResyncsEveryDocument()`: replace the queue block with

```php
        if ($sync === 'queue') {
            self::assertSame(['*'], $this->queued($index), 'one rebuild job instead of every document id');
            self::assertSame(1, $this->engine->queueSize($index));
            $check = array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === 'Sync queue') ?? self::fail('no queue check');
            self::assertSame('1 item(s) waiting, one of them a full rebuild (queued by a TRUNCATE)', $check->message);
            self::assertEqualsCanonicalizing([1, 3], $search('fragile'), 'nothing changes before the worker runs');
            self::assertSame(1, $this->converge($index));
            self::assertFalse($this->engine->rebuildRequested($index));
        }
```

- `testTruncateAlsoPicksUpDocumentsTheSourceOnlyReturnsNow()`: `[1, 2, 3, 4, 5]` becomes `['*']` (with `assertSame`);
- `testInsertUpdateAndDeleteStillSync()`: `[1, 2, 4]` becomes `['1', '2', '4']`;
- `testTruncateOnlyOnAnInheritanceParentKeepsTheChildDocuments()`: `[1, 2, 3]` becomes `['*']` (`assertSame`, message `'resynced, not wiped: one rebuild job'`);
- `testTruncatingASecondWatchedTableOfATableSourceResyncsInsteadOfWiping()`: `[1, 2, 3, 4, 5]` becomes `['*']` (`assertSame`, message `'one rebuild job, nothing deleted'`);
- add:

```php
    public function testIdsQueuedBeforeTheJobAreStillProcessed(): void
    {
        $index = $this->notedIndex('queue', TriggerLevel::Statement);
        $fuzzphony = $this->install($index);
        $search = static fn(string $text): array => $fuzzphony->in('noted')->query($text)->thresholds(['fuzzy_mode' => 'never'])->get()->ids();

        $this->connection->execute("UPDATE fz_note SET note = 'refurbished' WHERE id = 1"); // queues product 1
        $this->connection->execute('TRUNCATE fz_hidden'); // one job
        self::assertEqualsCanonicalizing(['1', '*'], $this->queued($index));

        self::assertSame(2, $this->converge($index), 'the rebuild, then id 1');
        self::assertSame(0, $this->engine->queueSize($index));
        self::assertEqualsCanonicalizing([1, 4], $search('refurbished'));
        self::assertSame([5], $search('torch'));
    }

    public function testATruncateDuringARebuildQueuesAnotherOne(): void
    {
        $index = $this->notedIndex('queue', TriggerLevel::Statement);
        $fuzzphony = $this->install($index);

        $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, onBatch: function (): void {
            $this->connection->execute('TRUNCATE fz_hidden');
        }));

        self::assertTrue($this->engine->rebuildRequested($index), 'the running rebuild may have read the table before the TRUNCATE: the job stays');
        self::assertSame(1, $this->converge($index));
        self::assertFalse($this->engine->rebuildRequested($index));
        self::assertSame([5], $fuzzphony->in('noted')->query('torch')->get()->ids());
    }
```

`tests/Integration/Command/WorkerCommandTest.php` (add `use Fuzzphony\Core\Support\Coerce;`):

```php
    public function testOnceRunsTheRebuildATruncateQueued(): void
    {
        $fuzzphony = $this->queueModeFuzzphony();
        $connection = $this->context->connection;
        $connection->execute('TRUNCATE fz_brand CASCADE'); // fz_product goes too; both are watched: still one job

        self::assertSame(['*'], array_map(Coerce::str(...), array_column($connection->fetchAll("SELECT doc_id FROM fuzzphony_queue WHERE index_name = 'products'"), 'doc_id')));
        $tester = new CommandTester(new WorkerCommand($fuzzphony));
        $status = $tester->execute(['--once' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Processed 1 queued item(s).', $tester->getDisplay());
        self::assertSame(0, Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_products')), 'the source is empty, and so is the index');
        self::assertSame(0, Coerce::int($connection->fetchValue("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'products'")));
    }
```

`tests/Integration/ZeroDowntimeReindexTest.php` needs no change: in queue mode its `TRUNCATE fz_hidden` during the build now queues the job, `processQueue()` skips it, and the `Worker::runOnce()` after the swap runs it.

`tests/Integration/DedicatedSchemaTest.php`, `testQueueAndTruncateSyncWorkFromASessionThatSeesNeitherSchema()`: the `TRUNCATE public.fz_product` now queues a rebuild job, and a rebuild reads the source through `sourceIds()` in the worker's session, like `fuzzphony:reindex` (0.4 already required the source tables on the caller's `search_path` for `sourceIds()`). Replace the last three lines of the test with:

```php
        $this->connection->execute('TRUNCATE public.fz_product');
        // a rebuild job reads the source like a reindex: the source tables must be visible, Fuzzphony's schema need not be
        $this->connection->execute('SET search_path TO public');
        self::assertSame(1, (new Worker($engine))->runOnce([$index]));
        self::assertSame(0, $fuzzphony->in('products')->get()->total);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('public.fuzzphony_products')"), 'the rebuild was built in the configured schema, not on the search_path');
```

- [ ] **Step 6: Run the integration tests**

Run: `vendor/bin/phpunit tests/Integration/TruncateSyncTest.php tests/Integration/Command/WorkerCommandTest.php tests/Integration/ZeroDowntimeReindexTest.php tests/Integration/ReindexPruningTest.php tests/Integration/Command/ReindexCommandTest.php tests/Integration/DedicatedSchemaTest.php`
Expected: PASS. (`ReindexCommandTest::testARoleThatCannotBuildNextToTheLiveIndexRebuildsInPlaceAndSaysSo` keeps passing because `clearRebuildRequest()` skips a role without rights on the queue.)

- [ ] **Step 7: Update the docs**

`docs/sync.md`: replace the section `## TRUNCATE` (up to `## Reindexing and orphan pruning`) with:

```markdown
## TRUNCATE

In `trigger` and `queue` mode a `TRUNCATE` is followed by a separate statement-level
`AFTER TRUNCATE` trigger:

- Truncating a table-sourced index's own table empties the index right away when the source is
  then really empty, and drops its queued ids. A `TRUNCATE` never waits for the queue rows a
  running worker holds.
- Truncating any other watched table (a joined table, a query source's tables), or a source that
  still returns rows afterwards (`TRUNCATE ONLY` on a table-inheritance parent), needs a full
  resync: the removed rows can no longer tell which documents they belonged to.
  - In `queue` mode the trigger queues one full-rebuild job, so the `TRUNCATE` itself stays cheap.
    The worker runs it before the queued ids, as a zero-downtime rebuild (see
    [Reindexing](#reindexing-and-orphan-pruning)); a worker role that cannot build next to the live
    index runs it in place. Like `fuzzphony:reindex`, the rebuild reads the source in the worker's
    session, so that session must see the source tables on its `search_path`. The doctor's queue
    check names a pending job.
  - In `trigger` mode there is no worker to hand the job to: every indexed document, plus every
    document the source now returns, is resynced inside the truncating transaction. On a
    1M-document index that took 42.8 s, holding an `ACCESS EXCLUSIVE` lock on the truncated table
    and row locks on the index the whole time. Prefer `queue` mode for large indexes that watch
    joined tables, or truncate in a maintenance window.

`orm` and `manual` mode never see a `TRUNCATE`: run `fuzzphony:reindex`. Indexes set up with an
older version get the `TRUNCATE` trigger from `fuzzphony:schema --apply`
(`fuzzphony:doctor` reports it missing until then).

```

`docs/limitations.md`: delete the section `## TRUNCATE on a watched table` entirely.

`README.md`, "Known limitations": delete the bullet that starts "- [`TRUNCATE` on a joined table](…#truncate-on-a-watched-table)" (three lines).

`docs/commands.md`: the `fuzzphony:worker` row's purpose becomes `drain the sync queue, including the full rebuilds a \`TRUNCATE\` queued; graceful on SIGTERM`.

`CHANGELOG.md`, `### Breaking`, add:

```markdown
- `Engine` has a new method `rebuildRequested(IndexDefinition $index): bool` (the worker asks
  whether a `TRUNCATE` queued a full rebuild). Custom engines must implement it (`return false;`
  when their queue has no such job).
- In `queue` mode a `TRUNCATE` that needs a full resync queues one job, the queue row
  `(index_name, '*')`, instead of every document id, and the worker runs it as a full rebuild.
  Code that reads `fuzzphony_queue` directly must skip that row. The worker role builds next to the
  live index when it may (see the reindex entry above), else in place.
```

`UPGRADE.md`, `## From 0.4 to 0.5`, append:

```markdown
5. **`TRUNCATE` in queue mode.** A `TRUNCATE` of a joined table (or of a query source's table)
   queues one full-rebuild job, the row `(index_name, '*')` in `fuzzphony_queue`, instead of every
   document id; the worker runs it before the queued ids. If you read the queue yourself, skip that
   row. For a zero-downtime rebuild the worker's role needs the reindex rights of step 4; without
   them it rebuilds in place. Either way the rebuild reads the source in the worker's session, like
   `fuzzphony:reindex`: its `search_path` must see the source tables. Custom engines implement
   `rebuildRequested()` (`return false;` keeps the old behaviour).
```

- [ ] **Step 8: Run the gate**

Run the gate. Expected: all green; no escaped mutant on the changed lines.

- [ ] **Step 9: Commit**

```bash
git add src/Core/Engine/Engine.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/ShadowRebuild.php src/Engine/Postgres/PostgresEngine.php src/Engine/Postgres/Inspection/PostgresInspector.php src/Core/Sync/Worker.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Postgres/ShadowRebuildTest.php tests/Unit/Postgres/PostgresEngineGuardTest.php tests/Unit/Core/Sync/WorkerTest.php tests/Integration/TruncateSyncTest.php tests/Integration/Command/WorkerCommandTest.php tests/Integration/DedicatedSchemaTest.php docs/sync.md docs/limitations.md docs/commands.md README.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Queue one rebuild job for a TRUNCATE instead of every id

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 6: Partition-aware sync and its doctor checks (R6)

**Files:**
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`index()` watch loop, `drop()`, new `partitionTriggers()`)
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php` (`triggers()`, new `partitions()`)
- Test: `tests/Unit/Postgres/SchemaGeneratorTest.php`
- Create: `tests/Integration/PartitionSyncTest.php`
- Docs: `docs/sync.md`, `docs/limitations.md`, `docs/commands.md`, `README.md`, `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: `Names::triggerName($index, $watch, '_trn')`, `Names::syncFunction()`; the `'*'` job and `Worker` (Task 5) for queue mode.
- Produces: an index plan statement per watch described `'TRUNCATE sync on every partition of <table>'` (trigger modes) or `'No TRUNCATE sync on the partitions of <table>'` (other modes, and `drop()`); doctor checks named `'Partitions of <table>'`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Postgres/SchemaGeneratorTest.php`, add:

```php
    public function testTheTruncateTriggerGoesOnEveryPartitionWhenTheSchemaIsApplied(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->statements;
        $partitions = array_values(array_filter($statements, static fn(Statement $s): bool => $s->description === 'TRUNCATE sync on every partition of fz_brand'));

        self::assertCount(1, $partitions);
        self::assertSame(<<<'SQL'
            DO $fuzzphony$
            DECLARE
                r record;
            BEGIN
                FOR r IN SELECT t.relid::regclass::text AS part FROM pg_partition_tree(to_regclass('"fz_brand"')) AS t WHERE t.level > 0 LOOP
                    EXECUTE format('CREATE OR REPLACE TRIGGER %I AFTER TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %s()', 'fuzzphony_sync_products__fz_brand_trn', r.part, '"public"."fuzzphony_sync_products__fz_brand"');
                END LOOP;
            END
            $fuzzphony$
            SQL, $partitions[0]->sql);
    }

    public function testWithoutTriggersAndOnDropThePartitionTriggersGo(): void
    {
        $drop = "EXECUTE format('DROP TRIGGER IF EXISTS %I ON %s', 'fuzzphony_sync_products__fz_brand_trn', r.part);";

        $manual = (new PostgresSchemaGenerator())->index(Indexes::products('manual'));
        self::assertContains('No TRUNCATE sync on the partitions of fz_brand', array_map(static fn(Statement $s): string => $s->description, $manual->statements));
        self::assertStringContainsString($drop, $manual->toSql());
        self::assertStringNotContainsString('AFTER TRUNCATE ON %s', $manual->toSql());

        $sql = (new PostgresSchemaGenerator())->drop(Indexes::products())->toSql();
        $dropAt = strpos($sql, $drop);
        $functionAt = strpos($sql, 'DROP FUNCTION IF EXISTS "public"."fuzzphony_sync_products__fz_brand"()');
        self::assertIsInt($dropAt);
        self::assertIsInt($functionAt);
        self::assertLessThan($functionAt, $dropAt, 'before the function the triggers depend on');
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: FAIL (no partition statement).

- [ ] **Step 3: Implement the generator part**

`PostgresSchemaGenerator::index()`, watch loop: replace

```php
            if (!$index->sync->usesTriggers()) {
                continue;
            }
```

with

```php
            if (!$index->sync->usesTriggers()) {
                $statements[] = $this->partitionTriggers($index, $watch, false);

                continue;
            }
```

and after the `foreach ($this->triggerDefinitions($index, $watch) …)` loop (still inside the watch loop) add `$statements[] = $this->partitionTriggers($index, $watch, true);`.

`drop()`: inside the watch loop, between the `DROP TRIGGER` loop and the `DROP FUNCTION … sync` statement, add `$statements[] = $this->partitionTriggers($index, $watch, false);`.

Add the method (after `obsoleteTriggerNames()`):

```php
    /**
     * The TRUNCATE trigger on every partition of a partitioned watched table, at every level:
     * truncating one partition fires only that partition's triggers. pg_partition_tree() lists the
     * partitions when the plan runs (so the plan stays database-free) and nothing for a table that
     * is not partitioned. The trigger name is the parent's: trigger names are per table. Row-level
     * triggers are cloned to partitions by PostgreSQL; statement-level ones cannot go on them.
     * Without $create (a sync mode without triggers, drop()) the same loop removes them.
     */
    private function partitionTriggers(IndexDefinition $index, Watch $watch, bool $create): Statement
    {
        $trigger = Sql::string($this->names->triggerName($index, $watch, '_trn'));
        $action = $create
            ? sprintf("format('CREATE OR REPLACE TRIGGER %%I AFTER TRUNCATE ON %%s FOR EACH STATEMENT EXECUTE FUNCTION %%s()', %s, r.part, %s)", $trigger, Sql::string($this->names->syncFunction($index, $watch)))
            : sprintf("format('DROP TRIGGER IF EXISTS %%I ON %%s', %s, r.part)", $trigger);

        return new Statement(sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                BEGIN
                    FOR r IN SELECT t.relid::regclass::text AS part FROM pg_partition_tree(to_regclass(%2$s)) AS t WHERE t.level > 0 LOOP
                        EXECUTE %3$s;
                    END LOOP;
                END
                %1$s
                SQL,
            self::TAG,
            Sql::string(Sql::ident($watch->table)),
            $action,
        ), sprintf($create ? 'TRUNCATE sync on every partition of %s' : 'No TRUNCATE sync on the partitions of %s', $watch->table));
    }
```

- [ ] **Step 4: Run the unit tests again**

Run: `vendor/bin/phpunit tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing integration tests**

Create `tests/Integration/PartitionSyncTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A partitioned watched table, two levels deep: truncating one partition is followed. */
final class PartitionSyncTest extends TestCase
{
    private const string TRIGGER = 'fuzzphony_sync_parts__fz_part_trn';

    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows()); // resets queue and meta
        $this->connection->execute('DROP TABLE IF EXISTS fz_part, fuzzphony_parts, fuzzphony_parts__next, fuzzphony_parts__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_part (id bigint PRIMARY KEY, name text NOT NULL) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE fz_part_a PARTITION OF fz_part FOR VALUES FROM (1) TO (100) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE fz_part_a1 PARTITION OF fz_part_a FOR VALUES FROM (1) TO (50)');
        $this->connection->execute('CREATE TABLE fz_part_a2 PARTITION OF fz_part_a FOR VALUES FROM (50) TO (100)');
        $this->connection->execute('CREATE TABLE fz_part_b PARTITION OF fz_part FOR VALUES FROM (100) TO (200)');
        $this->connection->execute("INSERT INTO fz_part VALUES (1, 'alpha lamp'), (60, 'beta lamp'), (150, 'gamma lamp')");
    }

    protected function tearDown(): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock_all()');
    }

    /** @return iterable<string, array{string, TriggerLevel}> */
    public static function modes(): iterable
    {
        foreach (['queue', 'trigger'] as $sync) {
            foreach (TriggerLevel::cases() as $level) {
                yield sprintf('%s sync, %s level', $sync, $level->value) => [$sync, $level];
            }
        }
    }

    #[DataProvider('modes')]
    public function testTruncatingOneLeafPartitionIsFollowed(string $sync, TriggerLevel $level): void
    {
        [$index, $fuzzphony] = $this->install($sync, $level);
        self::assertEqualsCanonicalizing([1, 60, 150], $this->lamps($fuzzphony));

        $this->connection->execute('TRUNCATE fz_part_a1');

        if ($sync === 'queue') {
            self::assertTrue($this->engine->rebuildRequested($index), 'one rebuild job');
            (new Worker($this->engine))->runOnce([$index]);
        }
        self::assertEqualsCanonicalizing([60, 150], $this->lamps($fuzzphony));
    }

    public function testEveryPartitionAtEveryLevelCarriesTheTrigger(): void
    {
        [, $fuzzphony] = $this->install('queue', TriggerLevel::Row);

        self::assertSame(['fz_part', 'fz_part_a', 'fz_part_a1', 'fz_part_a2', 'fz_part_b'], $this->carriers());
        $check = $this->check($fuzzphony, 'Partitions of fz_part');
        self::assertSame(CheckStatus::Ok, $check->status);
        self::assertSame('4 partition(s), each with the TRUNCATE trigger', $check->message);
    }

    public function testAPartitionAttachedAfterTheApplyIsReportedAndFixedByApply(): void
    {
        [, $fuzzphony] = $this->install('queue', TriggerLevel::Row);
        $this->connection->execute('CREATE TABLE fz_part_c PARTITION OF fz_part FOR VALUES FROM (200) TO (300)');

        $check = $this->check($fuzzphony, 'Partitions of fz_part');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame(self::TRIGGER . ' missing on fz_part_c: a TRUNCATE of such a partition leaves stale documents in the index.', $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $check->fix);

        $fuzzphony->schema()->apply($this->connection);

        self::assertSame('5 partition(s), each with the TRUNCATE trigger', $this->check($fuzzphony, 'Partitions of fz_part')->message);
    }

    public function testStatementLevelSyncWarnsAboutWritesThatTargetAPartition(): void
    {
        [, $statement] = $this->install('queue', TriggerLevel::Statement);
        $warning = array_values(array_filter($statement->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part' && $c->status === CheckStatus::Warning));
        self::assertCount(1, $warning);
        self::assertSame('Writes that target a partition directly are not synced (statement-level triggers cannot go on partitions); use trigger_level: row, or write through the parent.', $warning[0]->message);

        [, $row] = $this->install('queue', TriggerLevel::Row);
        self::assertSame([], array_values(array_filter($row->inspect('parts')->checks, static fn(Check $c): bool => $c->status === CheckStatus::Warning && $c->name === 'Partitions of fz_part')));
    }

    public function testAModeWithoutTriggersAndDropRemoveThePartitionTriggers(): void
    {
        $this->install('queue', TriggerLevel::Row);
        [, $manual] = $this->install('manual', TriggerLevel::Row);
        self::assertSame([], $this->carriers());
        self::assertSame([], array_values(array_filter($manual->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part')), 'no triggers expected, none checked');

        [$index] = $this->install('trigger', TriggerLevel::Row);
        self::assertCount(5, $this->carriers());
        $this->engine->dropSchema($index)->apply($this->connection);
        self::assertSame([], $this->carriers());
    }

    /** @return array{IndexDefinition, Fuzzphony} */
    private function install(string $sync, TriggerLevel $level): array
    {
        $index = IndexDefinition::builder('parts')->fromTable('fz_part')->field('name', 'A')->sync($sync)->triggerLevel($level)->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('parts');

        return [$index, $fuzzphony];
    }

    /** @return list<int|string> */
    private function lamps(Fuzzphony $fuzzphony): array
    {
        return $fuzzphony->in('parts')->query('lamp')->thresholds(['fuzzy_mode' => 'never'])->get()->ids();
    }

    /** @return list<string> the tables with the TRUNCATE trigger */
    private function carriers(): array
    {
        return array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            'SELECT c.relname FROM pg_trigger AS g JOIN pg_class AS c ON c.oid = g.tgrelid WHERE g.tgname = :trigger ORDER BY c.relname COLLATE "C"',
            ['trigger' => self::TRIGGER],
        ), 'relname'));
    }

    private function check(Fuzzphony $fuzzphony, string $name): Check
    {
        return array_find($fuzzphony->inspect('parts')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}
```

- [ ] **Step 6: Run them**

Run: `vendor/bin/phpunit tests/Integration/PartitionSyncTest.php`
Expected: FAIL: `testTruncatingOneLeafPartitionIsFollowed` passes already (the generator of Step 3 installs the triggers), the doctor tests fail (no "Partitions of fz_part" check).

- [ ] **Step 7: Implement the doctor checks**

`PostgresInspector.php`: add `use Fuzzphony\Core\Definition\TriggerLevel;` and `use Fuzzphony\Core\Definition\Watch;`. In `triggers()`, at the end of the watch loop body (after the `$leftover` block), add:

```php
            if ($index->sync->usesTriggers()) {
                array_push($checks, ...$this->partitions($index, $watch));
            }
```

Add the method:

```php
    /**
     * A partitioned watched table: every partition, at every level, needs the TRUNCATE trigger (a
     * partition attached after the last apply lacks it). Statement-level triggers cannot go on
     * partitions, so at that level a write that targets a partition directly is not synced.
     *
     * @return list<Check>
     */
    private function partitions(IndexDefinition $index, Watch $watch): array
    {
        $trigger = $this->names->triggerName($index, $watch, '_trn');
        $rows = $this->connection->fetchAll(
            'SELECT t.relid::regclass::text AS part, EXISTS (SELECT 1 FROM pg_trigger AS g WHERE g.tgrelid = t.relid AND g.tgname = :trigger) AS synced FROM pg_partition_tree(to_regclass(:table)) AS t WHERE t.level > 0 ORDER BY 1',
            ['trigger' => $trigger, 'table' => $watch->table],
        );
        if ($rows === []) {
            return [];
        }
        $label = 'Partitions of ' . $watch->table;
        $missing = [];
        foreach ($rows as $row) {
            if (!(bool) $row['synced']) {
                $missing[] = Coerce::str($row['part']);
            }
        }
        $checks = [$missing === []
            ? Check::ok($label, sprintf('%d partition(s), each with the TRUNCATE trigger', count($rows)))
            : Check::error($label, sprintf('%s missing on %s: a TRUNCATE of such a partition leaves stale documents in the index.', $trigger, implode(', ', $missing)), self::APPLY)];
        if ($index->triggerLevel === TriggerLevel::Statement) {
            $checks[] = Check::warning($label, 'Writes that target a partition directly are not synced (statement-level triggers cannot go on partitions); use trigger_level: row, or write through the parent.');
        }

        return $checks;
    }
```

- [ ] **Step 8: Run the integration tests again**

Run: `vendor/bin/phpunit tests/Integration/PartitionSyncTest.php tests/Integration/TruncateSyncTest.php tests/Integration/Command/DoctorCommandTest.php`
Expected: PASS.

- [ ] **Step 9: Update the docs**

`docs/sync.md`, "Statement-level triggers": replace its last paragraph ("Statement-level triggers cannot be attached to individual partitions: …") with `Statement-level triggers cannot be attached to individual partitions (a PostgreSQL rule): see [Partitioned tables](#partitioned-tables).` Then add, right before `## Reindexing and orphan pruning`:

```markdown
## Partitioned tables

Watch the partitioned parent. Writes through the parent are synced at both trigger levels.
PostgreSQL clones row-level triggers to every partition, so with `trigger_level: row` a write that
targets a partition directly (`INSERT INTO product_2024 …`) is synced too. Statement-level triggers
cannot go on partitions, so with the default `trigger_level: statement` such a write is not synced
(the doctor warns): write through the parent, or use `trigger_level: row`.

`fuzzphony:schema --apply` puts the `TRUNCATE` trigger on every partition, at every level, so
truncating a single partition (`TRUNCATE product_2024`) is followed like any `TRUNCATE` of a
watched table (see [TRUNCATE](#truncate)). A partition attached after the last apply has no such
trigger yet: the doctor lists it, and `fuzzphony:schema --apply` adds it. Truncating the parent
fires the trigger of the parent and of every partition: still one rebuild job in `queue` mode, but
one resync per partition in `trigger` mode.

```

`docs/limitations.md`: delete the section `## Partitioned tables`, and replace the intro sentence "Each of these has a planned fix on the [roadmap](roadmap.md), except the partition trigger rule, which PostgreSQL imposes." with "Each of these has a planned fix on the [roadmap](roadmap.md)."

`README.md`, "Known limitations": delete the bullet that starts "- [Partitions](…#partitioned-tables)" (three lines).

`docs/commands.md`, doctor list, after the item about missing or disabled triggers:

```markdown
- partitioned watched tables: every partition carries the `TRUNCATE` trigger (one attached after
  the last apply does not), and a warning at `trigger_level: statement`, where a write that targets
  a partition directly is not synced;
```

`CHANGELOG.md`, `### Added`:

```markdown
- Partition-aware sync: `fuzzphony:schema --apply` puts the `TRUNCATE` trigger on every partition
  of a partitioned watched table, at every level, so truncating one partition is followed. The
  doctor lists partitions without it (one attached after the last apply) and warns at
  `trigger_level: statement`, where writes that target a partition directly are not synced.
```

`UPGRADE.md`, `## From 0.4 to 0.5`, append:

```markdown
6. **Partitioned tables.** The apply of step 1 puts the `TRUNCATE` trigger on every partition of a
   watched partitioned table. Run `fuzzphony:schema --apply` again after attaching a partition
   (the doctor lists the ones without the trigger).
```

- [ ] **Step 10: Run the gate**

Run the gate. Expected: all green; no escaped mutant on the changed lines.

- [ ] **Step 11: Commit**

```bash
git add src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/Inspection/PostgresInspector.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Integration/PartitionSyncTest.php docs/sync.md docs/limitations.md docs/commands.md README.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Follow a TRUNCATE of a single partition

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 7: Demo, documentation pass, final verification

**Files:**
- Modify: `demo/src/Controller/CompareController.php` (`EXAMPLES`)
- Modify: `demo/README.md`
- Modify: `docs/roadmap.md` (v0.5 section), `README.md` (Roadmap bullet), `CHANGELOG.md` (final read), `UPGRADE.md` (final read)
- Test: none new (`PublicApiTest::testTheDemoAndTheBenchmarkUseOnlyThePublicApi` covers the demo's imports)

**Interfaces:**
- Consumes: everything above; no code interface is produced.

- [ ] **Step 1: A field-scoped example in the demo**

`demo/src/Controller/CompareController.php`, `EXAMPLES`: after `'field' => 'category:kitchen kettle',` add

```php
        'exact field' => 'brand:sony headphones',
```

(The Benchmark page lists `CompareController::EXAMPLES` too, so it gains a row; nothing else reads the keys.)

- [ ] **Step 2: The demo README**

`demo/README.md`:
- in the pages table, the "ILIKE vs Fuzzphony" row: replace "one-click accent / typo / stemming / phrase / field / prefix examples" with "one-click accent / typo / stemming / phrase / field / exact field (`brand:sony headphones` searches the brand only, typos included) / prefix examples";
- after the paragraph that starts "Try the sync:", add:

```markdown
Try a zero-downtime rebuild: `DEMO_REINDEX=always docker compose run --rm init` rebuilds every
index next to the live one while the site keeps answering from the old index, and swaps each one in
when it is complete (`init` connects as the owner role, which may build next to the live index).
The `worker` connects as `fuzzphony_app`, which has no DDL rights: a rebuild job that a `TRUNCATE`
queues runs in place there.
```

- in "Reset and reseed", replace "To rebuild only the index: `DEMO_REINDEX=always docker compose run --rm init`." with "To rebuild only the index: `DEMO_REINDEX=always docker compose run --rm init` (zero downtime: the site keeps answering from the old index until the swap)."

- [ ] **Step 3: Roadmap and README**

`docs/roadmap.md`, `## v0.5: Index lifecycle`: replace the intro paragraph ("Reindexing and sync, built on the 0.4 schema. …") with

```markdown
Reindexing and sync, built on the 0.4 schema. Implemented, not released yet: see the
[CHANGELOG](../CHANGELOG.md#unreleased) and [UPGRADE.md](../UPGRADE.md#from-04-to-05). The sidecar
layout is 2, and `fuzzphony:schema --apply` upgrades an older one.
```

and the "Zero-downtime reindex" subsection's text with

```markdown
A full rebuild is built in a shadow table, caught up with the changes made meanwhile and swapped in
atomically, so it never serves partial results ([ADR 0008](adr/0008-shadow-rebuild-with-a-change-log.md)).
A `TRUNCATE` on a watched table queues one full-rebuild job instead of every document id.
```

`README.md`, "Roadmap": the v0.5 bullet becomes `- v0.5 Index lifecycle (implemented, unreleased): zero-downtime reindex, exact field scoping, partition-aware sync.`

- [ ] **Step 4: Dead links and leftovers**

Run:

```bash
git grep -n -e "field-scoping-works-per-weight-group" -e "field-scoping-is-exact-only" -e "limitations.md#truncate-on-a-watched-table" -e "limitations.md#partitioned-tables" -e "per weight group" -- README.md docs demo/README.md ':!docs/superpowers'
```

Expected: no output. Fix every hit by pointing it at the new section (`sync.md#truncate`, `sync.md#partitioned-tables`, `searching.md#query-syntax`) or deleting the stale sentence.

Then read `CHANGELOG.md` `## [Unreleased]` and `UPGRADE.md` `## From 0.4 to 0.5` top to bottom: the Breaking list covers layout 2, exact field scoping, the four rebuild SPI methods, the reindex default (disk, rights, `pruned`/`swapped`, `InvalidArgument` on a second run), `rebuildRequested()` and the `'*'` queue row; UPGRADE has items 1 to 6 in that order, numbered consecutively. Fix gaps in place.

- [ ] **Step 5: Final verification**

Run the gate once more on the whole branch, plus:

```bash
composer qa
git diff main --stat
```

Expected: the gate is green; coverage `Lines: 100.00%`; the Infection diff run reports no escaped mutant on changed lines; `git diff main --stat` lists only files named in this plan.

- [ ] **Step 6: Commit**

```bash
git add demo/src/Controller/CompareController.php demo/README.md docs/roadmap.md README.md CHANGELOG.md UPGRADE.md
git commit -F- <<'EOF'
Demo and docs for the index lifecycle milestone

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

(Add any file Step 4 changed to the `git add` line.)

---

## Self-review

Checked against the spec after writing (re-run if the spec changes):

1. **Spec coverage.**
   - R1: shadow table and second refresh function (Task 3); catch-up, `ACCESS EXCLUSIVE` swap, renames of table / primary key / indexes, plan-cache behaviour (Task 3, `testTheLiveRefreshFunctionWritesTheNewTableAfterTheSwap`); advisory lock and fail-fast `InvalidArgument` (Task 3 engine, Task 4 end to end); `ReindexOptions::$inPlace`, `--in-place`, `ReindexResult::$swapped` (Task 4); resume continues a shadow, else in place (Task 3 `begin()`, Task 4 tests); crash leaves the shadow, next full run starts over, doctor warns (Task 4); Engine SPI (Task 3, signatures adjusted per decision 3); disk documented (Task 4). The catch-up mechanism deviates on purpose (decision 1, ADR 0008).
   - R2: one `'*'` row in the queue-mode truncate branch (Task 5); `processQueue()` never casts it (Task 5); the worker runs a shadow rebuild first (Task 5; clearing per decision 10); `queueSize()` counts it as one, the doctor names it (Task 5); trigger mode keeps the inline resync, documented (Task 5 docs); own-table fast path unchanged (existing tests stay green).
   - R3: `t_<field>` / `z_<field>` through `Names` with `limit()` (Task 2); refresh function fills them (Task 2); both compilers' scoped leaves (Task 2, decision 11 for the strict branch); unscoped unchanged, unknown field warns (Task 2 tests); ranking unchanged (Task 2 `testAScopedWordRanksLikeTheSameUnscopedWord`); storage documented (Task 2).
   - R4: `LAYOUT_VERSION = 2`, guarded DO step before the meta upsert, in `--dump-migration`, clears `documents_hash`, documents hash includes the layout, meta-less installs run no step (Task 1; columns in Task 2).
   - R5: "Shared objects" check, all four branches, once per report next to "Schema version" (Task 1).
   - R6: DO block over `pg_partition_tree` at every level, trigger removal on drop and in trigger-less modes, doctor lists partitions without it, statement-level warning (Task 6).
   - Testing section: swap under writes in both sync modes via `onBatch` (Task 4 `testWritesDuringTheBuildEndUpInTheSwappedIndex`, Task 3 at SPI level), search during the build (Task 3, Task 4), crash (Task 4), second rebuild (Tasks 3 and 4), names after the swap (Task 3); `'*'` job (Task 5); field scoping unit + integration (Task 2); step runner (Tasks 1 and 2); partitions two levels, both modes, attached partition, statement warning (Task 6); `*` row branches (Task 1).
   - Order: Tasks 1 to 7 follow the spec's steps 1 to 7.
   - Success criteria: the four limitations entries leave `docs/limitations.md` (Tasks 2, 5, 6); CHANGELOG Breaking and UPGRADE "From 0.4 to 0.5" in every task that breaks something; demo shows a field-scoped query and a zero-downtime rebuild (Task 7).
2. **Placeholder scan.** No TBD / "similar to" / "add validation". Every code step has the code; every test step has the test; commands are exact.
3. **Type consistency.** `beginRebuild(IndexDefinition, bool = false): bool`, `refreshShadow(IndexDefinition, array): int`, `finishRebuild(IndexDefinition): void`, `abortRebuild(IndexDefinition, bool = false): void`, `rebuildRequested(IndexDefinition): bool` are the same in Engine, PostgresEngine, the Reindexer / Worker calls and the mocks. `ShadowRebuild::begin/refresh/finish/abort` match between Tasks 3 and 5. `FuzzyQueryCompiler::scope()` returns `array{predicate, columns}|null` and `SearchSqlBuilder::ranked()` reads exactly those keys. `ReindexOptions` gains `inPlace` as the last parameter, `ReindexResult` gains `swapped` as the last parameter (positional callers unaffected). Names: `shadowIndexName(string $liveName)` is used with live index names (from `indexes()` keys and `indexName($index, 'pkey')`) everywhere.
4. **Review Focus.** Each of the five lines has its test in the owning task (Tasks 2, 3, 4), named in the Review Focus section.

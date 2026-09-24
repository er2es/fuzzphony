# Column-Aware Trigger Filtering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A watched table's UPDATE only queues/triggers a document refresh when a column that actually feeds the index (or an explicitly declared set of columns, for joined watches) changed — not on every UPDATE regardless of which columns changed.

**Architecture:** `PostgresSchemaGenerator::relevantColumns()` resolves, per watch, either `null` (no filtering — today's behavior) or a `list<string>` of column names to diff: auto-derived from the index's own fields/filters/boost/recency for the self-watch (automatic, no new API), or from a new explicit `Watch::$columns` for joined watches (opt-in via `.watch(..., columns: [...])`). Row-level triggers get one prepended `IS DISTINCT FROM`-based guard. Statement-level triggers (the default `TriggerLevel`) need their two combined `TG_OP` branches restructured into three mutually exclusive ones, because a query naming both `fz_new` and `fz_old` transition tables errors during an insert-only or delete-only trigger invocation regardless of WHERE-clause short-circuiting — the filtered UPDATE branch uses a `LEFT JOIN` (not an inner join) so a watched row whose correlating key can't be matched on the other side is conservatively treated as "changed" rather than silently dropped.

**Tech Stack:** PHP 8.4, PostgreSQL 15+, PHPUnit 12, PHPStan (max + strict-rules), php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-09-24-column-aware-trigger-filtering-design.md`

## Global Constraints

- `composer cs` and `composer stan` must pass after every task (repo is at zero PHPStan errors — do not reintroduce any).
- Wither methods (`IndexDefinition::with()`) use explicit, validated named constructor arguments — never `get_object_vars()`/untyped merge+spread. (This plan's tasks don't need to touch `with()` at all — `Watch` objects pass through `IndexDefinition::$watches` unchanged as a `list<Watch>`, and `Watch` itself is a plain, non-wither value object.)
- Narrowing a `mixed` value to a scalar goes through `Fuzzphony\Core\Support\Coerce` (`Coerce::str()`/`Coerce::int()`/`Coerce::float()`), never a bare cast.
- The generated SQL for a watch with no column list (`relevantColumns()` returns `null`) must be **byte-identical** to today's output — this feature must not churn DDL for anyone not opting in (self-watch auto-derivation is the one exception: it changes DDL automatically and deliberately, since it's the whole point of the feature; explicit joined-watch `columns` is opt-in).
- Run `vendor/bin/phpunit --testsuite=unit` after every unit-test task. Integration tasks need a live Postgres: check `docker ps` first (a `demo` Postgres container may already occupy port 5432 — if so, start a separate throwaway container on another host port, e.g. `docker run -d --name fuzzphony-plan-test-db -e POSTGRES_DB=fuzzphony -e POSTGRES_USER=fuzzphony -e POSTGRES_PASSWORD=fuzzphony -p 5433:5432 postgres:17`, and use `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5433;dbname=fuzzphony;user=fuzzphony;password=fuzzphony"`). Tear the throwaway container down when the plan is complete.
- Commit locally after each task (`git add` the exact files touched — never `git add -A`).

## Review Focus

- **A self-watch UPDATE that changes only an unrelated, never-mapped column** (e.g. a table column that exists but isn't a field/filter/boost/recency) must not enqueue/refresh. The spec's whole point; every task that touches trigger generation needs this proven, not just code-reviewed.
- **A joined-watch UPDATE with `columns` set, where the changed column ISN'T in the list** must not enqueue/refresh, and one where it IS must. Symmetric to the self-watch case but exercises the opt-in path and the transition-table restructuring.
- **An INSERT or DELETE on a statement-level-triggered, column-filtered watch must not error.** This is the specific bug the redesign exists to avoid (referencing an unregistered transition table). A regression here would silently corrupt writes to the watched table, not just skip an optimization — the highest-severity thing this plan could get wrong.
- **A watch whose `columns` is `null` (the overwhelming majority of existing watches, including every existing test fixture) must produce byte-identical trigger SQL to today's.** Silent DDL churn for non-adopters, or worse, a subtle semantic change hidden inside "no functional difference," is exactly the kind of regression a reviewer skimming only the new code would miss.
- **An explicit `columns` list containing an invalid column name** must be rejected by `DefinitionValidator`, the same way an invalid filter/field column name already is — not silently accepted into DDL that would then fail against the real database with a confusing runtime error.

---

### Task 1: `Watch::$columns` + `IndexBuilder::watch()` + validation

**Files:**
- Modify: `src/Core/Definition/Watch.php` (whole file, currently 21 lines)
- Modify: `src/Core/Definition/IndexBuilder.php:77-82` (`watch()`)
- Modify: `src/Core/Definition/DefinitionValidator.php:96-106` (the per-watch validation loop)
- Test: `tests/Unit/Core/Definition/DefinitionValidatorTest.php`

**Interfaces:**
- Produces: `Watch::$columns` (`list<string>|null`), `IndexBuilder::watch(string $table, string $affectedIds = 'SELECT :id', string $keyColumn = 'id', ?array $columns = null): self`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Core/Definition/DefinitionValidatorTest.php`:

```php
    public function testBuilderCanDeclareWatchColumns(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name')
            ->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])
            ->build();

        self::assertSame(['name'], $definition->watches[0]->columns);
    }

    public function testWatchColumnsMustBeValidColumnNames(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::table('product'),
            fields: [new FieldDefinition('name')],
            watches: [new Watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['not a column!'])],
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(1, $violations);
        self::assertStringContainsString('column "not a column!"', $violations[0]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'testBuilderCanDeclareWatchColumns|testWatchColumnsMustBeValidColumnNames'`
Expected: FAIL — `testBuilderCanDeclareWatchColumns` fails with "Unknown named parameter $columns"; `testWatchColumnsMustBeValidColumnNames` fails the same way on `new Watch(...)`.

- [ ] **Step 3: Add `$columns` to `Watch`**

Replace `src/Core/Definition/Watch.php` entirely with:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/**
 * "When a row of $table changes, which documents must be reindexed?"
 *
 * $affectedIds is a SELECT returning document ids; ":id" is replaced with the changed row's key.
 * Example (brand renamed -> its products): table "brand", affectedIds "SELECT id FROM product WHERE brand_id = :id".
 */
final readonly class Watch
{
    /**
     * @param list<string>|null $columns Restrict UPDATE-triggered refreshes to changes in these
     *     columns of $table; null (the default) refreshes on every UPDATE, same as before this
     *     option existed. Ignored for the automatic self-watch on the index's own source table,
     *     where the relevant columns are derived automatically from its fields/filters/boost/recency.
     */
    public function __construct(
        public string $table,
        public string $affectedIds = 'SELECT :id',
        public string $keyColumn = 'id',
        public ?array $columns = null,
    ) {}
}
```

- [ ] **Step 4: Add `$columns` to `IndexBuilder::watch()`**

In `src/Core/Definition/IndexBuilder.php`, change (lines 77-82):

```php
    public function watch(string $table, string $affectedIds = 'SELECT :id', string $keyColumn = 'id'): self
    {
        $this->watches[] = new Watch($table, $affectedIds, $keyColumn);

        return $this;
    }
```

to:

```php
    /** @param list<string>|null $columns */
    public function watch(string $table, string $affectedIds = 'SELECT :id', string $keyColumn = 'id', ?array $columns = null): self
    {
        $this->watches[] = new Watch($table, $affectedIds, $keyColumn, $columns);

        return $this;
    }
```

- [ ] **Step 5: Run tests to verify they still fail on the validator, then add validation**

Run: `vendor/bin/phpunit --filter testBuilderCanDeclareWatchColumns`
Expected: PASS (this one only needed steps 3-4).

Run: `vendor/bin/phpunit --filter testWatchColumnsMustBeValidColumnNames`
Expected: FAIL — `assertCount(1, $violations)` fails because `$violations` is `[]` (nothing validates `columns` yet). `Watch` and `Source` and `FieldDefinition` need importing in the test file if not already — check the existing `use` block in `DefinitionValidatorTest.php`; `Watch` is already imported (used by an existing test), `Source` and `FieldDefinition` likewise.

In `src/Core/Definition/DefinitionValidator.php`, the per-watch loop currently (lines 96-106) reads:

```php
        foreach ($index->watches as $watch) {
            if (!Identifier::isTable($watch->table)) {
                $v[] = sprintf('Watched table "%s" is not a valid identifier.', $watch->table);
            }
            if (!Identifier::isColumn($watch->keyColumn)) {
                $v[] = sprintf('Watch key column "%s" is not a valid column name.', $watch->keyColumn);
            }
            if (preg_match_all('/:id\b/', $watch->affectedIds) !== 1 || preg_match('/^\s*select\b/i', $watch->affectedIds) !== 1) {
                $v[] = sprintf('Watch on "%s": affectedIds must be a SELECT containing ":id" exactly once, e.g. "SELECT id FROM product WHERE brand_id = :id".', $watch->table);
            }
        }
```

Change to:

```php
        foreach ($index->watches as $watch) {
            if (!Identifier::isTable($watch->table)) {
                $v[] = sprintf('Watched table "%s" is not a valid identifier.', $watch->table);
            }
            if (!Identifier::isColumn($watch->keyColumn)) {
                $v[] = sprintf('Watch key column "%s" is not a valid column name.', $watch->keyColumn);
            }
            if (preg_match_all('/:id\b/', $watch->affectedIds) !== 1 || preg_match('/^\s*select\b/i', $watch->affectedIds) !== 1) {
                $v[] = sprintf('Watch on "%s": affectedIds must be a SELECT containing ":id" exactly once, e.g. "SELECT id FROM product WHERE brand_id = :id".', $watch->table);
            }
            foreach ($watch->columns ?? [] as $column) {
                if (!Identifier::isColumn($column)) {
                    $v[] = sprintf('Watch on "%s": column "%s" is not a valid column name.', $watch->table, $column);
                }
            }
        }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testBuilderCanDeclareWatchColumns|testWatchColumnsMustBeValidColumnNames'`
Expected: PASS (2 tests).

- [ ] **Step 7: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Core/Definition/Watch.php src/Core/Definition/IndexBuilder.php src/Core/Definition/DefinitionValidator.php tests/Unit/Core/Definition/DefinitionValidatorTest.php
git commit -m "Add Watch::\$columns and validate explicit watch column names"
```

---

### Task 2: `PostgresSchemaGenerator::relevantColumns()`

**Files:**
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (add a new public method; exact insertion point below)
- Test: `tests/Unit/Postgres/SchemaGeneratorTest.php`

**Interfaces:**
- Consumes: `Watch::$columns` (Task 1), `IndexDefinition::$source`/`$fields`/`$filters`/`$boostColumn`/`$recencyColumn`.
- Produces: `PostgresSchemaGenerator::relevantColumns(IndexDefinition $index, Watch $watch): ?array` (a `list<string>|null`; the list, when non-null, is never empty — see rationale below).

This method resolves, for one watch, which columns to diff on UPDATE — or `null` for "no filtering, current behavior." It's `public` (not `private`) because Task 5's doctor check needs to call it too, matching the existing pattern where `PostgresInspector` reads other `public` `PostgresSchemaGenerator` methods like `columns()`, `indexes()`, `triggerDefinitions()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Postgres/SchemaGeneratorTest.php` (add `use Fuzzphony\Core\Definition\Watch;` and `use Fuzzphony\Core\Definition\FieldDefinition;` to the file's `use` block if not already present — check first; `Watch`/`FieldDefinition` are not currently imported there):

```php
    public function testRelevantColumnsAutoDerivesForTheSelfWatchOnATableSource(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')
            ->field('name', 'A')->filter('price', 'int')->boostBy('popularity')->recencyBy('created_at')
            ->build();
        $selfWatch = $definition->effectiveWatches()[0];

        self::assertSame(['name', 'price', 'popularity', 'created_at'], (new PostgresSchemaGenerator())->relevantColumns($definition, $selfWatch));
    }

    public function testRelevantColumnsIsNullForAQuerySourcesOwnWatch(): void
    {
        // Indexes::products() is a query source; its watch($table) targets the same physical
        // table the query reads from, but Fuzzphony has no certain column mapping for a query
        // source, so this must NOT be treated as an auto-derivable self-watch.
        $definition = Indexes::products();
        $ownWatch = $definition->watches[0];

        self::assertNull((new PostgresSchemaGenerator())->relevantColumns($definition, $ownWatch));
    }

    public function testRelevantColumnsReturnsExplicitJoinedWatchColumns(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')->field('name', 'A')
            ->watch('brand', 'SELECT id FROM t WHERE brand_id = :id', columns: ['name', 'country'])
            ->build();
        $joinedWatch = $definition->watches[0];

        self::assertSame(['name', 'country'], (new PostgresSchemaGenerator())->relevantColumns($definition, $joinedWatch));
    }

    public function testRelevantColumnsIsNullForAJoinedWatchWithNoExplicitColumns(): void
    {
        $definition = Indexes::products();
        $brandWatch = $definition->watches[1]; // fz_brand, the joined watch

        self::assertNull((new PostgresSchemaGenerator())->relevantColumns($definition, $brandWatch));
    }

    public function testRelevantColumnsTreatsAnExplicitEmptyListAsNull(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')->field('name', 'A')
            ->watch('brand', 'SELECT id FROM t WHERE brand_id = :id', columns: [])
            ->build();
        $joinedWatch = $definition->watches[0];

        self::assertNull((new PostgresSchemaGenerator())->relevantColumns($definition, $joinedWatch));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'testRelevantColumns'`
Expected: FAIL — `Call to undefined method Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator::relevantColumns()`.

- [ ] **Step 3: Add the method**

In `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php`, add this public method right after `columns()` (currently ending at line 164, just before `indexes()` at line 166):

```php
    /**
     * Which columns of $watch->table an UPDATE must change to warrant a refresh — null means
     * every UPDATE refreshes (today's behavior, unchanged). Explicit Watch::$columns always wins;
     * otherwise, for the automatic self-watch on a table source, the relevant columns are derived
     * from the index's own fields, filters, boost and recency columns.
     *
     * @return list<string>|null
     */
    public function relevantColumns(IndexDefinition $index, Watch $watch): ?array
    {
        if ($watch->columns !== null) {
            return $watch->columns !== [] ? $watch->columns : null;
        }
        if ($index->source->table === null || $watch->table !== $index->source->table) {
            return null;
        }

        $columns = [];
        foreach ($index->fields as $field) {
            $columns[] = $field->column();
        }
        foreach ($index->filters as $filter) {
            $columns[] = $filter->column();
        }
        if ($index->boostColumn !== null) {
            $columns[] = $index->boostColumn;
        }
        if ($index->recencyColumn !== null) {
            $columns[] = $index->recencyColumn;
        }

        return array_values(array_unique($columns));
    }
```

(This list is never empty in the auto-derived branch: `DefinitionValidator` already requires at least one field, so `$columns` always has at least one entry before `array_unique`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testRelevantColumns'`
Expected: PASS (5 tests).

- [ ] **Step 5: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Engine/Postgres/Schema/PostgresSchemaGenerator.php tests/Unit/Postgres/SchemaGeneratorTest.php
git commit -m "Add PostgresSchemaGenerator::relevantColumns()"
```

---

### Task 3: Row-level trigger guard

**Files:**
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php:297-323` (`syncFunction()`)
- Test: `tests/Unit/Postgres/SchemaGeneratorTest.php`

**Interfaces:**
- Consumes: `PostgresSchemaGenerator::relevantColumns()` (Task 2).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Postgres/SchemaGeneratorTest.php`:

```php
    public function testRowLevelTriggersSkipUnchangedColumns(): void
    {
        $definition = Indexes::products('queue')->with(triggerLevel: TriggerLevel::Row, tenant: null);
        // Force a self-watch scenario isn't available on this query-sourced fixture; instead prove
        // the guard is emitted for a watch that DOES have relevant columns via an explicit list.
        $definition = IndexDefinition::builder($definition->name)
            ->fromQuery('SELECT p.id, p.name FROM fz_product p')
            ->field('name', 'A')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->triggerLevel(TriggerLevel::Row)
            ->build();

        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString(
            "IF TG_OP = 'UPDATE' AND NOT (NEW.\"name\" IS DISTINCT FROM OLD.\"name\") THEN\n        RETURN NULL;\n    END IF;",
            $sql,
        );
    }

    public function testRowLevelTriggersWithoutColumnsAreUnchanged(): void
    {
        $definition = Indexes::products('queue')->with(triggerLevel: TriggerLevel::Row);
        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringNotContainsString("TG_OP = 'UPDATE' AND NOT", $sql);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'testRowLevelTriggersSkipUnchangedColumns|testRowLevelTriggersWithoutColumnsAreUnchanged'`
Expected: `testRowLevelTriggersSkipUnchangedColumns` FAILs (no guard emitted yet); `testRowLevelTriggersWithoutColumnsAreUnchanged` PASSes already (nothing to break yet) — that's fine, it's the regression guard for the next step.

- [ ] **Step 3: Add the guard**

In `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php`, `syncFunction()` currently (lines 297-323):

```php
    private function syncFunction(IndexDefinition $index, Watch $watch): string
    {
        $body = [];
        foreach (['NEW' => "TG_OP <> 'DELETE'", 'OLD' => "TG_OP <> 'INSERT'"] as $record => $condition) {
            $affected = (string) preg_replace('/:id\b/', $record . '.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $action = $index->sync === SyncMode::Trigger
                ? sprintf(
                    'PERFORM %s(ARRAY(SELECT a.doc_id::%s FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL));',
                    Sql::ident($this->refreshFunctionName($index)),
                    $index->idType->sqlType(),
                    $affected,
                )
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT %s, a.doc_id::text FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    self::QUEUE_TABLE,
                    Sql::string($index->name),
                    $affected,
                );
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $action);
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
            implode("\n", $body),
        );
    }
```

Change to:

```php
    private function syncFunction(IndexDefinition $index, Watch $watch): string
    {
        $columns = $this->relevantColumns($index, $watch);
        $body = [];
        if ($columns !== null) {
            $diff = implode(' OR ', array_map(
                static fn(string $c): string => sprintf('NEW.%1$s IS DISTINCT FROM OLD.%1$s', Sql::ident($c)),
                $columns,
            ));
            $body[] = sprintf("    IF TG_OP = 'UPDATE' AND NOT (%s) THEN\n        RETURN NULL;\n    END IF;", $diff);
        }
        foreach (['NEW' => "TG_OP <> 'DELETE'", 'OLD' => "TG_OP <> 'INSERT'"] as $record => $condition) {
            $affected = (string) preg_replace('/:id\b/', $record . '.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $action = $index->sync === SyncMode::Trigger
                ? sprintf(
                    'PERFORM %s(ARRAY(SELECT a.doc_id::%s FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL));',
                    Sql::ident($this->refreshFunctionName($index)),
                    $index->idType->sqlType(),
                    $affected,
                )
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT %s, a.doc_id::text FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    self::QUEUE_TABLE,
                    Sql::string($index->name),
                    $affected,
                );
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $action);
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
            implode("\n", $body),
        );
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testRowLevelTriggersSkipUnchangedColumns|testRowLevelTriggersWithoutColumnsAreUnchanged'`
Expected: PASS (2 tests).

- [ ] **Step 5: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Engine/Postgres/Schema/PostgresSchemaGenerator.php tests/Unit/Postgres/SchemaGeneratorTest.php
git commit -m "Skip row-level trigger refresh when no relevant column changed"
```

---

### Task 4: Statement-level trigger restructuring (the hard part)

**Files:**
- Modify: `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php:325-353` (replace `statementSyncFunction()` with the new structure below; it grows into one entry method plus four small private helpers)
- Test: `tests/Unit/Postgres/SchemaGeneratorTest.php`

**Interfaces:**
- Consumes: `PostgresSchemaGenerator::relevantColumns()` (Task 2).

Read the design spec's "Statement-level trigger — the harder case" section before starting this task; it explains *why* a naive "just add a WHERE clause" fix is wrong (referencing an unregistered transition table errors regardless of WHERE-clause short-circuiting) and why the UPDATE branch uses a `LEFT JOIN` rather than an inner join (an inner join would silently drop a row whose correlating key changed between OLD and NEW, instead of conservatively treating it as changed).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Postgres/SchemaGeneratorTest.php`:

```php
    public function testStatementLevelTriggersWithoutColumnsAreByteIdenticalToBefore(): void
    {
        // Regression guard: relevantColumns() is null for every watch on Indexes::products()
        // (query source; no watch has explicit columns), so this must produce exactly what
        // testStatementLevelTriggersUseTransitionTables already asserts.
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();

        self::assertStringContainsString('FROM fz_new AS r CROSS JOIN LATERAL (SELECT id FROM fz_product WHERE brand_id = r."id")', $sql);
        self::assertStringNotContainsString('LEFT JOIN', $sql);
    }

    public function testStatementLevelTriggersFilterByColumnOnUpdateOnly(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();

        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString("IF TG_OP = 'INSERT' THEN", $sql);
        self::assertStringContainsString("IF TG_OP = 'DELETE' THEN", $sql);
        self::assertStringContainsString("IF TG_OP = 'UPDATE' THEN", $sql);
        self::assertStringContainsString('fz_new AS r LEFT JOIN fz_old o ON o."id" = r."id"', $sql);
        self::assertStringContainsString('o."id" IS NULL OR (r."name" IS DISTINCT FROM o."name")', $sql);
        self::assertStringContainsString('fz_old AS r LEFT JOIN fz_new n ON n."id" = r."id"', $sql);
        self::assertStringContainsString('n."id" IS NULL OR (r."name" IS DISTINCT FROM n."name")', $sql);
        // INSERT/DELETE branches never reference the other side's transition table.
        self::assertStringNotContainsString("IF TG_OP = 'INSERT' THEN\n        PERFORM", $sql); // sanity: this fixture uses queue mode
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'testStatementLevelTriggersWithoutColumnsAreByteIdenticalToBefore|testStatementLevelTriggersFilterByColumnOnUpdateOnly'`
Expected: `testStatementLevelTriggersWithoutColumnsAreByteIdenticalToBefore` PASSes already (nothing changed yet — this is the regression guard, confirmed green *before* the refactor so you know it was already true, then re-run *after* to confirm the refactor didn't break it). `testStatementLevelTriggersFilterByColumnOnUpdateOnly` FAILs (no three-branch structure yet).

- [ ] **Step 3: Replace `statementSyncFunction()`**

In `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php`, delete the entire current `statementSyncFunction()` method (lines 325-353):

```php
    /** Statement-level variant: one set-based INSERT / refresh per statement via transition tables. */
    private function statementSyncFunction(IndexDefinition $index, Watch $watch): string
    {
        $body = [];
        foreach (['fz_new' => "TG_OP IN ('INSERT', 'UPDATE')", 'fz_old' => "TG_OP IN ('UPDATE', 'DELETE')"] as $rows => $condition) {
            $affected = (string) preg_replace('/:id\b/', 'r.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $source = sprintf('%s AS r CROSS JOIN LATERAL (%s) AS a(doc_id)', $rows, $affected);
            $action = $index->sync === SyncMode::Trigger
                ? sprintf(
                    'PERFORM %s(ARRAY(SELECT DISTINCT a.doc_id::%s FROM %s WHERE a.doc_id IS NOT NULL));',
                    Sql::ident($this->refreshFunctionName($index)),
                    $index->idType->sqlType(),
                    $source,
                )
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT DISTINCT %s, a.doc_id::text FROM %s WHERE a.doc_id IS NOT NULL\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    self::QUEUE_TABLE,
                    Sql::string($index->name),
                    $source,
                );
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $action);
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
            implode("\n", $body),
        );
    }
```

Replace it with:

```php
    private function statementSyncFunction(IndexDefinition $index, Watch $watch): string
    {
        $columns = $this->relevantColumns($index, $watch);
        $body = $columns === null
            ? $this->statementBodyUnfiltered($index, $watch)
            : $this->statementBodyFiltered($index, $watch, $columns);

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
            $body,
        );
    }

    /** Unfiltered: today's two combined-condition branches, unchanged — byte-identical output. */
    private function statementBodyUnfiltered(IndexDefinition $index, Watch $watch): string
    {
        $body = [];
        foreach (['fz_new' => "TG_OP IN ('INSERT', 'UPDATE')", 'fz_old' => "TG_OP IN ('UPDATE', 'DELETE')"] as $rows => $condition) {
            $affected = (string) preg_replace('/:id\b/', 'r.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $source = sprintf('%s AS r CROSS JOIN LATERAL (%s) AS a(doc_id)', $rows, $affected);
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $this->statementAction($index, $source));
        }

        return implode("\n", $body);
    }

    /**
     * Filtered: three mutually exclusive branches (INSERT / UPDATE / DELETE), so a query naming
     * both transition tables is only ever reached during the _upd trigger invocation, where both
     * are actually registered. The UPDATE branch runs twice (once from the new row's perspective,
     * once from the old row's), matching the unfiltered version's existing dual computation; each
     * is restricted via a LEFT JOIN to rows whose relevant columns changed, or whose correlating
     * key has no match on the other side at all (conservatively treated as changed, rather than
     * silently dropped as an inner join would).
     *
     * @param list<string> $columns
     */
    private function statementBodyFiltered(IndexDefinition $index, Watch $watch, array $columns): string
    {
        $key = Sql::ident($watch->keyColumn);
        $body = [];
        $body[] = sprintf("    IF TG_OP = 'INSERT' THEN\n        %s\n    END IF;", $this->statementAction($index, $this->statementSource($watch, 'fz_new', 'r')));
        $body[] = sprintf("    IF TG_OP = 'DELETE' THEN\n        %s\n    END IF;", $this->statementAction($index, $this->statementSource($watch, 'fz_old', 'r')));

        $diffNew = implode(' OR ', array_map(static fn(string $c): string => sprintf('r.%1$s IS DISTINCT FROM o.%1$s', Sql::ident($c)), $columns));
        $diffOld = implode(' OR ', array_map(static fn(string $c): string => sprintf('r.%1$s IS DISTINCT FROM n.%1$s', Sql::ident($c)), $columns));
        $updateNewSource = sprintf('fz_new AS r LEFT JOIN fz_old o ON o.%1$s = r.%1$s CROSS JOIN LATERAL (%2$s) AS a(doc_id)', $key, (string) preg_replace('/:id\b/', 'r.' . $key, $watch->affectedIds, 1));
        $updateOldSource = sprintf('fz_old AS r LEFT JOIN fz_new n ON n.%1$s = r.%1$s CROSS JOIN LATERAL (%2$s) AS a(doc_id)', $key, (string) preg_replace('/:id\b/', 'r.' . $key, $watch->affectedIds, 1));
        $body[] = sprintf(
            "    IF TG_OP = 'UPDATE' THEN\n        %s\n        %s\n    END IF;",
            $this->statementAction($index, $updateNewSource, sprintf('o.%1$s IS NULL OR (%2$s)', $key, $diffNew)),
            $this->statementAction($index, $updateOldSource, sprintf('n.%1$s IS NULL OR (%2$s)', $key, $diffOld)),
        );

        return implode("\n", $body);
    }

    private function statementSource(Watch $watch, string $transitionTable, string $alias): string
    {
        $affected = (string) preg_replace('/:id\b/', $alias . '.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);

        return sprintf('%s AS %s CROSS JOIN LATERAL (%s) AS a(doc_id)', $transitionTable, $alias, $affected);
    }

    private function statementAction(IndexDefinition $index, string $source, ?string $extraWhere = null): string
    {
        $where = $extraWhere !== null ? sprintf('a.doc_id IS NOT NULL AND (%s)', $extraWhere) : 'a.doc_id IS NOT NULL';

        return $index->sync === SyncMode::Trigger
            ? sprintf(
                'PERFORM %s(ARRAY(SELECT DISTINCT a.doc_id::%s FROM %s WHERE %s));',
                Sql::ident($this->refreshFunctionName($index)),
                $index->idType->sqlType(),
                $source,
                $where,
            )
            : sprintf(
                "INSERT INTO %s (index_name, doc_id)\n        SELECT DISTINCT %s, a.doc_id::text FROM %s WHERE %s\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                self::QUEUE_TABLE,
                Sql::string($index->name),
                $source,
                $where,
            );
    }
```

Note: `$updateNewSource` and `$updateOldSource` both substitute `:id` with `r.<key>` — `r` is consistently the alias of the *current* transition table in each half (new-perspective vs old-perspective), exactly matching what the unfiltered version's `$rows => $condition` loop did (it always called the row alias `r` regardless of which transition table it came from).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testStatementLevelTriggersWithoutColumnsAreByteIdenticalToBefore|testStatementLevelTriggersFilterByColumnOnUpdateOnly'`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the FULL existing `SchemaGeneratorTest` file**

Run: `vendor/bin/phpunit tests/Unit/Postgres/SchemaGeneratorTest.php`
Expected: all pass, including `testStatementLevelTriggersUseTransitionTables` and `testRowLevelTriggers` (both pre-existing, both must be untouched by this refactor since neither fixture sets watch `columns`).

- [ ] **Step 6: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Engine/Postgres/Schema/PostgresSchemaGenerator.php tests/Unit/Postgres/SchemaGeneratorTest.php
git commit -m "Restructure statement-level trigger into three branches for column filtering"
```

---

### Task 5: `fuzzphony:doctor` reports column-aware filtering

**Files:**
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php:60` (`inspect()`), add a new private method after `tenantScoping()` (currently lines 400-406)

**Interfaces:**
- Consumes: `PostgresSchemaGenerator::relevantColumns()` (Task 2).

No new integration fixture needed — covered by extending the existing tenant-scoping integration test pattern in Task 8, once that task's fixture exists. This task's own test is a focused integration test using the existing `PostgresEngineTest`-style doctor check pattern.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/PostgresEngineTest.php` (this file already has a `fuzzphony(string $sync)` helper and a `testDoctorIsHappyAfterInstall`-style test to follow the pattern of — read the existing doctor test there first):

```php
    public function testDoctorReportsColumnAwareFiltering(): void
    {
        $fuzzphony = $this->fuzzphony('queue');

        $messages = array_column($fuzzphony->inspect('products')->checks, 'message', 'name');

        self::assertArrayHasKey('Column-aware filtering', $messages);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run (with a test Postgres reachable per Global Constraints): `FUZZPHONY_TEST_DSN="..." vendor/bin/phpunit --filter testDoctorReportsColumnAwareFiltering`
Expected: FAIL — no such check exists yet, `assertArrayHasKey` fails.

- [ ] **Step 3: Add the check**

In `src/Engine/Postgres/Inspection/PostgresInspector.php`, `inspect()` currently ends (line 60-68):

```php
        array_push($checks, ...$this->triggers($index));
        $checks[] = $this->queue($index, $options);
        if ($sidecarExists && $sourceColumns !== null) {
            $checks[] = $this->coverage($index, $options);
        }
        array_push($checks, ...$this->configuration($index));
        array_push($checks, ...$this->tenantScoping($index));

        return new InspectionReport($index->name, $checks);
```

Change to:

```php
        array_push($checks, ...$this->triggers($index));
        $checks[] = $this->queue($index, $options);
        if ($sidecarExists && $sourceColumns !== null) {
            $checks[] = $this->coverage($index, $options);
        }
        array_push($checks, ...$this->configuration($index));
        array_push($checks, ...$this->tenantScoping($index));
        array_push($checks, ...$this->columnAwareFiltering($index));

        return new InspectionReport($index->name, $checks);
```

Add a new private method right after `tenantScoping()` (currently lines 400-406):

```php
    /** @return list<Check> */
    private function columnAwareFiltering(IndexDefinition $index): array
    {
        $checks = [];
        foreach ($index->effectiveWatches() as $watch) {
            $columns = $this->schema->relevantColumns($index, $watch);
            if ($columns !== null) {
                $checks[] = Check::ok('Column-aware filtering', sprintf('active for %s (%s)', $watch->table, implode(', ', $columns)));
            }
        }

        return $checks;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `FUZZPHONY_TEST_DSN="..." vendor/bin/phpunit --filter testDoctorReportsColumnAwareFiltering`
Expected: PASS. (`Indexes::products()`'s self-watch on `fz_product` won't trigger this — it's a query source, `relevantColumns()` returns null for it — but that watch's table `fz_product` happens to equal the raw table name passed to `fromQuery()`'s `%s`, not `$index->source->table` which is null for a query source, so no check is emitted for it; assert on `assertArrayHasKey` only proves the KEY exists at all, which requires at least one watch to produce a non-empty result. If `Indexes::products()` produces zero column-aware checks, adjust the fixture call in this test to explicitly pass `columns` to one of its watches — check by running Step 2's failure output first to see what's actually emitted before assuming.)

- [ ] **Step 5: Full suites + quality gates**

Run:
```
vendor/bin/phpunit --testsuite=unit
composer cs
composer stan
FUZZPHONY_TEST_DSN="..." vendor/bin/phpunit --testsuite=integration
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Engine/Postgres/Inspection/PostgresInspector.php tests/Integration/PostgresEngineTest.php
git commit -m "fuzzphony:doctor reports column-aware filtering"
```

---

### Task 6: YAML `columns` key for watches

**Files:**
- Modify: `src/Core/Definition/ArrayDefinitionLoader.php:11-27` (class docblock), `:53-56` (`load()`'s watch loop), `:99-102` (`override()`'s watch loop)
- Test: `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php`

**Interfaces:**
- Consumes: `IndexBuilder::watch(..., ?array $columns)` (Task 1), `Watch::$columns`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php`:

```php
    public function testWatchColumnsCanBeDeclaredInYaml(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('products', [
            'source' => ['query' => 'SELECT p.id, p.name FROM product p'],
            'fields' => ['name' => 'A'],
            'watch' => ['product' => ['ids' => 'SELECT :id', 'columns' => ['name']]],
        ]);

        self::assertSame(['name'], $definition->watches[0]->columns);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testWatchColumnsCanBeDeclaredInYaml`
Expected: FAIL — `$definition->watches[0]->columns` is `null` (the `columns` key is silently ignored by `map()`, since `self::map($options)` only strips non-array shapes, it doesn't reject unknown keys inside a watch's own options — so this fails on the assertion, not an exception).

- [ ] **Step 3: Read `columns` in `load()` and `override()`**

In `src/Core/Definition/ArrayDefinitionLoader.php`, `load()`'s watch loop currently (lines 53-56):

```php
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $this->map($options);
            $builder->watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'));
        }
```

Change to:

```python
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $this->map($options);
            $builder->watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($options['columns'] ?? null));
        }
```

(That's PHP, not Python — write it as a `.php` edit; the code fence language tag above is illustrative text only, copy the code verbatim as PHP.)

`override()`'s watch loop currently (lines 99-102):

```php
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $this->map($options);
            $changes['watches'] = [...($changes['watches'] ?? $definition->watches), new Watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'))];
        }
```

Change to:

```php
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $this->map($options);
            $changes['watches'] = [...($changes['watches'] ?? $definition->watches), new Watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($options['columns'] ?? null))];
        }
```

Add a new private static helper, right after the existing `str()` helper (currently lines 163-166):

```php
    /** @return list<string>|null */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $strings = array_values(array_filter($value, 'is_string'));

        return $strings !== [] ? $strings : null;
    }
```

Update the class docblock's YAML shape example (lines 11-27) to mention the new key. It currently shows:

```
 *   watch: { brand: "SELECT id FROM product WHERE brand_id = :id" }
```

Change to also show the array form with `columns` (matching the docblock's existing style of showing both a short form elsewhere, e.g. `fields`):

```
 *   watch: { brand: { ids: "SELECT id FROM product WHERE brand_id = :id", columns: [name] } }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testWatchColumnsCanBeDeclaredInYaml`
Expected: PASS.

- [ ] **Step 5: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Definition/ArrayDefinitionLoader.php tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php
git commit -m "Add YAML columns key for watches"
```

---

### Task 7: Exporter support (learned from the multi-tenancy retrospective)

**Files:**
- Modify: `src/Core/Wizard/Export/ArrayExporter.php:45-47` (the watch export line)
- Modify: `src/Core/Wizard/Export/BuilderExporter.php:26-28` (the watch export line)
- Test: `tests/Unit/Core/Wizard/ExportersTest.php`

**Interfaces:**
- Consumes: `Watch::$columns` (Task 1). No changes needed to `YamlExporter` (delegates to `ArrayExporter`) or `AttributeExporter` (already refuses to export any index with explicit watches — confirmed in the design spec).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Wizard/ExportersTest.php`:

```php
    public function testArrayExportRoundTripsWatchColumns(): void
    {
        $original = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();

        $reloaded = (new ArrayDefinitionLoader())->load('products', (new ArrayExporter())->export($original));

        self::assertSame(['name'], $reloaded->watches[1]->columns);
        self::assertEquals($original, $reloaded);
    }

    public function testBuilderExportRoundTripsWatchColumns(): void
    {
        $original = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();

        $code = (new BuilderExporter())->export($original);

        self::assertStringContainsString("->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: array (\n  0 => 'name',\n))", $code);
    }
```

(The exact `var_export()` formatting of the `columns:` array in the second test may differ slightly from this literal string — if the assertion fails only on whitespace/formatting of the array literal, run the test once, read the actual generated `$code` from the failure output, and adjust the expected string to match `var_export`'s real output rather than guessing further; the *presence* of a `columns:` named argument is what this test is actually checking.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'testArrayExportRoundTripsWatchColumns|testBuilderExportRoundTripsWatchColumns'`
Expected: FAIL — `testArrayExportRoundTripsWatchColumns` fails because `$reloaded->watches[1]->columns` is `null` (exporter doesn't emit it yet); `testBuilderExportRoundTripsWatchColumns` fails because the generated code has no `columns:` argument.

- [ ] **Step 3: Update `ArrayExporter`**

In `src/Core/Wizard/Export/ArrayExporter.php`, the watch export line currently (lines 45-47):

```php
        foreach ($index->watches as $watch) {
            $out['watch'][$watch->table] = $watch->keyColumn === 'id' ? $watch->affectedIds : ['ids' => $watch->affectedIds, 'key' => $watch->keyColumn];
        }
```

Change to:

```php
        foreach ($index->watches as $watch) {
            $out['watch'][$watch->table] = $watch->keyColumn === 'id' && $watch->columns === null
                ? $watch->affectedIds
                : array_filter([
                    'ids' => $watch->affectedIds,
                    'key' => $watch->keyColumn !== 'id' ? $watch->keyColumn : null,
                    'columns' => $watch->columns,
                ], static fn(mixed $v): bool => $v !== null);
        }
```

- [ ] **Step 4: Update `BuilderExporter`**

In `src/Core/Wizard/Export/BuilderExporter.php`, the watch export line currently (lines 26-28):

```php
        foreach ($index->watches as $watch) {
            $lines[] = sprintf('    ->watch(%s, %s%s)', $e($watch->table), $e($watch->affectedIds), $watch->keyColumn !== 'id' ? ', ' . $e($watch->keyColumn) : '');
        }
```

Change to:

```php
        foreach ($index->watches as $watch) {
            $args = [$e($watch->table), $e($watch->affectedIds)];
            if ($watch->columns !== null) {
                $args[] = $watch->keyColumn !== 'id' ? $e($watch->keyColumn) : 'key: ' . $e($watch->keyColumn);
                // key wasn't changed but columns is set: name it explicitly since a positional
                // argument can't be skipped once a later one is supplied.
                if ($watch->keyColumn === 'id') {
                    array_pop($args);
                } else {
                    array_pop($args);
                    $args[] = $e($watch->keyColumn);
                }
                $args[] = 'columns: ' . $e($watch->columns);
            } elseif ($watch->keyColumn !== 'id') {
                $args[] = $e($watch->keyColumn);
            }
            $lines[] = sprintf('    ->watch(%s)', implode(', ', $args));
        }
```

(That inline pop/push dance is confusing to read — replace it with the clearer equivalent below instead; both produce the same output, but this version is the one to actually type in:)

```php
        foreach ($index->watches as $watch) {
            $args = [$e($watch->table), $e($watch->affectedIds)];
            if ($watch->columns !== null) {
                if ($watch->keyColumn !== 'id') {
                    $args[] = $e($watch->keyColumn);
                }
                $args[] = 'columns: ' . $e($watch->columns);
            } elseif ($watch->keyColumn !== 'id') {
                $args[] = $e($watch->keyColumn);
            }
            $lines[] = sprintf('    ->watch(%s)', implode(', ', $args));
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testArrayExportRoundTripsWatchColumns|testBuilderExportRoundTripsWatchColumns'`
Expected: PASS. If `testBuilderExportRoundTripsWatchColumns`'s exact expected string doesn't match (see the note in Step 1), update the assertion to match the real `var_export()` output rather than the code.

- [ ] **Step 6: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Core/Wizard/Export/ArrayExporter.php src/Core/Wizard/Export/BuilderExporter.php tests/Unit/Core/Wizard/ExportersTest.php
git commit -m "Export Watch::\$columns from ArrayExporter and BuilderExporter"
```

---

### Task 8: Integration tests — the real proof

This is the task that proves the feature actually works against real PostgreSQL, for both the self-watch and joined-watch cases, and specifically proves the statement-level restructuring doesn't error on insert-only/delete-only invocations (the Review Focus item this whole redesign exists to satisfy).

**Files:**
- Modify: `tests/Integration/PostgresTestCase.php:28` (`createFixtures()` — add one harmless extra column to `fz_brand` so there's an "irrelevant column" to test against)
- Create: `tests/Integration/ColumnAwareFilteringTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-4.

- [ ] **Step 1: Add an irrelevant column to the shared fixture**

In `tests/Integration/PostgresTestCase.php`, change (line 28):

```php
        $connection->execute('CREATE TABLE fz_brand (id bigint PRIMARY KEY, name text NOT NULL)');
```

to:

```php
        $connection->execute("CREATE TABLE fz_brand (id bigint PRIMARY KEY, name text NOT NULL, country text NOT NULL DEFAULT '')");
```

(`country` is never selected by any existing query — `Indexes::products()`'s join only reads `b.name AS brand` — so this is additive and safe for every other integration test.)

- [ ] **Step 2: Write the failing tests**

Create `tests/Integration/ColumnAwareFilteringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\TestCase;

/**
 * A watched table's UPDATE must only refresh documents when a relevant column changed —
 * and, for statement-level triggers, insert-only/delete-only invocations must never error.
 */
final class ColumnAwareFilteringTest extends TestCase
{
    private function connection(): \Fuzzphony\Core\Database\Connection
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());

        return $connection;
    }

    private function apply(\Fuzzphony\Core\Database\Connection $connection, IndexDefinition $index): Fuzzphony
    {
        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    public function testSelfWatchSkipsAnIrrelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        // A table-source index: fields/filters map to name/price only, so relevantColumns()
        // auto-derives ['name', 'price'] for the self-watch — popularity is NOT relevant.
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->build();
        $this->apply($connection, $index);

        $before = (int) $connection->fetchValue("SELECT count(*) FROM {$index->sidecarTable()}");
        $connection->execute('UPDATE fz_product SET popularity = 999 WHERE id = 1');
        $connection->execute(sprintf('SELECT count(*) FROM %s', PostgresSchemaGeneratorQueueTable::NAME));

        $queued = (int) $connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n',
            ['n' => 'products_direct'],
        );
        self::assertSame(0, $queued, 'updating an unrelated column must not enqueue a refresh');
        self::assertSame($before, (int) $connection->fetchValue("SELECT count(*) FROM {$index->sidecarTable()}"));
    }

    public function testSelfWatchEnqueuesOnARelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_product SET name = 'Renamed' WHERE id = 1");

        $queued = (int) $connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n AND doc_id = :id',
            ['n' => 'products_direct', 'id' => '1'],
        );
        self::assertSame(1, $queued, 'updating a mapped field column must enqueue a refresh');
    }

    public function testJoinedWatchWithColumnsSkipsAnIrrelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_brand SET country = 'FI' WHERE id = 1"); // brand 1 = Logitech, products 1 & 4

        $queued = (int) $connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']);
        self::assertSame(0, $queued, 'updating an unwatched brand column must not enqueue a refresh');
    }

    public function testJoinedWatchWithColumnsEnqueuesOnARelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1"); // fans out to products 1 & 4

        $queued = (int) $connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']);
        self::assertSame(2, $queued, 'renaming the watched brand column must enqueue both its products');
    }

    /**
     * Regression guard for the exact bug this redesign works around: an INSERT-only or
     * DELETE-only statement-level trigger invocation must not reference the transition table
     * it doesn't have. If statementBodyFiltered() ever regresses to referencing fz_old inside
     * the INSERT branch (or fz_new inside DELETE), this errors instead of just failing an
     * assertion.
     */
    public function testColumnFilteredStatementLevelTriggersDoNotErrorOnInsertOrDelete(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->triggerLevel(TriggerLevel::Statement)
            ->build();
        $this->apply($connection, $index);

        $connection->execute("INSERT INTO fz_brand (id, name, country) VALUES (99, 'New Brand', '')");
        $connection->execute('DELETE FROM fz_brand WHERE id = 99');

        $this->addToAssertionCount(1); // reaching here without a thrown \PDOException is the assertion
    }

    public function testRowLevelTriggersAlsoWorkWithColumnFiltering(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->triggerLevel(TriggerLevel::Row)
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_brand SET country = 'FI' WHERE id = 1");
        $unrelated = (int) $connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']);

        $connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1");
        $relevant = (int) $connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']);

        self::assertSame(0, $unrelated);
        self::assertSame(2, $relevant);
    }
}
```

Remove the stray `$connection->execute(sprintf('SELECT count(*) FROM %s', PostgresSchemaGeneratorQueueTable::NAME));` line from `testSelfWatchSkipsAnIrrelevantColumnUpdate` above — it references a class that doesn't exist; it was a copy-paste leftover from drafting this plan and must not appear in the file you create. The test should read straight from `$before = ...` to `$connection->execute('UPDATE fz_product SET popularity = 999 WHERE id = 1');` to the `$queued = ...` assertion, with no line in between.

- [ ] **Step 3: Run tests to verify they fail**

Run (with `docker ps` checked and a test Postgres reachable per Global Constraints):
`FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5433;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit tests/Integration/ColumnAwareFilteringTest.php`
Expected: FAIL across the board — none of Tasks 1-4's DDL changes are wired into a real applied schema differently than before *for these specific new index definitions* until this task itself proves it; more concretely, expect failures like "expected 0, got 1" (filtering not effective) rather than SQL errors, since Tasks 1-4 are already committed and should already be generating the right DDL — this step's job is to catch any integration-level surprise (e.g. a `country` column default, a join key mismatch) that unit tests couldn't see.

- [ ] **Step 4: Fix, if anything is wrong; otherwise confirm they pass as-is**

If a test fails for a reason other than "filtering isn't happening yet" (which shouldn't happen — Tasks 1-4 already implemented the generator), investigate the actual generated SQL via `(new PostgresSchemaGenerator())->index($index)->toSql()` printed to a scratch file, compare against what Task 4 designed, and fix the schema generator rather than the test, unless the test itself has a mistake (e.g. wrong expected count for the fixture data).

Run: `FUZZPHONY_TEST_DSN="..." vendor/bin/phpunit tests/Integration/ColumnAwareFilteringTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Full suites + quality gates**

Run:
```
vendor/bin/phpunit --testsuite=unit
composer cs
composer stan
FUZZPHONY_TEST_DSN="..." vendor/bin/phpunit --testsuite=integration
```
Expected: all green, including the full pre-existing integration suite (confirms the `fz_brand.country` column addition didn't break anything else).

- [ ] **Step 6: Commit**

```bash
git add tests/Integration/PostgresTestCase.php tests/Integration/ColumnAwareFilteringTest.php
git commit -m "Add integration tests proving column-aware trigger filtering"
```

---

### Task 9: README documentation + roadmap

**Files:**
- Modify: `README.md` (extend "Keeping the index in sync" section; update the roadmap bullet)

No test — documentation only. Verification is a manual read-through confirming the code example matches the real, already-implemented API (all of it landed in Tasks 1-7).

- [ ] **Step 1: Document the feature**

In `README.md`, find the "## Keeping the index in sync" section (the one explaining queue/trigger/orm/manual sync modes and statement-level triggers). Its current text includes a sentence acknowledging the limitation this feature fixes: search for the "Known limitations" bullet about triggers firing on every UPDATE — it currently reads something like:

```
* Sync triggers fire for every UPDATE of a watched table, even when only unrelated columns
  change (the refresh is idempotent, just wasted work). Column-aware filtering is planned.
```

Change that bullet to reflect that it's now implemented, and add a short explanation + example to "## Keeping the index in sync" itself:

```markdown
Watched tables also skip wasted work: a table-sourced index's own watch automatically only
refreshes on UPDATEs that actually change a mapped field, filter, boost or recency column —
no configuration needed. Joined-table watches can opt into the same behavior explicitly:

```php
->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])
// updating any OTHER column of "brand" no longer refreshes dependent products
```

Omitting `columns` on a joined watch keeps today's behavior (every UPDATE refreshes) —
this is opt-in for joined watches because Fuzzphony has no way to know which of a joined
table's columns matter without you saying so.
```

In the "Known limitations" bullet list, remove or reword the now-fixed limitation:

```
* Sync triggers fire for every UPDATE of a watched table, even when only unrelated columns
  change (the refresh is idempotent, just wasted work). Column-aware filtering is planned.
```

to:

```
* ~~Sync triggers fire for every UPDATE of a watched table, even when only unrelated columns
  change.~~ **Shipped** for the index's own source table (automatic) and for joined-table
  watches (opt-in `columns:`, see [Keeping the index in sync](#keeping-the-index-in-sync)).
```

Update the roadmap's column-aware trigger filtering bullet (currently a plain, un-struck line
alongside the multi-tenancy bullet that already uses the `~~...~~ **Shipped**` pattern — match
that exact pattern):

```
  * Column-aware trigger filtering (a watched table's UPDATE only queues a refresh when a
    relevant column actually changed).
```

to:

```
  * ~~Column-aware trigger filtering (a watched table's UPDATE only queues a refresh when a
    relevant column actually changed).~~ **Shipped** — see
    [Keeping the index in sync](#keeping-the-index-in-sync).
```

- [ ] **Step 2: Read-through check**

Re-read the changed sections once end to end. Confirm the code example (`->watch('brand', ..., columns: ['name'])`) matches the real, already-implemented `IndexBuilder::watch()` signature from Task 1 — no placeholders, no invented syntax.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "Document column-aware trigger filtering in the README"
```

---

## Plan self-review (already applied above)

- **Spec coverage:** self-watch auto-derivation (Task 2), joined-watch opt-in API (Task 1), row-level guard (Task 3), statement-level restructuring (Task 4), validation (Task 1), doctor check (Task 5), YAML (Task 6), exporters (Task 7), integration proof (Task 8), README (Task 9) — every spec section maps to a task.
- **Placeholder scan:** no TBD/TODO; Task 8's draft included one deliberately-flagged copy-paste leftover line with explicit instructions to remove it, which is not a placeholder — it's an explicit correction instruction, called out precisely so an implementer doesn't silently reproduce a broken reference.
- **Type consistency:** `Watch::$columns` (`list<string>|null`) is used identically across Tasks 1, 2, 3, 4, 6, 7. `PostgresSchemaGenerator::relevantColumns()`'s signature (`IndexDefinition, Watch): ?array`) is defined once in Task 2 and consumed identically in Tasks 3, 4, 5.
- **Review Focus:** all five items map to a task's test — unrelated-column self-watch update (Task 8, `testSelfWatchSkipsAnIrrelevantColumnUpdate`), joined-watch symmetric case (Task 8, `testJoinedWatchWithColumns*`), insert/delete-must-not-error (Task 8, `testColumnFilteredStatementLevelTriggersDoNotErrorOnInsertOrDelete`), byte-identical-when-null (Task 4, `testStatementLevelTriggersWithoutColumnsAreByteIdenticalToBefore` + the full pre-existing `SchemaGeneratorTest` suite re-run in Task 4 Step 5), invalid column name rejected (Task 1, `testWatchColumnsMustBeValidColumnNames`).

# v0.4 Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the ground later milestones build on: one exception hierarchy, typed withers, a reindex options/result pair, PostgreSQL naming moved out of Core, every Fuzzphony object in a configurable (dedicated) schema with fully schema-qualified SQL, a versioned sidecar (`fuzzphony_meta`) checked by the doctor, Doctrine Migrations support (a `schema_filter` that hides Fuzzphony's objects) and an explicit public API (`@internal` everywhere else). The demo runs on `schema: fuzzphony`.

**Architecture:** A new internal `Fuzzphony\Engine\Postgres\Schema\Names` owns every database object name (qualified, quoted, 63-byte limited) and `Types` owns SQL types; the generator, engine, SQL builders, highlighter, inspector and introspector get their names from one `Names` instance built from `extensionSchema` + `schema`. `fuzzphony_meta` records per index the layout version, a definition hash (DDL-shaping parts, written by apply) and a documents hash (content-shaping parts, written by a full reindex through the new `Engine::recordReindex()`); the doctor compares them. The bundle prepends a DBAL `schema_filter` when DoctrineBundle is present. Everything else is API hygiene in Core.

**Tech Stack:** PHP 8.4, PostgreSQL 15+ (tests on 17), PHPUnit 12, PHPStan 2 (level max + strict rules), php-cs-fixer, Infection, Symfony 7.4/8, Doctrine DBAL 4 / ORM 3.

**Spec:** `docs/superpowers/specs/2026-09-26-v04-foundations-design.md` (rulings R1–R8, binding). Read it before starting any task.

## Global Constraints

- PHP 8.4 only features are fine (`array_find`, `array_any`, property promotion, `readonly`); `declare(strict_types=1);` in every file.
- `composer stan` (PHPStan level max + phpstan-strict-rules, paths `src` and `tests`) reports **0 errors** after every task.
- `composer cs` (php-cs-fixer dry run) is clean after every task; fix with `composer cs:fix`.
- **100% line coverage of `src/`** after every task (CI hard floor is 90%, the project target is 100%); no new `@codeCoverageIgnore`.
- New and changed tests must **kill the mutants of the lines they cover** (the PR's `mutation / diff` job runs `vendor/bin/infection --threads=max --only-covering-test-cases --show-mutations=0 --git-diff-lines --git-diff-base=origin/main`). Assert exact strings, exact values and both branches of every condition you add.
- Every breaking change goes under `### Breaking` in `CHANGELOG.md` `## [Unreleased]` (create the heading above `### Added`) **and** into `UPGRADE.md` section `## From 0.3 to 0.4` (create it above `## From 0.3.1 to 0.3.2`) **in the same task** that makes the change. Docs that describe changed behaviour (README, `docs/*.md`, `demo/README.md`) change in the same task too.
- Withers construct through the constructor (`new self(...)` with named arguments); never spread `get_object_vars()`.
- `mixed` is narrowed with `Fuzzphony\Core\Support\Coerce` (`Coerce::str/int/float`), never with unchecked casts.
- Every user-derived value in SQL is a bound parameter; identifiers go through `Sql::ident()` / `Names`; `Connection` placeholders are named and each is used exactly once (PDO rejects unused bound parameters, so add a parameter only when its placeholder is in the SQL).
- Work in a git worktree (superpowers:using-git-worktrees). `composer install` is allowed **only inside the worktree**, never in the shared checkout `D:\xampp_php7\htdocs\fuzzphony`; never `composer update`/`require`.
- Commits: explicit paths only (`git add <paths>`), never `git add -A`/`.`, never `--amend`, never `--no-verify`. Short imperative subject. Every message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Do not push (the human opens the PR from the branch; `main` is protected).
- Integration tests run against a throwaway PostgreSQL 17 container, removed with `docker rm -fv` (never `docker volume prune`, never touch other projects' volumes):

  ```bash
  docker run -d --name fz-v04-pg -e POSTGRES_DB=fuzzphony -e POSTGRES_USER=fuzzphony -e POSTGRES_PASSWORD=fuzzphony -p 5452:5432 postgres:17
  until docker exec fz-v04-pg pg_isready -U fuzzphony -q; do sleep 1; done
  docker exec fz-v04-pg psql -U fuzzphony -c 'CREATE EXTENSION pg_trgm; CREATE EXTENSION unaccent;'
  export FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5452;dbname=fuzzphony;user=fuzzphony;password=fuzzphony"
  # ... run tests ...
  docker rm -fv fz-v04-pg
  ```

- Standard verification block (referred to as **"the gate"** in every task; all of it must pass before the task's commit):

  ```bash
  vendor/bin/phpunit --testsuite=unit
  FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5452;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --testsuite=integration
  composer stan
  composer cs
  FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5452;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text --only-summary-for-coverage-text   # Lines: 100.00%
  FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5452;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/infection --threads=max --only-covering-test-cases --show-mutations=0 --git-diff-lines --git-diff-base=main   # no escaped mutant on changed lines
  ```

  (Use pcov instead of Xdebug if that is what the machine has: `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text`.)

## Plan-time decisions (spec gaps and contradictions, resolved)

1. **Function `search_path` hardening (R6).** The spec says generated functions get `SET search_path = pg_catalog, pg_temp`. That is right for `fuzzphony_norm` (its body only uses `pg_catalog` built-ins and the schema-qualified `unaccent`), but the refresh and sync functions embed the developer's source query and watch SQL, which name tables unqualified (`FROM product`); pinned to `pg_catalog` they would fail on every existing install. Resolution: `fuzzphony_norm` gets `SET search_path = pg_catalog, pg_temp`; refresh and sync functions get `SET search_path FROM CURRENT` (the `search_path` of the session that ran `schema --apply`, captured at creation). A caller's `search_path` still cannot redirect anything inside them, Fuzzphony's own references are schema-qualified anyway, and developer SQL resolves exactly as it did when the schema was applied. Documented in `docs/configuration.md` and UPGRADE.
2. **`CREATE SCHEMA IF NOT EXISTS` only for a non-`public` schema.** PostgreSQL checks the `CREATE` privilege on the database before it looks at `IF NOT EXISTS`, so emitting it for `public` would break `schema --apply` for roles that can create tables but not schemas. Default installs therefore see no new statement.
3. **Hash inputs (R7).** The spec's `definition_hash` list omits the source and the boost/recency columns, yet the source is embedded in the refresh function and boost/recency add sidecar columns, so both shape DDL. They are included. `tenant` stays in (spec), although it shapes no DDL.
4. **The layout upgrade-step runner (R7)** ships as `PostgresSchemaGenerator::LAYOUT_VERSION = 1` plus the stored `layout_version` and the doctor's comparison. A step runner with zero steps would be untestable dead code under the 100% line-coverage floor, so the first milestone with a step (0.5) adds the runner together with its first step; the constant's docblock says so.
5. **Recording a reindex needs an engine method.** `Reindexer` is engine-agnostic, so `Engine` gains `recordReindex(IndexDefinition $index): void` (PostgreSQL: updates `fuzzphony_meta`; a custom engine may do nothing). It is called after a full run (no `resumeAfter`) unless the run skipped pruning because the source was empty. This is a breaking change for custom engines and is listed under Breaking.
6. **"No reindex needed" vs. the doctor.** After upgrading, `schema --apply` writes the meta row with `documents_hash = NULL`, so the doctor's "Documents" check warns until the next full reindex (spec: "missing → warning"). Search works without it. UPGRADE says so, and tells `doctor --strict` users in CI to run one full `fuzzphony:reindex`.
7. **`library_version`.** No version constant exists. The generator reads it from Composer: `InstalledVersions::getPrettyVersion()` of `fuzzphony/fuzzphony`, or of `fuzzphony/postgres-engine` when installed split (the demo), falling back to `unknown`. No release step has to bump anything.
8. **Public API list (R4).** Public method signatures of listed classes expose types the spec's list lacks; they are public too, otherwise callers and custom engines could not legally use them: `Schema\SchemaPlan`, `Schema\Statement` (`Fuzzphony::schema()`, `Engine`), `Engine\Capabilities`, `Engine\Capability` (`Engine::capabilities()`), `Query\SearchQuery`, `Query\Filter\Condition`, `Query\Filter\Operator` (`Engine::search()`, `SearchBuilder::where()`), `Definition\Source` (`IndexDefinition::$source`, `withSource()`), `Wizard\TableProfile`, `ColumnProfile`, `ColumnKind`, `ForeignKey` (`SourceIntrospector::describe()`, `DefinitionSuggester::suggest()`). With the spec's list that makes 69 public classes and 51 internal ones (of 120 after this milestone), not "about 80" internal; the CHANGELOG states the real number. The demo (`App\`) and `benchmarks/` are not analysed by PHPStan, and PHPStan's `@internal` rule never fires inside the `Fuzzphony` root namespace (tests included), so `PublicApiTest` checks that the demo and the benchmark import only public classes.
9. **`IndexArgument`** (bundle) throws Symfony Console's `InvalidArgumentException` today, which does not implement `FuzzphonyException`. It becomes `InvalidConfiguration` (no index configured), per R1's "everything src/ throws".
10. **`Sql::float()` non-finite** is reachable from caller input (`thresholds(['min_score' => INF])`), so it is `InvalidArgument`, not an internal `\LogicException`.
11. **`Weight::parse()`** stays (public enum), but `IndexBuilder::field()` no longer uses it: the builder parses every enum itself so the message names the index and the field. `Weight::parse()` throws `InvalidDefinition('weight', …)`, like `RankingProfile`'s `'ranking'` and `Thresholds`' `'thresholds'`.
12. **Doctor output order.** The new "Schema version", "Definition" and "Documents" checks go at the **end** of the report, so existing tests and scripts that read `problems()[0]` keep seeing the same first problem.
13. **Legacy-location warning (R6).** Per index: when the configured schema is not `public`, the index's sidecar is missing there and `"public"."fuzzphony_<index>"` exists, the doctor warns ("Schema" check) and points to UPGRADE.
14. **Integration test with the schema off the `search_path` (R6).** Searching, queue processing and TRUNCATE sync run with `SET search_path TO pg_catalog` (neither Fuzzphony's schema nor `public` visible). Highlighting and `sourceIds()` run the developer's source query in the caller's session, so they need the source tables on the caller's `search_path` as before; `docs/configuration.md` says so.
15. **Task 5 of the spec order is split in two** (a reviewer could reject either independently): Task 5 qualifies all SQL through `Names` and adds the engine argument; Task 6 adds namespace-aware doctor lookups, the legacy warning, the introspector and the bundle key.

## Review Focus

The five inputs the spec implies but no ruling tests, most likely to bite first; each has a test in the owning task:

1. **Upgrading a 0.3 install without setting `schema`**: `schema --apply` must emit no `CREATE SCHEMA` (a role without `CREATE` on the database must still be able to apply) and create nothing outside `public`. → Task 5, `SchemaGeneratorTest::testTheDefaultSchemaNeedsNoCreateSchema`.
2. **Awkward schema names** (`Fuzzphony` mixed case, `pg_x`, `a.b`, `bad name`): rejected with `InvalidConfiguration`, or quoted consistently in DDL and compared by exact `nspname` in catalog lookups (no case folding by `::regnamespace` on an unquoted literal). → Task 5, `NamesTest::testAnInvalidSchemaIsAConfigurationError` and `SchemaGeneratorTest::testCatalogLookupsCompareTheExactSchemaName`.
3. **A caller session whose `search_path` contains neither the schema nor `public`** (poolers, `SET search_path` per tenant): search, the worker and TRUNCATE sync still work. → Task 5, `DedicatedSchemaTest` with `SET search_path TO pg_catalog`.
4. **A meta row written by a newer Fuzzphony (downgrade / mixed deploy)**: the doctor reports an error naming both layouts, it does not crash or claim "older". → Task 7, `MetaTableTest::testTheDoctorReportsEachKindOfDrift`.
5. **`fuzzphony:schema --drop` on an install that has no meta table yet (pre-0.4) and a reindex before the first 0.4 apply**: neither fails (both statements are guarded by `to_regclass`). → Task 7, `MetaTableTest::testDropForgetsTheRowAndWorksWithoutTheTable` and `testAReindexBeforeTheFirstApplyDoesNotFail`.

---

## File Structure

Created:

| File | Responsibility | Task |
|---|---|---|
| `src/Core/Exception/InvalidArgument.php` | wrong runtime argument from a developer | 1 |
| `src/Core/Exception/InvalidConfiguration.php` | wrong engine / bundle configuration | 1 |
| `src/Core/Definition/EnumOption.php` | `@internal` enum parsing with "Allowed: …" messages | 1 |
| `src/Core/Sync/ReindexOptions.php`, `src/Core/Sync/ReindexResult.php` | reindex input / output value objects | 3 |
| `src/Engine/Postgres/Schema/Names.php` | `@internal` every object name, qualified + quoted, 63-byte limit | 4 (schema: 5, meta: 7) |
| `src/Engine/Postgres/Schema/Types.php` | `@internal` SQL types of ids and filters, compatible source types | 4 |
| `src/Engine/Postgres/Schema/Fingerprint.php` | `@internal` definition / documents / shared hashes | 7 |
| `src/Bridge/Doctrine/SchemaAssetFilter.php` | `@internal` the DBAL `schema_filter` regex | 8 |
| tests: `tests/Unit/Core/Definition/IndexBuilderTest.php`, `WeightTest.php`, `tests/Unit/Core/Schema/SchemaPlanTest.php`, `tests/Unit/Postgres/PostgresEngineGuardTest.php`, `tests/Unit/Core/Sync/ReindexOptionsTest.php`, `tests/Unit/Postgres/NamesTest.php`, `TypesTest.php`, `FingerprintTest.php`, `tests/Unit/Bridge/Doctrine/SchemaAssetFilterTest.php`, `tests/Unit/PublicApiTest.php`, `tests/Integration/DedicatedSchemaTest.php`, `tests/Integration/MetaTableTest.php`, `tests/Integration/Bridge/SchemaFilterTest.php` | | |

Deleted: `src/Core/Engine/Analyzer.php` (Task 9).

Modified (main ones): `IndexDefinition`, `IndexBuilder`, `ArrayDefinitionLoader`, `Weight`, `TextConfig`, `IdType`, `FilterType`, `RankingProfile`, `Identifier`, `SchemaPlan`, `Fuzzphony`, `Reindexer`, `Engine`, `PostgresEngine`, `PostgresSchemaGenerator`, `SearchSqlBuilder`, `FuzzyQueryCompiler`, `Highlighter`, `PostgresInspector`, `PostgresIntrospector`, `Sql`, `DoctrineNamingStrategy`, `AttributeExporter`, `FuzzphonyBundle`, `SchemaCommand`, `ReindexCommand`, `DoctorCommand`, `WizardCommand`, `IndexArgument`, `benchmarks/run.php`, `composer.json`, `src/Bundle/composer.json`, the demo (Task 10), `CHANGELOG.md`, `UPGRADE.md`, `README.md`, `CONTRIBUTING.md`, `docs/{architecture,configuration,commands,integrations,languages,sync}.md`, `demo/README.md`.

---

### Task 1: Exceptions (R1)

**Files:**
- Create: `src/Core/Exception/InvalidArgument.php`, `src/Core/Exception/InvalidConfiguration.php`, `src/Core/Definition/EnumOption.php`
- Modify: `src/Core/Definition/IndexBuilder.php:68-119`, `src/Core/Definition/Weight.php:15-18`, `src/Core/Definition/ArrayDefinitionLoader.php:75-80`, `src/Bridge/Doctrine/DoctrineNamingStrategy.php:41-43`, `src/Bundle/Command/SchemaCommand.php:268-274,300-351`, `src/Bundle/FuzzphonyBundle.php:151`, `src/Bundle/Command/IndexArgument.php:9,20`, `src/Core/Sync/Reindexer.php:43-45`, `src/Core/Wizard/Export/AttributeExporter.php:25-27`, `src/Core/Schema/SchemaPlan.php:21-36`, `src/Engine/Postgres/PostgresEngine.php:105-113,131-146,150-152,227-238,313-321`, `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php:32-37`, `src/Engine/Postgres/Sql/Sql.php:22-30`, `src/Engine/Postgres/Wizard/PostgresIntrospector.php:48-50`
- Test: create `tests/Unit/Core/Definition/IndexBuilderTest.php`, `tests/Unit/Core/Definition/WeightTest.php`, `tests/Unit/Core/Schema/SchemaPlanTest.php`, `tests/Unit/Postgres/PostgresEngineGuardTest.php`; modify `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php`, `tests/Unit/Bridge/Doctrine/DoctrineNamingStrategyTest.php:53`, `tests/Unit/Bundle/Command/IndexArgumentTest.php:12,24`, `tests/Unit/Core/Sync/ReindexerTest.php:19`, `tests/Unit/Core/Wizard/ExportersTest.php:188`, `tests/Unit/Postgres/SchemaGeneratorTest.php:20`, `tests/Unit/Postgres/SqlTest.php:14`, `tests/Integration/PostgresEngineTest.php:380`, `tests/Integration/WizardTest.php:52`, `tests/Integration/Command/WizardCommandTest.php:91`, `tests/Integration/Command/SchemaCommandTest.php:79-97`
- Docs: `CHANGELOG.md` (Breaking), `UPGRADE.md` (From 0.3 to 0.4, item "Exceptions")

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `final class Fuzzphony\Core\Exception\InvalidArgument extends \InvalidArgumentException implements FuzzphonyException {}`
  - `final class Fuzzphony\Core\Exception\InvalidConfiguration extends \InvalidArgumentException implements FuzzphonyException {}`
  - `Fuzzphony\Core\Definition\EnumOption::parse(string $enum, string $value, string $index, string $what, string $context = ''): \BackedEnum` (generic: `@template T of \BackedEnum`, `@param class-string<T> $enum`, `@return T`); throws `InvalidDefinition($index, ['Unknown <what> "<value>"<context>. Allowed: a, b, c.'])`.
  - `SchemaPlan::apply()` wraps a failing statement as `EngineFailure` with operation `schema statement "<description>"`.
  - `PostgresEngine` guard operation names: `source ids`, `queue size`, `explain`, `highlighting`, `inspection` (existing: `refresh`, `orphan pruning`, `queue processing`, `search`).

- [ ] **Step 1: Write the failing tests for the new exception types and enum parsing**

`tests/Unit/Core/Definition/IndexBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexBuilderTest extends TestCase
{
    /** @return iterable<string, array{\Closure(IndexBuilder): IndexBuilder, string}> */
    public static function typos(): iterable
    {
        yield 'weight' => [static fn(IndexBuilder $b): IndexBuilder => $b->field('name', 'e'), 'Unknown weight "E" of field "name". Allowed: A, B, C, D.'];
        yield 'filter type' => [static fn(IndexBuilder $b): IndexBuilder => $b->filter('price', 'integer'), 'Unknown filter type "integer" of filter "price". Allowed: bool, int, float, string, date, datetime.'];
        yield 'id type' => [static fn(IndexBuilder $b): IndexBuilder => $b->idType('bigint'), 'Unknown id type "bigint". Allowed: int, uuid, string.'];
        yield 'sync mode' => [static fn(IndexBuilder $b): IndexBuilder => $b->sync('realtime'), 'Unknown sync mode "realtime". Allowed: orm, trigger, queue, manual.'];
        yield 'trigger level' => [static fn(IndexBuilder $b): IndexBuilder => $b->triggerLevel('each'), 'Unknown trigger level "each". Allowed: statement, row.'];
    }

    /** @param \Closure(IndexBuilder): IndexBuilder $typo */
    #[DataProvider('typos')]
    public function testAnEnumTypoNamesTheIndexAndTheAllowedValues(\Closure $typo, string $message): void
    {
        try {
            $typo(IndexDefinition::builder('products'));
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertSame('products', $e->index);
            self::assertSame([$message], $e->violations);
        }
    }

    public function testValidStringsAndCasesAreAccepted(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name', 'a')
            ->filter('price', 'int')
            ->idType('uuid')
            ->sync('manual')
            ->triggerLevel('row')
            ->build();

        self::assertSame('A', $definition->fields[0]->weight->value);
        self::assertSame('int', $definition->filters[0]->type->value);
        self::assertSame('uuid', $definition->idType->value);
        self::assertSame('manual', $definition->sync->value);
        self::assertSame('row', $definition->triggerLevel->value);
    }
}
```

`tests/Unit/Core/Definition/WeightTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\TestCase;

final class WeightTest extends TestCase
{
    public function testParseAcceptsCasesAndLowercaseLabels(): void
    {
        self::assertSame(Weight::C, Weight::parse(Weight::C));
        self::assertSame(Weight::B, Weight::parse('b'));
    }

    public function testParseRejectsAnUnknownLabelWithTheAllowedOnes(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Unknown weight "E". Allowed: A, B, C, D.');

        Weight::parse('e');
    }
}
```

Append to `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php` (add `use Fuzzphony\Core\Definition\ArrayDefinitionLoader;` etc. as already imported there; `Product` fixture is imported):

```php
    public function testAnOverrideTypoInSyncOrTriggerLevelIsAnInvalidDefinition(): void
    {
        $loader = new ArrayDefinitionLoader();
        $attributes = (new AttributeDefinitionLoader())->load(Product::class);

        try {
            $loader->override($attributes, ['sync' => 'realtime']);
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertSame($attributes->name, $e->index);
            self::assertSame(['Unknown sync mode "realtime". Allowed: orm, trigger, queue, manual.'], $e->violations);
        }

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Unknown trigger level "each". Allowed: statement, row.');
        $loader->override($attributes, ['trigger_level' => 'each']);
    }
```

`tests/Unit/Core/Schema/SchemaPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Schema;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaPlanTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function transactionality(): iterable
    {
        yield 'inside the transaction' => [true];
        yield 'after the transaction' => [false];
    }

    #[DataProvider('transactionality')]
    public function testADatabaseErrorNamesTheFailingStatement(bool $transactional): void
    {
        $connection = new class implements Connection {
            /** @var list<string> */
            public array $executed = [];

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                if ($sql === 'BROKEN') {
                    throw new \PDOException('syntax error at or near "BROKEN"');
                }
                $this->executed[] = $sql;

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
        $plan = new SchemaPlan([new Statement('SELECT 1', 'first'), new Statement('BROKEN', 'second', $transactional)]);

        try {
            $plan->apply($connection);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame(
                "Fuzzphony schema statement \"second\" failed: syntax error at or near \"BROKEN\"\n"
                . 'Hint: Review the SQL with "bin/console fuzzphony:schema" (without --apply); every statement is safe to re-run.',
                $e->getMessage(),
            );
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
        self::assertSame(['SELECT 1'], $connection->executed);
    }
}
```

`tests/Unit/Postgres/PostgresEngineGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every database call of the engine arrives as EngineFailure, with the driver's exception kept. */
final class PostgresEngineGuardTest extends TestCase
{
    private const string DOCTOR = 'Run "bin/console fuzzphony:doctor" to check the index.';

    /** @return iterable<string, array{string, string, \Closure(string): bool, \Closure(PostgresEngine): mixed}> */
    public static function operations(): iterable
    {
        $always = static fn(string $sql): bool => true;
        yield 'source ids' => ['source ids', 'Run "bin/console fuzzphony:doctor": it checks that the source can be queried.', $always, static fn(PostgresEngine $e): mixed => $e->sourceIds(Indexes::products(), null, 10)];
        yield 'queue size' => ['queue size', 'Run "fuzzphony:schema --apply" to create the queue table.', $always, static fn(PostgresEngine $e): mixed => $e->queueSize(Indexes::products())];
        yield 'inspection' => ['inspection', 'Check that this connection can read the catalog and the source.', $always, static fn(PostgresEngine $e): mixed => $e->inspect(Indexes::products())];
        yield 'explain' => ['explain', self::DOCTOR, static fn(string $sql): bool => str_starts_with($sql, 'EXPLAIN'), static fn(PostgresEngine $e): mixed => self::fuzzphony($e)->in('products')->query('mouse')->explain()];
        yield 'highlighting' => ['highlighting', self::DOCTOR, static fn(string $sql): bool => str_contains($sql, 'ts_headline'), static fn(PostgresEngine $e): mixed => self::fuzzphony($e)->in('products')->query('mouse')->highlight('name')->get()];
    }

    /**
     * @param \Closure(string): bool           $failsOn
     * @param \Closure(PostgresEngine): mixed  $call
     */
    #[DataProvider('operations')]
    public function testADriverErrorArrivesAsEngineFailure(string $operation, string $hint, \Closure $failsOn, \Closure $call): void
    {
        $engine = new PostgresEngine(self::connection($failsOn));

        try {
            $call($engine);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame(sprintf("Fuzzphony %s failed: boom\nHint: %s", $operation, $hint), $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    private static function fuzzphony(PostgresEngine $engine): Fuzzphony
    {
        return new Fuzzphony($engine, new IndexRegistry([Indexes::products()]));
    }

    /**
     * A connection that throws \PDOException('boom') for every statement $failsOn selects; a
     * ranked search statement returns one hit, everything else nothing.
     *
     * @param \Closure(string): bool $failsOn
     */
    private static function connection(\Closure $failsOn): Connection
    {
        return new class ($failsOn) implements Connection {
            /** @param \Closure(string): bool $failsOn */
            public function __construct(private readonly \Closure $failsOn) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->check($sql);

                return str_starts_with($sql, 'WITH q AS') ? [[
                    'total' => 1, 'fts_n' => 1, 'fuzzy_n' => 0, 'id' => '1', 'score' => 1.0, 'r_text' => 1.0, 'r_fuzzy' => 0.0,
                    'relevance' => 1.0, 'exact_bonus' => 0.0, 'prefix_bonus' => 0.0, 'boost_bonus' => 0.0, 'recency_bonus' => 0.0,
                ]] : [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->check($sql);

                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->check($sql);

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }

            private function check(string $sql): void
            {
                if (($this->failsOn)($sql)) {
                    throw new \PDOException('boom');
                }
            }
        };
    }
}
```

Change the existing expectations (class only, messages unchanged unless stated):

- `DoctrineNamingStrategyTest.php:53`: `\LogicException::class` → `InvalidDefinition::class` (import `Fuzzphony\Core\Exception\InvalidDefinition`); keep the `expectExceptionMessage` (it is a substring of the new message).
- `IndexArgumentTest.php`: import `Fuzzphony\Core\Exception\InvalidConfiguration` instead of `Symfony\Component\Console\Exception\InvalidArgumentException`; expect `InvalidConfiguration::class`.
- `ReindexerTest.php:19`, `ExportersTest.php:188` (was `\LogicException`), `SqlTest.php:14`, `PostgresEngineTest.php:380`, `WizardTest.php:52`, `WizardCommandTest.php:91`: expect `InvalidArgument::class` (import `Fuzzphony\Core\Exception\InvalidArgument`).
- `SchemaGeneratorTest.php:20`: expect `InvalidConfiguration::class`.
- `SchemaCommandTest::testDumpMigrationFailsCleanlyWhenTheDirectoryCannotBeCreated` becomes:

```php
    public function testDumpMigrationFailsCleanlyWhenTheDirectoryCannotBeCreated(): void
    {
        // A plain file already occupies that path, so mkdir() cannot turn it into a directory.
        // mkdir() raises a PHP warning on failure, which a temporary error handler swallows.
        $path = sys_get_temp_dir() . '/fuzzphony-schema-command-test-blocked-' . bin2hex(random_bytes(4));
        file_put_contents($path, 'not a directory');
        set_error_handler(static fn(int $errno, string $errstr): bool => str_contains($errstr, 'mkdir()'));
        try {
            $status = $this->tester->execute(['--dump-migration' => $path], ['interactive' => false]);
        } finally {
            restore_error_handler();
            unlink($path);
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Cannot create directory', $this->tester->getDisplay());
    }
```

(import `Symfony\Component\Console\Command\Command` if the file does not yet.)

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter 'IndexBuilderTest|WeightTest|SchemaPlanTest|PostgresEngineGuardTest|ArrayDefinitionLoaderTest|DoctrineNamingStrategyTest|IndexArgumentTest|ReindexerTest|ExportersTest|SchemaGeneratorTest|SqlTest'`
Expected: FAIL — `Class "Fuzzphony\Core\Exception\InvalidArgument" not found`, `\ValueError` instead of `InvalidDefinition`, `PDOException` instead of `EngineFailure`.

- [ ] **Step 3: Add the exception classes and `EnumOption`**

`src/Core/Exception/InvalidArgument.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** A runtime argument a developer passed is wrong: a batch size below 1, an unknown table, an exporter that cannot express the definition. */
final class InvalidArgument extends \InvalidArgumentException implements FuzzphonyException {}
```

`src/Core/Exception/InvalidConfiguration.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** Engine or bundle configuration is wrong: an invalid schema name, orm_sync.async without Messenger, no index configured. */
final class InvalidConfiguration extends \InvalidArgumentException implements FuzzphonyException {}
```

`src/Core/Definition/EnumOption.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;

/** @internal Parses a developer-supplied enum value (builder strings, YAML) and names the allowed values on a typo. */
final class EnumOption
{
    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    public static function parse(string $enum, string $value, string $index, string $what, string $context = ''): \BackedEnum
    {
        /** @var T|null $case */
        $case = $enum::tryFrom($value);
        if ($case === null) {
            throw new InvalidDefinition($index, [sprintf(
                'Unknown %s "%s"%s. Allowed: %s.',
                $what,
                $value,
                $context,
                implode(', ', array_map(static fn(\BackedEnum $c): string => (string) $c->value, $enum::cases())),
            )]);
        }

        return $case;
    }
}
```

- [ ] **Step 4: Use `EnumOption` in the builder, `Weight::parse()` and the YAML override**

`IndexBuilder` (add nothing to `use`, same namespace):

```php
    public function field(string $name, Weight|string $weight = Weight::B, bool $fuzzy = false, bool $highlight = true, ?string $column = null): self
    {
        $weight = $weight instanceof Weight ? $weight : EnumOption::parse(Weight::class, strtoupper($weight), $this->name, 'weight', sprintf(' of field "%s"', $name));
        $this->fields[] = new FieldDefinition($name, $weight, $fuzzy, $highlight, $column);

        return $this;
    }

    public function filter(string $name, FilterType|string $type, ?string $column = null): self
    {
        $type = $type instanceof FilterType ? $type : EnumOption::parse(FilterType::class, $type, $this->name, 'filter type', sprintf(' of filter "%s"', $name));
        $this->filters[] = new FilterDefinition($name, $type, $column);

        return $this;
    }
```

`idType()`, `sync()`, `triggerLevel()`: replace `X::from($value)` with `EnumOption::parse(IdType::class, $type, $this->name, 'id type')`, `EnumOption::parse(SyncMode::class, $mode, $this->name, 'sync mode')`, `EnumOption::parse(TriggerLevel::class, $level, $this->name, 'trigger level')`.

`Weight::parse()`:

```php
    public static function parse(self|string $value): self
    {
        return $value instanceof self ? $value : EnumOption::parse(self::class, strtoupper($value), 'weight', 'weight');
    }
```

`ArrayDefinitionLoader::override()` lines 75-80:

```php
        if (isset($config['sync'])) {
            $changes['sync'] = EnumOption::parse(SyncMode::class, self::str($config['sync'], ''), $definition->name, 'sync mode');
        }
        if (isset($config['trigger_level'])) {
            $changes['triggerLevel'] = EnumOption::parse(TriggerLevel::class, self::str($config['trigger_level'], ''), $definition->name, 'trigger level');
        }
```

(Task 2 rewrites the rest of `override()`.)

- [ ] **Step 5: Replace the bare SPL throws**

- `DoctrineNamingStrategy::idColumn()`: `throw new InvalidDefinition($class, [sprintf('%s has a composite identifier; Fuzzphony indexes need a single-column id.', $class)]);` (import `Fuzzphony\Core\Exception\InvalidDefinition`).
- `FuzzphonyBundle:151`: `throw new InvalidConfiguration('fuzzphony.orm_sync.async requires symfony/messenger: composer require symfony/messenger'); // @codeCoverageIgnore` (the ignore is pre-existing, the line cannot run with Messenger installed).
- `IndexArgument`: import `Fuzzphony\Core\Exception\InvalidConfiguration` (drop the Symfony import), `throw new InvalidConfiguration('No search index is configured. Add #[Searchable] to an entity or define one under "fuzzphony.indexes".');`.
- `Reindexer:44`: `throw new InvalidArgument('Batch size must be >= 1.');`
- `AttributeExporter:26`: `throw new InvalidArgument('Only table sources without extra watches can be expressed with attributes; export YAML instead.');`
- `PostgresEngine:151`: `throw new InvalidArgument('Batch size must be >= 1.');`
- `PostgresSchemaGenerator:35`: `throw new InvalidConfiguration(sprintf('Invalid extension schema "%s".', $extensionSchema));`
- `Sql::float()`: `throw new InvalidArgument('Non-finite number in SQL.');`
- `PostgresIntrospector:49`: `throw new InvalidArgument(sprintf('Table "%s" does not exist (or is not visible on the search_path).', $table));`
- `SearchSqlBuilder.php:90,149` stay `\LogicException` (internal invariants).

`SchemaCommand`: `writeMigration()` returns `?string` and `null` when the directory cannot be created; `execute()`:

```php
        $directory = $input->getOption('dump-migration');
        if (is_string($directory)) {
            $file = $this->writeMigration($plan, $directory, Coerce::str($input->getOption('namespace')));
            if ($file === null) {
                $io->error(sprintf('Cannot create directory "%s".', $directory));

                return Command::FAILURE;
            }
            $io->success(sprintf('Migration written to %s (non-transactional, because indexes are built concurrently).', $file));

            return Command::SUCCESS;
        }
```

and in `writeMigration()`:

```php
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return null;
        }
```

- [ ] **Step 6: Wrap `SchemaPlan::apply()` and the unguarded engine calls**

`SchemaPlan` (import `Fuzzphony\Core\Exception\EngineFailure`):

```php
    /** Transactional statements run in one transaction; the rest (concurrent index builds) run after it. */
    public function apply(Connection $connection, ?callable $onStatement = null): void
    {
        $transactional = array_filter($this->statements, static fn(Statement $s): bool => $s->transactional);
        $separate = array_filter($this->statements, static fn(Statement $s): bool => !$s->transactional);

        $connection->transactional(static function (Connection $c) use ($transactional, $onStatement): void {
            foreach ($transactional as $statement) {
                self::run($c, $statement, $onStatement);
            }
        });
        foreach ($separate as $statement) {
            self::run($connection, $statement, $onStatement);
        }
    }

    private static function run(Connection $connection, Statement $statement, ?callable $onStatement): void
    {
        $onStatement !== null && $onStatement($statement);
        try {
            $connection->execute($statement->sql);
        } catch (\Throwable $e) {
            throw EngineFailure::wrap(
                sprintf('schema statement "%s"', $statement->description),
                $e,
                'Review the SQL with "bin/console fuzzphony:schema" (without --apply); every statement is safe to re-run.',
            );
        }
    }
```

`PostgresEngine`:

```php
    // explain(): the EXPLAIN round trip
            $plan = $this->guard('explain', fn(): array => $this->connection->transactional(fn(Connection $c): array => self::withSimilarityThreshold(
                $c,
                $run['threshold'],
                static function () use ($c, $last, $analyze): array {
                    $rows = $c->fetchAll(($analyze ? 'EXPLAIN (ANALYZE, BUFFERS) ' : 'EXPLAIN ') . $last['sql'], $last['params']);

                    return array_map(static fn(array $row): string => Coerce::str(reset($row)), $rows);
                },
            )), 'Run "bin/console fuzzphony:doctor" to check the index.');

    // sourceIds()
        $rows = $this->guard('source ids', fn(): array => $this->connection->fetchAll(sprintf(
            'SELECT doc.fz_id::text AS id FROM (%s) AS doc %s ORDER BY doc.fz_id LIMIT :limit',
            DocumentSql::select($index),
            $where,
        ), $params), 'Run "bin/console fuzzphony:doctor": it checks that the source can be queried.');

    // queueSize()
        return $this->guard('queue size', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT count(*) FROM %s WHERE index_name = :index', PostgresSchemaGenerator::QUEUE_TABLE),
            ['index' => $index->name],
        )), 'Run "fuzzphony:schema --apply" to create the queue table.');

    // inspect()
        return $this->guard(
            'inspection',
            fn(): InspectionReport => (new PostgresInspector($this->connection, $this->schema))->inspect($index, $options),
            'Check that this connection can read the catalog and the source.',
        );

    // execute(): highlighting
            $highlights = $this->guard('highlighting', fn(): array => (new Highlighter($this->connection))->highlight(
                $index,
                $query->highlight,
                $tsquery,
                array_map(static fn(array $r): int|string => $index->idType->cast(Coerce::str($r['id'])), $rows),
            ), 'Run "bin/console fuzzphony:doctor" to check the index.');
```

(`$tsquery` is a `string` inside that `if`; if PHPStan loses the narrowing inside the arrow function, assign `$tsquery` to a local `string $headline` before it.)

- [ ] **Step 7: Run the unit tests**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 8: Docs**

`CHANGELOG.md`, new `### Breaking` directly under `## [Unreleased]`:

```markdown
### Breaking

- Every exception Fuzzphony throws implements `FuzzphonyException`, except `\LogicException` for
  internal invariants. New `InvalidArgument` (a wrong runtime argument: batch size below 1, an
  unknown table in the wizard, `AttributeExporter::export()` on a definition it cannot express, a
  non-finite number) and `InvalidConfiguration` (an invalid extension schema, `orm_sync.async`
  without Messenger, no index configured); both extend `\InvalidArgumentException`, so existing
  `catch (\InvalidArgumentException)` blocks still work. An enum typo in the builder or YAML
  (`sync: realtime`, `->field('name', 'E')`) is an `InvalidDefinition` naming the allowed values
  instead of a `\ValueError`; a composite Doctrine identifier is an `InvalidDefinition` instead of
  a `\LogicException`; `AttributeExporter::export()` on a joined source throws `InvalidArgument`
  instead of `\LogicException`. Driver errors from `sourceIds()`, `queueSize()`, `explain()`,
  highlighting, the doctor and `SchemaPlan::apply()` arrive as `EngineFailure` (the driver
  exception is its previous exception). `fuzzphony:schema --dump-migration` into a directory that
  cannot be created prints the error and exits 1 instead of throwing.
```

`UPGRADE.md`, new section above `## From 0.3.1 to 0.3.2`:

```markdown
## From 0.3 to 0.4

1. **Exceptions.** Catch `Fuzzphony\Core\Exception\FuzzphonyException` to handle everything
   Fuzzphony throws. If you caught `\ValueError` around definition building (a YAML or builder
   enum typo), catch `InvalidDefinition`; if you caught `\LogicException` from
   `AttributeExporter::export()` or `DoctrineNamingStrategy`, catch `InvalidArgument` /
   `InvalidDefinition`; if you caught `\PDOException` or DBAL exceptions around `explain()`,
   highlighting, the doctor or `SchemaPlan::apply()`, catch `EngineFailure` (the driver exception
   is `getPrevious()`). `catch (\InvalidArgumentException)` keeps working.
```

- [ ] **Step 9: Run the gate** (Global Constraints). Expected: all green, 100% lines, no escaped mutant on changed lines.

- [ ] **Step 10: Commit**

```bash
git add src/Core/Exception/InvalidArgument.php src/Core/Exception/InvalidConfiguration.php src/Core/Definition/EnumOption.php \
  src/Core/Definition/IndexBuilder.php src/Core/Definition/Weight.php src/Core/Definition/ArrayDefinitionLoader.php \
  src/Bridge/Doctrine/DoctrineNamingStrategy.php src/Bundle/Command/SchemaCommand.php src/Bundle/FuzzphonyBundle.php \
  src/Bundle/Command/IndexArgument.php src/Core/Sync/Reindexer.php src/Core/Wizard/Export/AttributeExporter.php \
  src/Core/Schema/SchemaPlan.php src/Engine/Postgres/PostgresEngine.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php \
  src/Engine/Postgres/Sql/Sql.php src/Engine/Postgres/Wizard/PostgresIntrospector.php \
  tests/Unit/Core/Definition/IndexBuilderTest.php tests/Unit/Core/Definition/WeightTest.php tests/Unit/Core/Schema/SchemaPlanTest.php \
  tests/Unit/Postgres/PostgresEngineGuardTest.php tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php \
  tests/Unit/Bridge/Doctrine/DoctrineNamingStrategyTest.php tests/Unit/Bundle/Command/IndexArgumentTest.php \
  tests/Unit/Core/Sync/ReindexerTest.php tests/Unit/Core/Wizard/ExportersTest.php tests/Unit/Postgres/SchemaGeneratorTest.php \
  tests/Unit/Postgres/SqlTest.php tests/Integration/PostgresEngineTest.php tests/Integration/WizardTest.php \
  tests/Integration/Command/WizardCommandTest.php tests/Integration/Command/SchemaCommandTest.php CHANGELOG.md UPGRADE.md
git commit -m "Throw only Fuzzphony exceptions from src/

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Typed withers (R2)

**Files:**
- Modify: `src/Core/Definition/IndexDefinition.php:124-217` (replace `with()`, `typedList()`, `typedMap()`), `src/Core/Definition/ArrayDefinitionLoader.php:71-108`, `src/Bundle/Command/WizardCommand.php:88`
- Test: rewrite `tests/Unit/Core/Definition/IndexDefinitionTest.php`; modify the callers of `with(`: `tests/Unit/Core/Sync/WorkerTest.php:19`, `tests/Unit/Core/Wizard/ExportersTest.php:55`, `tests/Unit/Core/Definition/DefinitionValidatorTest.php:71`, `tests/Unit/Postgres/SchemaGeneratorTest.php:70,83,116,149,159,283,328`, `tests/Integration/PostgresEngineTest.php:89,228,251,556,572`
- Docs: `CHANGELOG.md` (Breaking), `UPGRADE.md` (item "Withers")

**Interfaces:**
- Consumes: `EnumOption::parse()` (Task 1).
- Produces (all on `Fuzzphony\Core\Definition\IndexDefinition`, each returns `self`, none validates):
  `withName(string $name)`, `withSource(Source $source)`, `withFields(array $fields)` (`list<FieldDefinition>`), `withFilters(array $filters)` (`list<FilterDefinition>`), `withWatches(array $watches)` (`list<Watch>`), `withIdType(IdType $idType)`, `withSync(SyncMode $sync)`, `withText(TextConfig $text)`, `withBoostColumn(?string $column)`, `withRecencyColumn(?string $column)`, `withProfiles(array $profiles)` (`array<string, RankingProfile>`), `withThresholds(Thresholds $thresholds)`, `withEntityClass(?string $entityClass)` (`class-string|null`), `withTriggerLevel(TriggerLevel $level)`, `withTenant(?string $filter)`. `with()` is removed.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Core/Definition/IndexDefinitionTest.php` (replaces the file):

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Source;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionTest extends TestCase
{
    /** @return iterable<string, array{\Closure(IndexDefinition): IndexDefinition, string, mixed}> */
    public static function withers(): iterable
    {
        yield 'name' => [static fn(IndexDefinition $d): IndexDefinition => $d->withName('other'), 'name', 'other'];
        yield 'source' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSource(Source::table('fz_other')), 'source', Source::table('fz_other')];
        yield 'fields' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFields([new FieldDefinition('name', Weight::A)]), 'fields', [new FieldDefinition('name', Weight::A)]];
        yield 'filters' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFilters([new FilterDefinition('price', FilterType::Float)]), 'filters', [new FilterDefinition('price', FilterType::Float)]];
        yield 'watches' => [static fn(IndexDefinition $d): IndexDefinition => $d->withWatches([new Watch('fz_product')]), 'watches', [new Watch('fz_product')]];
        yield 'id type' => [static fn(IndexDefinition $d): IndexDefinition => $d->withIdType(IdType::Uuid), 'idType', IdType::Uuid];
        yield 'sync' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSync(SyncMode::Manual), 'sync', SyncMode::Manual];
        yield 'text' => [static fn(IndexDefinition $d): IndexDefinition => $d->withText(new TextConfig('german', false)), 'text', new TextConfig('german', false)];
        yield 'boost column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withBoostColumn('rank'), 'boostColumn', 'rank'];
        yield 'no boost column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withBoostColumn(null), 'boostColumn', null];
        yield 'recency column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withRecencyColumn('updated_at'), 'recencyColumn', 'updated_at'];
        yield 'no recency column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withRecencyColumn(null), 'recencyColumn', null];
        yield 'profiles' => [static fn(IndexDefinition $d): IndexDefinition => $d->withProfiles(['default' => new RankingProfile(text: 0.5)]), 'profiles', ['default' => new RankingProfile(text: 0.5)]];
        yield 'thresholds' => [static fn(IndexDefinition $d): IndexDefinition => $d->withThresholds(new Thresholds(minScore: 0.2)), 'thresholds', new Thresholds(minScore: 0.2)];
        yield 'entity class' => [static fn(IndexDefinition $d): IndexDefinition => $d->withEntityClass(Product::class), 'entityClass', Product::class];
        yield 'trigger level' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTriggerLevel(TriggerLevel::Row), 'triggerLevel', TriggerLevel::Row];
        yield 'tenant' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTenant('brand_id'), 'tenant', 'brand_id'];
    }

    /** @param \Closure(IndexDefinition): IndexDefinition $wither */
    #[DataProvider('withers')]
    public function testEachWitherReplacesOnlyItsOwnProperty(\Closure $wither, string $property, mixed $expected): void
    {
        $original = Indexes::products();

        $changed = $wither($original);

        self::assertNotSame($original, $changed);
        self::assertEquals($expected, get_object_vars($changed)[$property]);
        self::assertSame(
            array_diff_key(get_object_vars($original), [$property => true]),
            array_diff_key(get_object_vars($changed), [$property => true]),
            'every other property is the very same value',
        );
    }

    public function testNullableWithersCanClearAValue(): void
    {
        $withEntity = Indexes::products()->withEntityClass(Product::class)->withTenant('brand_id');

        self::assertNull($withEntity->withEntityClass(null)->entityClass);
        self::assertNull($withEntity->withTenant(null)->tenant);
    }
}
```

Replace every `->with(` call on an `IndexDefinition` in the test files listed above: `with(name: 'other')` → `withName('other')`, `with(entityClass: null)` → `withEntityClass(null)`, `with(watches: [...])` → `withWatches([...])`, `with(triggerLevel: $x)` → `withTriggerLevel($x)`, `with(sync: $x)` → `withSync($x)`, `with(thresholds: $x)` → `withThresholds($x)`, `with(triggerLevel: TriggerLevel::Row, tenant: null)` → `withTriggerLevel(TriggerLevel::Row)->withTenant(null)`.

- [ ] **Step 2: Run the test to see it fail**

Run: `vendor/bin/phpunit --filter IndexDefinitionTest`
Expected: FAIL with `Call to undefined method Fuzzphony\Core\Definition\IndexDefinition::withName()`.

- [ ] **Step 3: Implement the withers**

In `IndexDefinition`, delete `with()`, `typedList()` and `typedMap()` (lines 124-217) and add:

```php
    public function withName(string $name): self
    {
        return $this->copy(name: $name);
    }

    public function withSource(Source $source): self
    {
        return $this->copy(source: $source);
    }

    /** @param list<FieldDefinition> $fields */
    public function withFields(array $fields): self
    {
        return $this->copy(fields: $fields);
    }

    /** @param list<FilterDefinition> $filters */
    public function withFilters(array $filters): self
    {
        return $this->copy(filters: $filters);
    }

    /** @param list<Watch> $watches */
    public function withWatches(array $watches): self
    {
        return $this->copy(watches: $watches);
    }

    public function withIdType(IdType $idType): self
    {
        return $this->copy(idType: $idType);
    }

    public function withSync(SyncMode $sync): self
    {
        return $this->copy(sync: $sync);
    }

    public function withText(TextConfig $text): self
    {
        return $this->copy(text: $text);
    }

    public function withBoostColumn(?string $column): self
    {
        return $this->copy(boostColumn: $column);
    }

    public function withRecencyColumn(?string $column): self
    {
        return $this->copy(recencyColumn: $column);
    }

    /** @param array<string, RankingProfile> $profiles must contain "default" */
    public function withProfiles(array $profiles): self
    {
        return $this->copy(profiles: $profiles);
    }

    public function withThresholds(Thresholds $thresholds): self
    {
        return $this->copy(thresholds: $thresholds);
    }

    /** @param class-string|null $entityClass */
    public function withEntityClass(?string $entityClass): self
    {
        return $this->copy(entityClass: $entityClass);
    }

    public function withTriggerLevel(TriggerLevel $level): self
    {
        return $this->copy(triggerLevel: $level);
    }

    /** @param string|null $filter the tenant filter's name; null = not tenant-scoped */
    public function withTenant(?string $filter): self
    {
        return $this->copy(tenant: $filter);
    }

    /**
     * The one place a copy is made, through the constructor. null keeps an object/array value;
     * false keeps a nullable string (so null can clear it).
     *
     * @param list<FieldDefinition>|null         $fields
     * @param list<FilterDefinition>|null        $filters
     * @param list<Watch>|null                   $watches
     * @param array<string, RankingProfile>|null $profiles
     * @param class-string|false|null            $entityClass
     */
    private function copy(
        ?string $name = null,
        ?Source $source = null,
        ?array $fields = null,
        ?array $filters = null,
        ?array $watches = null,
        ?IdType $idType = null,
        ?SyncMode $sync = null,
        ?TextConfig $text = null,
        string|false|null $boostColumn = false,
        string|false|null $recencyColumn = false,
        ?array $profiles = null,
        ?Thresholds $thresholds = null,
        string|false|null $entityClass = false,
        ?TriggerLevel $triggerLevel = null,
        string|false|null $tenant = false,
    ): self {
        return new self(
            name: $name ?? $this->name,
            source: $source ?? $this->source,
            fields: $fields ?? $this->fields,
            filters: $filters ?? $this->filters,
            watches: $watches ?? $this->watches,
            idType: $idType ?? $this->idType,
            sync: $sync ?? $this->sync,
            text: $text ?? $this->text,
            boostColumn: $boostColumn === false ? $this->boostColumn : $boostColumn,
            recencyColumn: $recencyColumn === false ? $this->recencyColumn : $recencyColumn,
            profiles: $profiles ?? $this->profiles,
            thresholds: $thresholds ?? $this->thresholds,
            entityClass: $entityClass === false ? $this->entityClass : $entityClass,
            triggerLevel: $triggerLevel ?? $this->triggerLevel,
            tenant: $tenant === false ? $this->tenant : $tenant,
        );
    }
```

Class docblock: add the sentence "Withers return a changed copy and do not validate; `IndexRegistry::register()` validates every definition it accepts."

- [ ] **Step 4: `ArrayDefinitionLoader::override()` chains the withers**

```php
    /**
     * Applies YAML overrides on top of an attribute-based definition (attributes stay primary).
     *
     * @param array<string, mixed> $config
     */
    public function override(IndexDefinition $definition, array $config): IndexDefinition
    {
        $name = $definition->name;
        $this->assertKnownKeys($name, $config);
        $merged = $definition;
        if (isset($config['sync'])) {
            $merged = $merged->withSync(EnumOption::parse(SyncMode::class, self::str($config['sync'], ''), $name, 'sync mode'));
        }
        if (isset($config['trigger_level'])) {
            $merged = $merged->withTriggerLevel(EnumOption::parse(TriggerLevel::class, self::str($config['trigger_level'], ''), $name, 'trigger level'));
        }
        if (isset($config['language']) || isset($config['unaccent'])) {
            $merged = $merged->withText(new TextConfig(self::str($config['language'] ?? null, $definition->text->language), (bool) ($config['unaccent'] ?? $definition->text->unaccent)));
        }
        if (isset($config['boost'])) {
            $merged = $merged->withBoostColumn(self::str($config['boost'], ''));
        }
        if (isset($config['recency'])) {
            $merged = $merged->withRecencyColumn(self::str($config['recency'], ''));
        }
        if (isset($config['tenant'])) {
            $merged = $merged->withTenant(self::str($config['tenant'], ''));
        }
        if (isset($config['profiles'])) {
            $merged = $merged->withProfiles($this->profiles($config['profiles']) + $definition->profiles);
        }
        if (isset($config['thresholds'])) {
            $merged = $merged->withThresholds($definition->thresholds->with($this->map($config['thresholds'])));
        }
        $watches = $definition->watches;
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $this->map($options);
            $watches[] = new Watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($name, $options['columns'] ?? null));
        }
        $merged = $merged->withWatches($watches);
        DefinitionValidator::assertValid($merged);

        return $merged;
    }
```

`WizardCommand:88`: `$index = $index->withFields($kept);`

- [ ] **Step 5: Run the unit tests**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS (`ArrayDefinitionLoaderTest::testYamlOverridesAttributeIndexes` and `testYamlOverrideCanChangeTriggerLevelLanguageBoostRecencyAndWatches` still pass unchanged: behaviour is the same).

- [ ] **Step 6: Docs**

CHANGELOG `### Breaking`, append:

```markdown
- `IndexDefinition::with(...)` is removed. It accepted any named argument, ignored unknown keys
  and silently kept the old value on a wrong type. Use the typed withers instead: `withName()`,
  `withSource()`, `withFields()`, `withFilters()`, `withWatches()`, `withIdType()`, `withSync()`,
  `withText()`, `withBoostColumn()`, `withRecencyColumn()`, `withProfiles()`,
  `withThresholds()`, `withEntityClass()`, `withTriggerLevel()`, `withTenant()`. A wrong type is
  now a PHP `TypeError` at the call site.
```

UPGRADE "From 0.3 to 0.4", item 2:

```markdown
2. **`IndexDefinition::with()` is gone.** Replace each named argument with its wither, and chain
   them: `$definition->with(sync: SyncMode::Manual, tenant: null)` becomes
   `$definition->withSync(SyncMode::Manual)->withTenant(null)`. The names are the property names:
   `name`, `source`, `fields`, `filters`, `watches`, `idType`, `sync`, `text`, `boostColumn`,
   `recencyColumn`, `profiles`, `thresholds`, `entityClass`, `triggerLevel`, `tenant` →
   `withName()` … `withTenant()`. Withers do not validate; registering the definition does.
```

- [ ] **Step 7: Run the gate.**

- [ ] **Step 8: Commit**

```bash
git add src/Core/Definition/IndexDefinition.php src/Core/Definition/ArrayDefinitionLoader.php src/Bundle/Command/WizardCommand.php \
  tests/Unit/Core/Definition/IndexDefinitionTest.php tests/Unit/Core/Sync/WorkerTest.php tests/Unit/Core/Wizard/ExportersTest.php \
  tests/Unit/Core/Definition/DefinitionValidatorTest.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Integration/PostgresEngineTest.php \
  CHANGELOG.md UPGRADE.md
git commit -m "Replace IndexDefinition::with() with typed withers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `ReindexOptions` / `ReindexResult` (R3)

**Files:**
- Create: `src/Core/Sync/ReindexOptions.php`, `src/Core/Sync/ReindexResult.php`
- Modify: `src/Core/Sync/Reindexer.php` (whole `run()`), `src/Core/Fuzzphony.php:51-67`, `src/Bundle/Command/ReindexCommand.php:40-73`, `src/Bundle/Command/WizardCommand.php:127-130`, `benchmarks/run.php:45`
- Test: create `tests/Unit/Core/Sync/ReindexOptionsTest.php`; rewrite `tests/Unit/Core/Sync/ReindexerTest.php`, `tests/Integration/ReindexPruningTest.php`; modify `tests/Conformance/EngineConformanceTestCase.php:329-347`, `tests/Integration/WizardTest.php:41`
- Docs: `docs/sync.md:94-99`, `CHANGELOG.md` (Breaking), `UPGRADE.md` (item "Reindexing")

**Interfaces:**
- Consumes: `InvalidArgument` (Task 1).
- Produces:
  - `final readonly class Fuzzphony\Core\Sync\ReindexOptions { __construct(int $batchSize = 5_000, int|string|null $resumeAfter = null, bool $prune = true, bool $pruneEmpty = false, ?\Closure $onBatch = null) }` — `$onBatch` is `\Closure(int $processed, int|string $lastId): void`; `batchSize < 1` → `InvalidArgument('Batch size must be >= 1, got <n>.')`.
  - `final readonly class Fuzzphony\Core\Sync\ReindexResult { __construct(int $written, ?int $pruned = null, bool $pruneSkippedEmptySource = false) }`.
  - `Fuzzphony::reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult`.
  - `Reindexer::run(IndexDefinition $index, ReindexOptions $options): ReindexResult`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Core/Sync/ReindexOptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\ReindexResult;
use PHPUnit\Framework\TestCase;

final class ReindexOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new ReindexOptions();

        self::assertSame(5_000, $options->batchSize);
        self::assertNull($options->resumeAfter);
        self::assertTrue($options->prune);
        self::assertFalse($options->pruneEmpty);
        self::assertNull($options->onBatch);
        self::assertSame(1, (new ReindexOptions(batchSize: 1))->batchSize, 'one is the smallest batch');
    }

    public function testABatchSizeBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Batch size must be >= 1, got 0.');

        new ReindexOptions(batchSize: 0);
    }

    public function testResultDefaults(): void
    {
        $result = new ReindexResult(3);

        self::assertSame(3, $result->written);
        self::assertNull($result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource);
    }
}
```

`tests/Unit/Core/Sync/ReindexerTest.php` (replaces the file):

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Sync;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Sync\Reindexer;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReindexerTest extends TestCase
{
    public function testBatchesUntilAShortBatchAndReportsProgress(): void
    {
        $engine = $this->engine([[1, 2], [3]]);
        $engine->expects(self::exactly(2))->method('refresh')->willReturnOnConsecutiveCalls(2, 1);
        $engine->expects(self::once())->method('pruneOrphans')->with(self::anything(), 2)->willReturn(4);
        $progress = [];

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(
            batchSize: 2,
            onBatch: static function (int $processed, int|string $lastId) use (&$progress): void {
                $progress[] = [$processed, $lastId];
            },
        ));

        self::assertSame([[2, 2], [3, 3]], $progress);
        self::assertSame(3, $result->written);
        self::assertSame(4, $result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource);
    }

    public function testAResumedRunStartsAfterTheIdAndNeverPrunes(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('sourceIds')->with(self::anything(), 7, 5_000)->willReturn([8]);
        $engine->method('refresh')->willReturn(1);
        $engine->expects(self::never())->method('pruneOrphans');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(resumeAfter: 7));

        self::assertSame(1, $result->written);
        self::assertNull($result->pruned);
    }

    public function testPruneFalseSkipsPruning(): void
    {
        $engine = $this->engine([[1]]);
        $engine->method('refresh')->willReturn(1);
        $engine->expects(self::never())->method('pruneOrphans');

        self::assertNull((new Reindexer($engine))->run(Indexes::products(), new ReindexOptions(prune: false))->pruned);
    }

    public function testAnEmptySourceIsOnlyPrunedWhenAskedTo(): void
    {
        $engine = $this->engine([[]]);
        $engine->expects(self::never())->method('pruneOrphans');

        $result = (new Reindexer($engine))->run(Indexes::products(), new ReindexOptions());

        self::assertSame(0, $result->written);
        self::assertNull($result->pruned);
        self::assertTrue($result->pruneSkippedEmptySource);

        $forced = $this->engine([[]]);
        $forced->expects(self::once())->method('pruneOrphans')->willReturn(5);
        $result = (new Reindexer($forced))->run(Indexes::products(), new ReindexOptions(pruneEmpty: true));

        self::assertSame(5, $result->pruned);
        self::assertFalse($result->pruneSkippedEmptySource);
    }

    /**
     * @param list<list<int>> $batches what sourceIds() returns, call by call
     *
     * @return Engine&MockObject
     */
    private function engine(array $batches): Engine
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('sourceIds')->willReturnOnConsecutiveCalls(...$batches);

        return $engine;
    }
}
```

`tests/Integration/ReindexPruningTest.php` — replace the three tests' bodies (keep `setUp()` and `indexed()`; add `use Fuzzphony\Core\Sync\ReindexOptions;`):

```php
    public function testPrunesByDefaultAndReportsHowMany(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4'); // manual sync: 4 is an orphan

        $result = $this->context->fuzzphony->reindex('products');

        self::assertSame(1, $result->pruned);
        self::assertSame(4, $this->indexed());
    }

    public function testPruneFalseLeavesTheIndexAlone(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4');

        $result = $this->context->fuzzphony->reindex('products', new ReindexOptions(prune: false));

        self::assertNull($result->pruned);
        self::assertSame(5, $this->indexed());
    }

    public function testASourceWithoutRowsIsNotPrunedUnlessForced(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $result = $this->context->fuzzphony->reindex('products');

        self::assertSame(0, $result->written);
        self::assertTrue($result->pruneSkippedEmptySource);
        self::assertNull($result->pruned);
        self::assertSame(5, $this->indexed());

        $forced = $this->context->fuzzphony->reindex('products', new ReindexOptions(pruneEmpty: true));

        self::assertSame(5, $forced->pruned);
        self::assertSame(0, $this->indexed());
    }
```

`EngineConformanceTestCase::testFullReindexPrunesOrphansButAResumedOneDoesNot` (import `ReindexOptions`):

```php
    public function testFullReindexPrunesOrphansButAResumedOneDoesNot(): void
    {
        $index = $this->fuzzphony->registry()->get('products');
        $this->connection->execute('DELETE FROM fz_product WHERE id = 4');

        $resumed = (new Reindexer($this->engine))->run($index, new ReindexOptions(resumeAfter: 1));
        self::assertNull($resumed->pruned, 'a resumed run covers only part of the source');
        self::assertContains(4, $this->ids($this->fuzzphony->in('products')->get()));

        $full = (new Reindexer($this->engine))->run($index, new ReindexOptions());
        self::assertSame(1, $full->pruned);
        self::assertNotContains(4, $this->ids($this->fuzzphony->in('products')->get()));
    }
```

`WizardTest.php:41`: `self::assertSame(5, $fuzzphony->reindex('wizard_products')->written);`

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter 'ReindexOptionsTest|ReindexerTest'`
Expected: FAIL with `Class "Fuzzphony\Core\Sync\ReindexOptions" not found`.

- [ ] **Step 3: Implement**

`src/Core/Sync/ReindexOptions.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Exception\InvalidArgument;

/**
 * How Fuzzphony::reindex() runs. Pruning is relative to what THIS session sees: where it sees fewer
 * rows than the application (row-level security, a query source using current_setting(), another
 * search_path), pass prune: false. A full run whose source returns no row skips pruning unless
 * pruneEmpty is set (an empty source is far more likely a visibility problem than intent).
 */
final readonly class ReindexOptions
{
    /** @param (\Closure(int $processed, int|string $lastId): void)|null $onBatch called after every batch */
    public function __construct(
        public int $batchSize = 5_000,
        /** Resume after this source id (printed while running); a resumed run never prunes. */
        public int|string|null $resumeAfter = null,
        public bool $prune = true,
        public bool $pruneEmpty = false,
        public ?\Closure $onBatch = null,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgument(sprintf('Batch size must be >= 1, got %d.', $batchSize));
        }
    }
}
```

`src/Core/Sync/ReindexResult.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

/** What a reindex did. */
final readonly class ReindexResult
{
    public function __construct(
        /** Documents written. */
        public int $written,
        /** Orphaned documents removed; null when pruning did not run (resumed run, prune: false, empty source). */
        public ?int $pruned = null,
        /** True when a full run found no source row and therefore did not prune (see ReindexOptions::$pruneEmpty). */
        public bool $pruneSkippedEmptySource = false,
    ) {}
}
```

`Reindexer::run()` (the batch-size check moves to `ReindexOptions`; drop the `InvalidArgument` import; update the class docblock's `$prune`/`$pruneEmpty` wording to "ReindexOptions"):

```php
    public function run(IndexDefinition $index, ReindexOptions $options): ReindexResult
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
            $written += $this->engine->refresh($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($options->onBatch !== null) {
                ($options->onBatch)($written, $after);
            }
        } while (count($ids) === $options->batchSize);

        if ($options->resumeAfter !== null || !$options->prune) {
            return new ReindexResult($written);
        }
        if ($seen === 0 && !$options->pruneEmpty) {
            return new ReindexResult($written, pruneSkippedEmptySource: true);
        }

        return new ReindexResult($written, $this->engine->pruneOrphans($index, $options->batchSize));
    }
```

`Fuzzphony::reindex()` (imports `ReindexOptions`, `ReindexResult`):

```php
    /**
     * Rebuilds the whole index from the source and, unless $options->prune is false, removes the
     * documents the source no longer returns. See ReindexOptions for resuming and pruning.
     */
    public function reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult
    {
        return (new Reindexer($this->engine))->run($this->registry->get($index), $options);
    }
```

`ReindexCommand::execute()` (drop the `Reindexer` import, import `ReindexOptions`):

```php
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, Coerce::int($input->getOption('batch')));
        $from = $input->getOption('from');
        $noPrune = $input->getOption('no-prune') === true;
        $pruneEmpty = $input->getOption('prune-empty') === true;

        foreach (IndexArgument::resolve($this->fuzzphony, $input) as $index) {
            $io->section(sprintf('Reindexing "%s"', $index->name));
            $started = microtime(true);
            $result = $this->fuzzphony->reindex($index->name, new ReindexOptions(
                batchSize: $batch,
                resumeAfter: is_string($from) ? $index->idType->cast($from) : null,
                prune: !$noPrune,
                pruneEmpty: $pruneEmpty,
                onBatch: static function (int $done, int|string $lastId) use ($io, $started): void {
                    $rate = $done / max(0.001, microtime(true) - $started);
                    $io->writeln(sprintf('  %s documents, %s/s, last id %s <comment>(resume: --from=%s)</comment>', number_format($done), number_format($rate), $lastId, $lastId));
                },
            ));
            $io->writeln(sprintf('  <info>%s documents in %.1fs</info>', number_format($result->written), microtime(true) - $started));
            $io->writeln(match (true) {
                $result->pruned !== null => sprintf('  %s orphaned document(s) removed (no longer in the source)', number_format($result->pruned)),
                $result->pruneSkippedEmptySource => '  <comment>The source returned no rows for this session, so nothing was pruned (row-level security or search_path? a TRUNCATE is handled by its trigger). Use --prune-empty to remove every indexed document anyway.</comment>',
                $noPrune => '  <comment>Pruning skipped (--no-prune).</comment>',
                default => '  <comment>Orphaned documents are only removed by a full run (without --from).</comment>',
            });
        }
        $io->success('Done. Tip: run fuzzphony:doctor to verify coverage.');

        return Command::SUCCESS;
    }
```

`WizardCommand::tryIt()` (import `ReindexOptions`):

```php
        $count = $fuzzphony->reindex($index->name, new ReindexOptions(onBatch: static function (int $done) use ($io): void {
            $io->write(sprintf("\r  indexed %s", number_format($done)));
        }))->written;
```

`benchmarks/run.php:45` (add `use Fuzzphony\Core\Sync\ReindexOptions;` next to the other imports):

```php
    $n = $fuzzphony->reindex('bench', new ReindexOptions(batchSize: 10_000, onBatch: static function (int $done): void { fwrite(STDERR, "\r  indexed " . number_format($done)); }))->written;
```

- [ ] **Step 4: Run the unit and integration tests**

Run: `vendor/bin/phpunit --testsuite=unit` then the integration suite (gate command). Expected: PASS, including `ReindexCommandTest` unchanged (same output lines).

- [ ] **Step 5: Docs**

`docs/sync.md` lines 94-99:

```markdown
`current_setting(...)`, a different `search_path` for the CLI user), a full reindex removes the
difference from the index. Pass `--no-prune` (or `new ReindexOptions(prune: false)` to
`$fuzzphony->reindex()`) there.

A full run whose source returns no row at all does not prune, and says so
(`ReindexResult::$pruneSkippedEmptySource`). That is far more likely a visibility problem than
intent (a real `TRUNCATE` is handled by its trigger). `--prune-empty`
(`new ReindexOptions(pruneEmpty: true)`) forces it.
```

CHANGELOG `### Breaking`, append:

```markdown
- `Fuzzphony::reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult`
  replaces the positional `$batchSize, $onBatch, $onPruned, $prune, $pruneEmpty, $onPruneSkipped`
  and the `int` return value; `Reindexer::run(IndexDefinition, ReindexOptions): ReindexResult`
  likewise. `ReindexResult` has `written`, `pruned` (null when pruning did not run) and
  `pruneSkippedEmptySource`, which replace the `onPruned` / `onPruneSkipped` callbacks. A batch size
  below 1 throws `InvalidArgument` when the options are created.
```

UPGRADE item 3:

````markdown
3. **Reindexing from PHP.** `reindex()` takes a `ReindexOptions` and returns a `ReindexResult`:

   ```php
   // 0.3
   $written = $fuzzphony->reindex('products', 10_000, $onBatch, onPruned: fn(int $n) => ..., prune: false);
   // 0.4
   $result = $fuzzphony->reindex('products', new ReindexOptions(batchSize: 10_000, onBatch: $onBatch(...), prune: false));
   $written = $result->written;
   $pruned = $result->pruned;                        // was onPruned; null when pruning did not run
   $skipped = $result->pruneSkippedEmptySource;      // was onPruneSkipped
   ```

   `onBatch` must be a `\Closure` (use `$callable(...)` for other callables). The console command
   is unchanged.
````

- [ ] **Step 6: Run the gate.**

- [ ] **Step 7: Commit**

```bash
git add src/Core/Sync/ReindexOptions.php src/Core/Sync/ReindexResult.php src/Core/Sync/Reindexer.php src/Core/Fuzzphony.php \
  src/Bundle/Command/ReindexCommand.php src/Bundle/Command/WizardCommand.php benchmarks/run.php \
  tests/Unit/Core/Sync/ReindexOptionsTest.php tests/Unit/Core/Sync/ReindexerTest.php tests/Integration/ReindexPruningTest.php \
  tests/Conformance/EngineConformanceTestCase.php tests/Integration/WizardTest.php docs/sync.md CHANGELOG.md UPGRADE.md
git commit -m "Reindex with ReindexOptions and return a ReindexResult

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `Names` and `Types`: PostgreSQL out of Core (R5)

A pure refactor: every generated statement stays **byte-identical** (the existing SQL assertions in `SchemaGeneratorTest`, `SearchSqlBuilderTest`, `FuzzyQueryCompilerTest` must pass unchanged). Task 5 then switches `Names` to schema-qualified output.

**Files:**
- Create: `src/Engine/Postgres/Schema/Names.php`, `src/Engine/Postgres/Schema/Types.php`
- Modify: `src/Core/Definition/IndexDefinition.php:48-51` (delete `sidecarTable()`), `src/Core/Definition/TextConfig.php:16-20` (delete `configName()`), `src/Core/Definition/IdType.php:13-20` (delete `sqlType()`), `src/Core/Definition/FilterType.php:49-76` (delete `sqlType()`, `compatibleSqlTypes()`), `src/Core/Ranking/RankingProfile.php:115-139` (delete `tsRankWeights()`, `num()`), `src/Core/Support/Identifier.php:41-49` (delete `limit()`), `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (throughout), `src/Engine/Postgres/PostgresEngine.php` (constructor + every name/type use), `src/Engine/Postgres/Sql/SearchSqlBuilder.php`, `src/Engine/Postgres/Sql/FuzzyQueryCompiler.php:16,54,167-168,244`, `src/Engine/Postgres/Highlighter.php:25,46,67`, `src/Engine/Postgres/Inspection/PostgresInspector.php` (throughout)
- Test: create `tests/Unit/Postgres/NamesTest.php`, `tests/Unit/Postgres/TypesTest.php`; modify `tests/Unit/Core/Definition/IdTypeTest.php:12-17`, `tests/Unit/Core/Ranking/RankingProfileTest.php:14-24`, `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php:34`, `tests/Unit/Core/Definition/AttributeDefinitionLoaderTest.php:29`, `tests/Unit/Core/Wizard/ExportersTest.php:110`, `tests/Unit/Postgres/SchemaGeneratorTest.php:18-24,213-215`, `tests/Unit/Postgres/SearchSqlBuilderTest.php` (new ts_rank test), `tests/Integration/ColumnAwareFilteringTest.php:53,61,95,103,143,146,151`, `tests/Integration/PostgresEngineTest.php:168`
- Docs: `CHANGELOG.md` (Breaking), `UPGRADE.md` (item "Removed Core methods")

**Interfaces:**
- Consumes: `InvalidConfiguration` (Task 1).
- Produces (`final readonly class Fuzzphony\Engine\Postgres\Schema\Names`, `@internal`):
  - `__construct(string $extensionSchema = 'public')` (Task 5 appends `string $schema = 'public'`), public promoted `$extensionSchema`; invalid → `InvalidConfiguration('Invalid extension schema "<x>".')`
  - `const int MAX_IDENTIFIER_BYTES = 63`; `static limit(string $name, int $max = self::MAX_IDENTIFIER_BYTES): string`
  - `extension(): string` (quoted extension schema), `sidecarName(IndexDefinition): string`, `sidecar(IndexDefinition): string`, `queue(): string`, `queueOrderIndex(): string`, `normFunction(): string`, `refreshFunctionName(IndexDefinition): string`, `refreshFunction(IndexDefinition): string`, `syncFunctionName(IndexDefinition, Watch): string`, `syncFunction(IndexDefinition, Watch): string`, `triggerName(IndexDefinition, Watch, string $suffix = ''): string`, `indexName(IndexDefinition, string $suffix): string`, `textConfigName(TextConfig): string`, `textConfig(TextConfig): string`, `regconfig(TextConfig): string` (a complete `'…'::regconfig` SQL literal), `stopDictionaryName(TextConfig): string`, `stopDictionary(TextConfig): string`. "…Name()" = bare name; the others = SQL.
  - `final class Types` (`@internal`): `static id(IdType): string`, `static filter(FilterType): string`, `static compatible(FilterType): list<string>`.
  - `PostgresSchemaGenerator::__construct(Names $names = new Names())`, `names(): Names`; constants `QUEUE_TABLE`, `NORM_FUNCTION` and methods `refreshFunctionName()`, `syncFunctionName()`, `stopDictionaryName()` removed (use `Names`); `stemDictionaryName()` stays.
  - `SearchSqlBuilder::__construct(IndexDefinition $index, Names $names = new Names())`, `FuzzyQueryCompiler::__construct(IndexDefinition $index, Thresholds $thresholds, Names $names = new Names())`, `Highlighter::__construct(Connection $connection, Names $names = new Names())`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Postgres/NamesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class NamesTest extends TestCase
{
    public function testObjectNames(): void
    {
        $names = new Names();
        $index = Indexes::products();
        $brand = new Watch('fz_brand');

        self::assertSame('"public"', $names->extension());
        self::assertSame('fuzzphony_products', $names->sidecarName($index));
        self::assertSame('"fuzzphony_products"', $names->sidecar($index));
        self::assertSame('fuzzphony_queue', $names->queue());
        self::assertSame('fuzzphony_queue_order', $names->queueOrderIndex());
        self::assertSame('fuzzphony_norm', $names->normFunction());
        self::assertSame('fuzzphony_refresh_products', $names->refreshFunctionName($index));
        self::assertSame('"fuzzphony_refresh_products"', $names->refreshFunction($index));
        self::assertSame('fuzzphony_sync_products__fz_brand', $names->syncFunctionName($index, $brand));
        self::assertSame('fuzzphony_sync_products__shop_brand', $names->syncFunctionName($index, new Watch('shop.brand')));
        self::assertSame('"fuzzphony_sync_products__fz_brand"', $names->syncFunction($index, $brand));
        self::assertSame('fuzzphony_sync_products__fz_brand', $names->triggerName($index, $brand));
        self::assertSame('fuzzphony_sync_products__fz_brand_ins', $names->triggerName($index, $brand, '_ins'));
        self::assertSame('fuzzphony_products_tsv', $names->indexName($index, 'tsv'));
    }

    public function testTextSearchNames(): void
    {
        $names = new Names();

        self::assertSame('fuzzphony_german', $names->textConfigName(new TextConfig('german')));
        self::assertSame('german', $names->textConfigName(new TextConfig('german', unaccent: false)));
        self::assertSame('"fuzzphony_german"', $names->textConfig(new TextConfig('german')));
        self::assertSame('"german"', $names->textConfig(new TextConfig('german', unaccent: false)));
        self::assertSame("'fuzzphony_english'::regconfig", $names->regconfig(new TextConfig()));
        self::assertSame("'simple'::regconfig", $names->regconfig(new TextConfig('simple', unaccent: false)));
        self::assertSame('fuzzphony_german_stop', $names->stopDictionaryName(new TextConfig('german')));
        self::assertSame('"fuzzphony_german_stop"', $names->stopDictionary(new TextConfig('german')));
    }

    public function testLongNamesAreCutToThePostgresLimitAndStayUnique(): void
    {
        $long = str_repeat('a', 70);

        self::assertSame('short', Names::limit('short'));
        self::assertSame(str_repeat('a', 63), Names::limit(str_repeat('a', 63)), 'exactly at the limit: unchanged');
        self::assertSame(substr($long, 0, 54) . '_' . hash('crc32b', $long), Names::limit($long));
        self::assertSame(63, strlen(Names::limit($long)));
        self::assertSame(substr($long, 0, 11) . '_' . hash('crc32b', $long), Names::limit($long, 20));

        $index = IndexDefinition::builder(str_repeat('long_index_name_', 3))->fromTable('t')->field('name')->build();
        self::assertLessThanOrEqual(63, strlen((new Names())->triggerName($index, new Watch(str_repeat('watched_table_', 4)), '_trn')));
    }

    public function testAnInvalidExtensionSchemaIsAConfigurationError(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Invalid extension schema "not a valid ident; drop table".');

        new Names('not a valid ident; drop table');
    }
}
```

`tests/Unit/Postgres/TypesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Engine\Postgres\Schema\Types;
use PHPUnit\Framework\TestCase;

final class TypesTest extends TestCase
{
    public function testIdTypes(): void
    {
        self::assertSame('bigint', Types::id(IdType::Int));
        self::assertSame('uuid', Types::id(IdType::Uuid));
        self::assertSame('text', Types::id(IdType::String));
    }

    public function testFilterTypes(): void
    {
        self::assertSame(
            ['boolean', 'bigint', 'double precision', 'text', 'date', 'timestamptz'],
            array_map(Types::filter(...), FilterType::cases()),
        );
    }

    public function testCompatibleSourceTypes(): void
    {
        self::assertSame(['boolean'], Types::compatible(FilterType::Bool));
        self::assertSame(['smallint', 'integer', 'bigint'], Types::compatible(FilterType::Int));
        self::assertSame(['real', 'double precision', 'numeric', 'smallint', 'integer', 'bigint'], Types::compatible(FilterType::Float));
        self::assertSame(['text', 'character varying', 'character', 'citext', 'uuid'], Types::compatible(FilterType::String));
        self::assertSame(['date', 'timestamp without time zone', 'timestamp with time zone'], Types::compatible(FilterType::Date));
        self::assertSame(['timestamp without time zone', 'timestamp with time zone', 'date'], Types::compatible(FilterType::DateTime));
    }
}
```

Add to `tests/Unit/Postgres/SearchSqlBuilderTest.php`:

```php
    public function testTheLabelWeightsBecomeTheTsRankWeightsArrayOrderedDToA(): void
    {
        $default = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(), new Thresholds(), 10, 0);
        $custom = (new SearchSqlBuilder(Indexes::products()))->ranked("'mouse'", 'mouse', null, [], new RankingProfile(labelWeights: ['b' => 0.8, 'd' => 0.0]), new Thresholds(), 10, 0);

        self::assertStringContainsString("ts_rank_cd('{0.1,0.2,0.4,1}'::real[], s.tsv, q.tsq, 32)", $default['sql']);
        self::assertStringContainsString("ts_rank_cd('{0,0.2,0.8,1}'::real[], s.tsv, q.tsq, 32)", $custom['sql']);
    }
```

Edit the Core tests that used the removed methods:

- `IdTypeTest`: delete `testSqlTypes()` (now `TypesTest::testIdTypes`).
- `RankingProfileTest::testDefaultsAreSensible`: delete the `tsRankWeights()` line; `testLabelWeightsAreMergedWithDefaults`: `self::assertSame(['A' => 1.0, 'B' => 0.8, 'C' => 0.2, 'D' => 0.1], (new RankingProfile(labelWeights: ['b' => 0.8]))->labelWeights);`
- `ArrayDefinitionLoaderTest:34`, `ExportersTest:110`: `self::assertSame('german', $definition->text->language); self::assertTrue($definition->text->unaccent);` (use the variable name of each test: `$definition` / `$loaded`); `AttributeDefinitionLoaderTest:29`: same with `'hungarian'`.
- `SchemaGeneratorTest`: delete the invalid-extension-schema test at lines 18-24 (now in `NamesTest`); line 215 `$generator->syncFunctionName(...)` → `(new Names())->syncFunctionName(...)`.
- `ColumnAwareFilteringTest`: every `{$index->sidecarTable()}` → build the SQL with `sprintf('… FROM %s …', (new Names())->sidecar($index))` (import `Fuzzphony\Engine\Postgres\Schema\Names`).
- `PostgresEngineTest:168` comment: `Identifier::limit()` → `Names::limit()`.

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter 'NamesTest|TypesTest|SearchSqlBuilderTest'`
Expected: FAIL with `Class "Fuzzphony\Engine\Postgres\Schema\Names" not found`.

- [ ] **Step 3: Create `Names` and `Types`**

`src/Engine/Postgres/Schema/Names.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal Every database object name Fuzzphony creates, in one place. Methods ending in "Name"
 * return the bare name (catalog lookups, derived index and trigger names); the others return SQL.
 * Index and trigger names are never qualified: an index lives in its table's schema, a trigger on
 * its table.
 */
final readonly class Names
{
    /** PostgreSQL's identifier limit (NAMEDATALEN - 1). */
    public const int MAX_IDENTIFIER_BYTES = 63;
    private const string PREFIX = 'fuzzphony_';

    public function __construct(
        /** Schema of the pg_trgm and unaccent extensions. */
        public string $extensionSchema = 'public',
    ) {
        if (!Identifier::isColumn($extensionSchema)) {
            throw new InvalidConfiguration(sprintf('Invalid extension schema "%s".', $extensionSchema));
        }
    }

    /** Keeps a generated name within PostgreSQL's limit while staying unique. */
    public static function limit(string $name, int $max = self::MAX_IDENTIFIER_BYTES): string
    {
        if (strlen($name) <= $max) {
            return $name;
        }

        return substr($name, 0, $max - 9) . '_' . hash('crc32b', $name);
    }

    public function extension(): string
    {
        return Sql::ident($this->extensionSchema);
    }

    public function sidecarName(IndexDefinition $index): string
    {
        return self::PREFIX . $index->name;
    }

    public function sidecar(IndexDefinition $index): string
    {
        return $this->qualify($this->sidecarName($index));
    }

    public function queue(): string
    {
        return self::PREFIX . 'queue';
    }

    public function queueOrderIndex(): string
    {
        return self::PREFIX . 'queue_order';
    }

    public function normFunction(): string
    {
        return self::PREFIX . 'norm';
    }

    public function refreshFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'refresh_' . $index->name);
    }

    public function refreshFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->refreshFunctionName($index));
    }

    public function syncFunctionName(IndexDefinition $index, Watch $watch): string
    {
        return self::limit(self::PREFIX . 'sync_' . $index->name . '__' . str_replace('.', '_', $watch->table));
    }

    public function syncFunction(IndexDefinition $index, Watch $watch): string
    {
        return $this->qualify($this->syncFunctionName($index, $watch));
    }

    /** "" = the row-level trigger (named like its function); "_ins", "_upd", "_del", "_trn" = the others. */
    public function triggerName(IndexDefinition $index, Watch $watch, string $suffix = ''): string
    {
        $function = $this->syncFunctionName($index, $watch);

        return $suffix === '' ? $function : self::limit($function . $suffix);
    }

    public function indexName(IndexDefinition $index, string $suffix): string
    {
        return self::limit($this->sidecarName($index) . '_' . $suffix);
    }

    /** The configuration an index uses: Fuzzphony's accent-folding copy, or the built-in one. */
    public function textConfigName(TextConfig $config): string
    {
        return $config->unaccent ? self::PREFIX . $config->language : $config->language;
    }

    public function textConfig(TextConfig $config): string
    {
        return $config->unaccent ? $this->qualify($this->textConfigName($config)) : Sql::ident($config->language);
    }

    /** The configuration as a SQL value, for to_tsvector() / to_tsquery() / ts_headline(). */
    public function regconfig(TextConfig $config): string
    {
        return Sql::string($this->textConfigName($config)) . '::regconfig';
    }

    /** The dictionary the accent-folding configuration consults for stop words, before unaccent. */
    public function stopDictionaryName(TextConfig $config): string
    {
        return self::PREFIX . $config->language . '_stop';
    }

    public function stopDictionary(TextConfig $config): string
    {
        return $this->qualify($this->stopDictionaryName($config));
    }

    private function qualify(string $name): string
    {
        return Sql::ident($name);
    }
}
```

`src/Engine/Postgres/Schema/Types.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;

/** @internal PostgreSQL types of document ids and filter columns. */
final class Types
{
    public static function id(IdType $type): string
    {
        return match ($type) {
            IdType::Int => 'bigint',
            IdType::Uuid => 'uuid',
            IdType::String => 'text',
        };
    }

    public static function filter(FilterType $type): string
    {
        return match ($type) {
            FilterType::Bool => 'boolean',
            FilterType::Int => 'bigint',
            FilterType::Float => 'double precision',
            FilterType::String => 'text',
            FilterType::Date => 'date',
            FilterType::DateTime => 'timestamptz',
        };
    }

    /**
     * Source column types that can back this filter without a lossy cast.
     *
     * @return list<string>
     */
    public static function compatible(FilterType $type): array
    {
        return match ($type) {
            FilterType::Bool => ['boolean'],
            FilterType::Int => ['smallint', 'integer', 'bigint'],
            FilterType::Float => ['real', 'double precision', 'numeric', 'smallint', 'integer', 'bigint'],
            FilterType::String => ['text', 'character varying', 'character', 'citext', 'uuid'],
            FilterType::Date => ['date', 'timestamp without time zone', 'timestamp with time zone'],
            FilterType::DateTime => ['timestamp without time zone', 'timestamp with time zone', 'date'],
        };
    }
}
```

- [ ] **Step 4: Delete the Core methods** listed under Files (and their now-unused imports). `DefinitionValidator` keeps `DOLLAR_QUOTE_TAG` and the 48-character name cap (R5 "stays in Core").

- [ ] **Step 5: Route every consumer through `Names` / `Types`** (output unchanged)

`PostgresSchemaGenerator`:
- `public function __construct(private readonly Names $names = new Names()) {}` and `public function names(): Names { return $this->names; }`; delete `QUEUE_TABLE`, `NORM_FUNCTION`, `refreshFunctionName()`, `syncFunctionName()`, `stopDictionaryName()`; keep `TAG` and `stemDictionaryName()`.
- `global()`: `$schema = $this->names->extensionSchema;`; `self::NORM_FUNCTION` → `$this->names->normFunction()`; `self::QUEUE_TABLE` → `$this->names->queue()`; the order index becomes `sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (index_name, queued_at)', $this->names->queueOrderIndex(), $this->names->queue())`; the dedupe key `$index->text->configName()` → `$this->names->textConfigName($index->text)`.
- `index()`: `$table = $this->names->sidecar($index);`; the trigger statement uses `$this->names->syncFunction($index, $watch)` instead of `Sql::ident($function)` (drop the `$function` local).
- `drop()`: `DROP FUNCTION IF EXISTS %s()` with `$this->names->syncFunction($index, $watch)`; `DROP FUNCTION IF EXISTS %s(%s[])` with `$this->names->refreshFunction($index), Types::id($index->idType)`; `DROP TABLE IF EXISTS %s` with `$this->names->sidecar($index)`; the queue DO block becomes `"DO " . self::TAG . " BEGIN IF to_regclass(%s) IS NOT NULL THEN DELETE FROM %s WHERE index_name = %s; END IF; END " . self::TAG` with `Sql::string($this->names->queue()), $this->names->queue(), Sql::string($index->name)`.
- `columns()`: `Types::id($index->idType) . ' PRIMARY KEY'`, `Types::filter($filter->type)`.
- `indexes()`: keys `$this->names->indexName($index, 'tsv')`, `indexName($index, 'fz')`, `indexName($index, 'f_' . $filter->name)`; `Sql::ident($this->extensionSchema)` → `$this->names->extension()`.
- `triggerDefinitions()` / `allTriggerNames()`: `Identifier::limit($function . '_x')` → `$this->names->triggerName($index, $watch, '_x')`; the row-level key `$function` → `$this->names->triggerName($index, $watch)`.
- `refreshFunction()`: `$this->names->sidecar($index)`, `$this->names->refreshFunction($index)` (instead of `Sql::ident($this->refreshFunctionName($index))`), `Types::id($index->idType)`, filter cast `Types::filter($filter->type)` (the old `FilterType::Int ? 'bigint' : sqlType()` ternary yields the same, `Types::filter(Int)` is `bigint`).
- `syncFunction()`, `statementSyncFunction()`, `truncateBranch()`, `statementAction()`: refresh via `$this->names->refreshFunction($index)`, `Types::id(...)`, queue via `$this->names->queue()`, sidecar via `$this->names->sidecar($index)`, function name via `$this->names->syncFunction($index, $watch)`.
- `textConfig()`: `$name = $this->names->textConfigName($config)`; `Sql::ident($name)` → `$this->names->textConfig($config)`; `Sql::ident($this->extensionSchema)` → `$this->names->extension()`; `$stop = $this->names->stopDictionaryName($config)`; `Sql::ident($stop)` → `$this->names->stopDictionary($config)`.
- `tsvectorExpression()`: `$config = $this->names->regconfig($index->text);`
- `fuzzyExpression()` / `exactExpression()`: `$norm = $this->names->normFunction();` captured with `use ($norm)` in the closure, in place of `self::NORM_FUNCTION`.
- Imports: drop `Identifier` and `FilterType` if unused; `Types` and `Names` share the namespace.

`PostgresEngine`:

```php
    private readonly Names $names;
    private readonly PostgresSchemaGenerator $schema;

    public function __construct(
        private readonly Connection $connection,
        string $extensionSchema = 'public',
    ) {
        $this->names = new Names($extensionSchema);
        $this->schema = new PostgresSchemaGenerator($this->names);
    }
```

and: `refresh()` → `sprintf('SELECT %s(CAST(:ids AS %s[]))', $this->names->refreshFunction($index), Types::id($index->idType))`; `sourceIds()` / `pruneOrphans()` / `processQueue()` → `Types::id(...)`; `pruneOrphans()` `Sql::ident($index->sidecarTable())` → `$this->names->sidecar($index)`; `processQueue()` / `queueSize()` `PostgresSchemaGenerator::QUEUE_TABLE` → `$this->names->queue()`, refresh → `$this->names->refreshFunction($index)`; `pipeline()` / `probe()` → `new FuzzyQueryCompiler($index, $thresholds, $this->names)`, `new SearchSqlBuilder($index, $this->names)`; `emptyQueries()` → `'… numnode(to_tsquery(%s, t.q)) = 0'` with `$this->names->regconfig($index->text)`; highlighting → `new Highlighter($this->connection, $this->names)`. Imports `Names`, `Types`.

`SearchSqlBuilder`: constructor `(private readonly IndexDefinition $index, private readonly Names $names = new Names())`; `$table = $this->names->sidecar($this->index)` (3 places); `$config = $this->names->regconfig($this->index->text)`; `sprintf('%s(%s) AS norm', $this->names->normFunction(), …)`; `new FuzzyQueryCompiler($this->index, $thresholds, $this->names)` (2 places); `$profile->tsRankWeights()` → `self::tsRankWeights($profile)` with

```php
    /** PostgreSQL ts_rank weights array literal, ordered {D, C, B, A}. */
    private static function tsRankWeights(RankingProfile $profile): string
    {
        $w = $profile->labelWeights;

        return sprintf('{%s,%s,%s,%s}', Sql::float($w['D']), Sql::float($w['C']), Sql::float($w['B']), Sql::float($w['A']));
    }
```

(`Sql::float()` formats exactly like the old `RankingProfile::num()` for the validated 0..1 range.) Drop the `PostgresSchemaGenerator` import.

`FuzzyQueryCompiler`: constructor third parameter `private readonly Names $names = new Names()`; `sprintf('%s(%s)', $this->names->normFunction(), $params->add($needle))`; `$schema = $this->names->extension();`; `matches()` → `'s.tsv @@ ' . $this->column('ft', sprintf('to_tsquery(%s, %s)', $this->names->regconfig($this->index->text), $params->add($tsquery)))`. Import `Fuzzphony\Engine\Postgres\Schema\Names`, drop `PostgresSchemaGenerator`.

`Highlighter`: constructor `(private readonly Connection $connection, private readonly Names $names = new Names())`; `$config = $this->names->regconfig($index->text);`; `Types::id($index->idType)`.

`PostgresInspector`: add `private readonly Names $names;` set in the constructor body from `$schema->names()`; messages keep today's bare names in this task:
- `$this->function($this->names->normFunction() . '(text)', 'Normaliser function')`
- `$this->regclass($this->names->sidecar($index))`; message `sprintf('Table "%s" does not exist.', $this->names->sidecarName($index))`
- refresh signature `sprintf('%s(%s[])', $this->names->refreshFunctionName($index), Types::id($index->idType))`
- `textConfig()`: `$name = $this->names->textConfigName($index->text)`; stop via `$this->names->stopDictionaryName(...)`; `keepsAccentedStopWords()` params `name` / `stop` likewise
- `sourceMapping()`: `Types::compatible($filter->type)`, `Types::compatible($type)`
- `sidecarColumns()` / `sidecarIndexes()` / `coverage()` / `orphans()`: `['table' => $this->names->sidecar($index)]`, `['t' => $this->names->sidecar($index)]`, and `$this->names->sidecar($index)` in place of `Sql::ident($index->sidecarTable())`
- `triggers()`: `$truncateTrigger = $this->names->triggerName($index, $watch, '_trn');` (comment: "`Names::limit()` hashes a long name …")
- `queue()`: `$this->regclass($this->names->queue())` and `FROM %s` with `$this->names->queue()`
- Drop the `Identifier` import.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --testsuite=unit` then the integration suite.
Expected: PASS with **no change** to any existing SQL assertion.

- [ ] **Step 7: Docs**

CHANGELOG `### Breaking`, append:

```markdown
- PostgreSQL naming and types left Core: `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
  `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
  `RankingProfile::tsRankWeights()` and `Identifier::limit()` are removed. They now live in the
  engine (`Fuzzphony\Engine\Postgres\Schema\Names` and `Types`, both internal).
```

UPGRADE item 4:

```markdown
4. **Removed Core helpers.** `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
   `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
   `RankingProfile::tsRankWeights()` and `Identifier::limit()` are gone. Nothing replaces them in
   the public API: the engine derives these names itself. If you queried the sidecar table by
   hand, its name is `fuzzphony_<index>` in Fuzzphony's schema (see step 6).
```

- [ ] **Step 8: Run the gate.**

- [ ] **Step 9: Commit**

```bash
git add src/Engine/Postgres/Schema/Names.php src/Engine/Postgres/Schema/Types.php src/Core/Definition/IndexDefinition.php \
  src/Core/Definition/TextConfig.php src/Core/Definition/IdType.php src/Core/Definition/FilterType.php src/Core/Ranking/RankingProfile.php \
  src/Core/Support/Identifier.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/PostgresEngine.php \
  src/Engine/Postgres/Sql/SearchSqlBuilder.php src/Engine/Postgres/Sql/FuzzyQueryCompiler.php src/Engine/Postgres/Highlighter.php \
  src/Engine/Postgres/Inspection/PostgresInspector.php tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/TypesTest.php \
  tests/Unit/Core/Definition/IdTypeTest.php tests/Unit/Core/Ranking/RankingProfileTest.php tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php \
  tests/Unit/Core/Definition/AttributeDefinitionLoaderTest.php tests/Unit/Core/Wizard/ExportersTest.php tests/Unit/Postgres/SchemaGeneratorTest.php \
  tests/Unit/Postgres/SearchSqlBuilderTest.php tests/Integration/ColumnAwareFilteringTest.php tests/Integration/PostgresEngineTest.php \
  CHANGELOG.md UPGRADE.md
git commit -m "Move PostgreSQL names and types out of Core into Names and Types

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Dedicated schema, part 1: schema-qualified SQL (R6)

**Files:**
- Modify: `src/Engine/Postgres/Schema/Names.php` (constructor, `quotedSchema()`, `qualify()`, `queue()`, `normFunction()`, `regconfig()`), `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`global()`, norm function, `refreshFunction()`, `syncFunction()`, `statementSyncFunction()`, `textConfig()`), `src/Engine/Postgres/PostgresEngine.php` (constructor), `src/Engine/Postgres/Inspection/PostgresInspector.php` (sidecar message, refresh signature)
- Test: modify `tests/Unit/Postgres/NamesTest.php`, `tests/Unit/Postgres/SchemaGeneratorTest.php`, `tests/Unit/Postgres/SearchSqlBuilderTest.php`, `tests/Unit/Postgres/FuzzyQueryCompilerTest.php`; create `tests/Integration/DedicatedSchemaTest.php`
- Docs: `docs/configuration.md` (new section "Fuzzphony's schema"), `docs/architecture.md:26-30`, `CHANGELOG.md` (Added, Changed), `UPGRADE.md` (item "Apply the schema")

**Interfaces:**
- Consumes: `Names` (Task 4), `InvalidConfiguration` (Task 1).
- Produces:
  - `Names::__construct(string $extensionSchema = 'public', string $schema = 'public')`, public promoted `$schema`; invalid schema → `InvalidConfiguration('Invalid schema "<x>": use a plain identifier such as "fuzzphony".')`; `pg_` prefix → `InvalidConfiguration('Invalid schema "<x>": names starting with "pg_" are reserved by PostgreSQL.')`.
  - `Names::quotedSchema(): string`. From now on `sidecar()`, `queue()`, `normFunction()`, `refreshFunction()`, `syncFunction()`, `textConfig()` (accent folding), `stopDictionary()` return `"<schema>"."<name>"`; `regconfig()` returns `'"<schema>"."fuzzphony_<lang>"'::regconfig` (or `'"<lang>"'::regconfig` without accent folding). Index and trigger names stay bare.
  - `PostgresEngine::__construct(Connection $connection, string $extensionSchema = 'public', string $schema = 'public')`.

- [ ] **Step 1: Write the failing tests**

`NamesTest` — change the expectations of `testObjectNames` / `testTextSearchNames` to the qualified forms and add the schema tests:

```php
    // in testObjectNames():
        self::assertSame('"public"', $names->quotedSchema());
        self::assertSame('"public"."fuzzphony_products"', $names->sidecar($index));
        self::assertSame('"public"."fuzzphony_queue"', $names->queue());
        self::assertSame('"public"."fuzzphony_norm"', $names->normFunction());
        self::assertSame('"public"."fuzzphony_refresh_products"', $names->refreshFunction($index));
        self::assertSame('"public"."fuzzphony_sync_products__fz_brand"', $names->syncFunction($index, $brand));
        // (bare-name assertions unchanged; queueOrderIndex() stays 'fuzzphony_queue_order')

    // in testTextSearchNames():
        self::assertSame('"public"."fuzzphony_german"', $names->textConfig(new TextConfig('german')));
        self::assertSame('"german"', $names->textConfig(new TextConfig('german', unaccent: false)));
        self::assertSame("'\"public\".\"fuzzphony_english\"'::regconfig", $names->regconfig(new TextConfig()));
        self::assertSame("'\"simple\"'::regconfig", $names->regconfig(new TextConfig('simple', unaccent: false)));
        self::assertSame('"public"."fuzzphony_german_stop"', $names->stopDictionary(new TextConfig('german')));

    public function testEveryOwnObjectIsQualifiedWithTheConfiguredSchema(): void
    {
        $names = new Names(schema: 'Fuzzphony');
        $index = Indexes::products();

        self::assertSame('Fuzzphony', $names->schema);
        self::assertSame('"Fuzzphony"', $names->quotedSchema());
        self::assertSame('"Fuzzphony"."fuzzphony_products"', $names->sidecar($index));
        self::assertSame('"Fuzzphony"."fuzzphony_queue"', $names->queue());
        self::assertSame('"public"', $names->extension(), 'the extensions stay where they are');
        self::assertSame('fuzzphony_products_tsv', $names->indexName($index, 'tsv'), 'an index lives in its table\'s schema');
        self::assertSame('fuzzphony_sync_products__fz_brand_trn', $names->triggerName($index, new Watch('fz_brand'), '_trn'), 'a trigger lives on its table');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSchemas(): iterable
    {
        yield 'space' => ['bad name', 'Invalid schema "bad name": use a plain identifier such as "fuzzphony".'];
        yield 'dot' => ['a.b', 'Invalid schema "a.b": use a plain identifier such as "fuzzphony".'];
        yield 'empty' => ['', 'Invalid schema "": use a plain identifier such as "fuzzphony".'];
        yield 'reserved' => ['pg_search', 'Invalid schema "pg_search": names starting with "pg_" are reserved by PostgreSQL.'];
    }

    #[DataProvider('invalidSchemas')]
    public function testAnInvalidSchemaIsAConfigurationError(string $schema, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        new Names(schema: $schema);
    }
```

(import `PHPUnit\Framework\Attributes\DataProvider`.)

`SchemaGeneratorTest` — add (import `Fuzzphony\Engine\Postgres\Schema\Names`):

```php
    public function testTheDefaultSchemaNeedsNoCreateSchema(): void
    {
        // PostgreSQL checks CREATE on the database before IF NOT EXISTS: a 0.3 install must still apply as a role without it.
        self::assertStringNotContainsString('CREATE SCHEMA', (new PostgresSchemaGenerator())->global(Indexes::products())->toSql());
    }

    public function testADedicatedSchemaIsCreatedFirstAndHoldsEveryObject(): void
    {
        $generator = new PostgresSchemaGenerator(new Names(schema: 'fuzzphony_s'));
        $global = $generator->global(Indexes::products());
        $sql = $global->merge($generator->index(Indexes::products()))->toSql();

        self::assertSame('CREATE SCHEMA IF NOT EXISTS "fuzzphony_s"', $global->statements[0]->sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "fuzzphony_s"."fuzzphony_queue"', $sql);
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS fuzzphony_queue_order ON "fuzzphony_s"."fuzzphony_queue"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "fuzzphony_s"."fuzzphony_products"', $sql);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION "fuzzphony_s"."fuzzphony_norm"(text)', $sql);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION "fuzzphony_s"."fuzzphony_refresh_products"(p_ids bigint[])', $sql);
        self::assertStringContainsString('EXECUTE FUNCTION "fuzzphony_s"."fuzzphony_sync_products__fz_brand"()', $sql);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_products_tsv" ON "fuzzphony_s"."fuzzphony_products"', $sql);
        self::assertStringContainsString('INSERT INTO "fuzzphony_s"."fuzzphony_queue" (index_name, doc_id)', $sql);
        self::assertStringContainsString("setweight(to_tsvector('\"fuzzphony_s\".\"fuzzphony_english\"'::regconfig", $sql);
        self::assertStringContainsString('"fuzzphony_s"."fuzzphony_norm"(doc."fld_name"::text)', $sql);
    }

    public function testGeneratedFunctionsPinTheirSearchPath(): void
    {
        $generator = new PostgresSchemaGenerator();
        $sql = $generator->global(Indexes::products())->merge($generator->index(Indexes::products()))->toSql();

        self::assertStringContainsString("RETURNS text\nLANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT\nSET search_path = pg_catalog, pg_temp\nAS \$fuzzphony\$", $sql);
        self::assertStringContainsString("RETURNS integer\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$", $sql);
        self::assertSame(2, substr_count($sql, "RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$"), 'both sync functions');

        $row = $generator->index(Indexes::products()->withTriggerLevel(TriggerLevel::Row))->toSql();
        self::assertSame(2, substr_count($row, "RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$"), 'row-level sync functions too');
    }

    public function testCatalogLookupsCompareTheExactSchemaName(): void
    {
        $sql = (new PostgresSchemaGenerator(new Names(schema: 'Fz')))->global(Indexes::products())->toSql();

        self::assertStringContainsString("WHERE c.cfgname = 'fuzzphony_english' AND n.nspname = 'Fz'", $sql);
        self::assertStringContainsString("WHERE d.dictname = 'fuzzphony_english_stop' AND n.nspname = 'Fz'", $sql);
        self::assertStringContainsString("EXECUTE format('CREATE TEXT SEARCH DICTIONARY %I.%I (TEMPLATE = pg_catalog.simple, STOPWORDS = %L, ACCEPT = false)', 'Fz', 'fuzzphony_english_stop', v_stopwords)", $sql);
        self::assertStringContainsString('CREATE TEXT SEARCH CONFIGURATION "Fz"."fuzzphony_english" (COPY = "english")', $sql);
        self::assertStringContainsString('WITH "Fz"."fuzzphony_english_stop", "public".unaccent, "english_stem"', $sql);
    }
```

Update the existing expectations that now change (all other SQL stays as it was):

| Before | After |
|---|---|
| `"fuzzphony_<index>"` as a table (`FROM`, `DELETE FROM`, `DROP TABLE`, `ON`) | `"public"."fuzzphony_<index>"` |
| `fuzzphony_queue` (unquoted table, not `fuzzphony_queue_order`) | `"public"."fuzzphony_queue"` |
| `PERFORM "fuzzphony_refresh_<index>"` / `CREATE OR REPLACE FUNCTION "fuzzphony_sync_…"` / `EXECUTE FUNCTION "fuzzphony_sync_…"()` | `"public"."fuzzphony_refresh_<index>"` / `"public"."fuzzphony_sync_…"` (trigger names in `DROP TRIGGER` / `CREATE OR REPLACE TRIGGER` stay bare) |
| `fuzzphony_norm(` | `"public"."fuzzphony_norm"(` |
| `'fuzzphony_english'::regconfig` | `'"public"."fuzzphony_english"'::regconfig` |
| `CREATE TEXT SEARCH CONFIGURATION "fuzzphony_english"` | `CREATE TEXT SEARCH CONFIGURATION "public"."fuzzphony_english"` |
| `WITH "fuzzphony_german_stop", "public".unaccent` | `WITH "public"."fuzzphony_german_stop", "public".unaccent` |
| `… DICTIONARY %I (TEMPLATE …)', 'fuzzphony_german_stop', v_stopwords)` | `… DICTIONARY %I.%I (TEMPLATE …)', 'public', 'fuzzphony_german_stop', v_stopwords)` |

Known places: `SchemaGeneratorTest.php:52-53,87,134,142-143,147,150-151,160,163,166,174,177,181,191,201,203,205,225`, `SearchSqlBuilderTest.php:26,66-68,83`, `FuzzyQueryCompilerTest.php:30,184,261-265`. Run the unit suite after the implementation and fix each remaining failure with this table only; any other difference is a bug in the implementation.

`tests/Integration/DedicatedSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Every Fuzzphony object in a schema that is on nobody's search_path: nothing may rely on the
 * search_path to find the sidecar table, the queue, the functions or the text configuration.
 * The fixtures (fz_product, fz_brand) stay in public, like an application's tables.
 */
final class DedicatedSchemaTest extends TestCase
{
    private const string SCHEMA = 'fuzzphony_s';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $this->connection->execute('SET search_path TO public');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('RESET search_path');
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
    }

    public function testEveryObjectLivesInTheConfiguredSchema(): void
    {
        $this->fuzzphony('queue');

        foreach (['fuzzphony_products', 'fuzzphony_queue'] as $table) {
            self::assertNotNull($this->connection->fetchValue(sprintf("SELECT to_regclass('%s.%s')", self::SCHEMA, $table)), $table);
            self::assertNull($this->connection->fetchValue(sprintf("SELECT to_regclass('public.%s')", $table)), $table . ' must not be created in public');
        }
        $functions = array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            'SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = :schema ORDER BY 1',
            ['schema' => self::SCHEMA],
        ), 'proname'));
        self::assertSame(['fuzzphony_norm', 'fuzzphony_refresh_products', 'fuzzphony_sync_products__fz_brand', 'fuzzphony_sync_products__fz_product'], $functions);
        self::assertTrue((bool) $this->connection->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = 'fuzzphony_english' AND n.nspname = :schema)",
            ['schema' => self::SCHEMA],
        ));
    }

    public function testSearchNeedsNeitherSchemaOnTheSearchPath(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('SET search_path TO pg_catalog');

        self::assertContains(1, $fuzzphony->in('products')->query('mouse')->get()->ids(), 'exact full-text match');
        $fuzzy = $fuzzphony->in('products')->query('headphnoes')->get();
        self::assertTrue($fuzzy->usedFuzzy);
        self::assertSame(3, $fuzzy->hits[0]->id ?? null, 'typo-tolerant (trigram) match');
        $relaxed = $fuzzphony->in('products')->query('wireless mouse offfice')->get();
        self::assertSame([1], $relaxed->ids());
        self::assertSame(5, $fuzzphony->in('products')->get()->total, 'browsing');
    }

    public function testQueueAndTruncateSyncWorkFromASessionThatSeesNeitherSchema(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $index = $fuzzphony->registry()->get('products');
        $engine = $fuzzphony->engine();

        $this->connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1"); // products 1 and 4
        $this->connection->execute('SET search_path TO pg_catalog');
        self::assertSame(2, $engine->queueSize($index));
        self::assertSame(2, (new Worker($engine))->runOnce([$index]));
        self::assertEqualsCanonicalizing([1, 4], $fuzzphony->in('products')->query('brand:"logitech g"')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());

        $this->connection->execute('TRUNCATE public.fz_product');
        (new Worker($engine))->runOnce([$index]);
        self::assertSame(0, $fuzzphony->in('products')->get()->total);
    }

    public function testTriggerSyncCallsTheQualifiedRefreshFunction(): void
    {
        $fuzzphony = $this->fuzzphony('trigger');

        $this->connection->execute("UPDATE fz_brand SET name = 'Zebra' WHERE id = 1");

        self::assertEqualsCanonicalizing([1, 4], $fuzzphony->in('products')->query('zebra')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
    }

    public function testDropRemovesTheIndexFromTheSchema(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $this->connection->execute("UPDATE fz_brand SET name = 'Queued' WHERE id = 1");

        $fuzzphony->engine()->dropSchema($fuzzphony->registry()->get('products'))->apply($this->connection);

        self::assertNull($this->connection->fetchValue(sprintf("SELECT to_regclass('%s.fuzzphony_products')", self::SCHEMA)));
        self::assertSame(0, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s.fuzzphony_queue', self::SCHEMA))));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'fuzzphony\\_sync\\_products%'")));
    }

    private function fuzzphony(string $sync): Fuzzphony
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, schema: self::SCHEMA), new IndexRegistry([Indexes::products($sync)]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter 'NamesTest|SchemaGeneratorTest'`
Expected: FAIL (`Unknown named parameter $schema`, unqualified names).

- [ ] **Step 3: `Names` qualifies with the schema**

Constructor and the changed methods (everything else as in Task 4):

```php
    public function __construct(
        /** Schema of the pg_trgm and unaccent extensions. */
        public string $extensionSchema = 'public',
        /** Schema of Fuzzphony's own tables, functions and text search configurations. */
        public string $schema = 'public',
    ) {
        if (!Identifier::isColumn($extensionSchema)) {
            throw new InvalidConfiguration(sprintf('Invalid extension schema "%s".', $extensionSchema));
        }
        if (!Identifier::isColumn($schema)) {
            throw new InvalidConfiguration(sprintf('Invalid schema "%s": use a plain identifier such as "fuzzphony".', $schema));
        }
        if (str_starts_with($schema, 'pg_')) {
            throw new InvalidConfiguration(sprintf('Invalid schema "%s": names starting with "pg_" are reserved by PostgreSQL.', $schema));
        }
    }

    public function quotedSchema(): string
    {
        return Sql::ident($this->schema);
    }

    public function queue(): string
    {
        return $this->qualify(self::PREFIX . 'queue');
    }

    public function normFunction(): string
    {
        return $this->qualify(self::PREFIX . 'norm');
    }

    /** The configuration as a SQL value, for to_tsvector() / to_tsquery() / ts_headline(). */
    public function regconfig(TextConfig $config): string
    {
        return Sql::string($this->textConfig($config)) . '::regconfig';
    }

    private function qualify(string $name): string
    {
        return Sql::ident($this->schema . '.' . $name);
    }
```

Update the class docblock: "Methods ending in "Name" return the bare name; the others return SQL, quoted and qualified with Fuzzphony's schema, so no statement depends on the caller's search_path."

- [ ] **Step 4: Generator: `CREATE SCHEMA`, pinned `search_path`, namespace-aware text search objects**

`global()` starts with:

```php
        $schema = $this->names->extensionSchema;
        $statements = [];
        if ($this->names->schema !== 'public') {
            // not for public: PostgreSQL checks CREATE on the database before IF NOT EXISTS
            $statements[] = new Statement(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $this->names->quotedSchema()), "Fuzzphony's own schema");
        }
        array_push(
            $statements,
            new Statement(sprintf('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA %s', Sql::ident($schema)), 'Trigram matching for typo tolerance'),
            // … the existing statements, unchanged except the norm function below …
        );
```

Norm function SQL:

```php
            new Statement(sprintf(
                "CREATE OR REPLACE FUNCTION %s(text) RETURNS text\nLANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT\nSET search_path = pg_catalog, pg_temp\nAS " . self::TAG . " SELECT btrim(regexp_replace(lower(%s.unaccent('%s.unaccent'::regdictionary, \$1)), '[^[:alnum:]]+', ' ', 'g')) " . self::TAG,
                $this->names->normFunction(),
                Sql::ident($schema),
                $schema,
            ), 'Normaliser for trigram / exact matching: lowercase, no accents, alphanumerics only'),
```

`refreshFunction()` heredoc, second line: `LANGUAGE plpgsql SET search_path FROM CURRENT AS %8$s`.

`syncFunction()` and `statementSyncFunction()`: `"CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS " . self::TAG . "\nBEGIN\n…"`.

Add a comment above `refreshFunction()`: "`SET search_path FROM CURRENT` keeps the search_path of the session that applied the schema: the embedded source query and watch SQL resolve their (usually unqualified) tables as they did then, and a caller's search_path cannot redirect anything inside. Fuzzphony's own objects are schema-qualified anyway."

`textConfig()`:

```php
    private function textConfig(TextConfig $config): Statement
    {
        $name = $this->names->textConfigName($config);
        $stop = $this->names->stopDictionaryName($config);

        return new Statement(sprintf(
            <<<'SQL'
                DO %8$s
                DECLARE
                    v_stopwords text;
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = %1$s AND n.nspname = %10$s) THEN
                        CREATE TEXT SEARCH CONFIGURATION %2$s (COPY = %3$s);
                    END IF;
                    SELECT substring(dictinitoption FROM 'stopwords *= *''([[:alnum:]_]+)''') INTO v_stopwords
                    FROM pg_ts_dict WHERE oid = %5$s::regdictionary;
                    IF v_stopwords IS NULL THEN
                        ALTER TEXT SEARCH CONFIGURATION %2$s
                            ALTER MAPPING FOR hword, hword_part, word WITH %4$s.unaccent, %6$s;
                    ELSE
                        IF NOT EXISTS (SELECT 1 FROM pg_ts_dict d JOIN pg_namespace n ON n.oid = d.dictnamespace WHERE d.dictname = %7$s AND n.nspname = %10$s) THEN
                            EXECUTE format('CREATE TEXT SEARCH DICTIONARY %%I.%%I (TEMPLATE = pg_catalog.simple, STOPWORDS = %%L, ACCEPT = false)', %10$s, %7$s, v_stopwords);
                        END IF;
                        ALTER TEXT SEARCH CONFIGURATION %2$s
                            ALTER MAPPING FOR hword, hword_part, word WITH %9$s, %4$s.unaccent, %6$s;
                    END IF;
                END
                %8$s
                SQL,
            Sql::string($name),
            $this->names->textConfig($config),
            Sql::ident($config->language),
            $this->names->extension(),
            Sql::string(Sql::ident($this->stemDictionaryName($config))),
            Sql::ident($this->stemDictionaryName($config)),
            Sql::string($stop),
            self::TAG,
            $this->names->stopDictionary($config),
            Sql::string($this->names->schema),
        ), sprintf('Text search configuration "%s" (%s stemming + accent folding, accented stop words dropped)', $name, $config->language));
    }
```

(The namespace is compared as the exact `nspname`, never through `'x'::regnamespace`, which would case-fold an unquoted name.)

- [ ] **Step 5: Engine constructor and inspector messages**

`PostgresEngine`:

```php
    /**
     * @param string $extensionSchema schema of the pg_trgm and unaccent extensions
     * @param string $schema          schema of Fuzzphony's own tables, functions and text search configurations
     */
    public function __construct(
        private readonly Connection $connection,
        string $extensionSchema = 'public',
        string $schema = 'public',
    ) {
        $this->names = new Names($extensionSchema, $schema);
        $this->schema = new PostgresSchemaGenerator($this->names);
    }
```

`PostgresInspector`: sidecar check message `sprintf('Table %s does not exist.', $this->names->sidecar($index))`; refresh signature `sprintf('%s(%s[])', $this->names->refreshFunction($index), Types::id($index->idType))` (so `to_regprocedure()` finds it outside the search_path). The norm, queue, sidecar and coverage lookups are already qualified through `Names`.

- [ ] **Step 6: Run the tests**

Run: unit suite, then fix the expectation table above; then the integration suite (the new `DedicatedSchemaTest` and every existing test must pass: existing installs in `public` are found through the qualified names).
Expected: PASS.

- [ ] **Step 7: Docs**

`docs/configuration.md`, new section after "Builder (plain PHP)":

```markdown
## Fuzzphony's schema

Every object Fuzzphony creates (sidecar tables, the sync queue, the version table, the helper
functions, the text search configurations and their stop-word dictionaries) lives in one schema,
`public` by default. `new PostgresEngine($connection, schema: 'fuzzphony')` (bundle:
`fuzzphony.schema`) puts them in their own schema instead; `fuzzphony:schema --apply` creates it.
Triggers stay on your tables, since PostgreSQL attaches a trigger to its table; they call the
schema-qualified sync function.

Every statement Fuzzphony generates or runs names its objects with their schema, so neither
Fuzzphony's schema nor the extensions' schema has to be on the `search_path`. The generated
functions are pinned too: the normaliser runs with `search_path = pg_catalog, pg_temp`, the
refresh and sync functions with the `search_path` of the session that ran `schema --apply`
(`SET search_path FROM CURRENT`), so your source query and watch SQL resolve their tables the way
they did then, whatever the writing session's `search_path` is. Only highlighting and
`fuzzphony:reindex` run your source query in the calling session, so that session must see your
source tables.
```

`docs/architecture.md` "Sidecar table": `…lives in its own table, `fuzzphony_<index>`, in Fuzzphony's schema (`public` unless configured, see [Fuzzphony's schema](configuration.md#fuzzphonys-schema))…`

CHANGELOG: under `### Added`:

```markdown
- `PostgresEngine` takes a `schema` argument (default `public`): the schema of every object
  Fuzzphony creates. `fuzzphony:schema --apply` creates it when it is not `public`.
```

under `### Changed`:

```markdown
- Every generated and runtime statement schema-qualifies Fuzzphony's own objects, so nothing
  depends on the `search_path` any more. The normaliser function runs with
  `search_path = pg_catalog, pg_temp`; the refresh and sync functions keep the `search_path` of the
  session that applied the schema (`SET search_path FROM CURRENT`). Run `fuzzphony:schema --apply`
  once after upgrading.
```

UPGRADE item 5:

```markdown
5. **Apply the schema once.** `bin/console fuzzphony:schema --apply` re-creates the functions
   with a fixed `search_path` and schema-qualified names. Nothing moves: without a `schema`
   setting everything stays in `public`. Everything is now created in and read from one
   configured schema, so a setup that relied on the `search_path` to place or find Fuzzphony's
   objects (one set per tenant schema, say) no longer works that way. The refresh and sync
   functions resolve your source tables with the `search_path` of the session that applies the
   schema: apply it with the same role and settings as your application.
```

- [ ] **Step 8: Run the gate.**

- [ ] **Step 9: Commit**

```bash
git add src/Engine/Postgres/Schema/Names.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/PostgresEngine.php \
  src/Engine/Postgres/Inspection/PostgresInspector.php tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php \
  tests/Unit/Postgres/SearchSqlBuilderTest.php tests/Unit/Postgres/FuzzyQueryCompilerTest.php tests/Integration/DedicatedSchemaTest.php \
  docs/configuration.md docs/architecture.md CHANGELOG.md UPGRADE.md
git commit -m "Schema-qualify every Fuzzphony object and pin the functions' search_path

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Dedicated schema, part 2: doctor, wizard and bundle (R6)

**Files:**
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php` (`textConfig()`, `keepsAccentedStopWords()`, new `location()`), `src/Engine/Postgres/Wizard/PostgresIntrospector.php:26-44`, `src/Bundle/FuzzphonyBundle.php` (docblock, `configure()`, `loadExtension()`)
- Test: modify `tests/Integration/DedicatedSchemaTest.php`, `tests/Unit/Bundle/FuzzphonyBundleTest.php`, `tests/Integration/AccentedStopWordsTest.php:171`
- Docs: `docs/configuration.md` (bundle block, "Fuzzphony's schema"), `docs/languages.md:32-35`, `docs/commands.md` (doctor bullets), `README.md` (good-fit bullet), `CHANGELOG.md` (Added), `UPGRADE.md` (item "Moving to a dedicated schema")

**Interfaces:**
- Consumes: `Names::$schema`, `Names::textConfig()`, `Names::stopDictionaryName()`, `Names::sidecar()`, `Names::sidecarName()` (Tasks 4-5), `PostgresEngine(…, string $schema)` (Task 5).
- Produces:
  - `PostgresIntrospector::__construct(Connection $connection, string $schema = 'public')`.
  - Bundle key `fuzzphony.schema` (scalar, default `public`); service `fuzzphony.engine` args `[connection, $extensionSchema, $schema]`; `fuzzphony.introspector` args `[connection, $schema]`; an invalid schema fails the container build with `InvalidConfiguration`.
  - Doctor check `Schema` (warning) for an install left in `public`.

- [ ] **Step 1: Write the failing tests**

Add to `DedicatedSchemaTest` (imports `Fuzzphony\Core\Inspection\Check`, `Fuzzphony\Core\Inspection\CheckStatus`, `Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector`):

```php
    public function testTheDoctorFindsEverythingInTheSchema(): void
    {
        $report = $this->fuzzphony('queue')->inspect('products');

        self::assertSame([], array_map(static fn(Check $c): string => $c->name . ': ' . $c->message, $report->problems()));
    }

    public function testTheDoctorWarnsAboutAnInstallLeftInPublic(): void
    {
        // what 0.3 built: everything in public
        (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual')])))->schema()->apply($this->connection);
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, schema: self::SCHEMA), new IndexRegistry([Indexes::products('manual')]));

        $checks = array_column($fuzzphony->inspect('products')->problems(), null, 'name');

        self::assertSame(CheckStatus::Warning, $checks['Schema']->status);
        self::assertSame('"fuzzphony_s" has no sidecar table for this index, but "public"."fuzzphony_products" exists: an install from before the dedicated schema.', $checks['Schema']->message);
        self::assertSame('See UPGRADE.md, "Moving to a dedicated schema": fuzzphony:schema --apply, fuzzphony:reindex, then drop the old objects.', $checks['Schema']->fix);
    }

    public function testNoLocationWarningWithoutAnOldInstall(): void
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, schema: self::SCHEMA), new IndexRegistry([Indexes::products('manual')]));

        $names = array_map(static fn(Check $c): string => $c->name, $fuzzphony->inspect('products')->checks);

        self::assertContains('Sidecar table', $names, 'never applied');
        self::assertNotContains('Schema', $names, 'and nothing left in public either');
    }

    public function testTheWizardDoesNotOfferTablesFromFuzzphonysSchema(): void
    {
        $this->fuzzphony('manual');
        $this->connection->execute(sprintf('CREATE TABLE %s.not_ours (id int)', self::SCHEMA));

        $hidden = array_column((new PostgresIntrospector($this->connection, self::SCHEMA))->tables(), 'table');
        $visible = array_column((new PostgresIntrospector($this->connection))->tables(), 'table');

        self::assertContains('fz_product', $hidden);
        self::assertNotContains(self::SCHEMA . '.not_ours', $hidden);
        self::assertContains(self::SCHEMA . '.not_ours', $visible, 'without the setting only fuzzphony_* tables are hidden');
    }

    public function testATextConfigurationWithTheSameNameInPublicDoesNotCount(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual')])))->schema()->apply($this->connection); // "public"."fuzzphony_english" exists too
        $this->connection->execute(sprintf('DROP TEXT SEARCH CONFIGURATION %s.fuzzphony_english CASCADE', self::SCHEMA));

        $checks = array_column($fuzzphony->inspect('products')->problems(), null, 'name');

        self::assertSame('"fuzzphony_s"."fuzzphony_english" is missing.', $checks['Text search configuration']->message);
    }
```

(`DROP TEXT SEARCH CONFIGURATION … CASCADE` drops nothing else: the sidecar stores `tsvector` values, not a dependency on the configuration.)

`AccentedStopWordsTest:171`: `'"public"."fuzzphony_german" is missing.'`

`FuzzphonyBundleTest` (import `Fuzzphony\Core\Exception\InvalidConfiguration`):

```php
    public function testTheSchemaReachesTheEngineAndTheIntrospector(): void
    {
        $default = $this->buildContainer(withOrm: false);
        $custom = $this->buildContainer(withOrm: false, config: ['schema' => 'fuzzphony', 'extension_schema' => 'extensions']);

        self::assertSame('public', $default->getDefinition('fuzzphony.engine')->getArgument(2));
        self::assertSame('public', $default->getDefinition('fuzzphony.introspector')->getArgument(1));
        self::assertSame('extensions', $custom->getDefinition('fuzzphony.engine')->getArgument(1));
        self::assertSame('fuzzphony', $custom->getDefinition('fuzzphony.engine')->getArgument(2));
        self::assertSame('fuzzphony', $custom->getDefinition('fuzzphony.introspector')->getArgument(1));
    }

    public function testAnInvalidSchemaFailsTheContainerBuild(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Invalid schema "bad name": use a plain identifier such as "fuzzphony".');

        $this->buildContainer(withOrm: false, config: ['schema' => 'bad name']);
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter FuzzphonyBundleTest` and the integration `--filter 'DedicatedSchemaTest|AccentedStopWordsTest'`.
Expected: FAIL (unknown config key `schema`, no `Schema` check, message without schema).

- [ ] **Step 3: Inspector**

`textConfig()`:

```php
    private function textConfig(IndexDefinition $index): Check
    {
        $label = $this->names->textConfig($index->text);
        $sql = 'SELECT count(*) > 0 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = :name';
        $params = ['name' => $this->names->textConfigName($index->text)];
        if ($index->text->unaccent) {
            // Fuzzphony's own copy must be in Fuzzphony's schema; a built-in one may be anywhere
            $sql .= ' AND n.nspname = :schema';
            $params['schema'] = $this->names->schema;
        }

        if (!(bool) $this->connection->fetchValue($sql, $params)) {
            return Check::error('Text search configuration', sprintf('%s is missing.', $label), self::APPLY);
        }
        if ($index->text->unaccent && $this->keepsAccentedStopWords($index)) {
            return Check::error(
                'Text search configuration',
                sprintf('%s keeps accented stop words (such as "für", "és", "à"): the stop-word dictionary %s does not run before unaccent.', $label, $this->names->stopDictionary($index->text)),
                self::APPLY . sprintf(', then bin/console fuzzphony:reindex %s', $index->name),
            );
        }

        return Check::ok('Text search configuration', $label);
    }
```

`keepsAccentedStopWords()`: in the `NOT EXISTS` subquery add `JOIN pg_namespace n ON n.oid = c.cfgnamespace` and `AND n.nspname = :schema`, pass `'schema' => $this->names->schema`. (`AccentedStopWordsTest` only asserts that message with `assertStringContainsString('keeps accented stop words', …)`, which still holds; its 0.3.0-repair test creates `"fuzzphony_german"` unqualified, i.e. in `public`, which is the default schema, so the namespace-aware lookup finds and repairs it.)

New check, right after the "Sidecar table" error in `inspect()`:

```php
        if (!$sidecarExists) {
            $checks[] = Check::error('Sidecar table', sprintf('Table %s does not exist.', $this->names->sidecar($index)), self::APPLY);
            array_push($checks, ...$this->location($index));
        } else {
```

```php
    /**
     * A dedicated schema that lacks this index while "public" still has it: an install from
     * before the "schema" setting, which is not moved automatically. (Only called when the
     * sidecar is missing, so with schema "public" the lookup below finds nothing either.)
     *
     * @return list<Check>
     */
    private function location(IndexDefinition $index): array
    {
        $legacy = Sql::ident('public.' . $this->names->sidecarName($index));
        if (!$this->regclass($legacy)) {
            return [];
        }

        return [Check::warning(
            'Schema',
            sprintf('%s has no sidecar table for this index, but %s exists: an install from before the dedicated schema.', $this->names->quotedSchema(), $legacy),
            'See UPGRADE.md, "Moving to a dedicated schema": fuzzphony:schema --apply, fuzzphony:reindex, then drop the old objects.',
        )];
    }
```

- [ ] **Step 4: Introspector**

```php
    public function __construct(
        private Connection $connection,
        /** Fuzzphony's schema: when it is not "public" it holds nothing to search, so it is hidden as a whole. */
        private string $schema = 'public',
    ) {}

    public function tables(): array
    {
        $hideSchema = $this->schema !== 'public';
        $rows = $this->connection->fetchAll(sprintf(<<<'SQL'
            SELECT CASE WHEN n.nspname = 'public' THEN c.relname ELSE n.nspname || '.' || c.relname END AS table_name,
                   greatest(c.reltuples, 0)::bigint AS rows
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ('r', 'p')
              AND n.nspname NOT IN ('pg_catalog', 'information_schema')
              AND n.nspname NOT LIKE 'pg_toast%%'
              AND c.relname NOT LIKE 'fuzzphony\_%%'
              AND NOT c.relispartition%s
            ORDER BY c.reltuples DESC, 1
            SQL, $hideSchema ? "\n  AND n.nspname <> :schema" : ''), $hideSchema ? ['schema' => $this->schema] : []);

        return array_map(static fn(array $r): array => ['table' => Coerce::str($r['table_name']), 'rows' => Coerce::int($r['rows'])], $rows);
    }
```

(`%` in the LIKE patterns is doubled because the SQL now goes through `sprintf`.)

- [ ] **Step 5: Bundle**

`configure()`: after `extension_schema`:

```php
                ->scalarNode('schema')->defaultValue('public')->info('Schema of Fuzzphony\'s own tables, functions and text search configurations (created by fuzzphony:schema --apply)')->end()
```

`loadExtension()` (import `Fuzzphony\Engine\Postgres\Schema\Names`):

```php
        $schemaRaw = Coerce::str($config['schema'] ?? null);
        // validated here, so an invalid name fails the container build instead of the first request
        $names = new Names($extensionSchema, $schemaRaw !== '' ? $schemaRaw : 'public');
        …
        $services->set('fuzzphony.engine', PostgresEngine::class)
            ->args([service('fuzzphony.connection'), $names->extensionSchema, $names->schema]);
        …
        $services->set('fuzzphony.introspector', PostgresIntrospector::class)->args([service('fuzzphony.connection'), $names->schema]);
```

Class docblock: add `  *     schema: public               # where Fuzzphony's own tables and functions live (e.g. fuzzphony)`.

(An empty `schema: ''` falls back to `public`, like `extension_schema`; `Names` never sees an empty string from the bundle.)

- [ ] **Step 6: Run the tests**

Run: unit + integration suites. Expected: PASS.

- [ ] **Step 7: Docs**

`docs/configuration.md` bundle block gains `  schema: public               # Fuzzphony's own schema, e.g. fuzzphony (see below)`; the "Fuzzphony's schema" section gains:

````markdown
With a dedicated schema, grant the application role what it needs there (the doctor and
`schema --apply` need more; run those as the owner):

```sql
GRANT USAGE ON SCHEMA fuzzphony TO app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA fuzzphony TO app;
```

`DROP SCHEMA fuzzphony CASCADE` then removes every index at once (drop the triggers on your tables
with `fuzzphony:schema --drop --apply` first). Moving an existing install out of `public` is not
automatic, see [UPGRADE.md](../UPGRADE.md#moving-to-a-dedicated-schema); until then the doctor
warns about the objects left in `public`.
````

`docs/languages.md:32-35`: "…creates a configuration `fuzzphony_<language>` in Fuzzphony's schema that copies…" and "…with a dictionary `fuzzphony_<language>_stop` (same schema) that uses…".

`docs/commands.md` doctor bullets: first bullet becomes "server version, extensions, text configuration and helper functions (looked up in Fuzzphony's schema);", add "- objects left in `public` after switching to a dedicated `schema`;".

`README.md`, "A good fit when" first bullet: append "With `schema: fuzzphony` not even a table lands in your schema."

CHANGELOG `### Added`:

```markdown
- `fuzzphony.schema` bundle setting (default `public`) for Fuzzphony's own schema; an invalid name
  fails the container build with `InvalidConfiguration`. The doctor looks its objects up in that
  schema and warns when an index is still in `public` from before the setting; the wizard hides
  the dedicated schema.
```

UPGRADE, new section after "From 0.3 to 0.4"'s numbered list (keep the anchor text exactly, the doctor refers to it):

````markdown
### Moving to a dedicated schema

Optional. Nothing moves by itself. The sync triggers on your tables keep their names in the new
schema, so the new `schema --apply` re-points them to the new functions; a `--drop` with the old
configuration run *afterwards* would remove them again. Two ways:

**Without downtime** (search keeps working from the old tables until the reindex is done):

1. Set `fuzzphony.schema: fuzzphony` (or pass `schema:` to `PostgresEngine`).
2. `bin/console fuzzphony:schema --apply`: creates the schema and every object in it and
   re-points the triggers to the new functions.
3. `bin/console fuzzphony:reindex`: fills the new sidecar tables.
4. Drop the old objects by hand (never with `--drop`, see above):

   ```sql
   DROP TABLE public.fuzzphony_<index>;              -- one per index
   DROP TABLE public.fuzzphony_queue, public.fuzzphony_meta;
   DROP FUNCTION public.fuzzphony_refresh_<index>, public.fuzzphony_sync_<index>__<table>, public.fuzzphony_norm;
   DROP TEXT SEARCH CONFIGURATION public.fuzzphony_<language>;
   DROP TEXT SEARCH DICTIONARY public.fuzzphony_<language>_stop;
   ```

**With a short search outage:**

1. With the old configuration: `bin/console fuzzphony:schema --drop --apply` (removes the
   triggers, functions and sidecar tables; the queue table, `fuzzphony_norm` and the text search
   configurations stay and can be dropped as above).
2. Set the schema, `bin/console fuzzphony:schema --apply`, `bin/console fuzzphony:reindex`.

`fuzzphony:doctor` warns ("Schema") while an old sidecar table is still in `public`.
````

- [ ] **Step 8: Run the gate.**

- [ ] **Step 9: Commit**

```bash
git add src/Engine/Postgres/Inspection/PostgresInspector.php src/Engine/Postgres/Wizard/PostgresIntrospector.php src/Bundle/FuzzphonyBundle.php \
  tests/Integration/DedicatedSchemaTest.php tests/Unit/Bundle/FuzzphonyBundleTest.php tests/Integration/AccentedStopWordsTest.php \
  docs/configuration.md docs/languages.md docs/commands.md README.md CHANGELOG.md UPGRADE.md
git commit -m "Add the fuzzphony.schema setting, schema-aware doctor and wizard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Sidecar version: `fuzzphony_meta` and the doctor's check (R7)

**Files:**
- Create: `src/Engine/Postgres/Schema/Fingerprint.php`
- Modify: `src/Engine/Postgres/Schema/Names.php` (`metaName()`, `meta()`), `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php` (`LAYOUT_VERSION`, meta table in `global()`, upserts in `global()` / `index()`, row deletion in `drop()`, `reindexed()`, `libraryVersion()`), `src/Core/Engine/Engine.php` (new `recordReindex()`), `src/Engine/Postgres/PostgresEngine.php` (implement it), `src/Core/Sync/Reindexer.php` (call it), `src/Engine/Postgres/Inspection/PostgresInspector.php` (`schemaVersion()` at the end of `inspect()`)
- Test: create `tests/Unit/Postgres/FingerprintTest.php`, `tests/Integration/MetaTableTest.php`; modify `tests/Unit/Postgres/NamesTest.php`, `tests/Unit/Postgres/SchemaGeneratorTest.php`, `tests/Unit/Core/Sync/ReindexerTest.php`, `tests/Unit/Core/Search/SearchBuilderTest.php` (anonymous `Engine`), `tests/Unit/Postgres/PostgresEngineGuardTest.php` (one more case), `tests/Integration/PostgresTestCase.php:47`, `tests/Integration/DedicatedSchemaTest.php`
- Docs: `docs/commands.md` (doctor), `docs/architecture.md` ("Sidecar table"), `CHANGELOG.md` (Added, Breaking), `UPGRADE.md` (item 5 extended, item "Custom engines")

**Interfaces:**
- Consumes: `Names` with schema (Task 5), typed withers (Task 2, in tests), `ReindexOptions` / `Reindexer::run()` (Task 3).
- Produces:
  - `Names::metaName(): string` (`fuzzphony_meta`), `Names::meta(): string` (`"<schema>"."fuzzphony_meta"`).
  - `final class Fingerprint` (`@internal`): `static definition(IndexDefinition): string`, `static documents(IndexDefinition): string`, `static shared(Names): string` — sha256 hex.
  - `PostgresSchemaGenerator::LAYOUT_VERSION = 1`, `PostgresSchemaGenerator::reindexed(IndexDefinition $index): string` (the SQL that records a full reindex).
  - `Engine::recordReindex(IndexDefinition $index): void`; `PostgresEngine::recordReindex()` guarded as `reindex record`.
  - Doctor checks, last in the report: `Schema version`, `Definition`, `Documents`.
  - Table `<schema>.fuzzphony_meta(index_name text primary key, layout_version integer not null, definition_hash text not null, documents_hash text, library_version text not null, applied_at timestamptz not null, reindexed_at timestamptz)`; row `*` for the shared objects.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Postgres/FingerprintTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Source;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    /** @return iterable<string, array{\Closure(IndexDefinition): IndexDefinition, bool, bool}> change, changes the DDL, changes the documents */
    public static function changes(): iterable
    {
        yield 'source' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSource(Source::table('fz_other')), true, true];
        yield 'field weight' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFields([new FieldDefinition('name', Weight::C, fuzzy: true), ...array_slice($d->fields, 1)]), true, true];
        yield 'filters' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFilters([]), true, true];
        yield 'text' => [static fn(IndexDefinition $d): IndexDefinition => $d->withText(new TextConfig('german')), true, true];
        yield 'boost' => [static fn(IndexDefinition $d): IndexDefinition => $d->withBoostColumn(null), true, true];
        yield 'recency' => [static fn(IndexDefinition $d): IndexDefinition => $d->withRecencyColumn(null), true, true];
        yield 'id type' => [static fn(IndexDefinition $d): IndexDefinition => $d->withIdType(IdType::String), true, false];
        yield 'watches' => [static fn(IndexDefinition $d): IndexDefinition => $d->withWatches([]), true, false];
        yield 'sync' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSync(SyncMode::Trigger), true, false];
        yield 'trigger level' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTriggerLevel(TriggerLevel::Row), true, false];
        yield 'tenant' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTenant('brand_id'), true, false];
        yield 'thresholds' => [static fn(IndexDefinition $d): IndexDefinition => $d->withThresholds(new Thresholds(minScore: 0.3)), false, false];
        yield 'profiles' => [static fn(IndexDefinition $d): IndexDefinition => $d->withProfiles(['default' => new RankingProfile(text: 0.5)]), false, false];
        yield 'highlight flag' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFields([new FieldDefinition('name', Weight::A, fuzzy: true, highlight: false), ...array_slice($d->fields, 1)]), false, false];
    }

    /** @param \Closure(IndexDefinition): IndexDefinition $change */
    #[DataProvider('changes')]
    public function testEachHashFollowsTheParts(\Closure $change, bool $ddl, bool $documents): void
    {
        $original = Indexes::products();
        $changed = $change($original);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Fingerprint::definition($original));
        self::assertSame(Fingerprint::definition($original), Fingerprint::definition(Indexes::products()), 'stable');
        self::assertSame($ddl, Fingerprint::definition($changed) !== Fingerprint::definition($original));
        self::assertSame($documents, Fingerprint::documents($changed) !== Fingerprint::documents($original));
    }

    public function testTheSharedHashFollowsTheSchemas(): void
    {
        self::assertSame(Fingerprint::shared(new Names()), Fingerprint::shared(new Names('public', 'public')));
        self::assertNotSame(Fingerprint::shared(new Names()), Fingerprint::shared(new Names(schema: 'fuzzphony')));
        self::assertNotSame(Fingerprint::shared(new Names()), Fingerprint::shared(new Names('extensions')));
    }
}
```

(`Indexes::products()`'s first field is `name`, weight A, fuzzy; the "field weight" and "highlight flag" rows rely on that.)

`NamesTest::testObjectNames` add:

```php
        self::assertSame('fuzzphony_meta', $names->metaName());
        self::assertSame('"public"."fuzzphony_meta"', $names->meta());
```

`SchemaGeneratorTest` (imports `Composer\InstalledVersions`, `Fuzzphony\Engine\Postgres\Schema\Fingerprint`, `Fuzzphony\Engine\Postgres\Sql\Sql`):

```php
    public function testApplyCreatesTheMetaTableAndRecordsTheSharedObjectsLast(): void
    {
        $statements = (new PostgresSchemaGenerator())->global(Indexes::products())->statements;
        $sql = implode("\n", array_map(static fn(Statement $s): string => $s->sql, $statements));
        $last = $statements[array_key_last($statements)];

        self::assertStringContainsString(
            "CREATE TABLE IF NOT EXISTS \"public\".\"fuzzphony_meta\" (\n    index_name text PRIMARY KEY,\n    layout_version integer NOT NULL,\n    definition_hash text NOT NULL,\n    documents_hash text,\n    library_version text NOT NULL,\n    applied_at timestamptz NOT NULL,\n    reindexed_at timestamptz\n)",
            $sql,
        );
        self::assertFalse($last->transactional, 'after everything else');
        self::assertSame(self::upsert('*', Fingerprint::shared(new Names())), $last->sql);
    }

    public function testApplyRecordsTheIndexLayoutAndDefinitionAfterTheConcurrentIndexBuilds(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products())->statements;
        $last = $statements[array_key_last($statements)];

        self::assertSame(1, PostgresSchemaGenerator::LAYOUT_VERSION);
        self::assertFalse($last->transactional);
        self::assertSame(self::upsert('products', Fingerprint::definition(Indexes::products())), $last->sql);
        self::assertSame('Record the layout and definition "products" was built from', $last->description);
    }

    public function testDropForgetsTheVersionRecordAndAReindexRecordsTheDocuments(): void
    {
        $generator = new PostgresSchemaGenerator();
        $drop = $generator->drop(Indexes::products())->statements;

        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_meta\"') IS NOT NULL THEN DELETE FROM \"public\".\"fuzzphony_meta\" WHERE index_name = 'products'; END IF; END \$fuzzphony\$",
            $drop[array_key_last($drop)]->sql,
        );
        self::assertSame(
            sprintf("DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_meta\"') IS NOT NULL THEN UPDATE \"public\".\"fuzzphony_meta\" SET documents_hash = '%s', reindexed_at = now() WHERE index_name = 'products'; END IF; END \$fuzzphony\$", Fingerprint::documents(Indexes::products())),
            $generator->reindexed(Indexes::products()),
        );
    }

    private static function upsert(string $index, string $hash): string
    {
        return sprintf(
            "INSERT INTO \"public\".\"fuzzphony_meta\" (index_name, layout_version, definition_hash, library_version, applied_at)\nVALUES ('%s', 1, '%s', %s, now())\nON CONFLICT (index_name) DO UPDATE SET layout_version = EXCLUDED.layout_version, definition_hash = EXCLUDED.definition_hash, library_version = EXCLUDED.library_version, applied_at = EXCLUDED.applied_at",
            $index,
            $hash,
            Sql::string((string) InstalledVersions::getPrettyVersion('fuzzphony/fuzzphony')),
        );
    }
```

`ReindexerTest` — the full run records, a resumed or empty-skipped run does not; add to the existing tests:

```php
    // testBatchesUntilAShortBatchAndReportsProgress():
        $engine->expects(self::once())->method('recordReindex');
    // testAResumedRunStartsAfterTheIdAndNeverPrunes():
        $engine->expects(self::never())->method('recordReindex');
    // testPruneFalseSkipsPruning(): a full run without pruning still rebuilt every document
        $engine->expects(self::once())->method('recordReindex');
    // testAnEmptySourceIsOnlyPrunedWhenAskedTo(): $engine never, $forced once
        $engine->expects(self::never())->method('recordReindex');
        $forced->expects(self::once())->method('recordReindex');
```

`SearchBuilderTest`'s anonymous `Engine` gains:

```php
            public function recordReindex(IndexDefinition $index): void
            {
                throw new \LogicException('not used by this test');
            }
```

`PostgresEngineGuardTest::operations()` gains:

```php
        yield 'reindex record' => ['reindex record', 'Run "fuzzphony:schema --apply".', $always, static fn(PostgresEngine $e): mixed => $e->recordReindex(Indexes::products())];
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter 'FingerprintTest|NamesTest|SchemaGeneratorTest|ReindexerTest|PostgresEngineGuardTest'`
Expected: FAIL (`Fingerprint` not found, no `recordReindex`).

- [ ] **Step 3: `Fingerprint` and `Names::meta()`**

`src/Engine/Postgres/Schema/Fingerprint.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;

/**
 * @internal What fuzzphony_meta records about a definition. The definition hash covers what shapes
 * the DDL (written by schema --apply, compared by the doctor); the documents hash covers what shapes
 * the stored documents (written by a full reindex). Ranking settings shape neither.
 */
final class Fingerprint
{
    public static function definition(IndexDefinition $index): string
    {
        return self::hash([
            'source' => self::source($index),
            'fields' => self::fields($index),
            'filters' => self::filters($index),
            'id_type' => $index->idType->value,
            'watches' => array_map(static fn(Watch $w): array => [$w->table, $w->affectedIds, $w->keyColumn, $w->columns], $index->watches),
            'sync' => $index->sync->value,
            'trigger_level' => $index->triggerLevel->value,
            'text' => [$index->text->language, $index->text->unaccent],
            'boost' => $index->boostColumn,
            'recency' => $index->recencyColumn,
            'tenant' => $index->tenant,
        ]);
    }

    public static function documents(IndexDefinition $index): string
    {
        return self::hash([
            'source' => self::source($index),
            'fields' => self::fields($index),
            'filters' => self::filters($index),
            'text' => [$index->text->language, $index->text->unaccent],
            'boost' => $index->boostColumn,
            'recency' => $index->recencyColumn,
        ]);
    }

    /** The shared objects (queue, normaliser, text configurations) depend only on where they live. */
    public static function shared(Names $names): string
    {
        return self::hash(['schema' => $names->schema, 'extension_schema' => $names->extensionSchema]);
    }

    /** @return array{string|null, string|null, string} */
    private static function source(IndexDefinition $index): array
    {
        return [$index->source->table, $index->source->query, $index->source->idColumn];
    }

    /** @return list<array{string, string, string, bool}> */
    private static function fields(IndexDefinition $index): array
    {
        return array_map(static fn(FieldDefinition $f): array => [$f->name, $f->column(), $f->weight->value, $f->fuzzy], $index->fields);
    }

    /** @return list<array{string, string, string}> */
    private static function filters(IndexDefinition $index): array
    {
        return array_map(static fn(FilterDefinition $f): array => [$f->name, $f->column(), $f->type->value], $index->filters);
    }

    /** @param array<string, mixed> $parts */
    private static function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
```

`Names`:

```php
    public function metaName(): string
    {
        return self::PREFIX . 'meta';
    }

    /** One row per index (plus "*" for the shared objects): the layout and definition it was built from. */
    public function meta(): string
    {
        return $this->qualify($this->metaName());
    }
```

- [ ] **Step 4: Generator**

Imports: `Composer\InstalledVersions`. Constant with docblock:

```php
    /**
     * The sidecar layout this version generates (1 = the 0.4 layout), recorded in fuzzphony_meta.
     * The milestone that first changes the layout bumps it and adds the upgrade step that runs
     * from the stored version up, together with its test; 0.4 has no step to run.
     */
    public const int LAYOUT_VERSION = 1;
```

`global()`: right after the queue order index statement:

```php
            new Statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n    index_name text PRIMARY KEY,\n    layout_version integer NOT NULL,\n    definition_hash text NOT NULL,\n    documents_hash text,\n    library_version text NOT NULL,\n    applied_at timestamptz NOT NULL,\n    reindexed_at timestamptz\n)",
                $this->names->meta(),
            ), 'Layout and definition each index was built from'),
```

and as the very last statement of `global()` (after the text configurations):

```php
        $statements[] = $this->recordApply('*', Fingerprint::shared($this->names), 'Record the layout of the shared objects');
```

`index()`: last statement, after the `CREATE INDEX CONCURRENTLY` loop:

```php
        $statements[] = $this->recordApply($index->name, Fingerprint::definition($index), sprintf('Record the layout and definition "%s" was built from', $index->name));
```

`drop()`: last statement:

```php
        $statements[] = new Statement(sprintf(
            'DO ' . self::TAG . ' BEGIN IF to_regclass(%s) IS NOT NULL THEN DELETE FROM %s WHERE index_name = %s; END IF; END ' . self::TAG,
            Sql::string($this->names->meta()),
            $this->names->meta(),
            Sql::string($index->name),
        ), 'Forget the version record');
```

New methods:

```php
    /** Records that a full reindex rebuilt every document from this definition; a no-op before the meta table exists. */
    public function reindexed(IndexDefinition $index): string
    {
        return sprintf(
            'DO ' . self::TAG . ' BEGIN IF to_regclass(%s) IS NOT NULL THEN UPDATE %s SET documents_hash = %s, reindexed_at = now() WHERE index_name = %s; END IF; END ' . self::TAG,
            Sql::string($this->names->meta()),
            $this->names->meta(),
            Sql::string(Fingerprint::documents($index)),
            Sql::string($index->name),
        );
    }

    /**
     * The meta row upsert. Not transactional, so SchemaPlan::apply() runs it after everything else,
     * including the concurrent index builds: the row only claims what was actually built.
     */
    private function recordApply(string $indexName, string $definitionHash, string $description): Statement
    {
        return new Statement(sprintf(
            "INSERT INTO %s (index_name, layout_version, definition_hash, library_version, applied_at)\nVALUES (%s, %d, %s, %s, now())\nON CONFLICT (index_name) DO UPDATE SET layout_version = EXCLUDED.layout_version, definition_hash = EXCLUDED.definition_hash, library_version = EXCLUDED.library_version, applied_at = EXCLUDED.applied_at",
            $this->names->meta(),
            Sql::string($indexName),
            self::LAYOUT_VERSION,
            Sql::string($definitionHash),
            Sql::string(self::libraryVersion()),
        ), $description, transactional: false);
    }

    /** For the record only: the monorepo package, or the engine package when installed split (as in the demo). */
    private static function libraryVersion(): string
    {
        return InstalledVersions::getPrettyVersion(InstalledVersions::isInstalled('fuzzphony/fuzzphony') ? 'fuzzphony/fuzzphony' : 'fuzzphony/postgres-engine') ?? 'unknown';
    }
```

- [ ] **Step 5: `Engine::recordReindex()`, the engine and the reindexer**

`Engine` (after `pruneOrphans()`):

```php
    /**
     * Called by the reindexer after a full run (not a resumed one) rebuilt every document from
     * $index. Engines that do not track which definition built the documents do nothing.
     */
    public function recordReindex(IndexDefinition $index): void;
```

`PostgresEngine`:

```php
    public function recordReindex(IndexDefinition $index): void
    {
        $this->guard('reindex record', fn(): int => $this->connection->execute($this->schema->reindexed($index)), 'Run "fuzzphony:schema --apply".');
    }
```

`Reindexer::run()` — the three returns become one exit that records a completed full run:

```php
        if ($options->resumeAfter !== null || !$options->prune) {
            $result = new ReindexResult($written);
        } elseif ($seen === 0 && !$options->pruneEmpty) {
            $result = new ReindexResult($written, pruneSkippedEmptySource: true);
        } else {
            $result = new ReindexResult($written, $this->engine->pruneOrphans($index, $options->batchSize));
        }
        // a resumed run covers part of the source; a skipped empty source is likely a visibility problem
        if ($options->resumeAfter === null && !$result->pruneSkippedEmptySource) {
            $this->engine->recordReindex($index);
        }

        return $result;
```

- [ ] **Step 6: The doctor's version checks**

`PostgresInspector::inspect()`: last line before the `return`: `array_push($checks, ...$this->schemaVersion($index));` (last on purpose, so `problems()[0]` of every existing report stays the same). Imports `Fingerprint`, `PostgresSchemaGenerator` (same namespace family: `Fuzzphony\Engine\Postgres\Schema\…`).

```php
    /** @return list<Check> */
    private function schemaVersion(IndexDefinition $index): array
    {
        $row = $this->regclass($this->names->meta())
            ? ($this->connection->fetchAll(
                sprintf('SELECT layout_version, definition_hash, documents_hash, library_version FROM %s WHERE index_name = :index', $this->names->meta()),
                ['index' => $index->name],
            )[0] ?? null)
            : null;
        if ($row === null) {
            return [Check::warning('Schema version', 'No version record: built before 0.4, or never applied.', self::APPLY)];
        }

        $layout = Coerce::int($row['layout_version']);
        $current = PostgresSchemaGenerator::LAYOUT_VERSION;
        $by = Coerce::str($row['library_version']);

        return [
            match (true) {
                $layout < $current => Check::error('Schema version', sprintf('Layout %d is older than this library\'s layout %d.', $layout, $current), self::APPLY),
                $layout > $current => Check::error('Schema version', sprintf('Layout %d was applied by a newer Fuzzphony (%s); this library knows layout %d.', $layout, $by, $current), sprintf('Upgrade fuzzphony/fuzzphony to %s or later.', $by)),
                default => Check::ok('Schema version', sprintf('layout %d, applied by %s', $layout, $by)),
            },
            Coerce::str($row['definition_hash']) === Fingerprint::definition($index)
                ? Check::ok('Definition', 'unchanged since the last apply')
                : Check::error('Definition', 'The definition changed since the last apply.', self::APPLY),
            Coerce::str($row['documents_hash']) === Fingerprint::documents($index)
                ? Check::ok('Documents', 'built from the current definition')
                : Check::warning('Documents', 'The documents were built from another definition, or not fully reindexed since 0.4.', sprintf('bin/console fuzzphony:reindex %s', $index->name)),
        ];
    }
```

- [ ] **Step 7: Integration tests**

`PostgresTestCase::createFixtures()` line 47: add `fuzzphony_meta` to the `DROP TABLE IF EXISTS … CASCADE` list.

`DedicatedSchemaTest::testEveryObjectLivesInTheConfiguredSchema`: the table list becomes `['fuzzphony_products', 'fuzzphony_queue', 'fuzzphony_meta']`.

`tests/Integration/MetaTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Integration\Command\CommandTestCase;
use PHPUnit\Framework\TestCase;

/** fuzzphony_meta: apply records the layout and definition, a full reindex the documents, the doctor compares. */
final class MetaTableTest extends TestCase
{
    private CommandTestCase $context;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase(); // fixtures reset, fuzzphony_meta dropped, "products" (manual sync)
    }

    public function testApplyWritesTheRowAndOnlyAFullReindexTheDocumentsHash(): void
    {
        $index = $this->context->fuzzphony->registry()->get('products');
        $this->context->fuzzphony->schema()->apply($this->context->connection);

        $row = $this->row('products');
        self::assertSame(PostgresSchemaGenerator::LAYOUT_VERSION, Coerce::int($row['layout_version']));
        self::assertSame(Fingerprint::definition($index), $row['definition_hash']);
        self::assertNull($row['documents_hash']);
        self::assertNull($row['reindexed_at']);
        self::assertNotSame('', Coerce::str($row['library_version']));
        self::assertSame(1, Coerce::int($this->row('*')['layout_version']));

        $this->context->fuzzphony->reindex('products', new ReindexOptions(resumeAfter: 2));
        self::assertNull($this->row('products')['documents_hash'], 'a resumed run does not cover every document');

        $this->context->fuzzphony->reindex('products');
        $row = $this->row('products');
        self::assertSame(Fingerprint::documents($index), $row['documents_hash']);
        self::assertNotNull($row['reindexed_at']);
    }

    public function testTheDoctorReportsEachKindOfDrift(): void
    {
        $this->context->applySchemaAndReindex();
        self::assertSame(['Schema version' => CheckStatus::Ok, 'Definition' => CheckStatus::Ok, 'Documents' => CheckStatus::Ok], $this->statuses());

        $this->context->connection->execute("UPDATE fuzzphony_meta SET definition_hash = 'x', documents_hash = NULL WHERE index_name = 'products'");
        self::assertSame(['Schema version' => CheckStatus::Ok, 'Definition' => CheckStatus::Error, 'Documents' => CheckStatus::Warning], $this->statuses());
        self::assertSame('bin/console fuzzphony:reindex products', $this->check('Documents')->fix);

        $this->context->connection->execute("UPDATE fuzzphony_meta SET layout_version = 0 WHERE index_name = 'products'");
        self::assertSame("Layout 0 is older than this library's layout 1.", $this->check('Schema version')->message);
        self::assertSame(CheckStatus::Error, $this->check('Schema version')->status);

        $this->context->connection->execute("UPDATE fuzzphony_meta SET layout_version = 99, library_version = '9.0.0' WHERE index_name = 'products'");
        self::assertSame('Layout 99 was applied by a newer Fuzzphony (9.0.0); this library knows layout 1.', $this->check('Schema version')->message);
        self::assertSame('Upgrade fuzzphony/fuzzphony to 9.0.0 or later.', $this->check('Schema version')->fix);

        $this->context->connection->execute("DELETE FROM fuzzphony_meta WHERE index_name = 'products'");
        self::assertSame(['Schema version' => CheckStatus::Warning], $this->statuses());
        self::assertSame('No version record: built before 0.4, or never applied.', $this->check('Schema version')->message);

        $this->context->connection->execute('DROP TABLE fuzzphony_meta');
        self::assertSame(['Schema version' => CheckStatus::Warning], $this->statuses(), 'an install from before 0.4');
    }

    public function testDropForgetsTheRowAndWorksWithoutTheTable(): void
    {
        $this->context->applySchemaAndReindex();
        $plan = $this->context->engine->dropSchema($this->context->fuzzphony->registry()->get('products'));

        $plan->apply($this->context->connection);
        self::assertSame([], $this->context->connection->fetchAll("SELECT 1 FROM fuzzphony_meta WHERE index_name = 'products'"));
        self::assertNotSame([], $this->context->connection->fetchAll("SELECT 1 FROM fuzzphony_meta WHERE index_name = '*'"), 'the shared row stays');

        $this->context->connection->execute('DROP TABLE fuzzphony_meta');
        $plan->apply($this->context->connection); // an install from before 0.4
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_meta')"));
    }

    public function testAReindexBeforeTheFirstApplyDoesNotFail(): void
    {
        $this->context->applySchemaAndReindex();
        $this->context->connection->execute('DROP TABLE fuzzphony_meta'); // a 0.3 install, upgraded, not yet applied

        self::assertSame(5, $this->context->fuzzphony->reindex('products')->written);
    }

    /** @return array<string, mixed> */
    private function row(string $index): array
    {
        return $this->context->connection->fetchAll('SELECT * FROM fuzzphony_meta WHERE index_name = :index', ['index' => $index])[0]
            ?? self::fail(sprintf('no meta row for "%s"', $index));
    }

    /** @return array<string, CheckStatus> */
    private function statuses(): array
    {
        $statuses = [];
        foreach ($this->context->fuzzphony->inspect('products')->checks as $check) {
            if (in_array($check->name, ['Schema version', 'Definition', 'Documents'], true)) {
                $statuses[$check->name] = $check->status;
            }
        }

        return $statuses;
    }

    private function check(string $name): Check
    {
        return array_find($this->context->fuzzphony->inspect('products')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}
```

(`CommandTestCase` calls `PostgresTestCase::createFixtures()`, which now drops `fuzzphony_meta`, so every test starts without it.)

- [ ] **Step 8: Run the tests**

Run: unit, then integration. Expected: PASS. If an existing test that asserts a healthy report (`PostgresEngineTest::testDoctorIsHappyAfterInstall`, `testSwitchingTriggerLevelLeavesNoDuplicates`, `testDoctorReportsAMissingTruncateTrigger`, `DoctorCommandTest::testHealthyIndexExitsZero`, `WizardTest`) fails, the fix is a full `reindex()` after its last `apply()` in the test, never a weaker assertion. All of them already reindex after applying, so none is expected to fail.

- [ ] **Step 9: Docs**

`docs/commands.md` doctor bullets, append:

```markdown
- the schema version: which layout and definition the index was last applied with (an error when
  the definition changed since, or the layout is older or newer than this library's) and whether
  the documents were built from the current definition (a warning until a full
  `fuzzphony:reindex` records it).
```

`docs/architecture.md` "Sidecar table", append: "`fuzzphony_meta` (same schema) records, per index, the sidecar layout version, a hash of the definition it was applied with and a hash of the definition its documents were built from; `fuzzphony:doctor` compares them with the current definition."

CHANGELOG `### Added`:

```markdown
- `fuzzphony_meta`: `fuzzphony:schema --apply` records per index the sidecar layout version (1),
  a hash of the definition parts that shape the DDL, and the library version; a full reindex
  records a hash of the parts that shape the documents. The doctor's new "Schema version",
  "Definition" and "Documents" checks report a missing record, a layout older or newer than the
  library's, a definition changed since the last apply, and documents built from another
  definition. `--drop` deletes the index's record; `--dump-migration` includes the upsert.
```

CHANGELOG `### Breaking`, append:

```markdown
- `Engine` has a new method `recordReindex(IndexDefinition $index): void`, called after a full
  reindex. Custom engines must implement it (an empty body is fine).
```

UPGRADE item 5, append: "It also creates `fuzzphony_meta` and records each index's layout and definition. Until the next full `bin/console fuzzphony:reindex`, `fuzzphony:doctor` warns that the documents' definition is unknown (check "Documents"); search works without it, but run one reindex before `fuzzphony:doctor --strict` in CI."

UPGRADE item 6:

```markdown
6. **Custom engines** implement `Engine::recordReindex(IndexDefinition $index): void`; an empty
   body is fine if the engine does not track which definition built its documents.
```

(Renumber "Moving to a dedicated schema" stays a `###` subsection after the list.)

- [ ] **Step 10: Run the gate.** The Infection run must kill the `?? 'unknown'` and ternary mutants of `libraryVersion()` through `SchemaGeneratorTest::upsert()`'s exact version string.

- [ ] **Step 11: Commit**

```bash
git add src/Engine/Postgres/Schema/Fingerprint.php src/Engine/Postgres/Schema/Names.php src/Engine/Postgres/Schema/PostgresSchemaGenerator.php \
  src/Core/Engine/Engine.php src/Engine/Postgres/PostgresEngine.php src/Core/Sync/Reindexer.php src/Engine/Postgres/Inspection/PostgresInspector.php \
  tests/Unit/Postgres/FingerprintTest.php tests/Unit/Postgres/NamesTest.php tests/Unit/Postgres/SchemaGeneratorTest.php tests/Unit/Core/Sync/ReindexerTest.php \
  tests/Unit/Core/Search/SearchBuilderTest.php tests/Unit/Postgres/PostgresEngineGuardTest.php tests/Integration/PostgresTestCase.php \
  tests/Integration/DedicatedSchemaTest.php tests/Integration/MetaTableTest.php docs/commands.md docs/architecture.md CHANGELOG.md UPGRADE.md
git commit -m "Record the sidecar layout and definition in fuzzphony_meta and check them

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Doctrine Migrations: the schema filter (R8)

**Files:**
- Create: `src/Bridge/Doctrine/SchemaAssetFilter.php`
- Modify: `src/Bundle/FuzzphonyBundle.php` (new `prependExtension()`, `DoctorCommand` argument), `src/Bundle/Command/DoctorCommand.php` (constructor, output), `composer.json` (`suggest`), `src/Bundle/composer.json` (`suggest`)
- Test: create `tests/Unit/Bridge/Doctrine/SchemaAssetFilterTest.php`, `tests/Integration/Bridge/SchemaFilterTest.php`; modify `tests/Unit/Bundle/FuzzphonyBundleTest.php`, `tests/Integration/Command/DoctorCommandTest.php`, `tests/Integration/Command/SchemaCommandTest.php` (`testDumpMigrationWritesAMigrationFile`)
- Docs: `docs/integrations.md` (new "Doctrine Migrations" section), `docs/commands.md` (schema row), `CHANGELOG.md` (Added)

**Interfaces:**
- Consumes: `Names` schema rules (Task 5), meta upsert in the index plan (Task 7).
- Produces:
  - `final class Fuzzphony\Bridge\Doctrine\SchemaAssetFilter` (`@internal`): `static regex(string $schema): string` → `~^(?!(public\.)?fuzzphony_)~` for `public`, `~^(?!<schema>\.)~` otherwise.
  - `FuzzphonyBundle::prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void`: with DoctrineBundle present, prepends `doctrine.dbal.connections.<fuzzphony.connection>.schema_filter`; when the application sets a `schema_filter` itself, sets container parameter `fuzzphony.schema_filter_conflict` (the regex) instead.
  - `DoctorCommand::__construct(Fuzzphony $fuzzphony, ?string $schemaFilter = null)`: a non-null value prints a "Doctrine schema filter" warning with the regex to merge.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Bridge/Doctrine/SchemaAssetFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaAssetFilterTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> schema, asset name as DBAL passes it, kept */
    public static function names(): iterable
    {
        yield 'public: application table' => ['public', 'product', true];
        yield 'public: qualified application table' => ['public', 'public.product', true];
        yield 'public: sidecar' => ['public', 'fuzzphony_products', false];
        yield 'public: qualified queue' => ['public', 'public.fuzzphony_queue', false];
        yield 'public: similar prefix' => ['public', 'fuzzphonyx', true];
        yield 'public: fuzzphony_ table in another schema' => ['public', 'shop.fuzzphony_x', true];
        yield 'dedicated: its tables' => ['fuzzphony', 'fuzzphony.fuzzphony_products', false];
        yield 'dedicated: anything in it' => ['fuzzphony', 'fuzzphony.other', false];
        yield 'dedicated: public table' => ['fuzzphony', 'product', true];
        yield 'dedicated: schema with the same prefix' => ['fuzzphony', 'fuzzphony2.product', true];
    }

    #[DataProvider('names')]
    public function testTheFilterHidesOnlyFuzzphonysObjects(string $schema, string $asset, bool $kept): void
    {
        self::assertSame($kept, preg_match(SchemaAssetFilter::regex($schema), $asset) === 1);
    }

    public function testTheRegexes(): void
    {
        self::assertSame('~^(?!(public\.)?fuzzphony_)~', SchemaAssetFilter::regex('public'));
        self::assertSame('~^(?!fuzzphony\.)~', SchemaAssetFilter::regex('fuzzphony'));
    }
}
```

`FuzzphonyBundleTest` (imports `Fuzzphony\Bundle\Command\DoctorCommand`, `Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface`):

```php
    public function testTheSchemaFilterIsPrependedForTheFuzzphonyConnection(): void
    {
        $public = $this->prepended(['connection' => 'default'], null);
        $dedicated = $this->prepended(['connection' => 'main', 'schema' => 'fuzzphony'], ['dbal' => ['url' => 'pgsql://x']]);

        self::assertSame([['dbal' => ['connections' => ['default' => ['schema_filter' => '~^(?!(public\.)?fuzzphony_)~']]]]], $public->getExtensionConfig('doctrine'));
        self::assertSame(['dbal' => ['connections' => ['main' => ['schema_filter' => '~^(?!fuzzphony\.)~']]]], $dedicated->getExtensionConfig('doctrine')[0]);
        self::assertFalse($dedicated->hasParameter('fuzzphony.schema_filter_conflict'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function ownFilters(): iterable
    {
        yield 'shorthand dbal config' => [['dbal' => ['schema_filter' => '~^(?!legacy_)~']]];
        yield 'named connection' => [['dbal' => ['connections' => ['default' => ['schema_filter' => '~^(?!legacy_)~']]]]];
    }

    /** @param array<string, mixed> $doctrine */
    #[DataProvider('ownFilters')]
    public function testAnApplicationFilterIsLeftAloneAndReportedByTheDoctor(array $doctrine): void
    {
        $container = $this->prepended([], $doctrine);

        self::assertSame([$doctrine], $container->getExtensionConfig('doctrine'), 'nothing prepended');
        self::assertSame('~^(?!(public\.)?fuzzphony_)~', $container->getParameter('fuzzphony.schema_filter_conflict'));

        $extension = $container->getExtension('fuzzphony');
        $extension->load($container->getExtensionConfig('fuzzphony'), $container);
        self::assertSame('~^(?!(public\.)?fuzzphony_)~', $container->getDefinition(DoctorCommand::class)->getArgument(1));
    }

    public function testWithoutDoctrineNothingIsPrepended(): void
    {
        $container = $this->prepended([], null, withDoctrine: false);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
        self::assertFalse($container->hasParameter('fuzzphony.schema_filter_conflict'));
        $container->getExtension('fuzzphony')->load($container->getExtensionConfig('fuzzphony'), $container);
        self::assertNull($container->getDefinition(DoctorCommand::class)->getArgument(1));
    }

    /**
     * Registers Fuzzphony's extension (and a fake "doctrine" one), loads the given configs and
     * runs the prepend phase, as a kernel does before loading the extensions.
     *
     * @param array<string, mixed>      $fuzzphony
     * @param array<string, mixed>|null $doctrine
     */
    private function prepended(array $fuzzphony, ?array $doctrine, bool $withDoctrine = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['kernel.environment' => 'test', 'kernel.debug' => false, 'kernel.project_dir' => dirname(__DIR__, 3), 'kernel.cache_dir' => sys_get_temp_dir(), 'kernel.build_dir' => sys_get_temp_dir()] as $name => $value) {
            $container->setParameter($name, $value);
        }
        if ($withDoctrine) {
            $container->registerExtension(new class extends Extension {
                public function load(array $configs, ContainerBuilder $container): void {}

                public function getAlias(): string
                {
                    return 'doctrine';
                }
            });
        }
        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('fuzzphony', $fuzzphony);
        if ($doctrine !== null) {
            $container->loadFromExtension('doctrine', $doctrine);
        }
        $extension->prepend($container);

        return $container;
    }
```

`DoctorCommandTest`:

```php
    public function testAnApplicationSchemaFilterIsAWarningWithTheRegexToMerge(): void
    {
        $this->context->applySchemaAndReindex();
        $tester = new CommandTester(new DoctorCommand($this->context->fuzzphony, '~^(?!(public\.)?fuzzphony_)~'));

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => false]), $tester->getDisplay());
        self::assertStringContainsString('Doctrine schema filter', $tester->getDisplay());
        self::assertStringContainsString('~^(?!(public\.)?fuzzphony_)~', $tester->getDisplay());
        self::assertStringContainsString('Healthy, with warnings', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true], ['interactive' => false]));
    }

    public function testTheSchemaFilterWarningDoesNotHideAnError(): void
    {
        $tester = new CommandTester(new DoctorCommand($this->context->fuzzphony, '~^(?!(public\.)?fuzzphony_)~'));

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false])); // schema never applied
        self::assertStringContainsString('Problems found', $tester->getDisplay());
    }
```

(`SymfonyStyle::writeln()` does not wrap, so the regex arrives on one line.)

`SchemaCommandTest::testDumpMigrationWritesAMigrationFile`, add after `self::assertStringContainsString('isTransactional', $contents);`:

```php
            // the last statement of up() records the index's layout and definition (var_export escapes the quotes)
            $last = substr($contents, (int) strrpos($contents, '$this->addSql('));
            self::assertStringStartsWith('$this->addSql(\'INSERT INTO "public"."fuzzphony_meta" (index_name, layout_version,', $last);
            self::assertStringContainsString("VALUES (\\'products\\', 1, ", $last);
            self::assertStringContainsString('ON CONFLICT (index_name) DO UPDATE SET layout_version = EXCLUDED.layout_version', $last);
```

`tests/Integration/Bridge/SchemaFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Table;
use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/** The schema_filter the bundle prepends hides Fuzzphony's tables from Doctrine's schema tools (migrations:diff). */
final class SchemaFilterTest extends TestCase
{
    private const string SCHEMA = 'fuzzphony_f';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        // leftovers of other tests (tsvector columns DBAL cannot map) must not decide this test
        foreach ($this->connection->fetchAll("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'fuzzphony\\_%'") as $row) {
            $this->connection->execute('DROP TABLE ' . Sql::ident('public.' . Coerce::str($row['tablename'])) . ' CASCADE');
        }
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
    }

    public function testFuzzphonysTablesInPublicAreHidden(): void
    {
        $this->apply('public');

        $tables = $this->introspectedTables(SchemaAssetFilter::regex('public'));

        self::assertNotSame([], array_filter($tables, static fn(string $t): bool => str_ends_with($t, 'fz_product')), 'application tables stay visible');
        self::assertSame([], array_values(array_filter($tables, static fn(string $t): bool => str_contains($t, 'fuzzphony_'))));
    }

    public function testADedicatedSchemaIsHiddenAsAWhole(): void
    {
        $this->apply(self::SCHEMA);

        $tables = $this->introspectedTables(SchemaAssetFilter::regex(self::SCHEMA));

        self::assertNotSame([], array_filter($tables, static fn(string $t): bool => str_ends_with($t, 'fz_product')));
        self::assertSame([], array_values(array_filter($tables, static fn(string $t): bool => str_starts_with($t, self::SCHEMA . '.'))));
    }

    private function apply(string $schema): void
    {
        (new Fuzzphony(new PostgresEngine($this->connection, schema: $schema), new IndexRegistry([Indexes::products('manual')])))->schema()->apply($this->connection);
    }

    /** @return list<string> */
    private function introspectedTables(string $regex): array
    {
        $dbal = DoctrineTestCase::dbalConnection();
        // like DoctrineBundle's RegexSchemaAssetFilter: DBAL passes table names as strings, sequences as assets
        $dbal->getConfiguration()->setSchemaAssetsFilter(
            static fn(string|AbstractAsset $asset): bool => preg_match($regex, is_string($asset) ? $asset : $asset->getName()) === 1,
        );

        return array_map(static fn(Table $t): string => $t->getName(), array_values($dbal->createSchemaManager()->introspectSchema()->getTables()));
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --testsuite=unit --filter 'SchemaAssetFilterTest|FuzzphonyBundleTest'`
Expected: FAIL (`SchemaAssetFilter` not found; nothing prepended).

- [ ] **Step 3: Implement**

`src/Bridge/Doctrine/SchemaAssetFilter.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

/**
 * @internal The DBAL schema_filter that keeps Fuzzphony's objects out of Doctrine's schema tools,
 * so "doctrine:migrations:diff" never proposes dropping them. DBAL passes table names as
 * "table" (current schema) or "schema.table". A schema name is a plain identifier (Names rejects
 * anything else when the container is built), so it needs no regex quoting.
 */
final class SchemaAssetFilter
{
    public static function regex(string $schema): string
    {
        return $schema === 'public'
            ? '~^(?!(public\.)?fuzzphony_)~'
            : sprintf('~^(?!%s\.)~', $schema);
    }
}
```

`FuzzphonyBundle` (imports `Fuzzphony\Bridge\Doctrine\SchemaAssetFilter`):

```php
    /**
     * With DoctrineBundle, hide Fuzzphony's tables from Doctrine's schema tools (migrations:diff
     * would otherwise propose dropping them). An application that sets its own schema_filter keeps
     * it: merging regexes is its call, and fuzzphony:doctor prints the one to merge.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        $connection = 'default';
        $schema = 'public';
        foreach ($builder->getExtensionConfig('fuzzphony') as $config) {
            $connection = is_string($config['connection'] ?? null) ? $config['connection'] : $connection;
            $schema = is_string($config['schema'] ?? null) ? $config['schema'] : $schema;
        }
        $filter = SchemaAssetFilter::regex($schema);
        foreach ($builder->getExtensionConfig('doctrine') as $doctrine) {
            $dbal = is_array($doctrine['dbal'] ?? null) ? $doctrine['dbal'] : [];
            $connections = is_array($dbal['connections'] ?? null) ? $dbal['connections'] : [];
            $named = is_array($connections[$connection] ?? null) ? $connections[$connection] : [];
            if (isset($dbal['schema_filter']) || isset($named['schema_filter'])) {
                $builder->setParameter('fuzzphony.schema_filter_conflict', $filter);

                return;
            }
        }
        $builder->prependExtensionConfig('doctrine', ['dbal' => ['connections' => [$connection => ['schema_filter' => $filter]]]]);
    }
```

In `loadExtension()`:

```php
        $schemaFilterConflict = $builder->hasParameter('fuzzphony.schema_filter_conflict')
            ? Coerce::str($builder->getParameter('fuzzphony.schema_filter_conflict'))
            : null;
        …
            DoctorCommand::class => [service('fuzzphony'), $schemaFilterConflict],
```

`DoctorCommand`:

```php
    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        /** The schema_filter regex to merge when the application sets its own DBAL schema_filter; null = Fuzzphony's filter is in place. */
        private readonly ?string $schemaFilter = null,
    ) {
        parent::__construct();
    }
```

and after the per-index loop, before `$strict = …`:

```php
        if ($this->schemaFilter !== null) {
            $io->section('Doctrine schema filter');
            $io->writeln(sprintf(
                ' <comment>!</comment> Your DBAL connection sets its own schema_filter, so Fuzzphony added none, and "doctrine:migrations:diff" will propose dropping Fuzzphony\'s tables. Exclude them in your filter; Fuzzphony\'s own would be: %s',
                $this->schemaFilter,
            ));
            $worst = $worst === CheckStatus::Ok ? CheckStatus::Warning : $worst;
        }
```

`composer.json` and `src/Bundle/composer.json`, `suggest` (keep keys sorted as they are):

```json
        "doctrine/migrations": "Run the migration fuzzphony:schema --dump-migration writes (the bundle hides Fuzzphony's tables from migrations:diff)",
```

(`suggest` is not part of `composer.lock`'s content hash; no lock update is needed. Do not run composer.)

- [ ] **Step 4: Run the tests**

Run: unit + integration. Expected: PASS. Then, in the worktree's `demo/` (after `composer install` there), check the prepend against the real DoctrineBundle: `php bin/console debug:config doctrine dbal` shows `schema_filter: '~^(?!(public\.)?fuzzphony_)~'` under `connections.default`, and `php bin/console lint:container` passes.

- [ ] **Step 5: Docs**

`docs/integrations.md`, new section after "Doctrine":

````markdown
## Doctrine Migrations

`bin/console fuzzphony:schema --dump-migration=migrations` writes the schema as a Doctrine
migration class (needs `doctrine/migrations`). Every statement is idempotent (`IF NOT EXISTS`,
`CREATE OR REPLACE`), so it is safe to commit and to run again; it ends by recording the layout
and definition in `fuzzphony_meta`. It is not transactional, because the indexes are built
`CONCURRENTLY`, and `down()` is irreversible (use `fuzzphony:schema --drop --apply`).

Doctrine's schema tools must not see Fuzzphony's tables, or `doctrine:migrations:diff` proposes
dropping them. With DoctrineBundle, the bundle sets the connection's `schema_filter` for you:
`~^(?!(public\.)?fuzzphony_)~`, or `~^(?!fuzzphony\.)~` with `schema: fuzzphony`. If your
connection already has a `schema_filter`, the bundle leaves it alone and `fuzzphony:doctor` warns;
merge the two, for example:

```yaml
doctrine:
  dbal:
    schema_filter: '~^(?!(public\.)?(fuzzphony_|legacy_))~'
```
````

`docs/commands.md` schema row: "`fuzzphony:schema [index] [--apply\|--drop\|--dump-migration=dir]` | show / apply / export idempotent DDL (alias `fuzzphony:install`); `--dump-migration` writes a Doctrine migration, see [Doctrine Migrations](integrations.md#doctrine-migrations) |"; doctor bullets add "- with DoctrineBundle, an application `schema_filter` that would let `migrations:diff` drop Fuzzphony's tables;".

CHANGELOG `### Added`:

```markdown
- Doctrine Migrations: with DoctrineBundle, the bundle sets the DBAL `schema_filter` of
  Fuzzphony's connection so `doctrine:migrations:diff` never proposes dropping Fuzzphony's tables;
  an application that sets its own filter keeps it and `fuzzphony:doctor` prints the regex to
  merge. `doctrine/migrations` is suggested.
```

- [ ] **Step 6: Run the gate.**

- [ ] **Step 7: Commit**

```bash
git add src/Bridge/Doctrine/SchemaAssetFilter.php src/Bundle/FuzzphonyBundle.php src/Bundle/Command/DoctorCommand.php composer.json src/Bundle/composer.json \
  tests/Unit/Bridge/Doctrine/SchemaAssetFilterTest.php tests/Integration/Bridge/SchemaFilterTest.php tests/Unit/Bundle/FuzzphonyBundleTest.php \
  tests/Integration/Command/DoctorCommandTest.php tests/Integration/Command/SchemaCommandTest.php docs/integrations.md docs/commands.md CHANGELOG.md
git commit -m "Hide Fuzzphony's tables from Doctrine's schema tools

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Public API, `@internal`, `Analyzer` removal (R4)

**Files:**
- Delete: `src/Core/Engine/Analyzer.php`
- Modify (add `@internal` to the class docblock, before any attribute; create `/** @internal … */` where there is none): `src/Bridge/Doctrine/DoctrineIndexDiscovery.php`, `src/Bridge/Doctrine/DoctrineNamingStrategy.php`, `src/Bundle/Command/{DoctorCommand,ReindexCommand,SchemaCommand,SearchCommand,WizardCommand,WorkerCommand}.php`, `src/Bundle/Messenger/{MessengerRefreshDispatcher,RefreshDocumentsHandler}.php`, `src/Core/Definition/{ArrayDefinitionLoader,AttributeDefinitionLoader,ConventionNamingStrategy,DefinitionValidator,NamingStrategy}.php`, `src/Core/Query/Ast/{AllOf,AnyOf,FieldScoped,Node,NodeInspector,Not,Phrase,Term}.php`, `src/Core/Query/{ParsedQuery,QueryParser,Relaxation}.php`, `src/Core/Support/{Coerce,Identifier}.php`, `src/Core/Sync/{Reindexer,Worker}.php`, `src/Core/Wizard/Export/ArrayExporter.php`, `src/Engine/Postgres/Inspection/PostgresInspector.php`, `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php`, `src/Engine/Postgres/Sql/TsQueryCompiler.php`, `src/Engine/Postgres/Wizard/PostgresIntrospector.php` (35 files); method-level `@internal` on `PostgresEngine::schemaGenerator()`
- Test: create `tests/Unit/PublicApiTest.php`
- Docs: `docs/architecture.md` (new "Public API" section, drop `Analyzer` if mentioned), `CONTRIBUTING.md:54-60` (BC paragraph), `CHANGELOG.md` (Breaking), `UPGRADE.md` (item "Internal classes")

**Interfaces:**
- Consumes: every class name produced by Tasks 1-8.
- Produces: the public API list (69 classes) as `PublicApiTest::PUBLIC` and as `docs/architecture.md#public-api`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/PublicApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The public API is an explicit list (docs/architecture.md#public-api); every other class,
 * interface and enum in src/ is @internal. From 1.0 the BC promise covers exactly this list.
 */
final class PublicApiTest extends TestCase
{
    private const array PUBLIC = [
        \Fuzzphony\Bridge\Doctrine\DbalConnection::class,
        \Fuzzphony\Bridge\Doctrine\EntityLoader::class,
        \Fuzzphony\Bridge\Doctrine\OrmSyncListener::class,
        \Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter::class,
        \Fuzzphony\Bundle\FuzzphonyBundle::class,
        \Fuzzphony\Bundle\Messenger\RefreshDocuments::class,
        \Fuzzphony\Bundle\Twig\SearchComponent::class,
        \Fuzzphony\Core\Attribute\SearchField::class,
        \Fuzzphony\Core\Attribute\SearchFilter::class,
        \Fuzzphony\Core\Attribute\Searchable::class,
        \Fuzzphony\Core\Database\Connection::class,
        \Fuzzphony\Core\Database\PdoConnection::class,
        \Fuzzphony\Core\Definition\FieldDefinition::class,
        \Fuzzphony\Core\Definition\FilterDefinition::class,
        \Fuzzphony\Core\Definition\FilterType::class,
        \Fuzzphony\Core\Definition\IdType::class,
        \Fuzzphony\Core\Definition\IndexBuilder::class,
        \Fuzzphony\Core\Definition\IndexDefinition::class,
        \Fuzzphony\Core\Definition\Source::class,
        \Fuzzphony\Core\Definition\SyncMode::class,
        \Fuzzphony\Core\Definition\TextConfig::class,
        \Fuzzphony\Core\Definition\TriggerLevel::class,
        \Fuzzphony\Core\Definition\Watch::class,
        \Fuzzphony\Core\Definition\Weight::class,
        \Fuzzphony\Core\Engine\Capabilities::class,
        \Fuzzphony\Core\Engine\Capability::class,
        \Fuzzphony\Core\Engine\Engine::class,
        \Fuzzphony\Core\Exception\EngineFailure::class,
        \Fuzzphony\Core\Exception\FuzzphonyException::class,
        \Fuzzphony\Core\Exception\InvalidArgument::class,
        \Fuzzphony\Core\Exception\InvalidConfiguration::class,
        \Fuzzphony\Core\Exception\InvalidDefinition::class,
        \Fuzzphony\Core\Exception\InvalidQuery::class,
        \Fuzzphony\Core\Exception\UnknownIndex::class,
        \Fuzzphony\Core\Fuzzphony::class,
        \Fuzzphony\Core\Inspection\Check::class,
        \Fuzzphony\Core\Inspection\CheckStatus::class,
        \Fuzzphony\Core\Inspection\InspectOptions::class,
        \Fuzzphony\Core\Inspection\InspectionReport::class,
        \Fuzzphony\Core\Query\Filter\Condition::class,
        \Fuzzphony\Core\Query\Filter\Operator::class,
        \Fuzzphony\Core\Query\SearchQuery::class,
        \Fuzzphony\Core\Ranking\FuzzyMode::class,
        \Fuzzphony\Core\Ranking\RankingProfile::class,
        \Fuzzphony\Core\Ranking\Thresholds::class,
        \Fuzzphony\Core\Registry\IndexRegistry::class,
        \Fuzzphony\Core\Schema\SchemaPlan::class,
        \Fuzzphony\Core\Schema\Statement::class,
        \Fuzzphony\Core\Search\Explanation::class,
        \Fuzzphony\Core\Search\Hit::class,
        \Fuzzphony\Core\Search\ScoreBreakdown::class,
        \Fuzzphony\Core\Search\SearchBuilder::class,
        \Fuzzphony\Core\Search\SearchResult::class,
        \Fuzzphony\Core\Sync\ImmediateRefreshDispatcher::class,
        \Fuzzphony\Core\Sync\RefreshDispatcher::class,
        \Fuzzphony\Core\Sync\ReindexOptions::class,
        \Fuzzphony\Core\Sync\ReindexResult::class,
        \Fuzzphony\Core\Wizard\ColumnKind::class,
        \Fuzzphony\Core\Wizard\ColumnProfile::class,
        \Fuzzphony\Core\Wizard\Decision::class,
        \Fuzzphony\Core\Wizard\DefinitionSuggester::class,
        \Fuzzphony\Core\Wizard\Export\AttributeExporter::class,
        \Fuzzphony\Core\Wizard\Export\BuilderExporter::class,
        \Fuzzphony\Core\Wizard\Export\YamlExporter::class,
        \Fuzzphony\Core\Wizard\ForeignKey::class,
        \Fuzzphony\Core\Wizard\SourceIntrospector::class,
        \Fuzzphony\Core\Wizard\Suggestion::class,
        \Fuzzphony\Core\Wizard\TableProfile::class,
        \Fuzzphony\Engine\Postgres\PostgresEngine::class,
    ];

    /** Source directory => namespace, as in composer.json's autoload. */
    private const array PREFIXES = [
        'Core/' => 'Fuzzphony\\Core\\',
        'Engine/Postgres/' => 'Fuzzphony\\Engine\\Postgres\\',
        'Bridge/Doctrine/' => 'Fuzzphony\\Bridge\\Doctrine\\',
        'Bundle/' => 'Fuzzphony\\Bundle\\',
    ];

    public function testEveryClassIsEitherPublicApiOrInternal(): void
    {
        $wrong = [];
        foreach (self::classes() as $class) {
            $internal = str_contains((string) (new \ReflectionClass($class))->getDocComment(), '@internal');
            $public = in_array($class, self::PUBLIC, true);
            if ($internal === $public) {
                $wrong[] = sprintf('%s: %s', $class, $public ? 'public API, but tagged @internal' : 'neither public API nor @internal');
            }
        }

        self::assertSame([], $wrong);
        self::assertCount(69, self::PUBLIC);
        self::assertCount(120, self::classes());
    }

    public function testTheArchitectureDocListsExactlyThePublicApi(): void
    {
        $docs = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/architecture.md');
        $start = strpos($docs, "\n## Public API\n");
        self::assertNotFalse($start, 'docs/architecture.md has a "## Public API" section');
        $end = strpos($docs, "\n## ", $start + 1);
        $section = $end === false ? substr($docs, $start) : substr($docs, $start, $end - $start);

        foreach (self::PUBLIC as $class) {
            self::assertStringContainsString('`' . $class . '`', $section, $class . ' is missing from the Public API section');
        }
        self::assertSame(count(self::PUBLIC), substr_count($section, '`Fuzzphony\\'), 'the section lists nothing else');
    }

    public function testTheDemoAndTheBenchmarkUseOnlyThePublicApi(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/benchmarks/run.php'];
        foreach (['/demo/src/*/*.php', '/demo/src/*/*/*.php'] as $pattern) {
            $found = glob($root . $pattern);
            array_push($files, ...($found !== false ? $found : []));
        }
        $used = [];
        foreach ($files as $file) {
            preg_match_all('/^use (Fuzzphony\\\\[A-Za-z\\\\]+);/m', (string) file_get_contents($file), $matches);
            array_push($used, ...$matches[1]);
        }

        self::assertNotSame([], $used);
        self::assertSame([], array_values(array_diff(array_unique($used), self::PUBLIC)), 'internal classes used by the demo or the benchmark');
    }

    /** @return list<string> */
    private static function classes(): array
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root)), -4);
            foreach (self::PREFIXES as $directory => $namespace) {
                if (str_starts_with($relative, $directory)) {
                    $classes[] = $namespace . str_replace('/', '\\', substr($relative, strlen($directory)));
                }
            }
        }
        sort($classes);

        return $classes;
    }
}
```

(`new \ReflectionClass($class)` needs a `class-string`; if PHPStan objects to the plain `string`, assert `class_exists($class) || interface_exists($class) || enum_exists($class)` first — it narrows. Every class must autoload: API Platform, Messenger and UX Live Component are dev dependencies, so the bundle integrations load.)

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter PublicApiTest`
Expected: FAIL listing the 35 untagged internal classes, `Fuzzphony\Core\Engine\Analyzer: neither…`, the count 121, and the missing docs section.

- [ ] **Step 3: Tag, delete, document**

- Delete `src/Core/Engine/Analyzer.php` (no implementation, no reference: `grep -rn Analyzer src tests` must return nothing afterwards).
- Tag the 35 files. Examples: `/** @internal Collects ALL problems of a definition at once, each with a concrete fix. */` for `DefinitionValidator`; for the commands `/** @internal The fuzzphony:doctor console command; its CLI is public, the class is not. */` above `#[AsCommand(...)]`.
- `PostgresEngine::schemaGenerator()`: `/** @internal */`.
- `docs/architecture.md`, new section after "Engine":

```markdown
## Public API

From 1.0 the backward-compatibility promise covers exactly these classes, interfaces and enums;
everything else in `src/` is marked `@internal` and may change in any release.

- Entry point: `Fuzzphony\Core\Fuzzphony`
- Attributes: `Fuzzphony\Core\Attribute\Searchable`, `Fuzzphony\Core\Attribute\SearchField`,
  `Fuzzphony\Core\Attribute\SearchFilter`
- Definitions: `Fuzzphony\Core\Definition\IndexDefinition`, `Fuzzphony\Core\Definition\IndexBuilder`,
  `Fuzzphony\Core\Definition\Source`, `Fuzzphony\Core\Definition\FieldDefinition`,
  `Fuzzphony\Core\Definition\FilterDefinition`, `Fuzzphony\Core\Definition\Watch`,
  `Fuzzphony\Core\Definition\TextConfig`, `Fuzzphony\Core\Definition\Weight`,
  `Fuzzphony\Core\Definition\FilterType`, `Fuzzphony\Core\Definition\IdType`,
  `Fuzzphony\Core\Definition\SyncMode`, `Fuzzphony\Core\Definition\TriggerLevel`
- Searching: `Fuzzphony\Core\Search\SearchBuilder`, `Fuzzphony\Core\Search\SearchResult`,
  `Fuzzphony\Core\Search\Hit`, `Fuzzphony\Core\Search\ScoreBreakdown`,
  `Fuzzphony\Core\Search\Explanation`, `Fuzzphony\Core\Query\SearchQuery`,
  `Fuzzphony\Core\Query\Filter\Condition`, `Fuzzphony\Core\Query\Filter\Operator`
- Ranking: `Fuzzphony\Core\Ranking\RankingProfile`, `Fuzzphony\Core\Ranking\Thresholds`,
  `Fuzzphony\Core\Ranking\FuzzyMode`
- Database: `Fuzzphony\Core\Database\Connection`, `Fuzzphony\Core\Database\PdoConnection`
- Registry and schema: `Fuzzphony\Core\Registry\IndexRegistry`, `Fuzzphony\Core\Schema\SchemaPlan`,
  `Fuzzphony\Core\Schema\Statement`
- Sync: `Fuzzphony\Core\Sync\ReindexOptions`, `Fuzzphony\Core\Sync\ReindexResult`,
  `Fuzzphony\Core\Sync\RefreshDispatcher`, `Fuzzphony\Core\Sync\ImmediateRefreshDispatcher`
- Doctor: `Fuzzphony\Core\Inspection\InspectOptions`, `Fuzzphony\Core\Inspection\InspectionReport`,
  `Fuzzphony\Core\Inspection\Check`, `Fuzzphony\Core\Inspection\CheckStatus`
- Exceptions: `Fuzzphony\Core\Exception\FuzzphonyException`, `Fuzzphony\Core\Exception\EngineFailure`,
  `Fuzzphony\Core\Exception\InvalidArgument`, `Fuzzphony\Core\Exception\InvalidConfiguration`,
  `Fuzzphony\Core\Exception\InvalidDefinition`, `Fuzzphony\Core\Exception\InvalidQuery`,
  `Fuzzphony\Core\Exception\UnknownIndex`
- Engine SPI (for custom engines): `Fuzzphony\Core\Engine\Engine`, `Fuzzphony\Core\Engine\Capabilities`,
  `Fuzzphony\Core\Engine\Capability`; the PostgreSQL engine: `Fuzzphony\Engine\Postgres\PostgresEngine`
- Wizard: `Fuzzphony\Core\Wizard\DefinitionSuggester`, `Fuzzphony\Core\Wizard\SourceIntrospector`,
  `Fuzzphony\Core\Wizard\Suggestion`, `Fuzzphony\Core\Wizard\Decision`,
  `Fuzzphony\Core\Wizard\TableProfile`, `Fuzzphony\Core\Wizard\ColumnProfile`,
  `Fuzzphony\Core\Wizard\ColumnKind`, `Fuzzphony\Core\Wizard\ForeignKey`,
  `Fuzzphony\Core\Wizard\Export\YamlExporter`, `Fuzzphony\Core\Wizard\Export\BuilderExporter`,
  `Fuzzphony\Core\Wizard\Export\AttributeExporter`
- Doctrine: `Fuzzphony\Bridge\Doctrine\DbalConnection`, `Fuzzphony\Bridge\Doctrine\EntityLoader`,
  `Fuzzphony\Bridge\Doctrine\OrmSyncListener`
- Symfony: `Fuzzphony\Bundle\FuzzphonyBundle`, `Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter`,
  `Fuzzphony\Bundle\Twig\SearchComponent`, `Fuzzphony\Bundle\Messenger\RefreshDocuments`

The console commands' names, arguments and options are public too; their classes are not.
`tests/Unit/PublicApiTest.php` keeps this list, the `@internal` tags and the demo's imports in
step.
```

  Also in "Packages": drop `Engine contract` wording only if it names `Analyzer` (it does not; leave it).
- `CONTRIBUTING.md` "Backward compatibility": "…From 1.0 on the project follows Semantic Versioning for the [public API](docs/architecture.md#public-api): classes and methods marked `@internal` are not covered, and anything removed is deprecated for at least one minor version first. A new class is `@internal` unless it is added to that list (and to `tests/Unit/PublicApiTest.php`)."

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --testsuite=unit`, `composer stan` (PHPStan's `@internal` rule must stay silent: tests live in the `Fuzzphony` root namespace).
Expected: PASS, 0 errors.

- [ ] **Step 5: Docs**

CHANGELOG `### Breaking`, append:

```markdown
- The public API is now explicit ([docs/architecture.md](docs/architecture.md#public-api)): 51
  classes are marked `@internal` (loaders, validators, the query parser and AST, the reindexer and
  worker, the console command classes, the SQL compilers, the schema generator, the doctor, the
  introspector, …) and may change in any release. The unused `Core\Engine\Analyzer` interface is
  removed.
```

UPGRADE item 7:

```markdown
7. **Internal classes.** Only the classes listed under
   [Public API](docs/architecture.md#public-api) are covered by the upgrade notes from now on. If
   you use an `@internal` class directly (`Reindexer`, `Worker`, `ArrayDefinitionLoader`,
   `QueryParser`, `DefinitionValidator`, …), move to the public entry points (`Fuzzphony`,
   `IndexDefinition::builder()`, the bundle's configuration) or open an issue describing the use
   case. PHPStan reports such uses (`@internal` from outside the `Fuzzphony` namespace).
```

- [ ] **Step 6: Run the gate.**

- [ ] **Step 7: Commit**

```bash
git rm src/Core/Engine/Analyzer.php
git add src/Bridge/Doctrine/DoctrineIndexDiscovery.php src/Bridge/Doctrine/DoctrineNamingStrategy.php \
  src/Bundle/Command/DoctorCommand.php src/Bundle/Command/ReindexCommand.php src/Bundle/Command/SchemaCommand.php \
  src/Bundle/Command/SearchCommand.php src/Bundle/Command/WizardCommand.php src/Bundle/Command/WorkerCommand.php \
  src/Bundle/Messenger/MessengerRefreshDispatcher.php src/Bundle/Messenger/RefreshDocumentsHandler.php \
  src/Core/Definition/ArrayDefinitionLoader.php src/Core/Definition/AttributeDefinitionLoader.php src/Core/Definition/ConventionNamingStrategy.php \
  src/Core/Definition/DefinitionValidator.php src/Core/Definition/NamingStrategy.php \
  src/Core/Query/Ast/AllOf.php src/Core/Query/Ast/AnyOf.php src/Core/Query/Ast/FieldScoped.php src/Core/Query/Ast/Node.php \
  src/Core/Query/Ast/NodeInspector.php src/Core/Query/Ast/Not.php src/Core/Query/Ast/Phrase.php src/Core/Query/Ast/Term.php \
  src/Core/Query/ParsedQuery.php src/Core/Query/QueryParser.php src/Core/Query/Relaxation.php \
  src/Core/Support/Coerce.php src/Core/Support/Identifier.php src/Core/Sync/Reindexer.php src/Core/Sync/Worker.php \
  src/Core/Wizard/Export/ArrayExporter.php src/Engine/Postgres/Inspection/PostgresInspector.php \
  src/Engine/Postgres/Schema/PostgresSchemaGenerator.php src/Engine/Postgres/Sql/TsQueryCompiler.php \
  src/Engine/Postgres/Wizard/PostgresIntrospector.php src/Engine/Postgres/PostgresEngine.php \
  tests/Unit/PublicApiTest.php docs/architecture.md CONTRIBUTING.md CHANGELOG.md UPGRADE.md
git commit -m "Mark everything outside the public API @internal and drop Analyzer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Demo on `schema: fuzzphony`, final docs pass

**Files:**
- Modify: `demo/config/packages/fuzzphony.yaml`, `demo/docker/init.sh` (reindex check, grants), `demo/src/Service/Languages.php` (config names), `demo/src/Controller/PagesController.php:65`, `demo/README.md`, `CHANGELOG.md` (Changed: demo), `UPGRADE.md` (final read-through), `docs/roadmap.md` (nothing unless a v0.4 item changed meaning)
- Test: the demo stack itself (isolated compose project), `PublicApiTest::testTheDemoAndTheBenchmarkUseOnlyThePublicApi`

**Interfaces:**
- Consumes: bundle key `fuzzphony.schema` (Task 6), `FuzzphonyException` hierarchy (Task 1), the schema filter prepend (Task 8), the version checks (Task 7).
- Produces: nothing for other tasks.

- [ ] **Step 1: Configure the demo**

`demo/config/packages/fuzzphony.yaml`, directly under `fuzzphony:`:

```yaml
  # Every Fuzzphony table, function and text search configuration lives in its own schema; the application's
  # tables (bench_product, lang_product, ...) stay in public. fuzzphony:schema --apply creates it.
  schema: fuzzphony
```

and the `lang_en` comment: `# Each index stems and removes stop words with its own language and folds accents (fuzzphony.fuzzphony_<language>).`

`demo/src/Service/Languages.php`: the five `'config' => 'fuzzphony_<language>'` become `'config' => 'fuzzphony.fuzzphony_<language>'` (english, german, french, spanish, hungarian); the `LANGUAGES` docblock: "`config` is what `fuzzphony:schema --apply` creates for the index in Fuzzphony's schema (`fuzzphony`, see config/packages/fuzzphony.yaml) …". The raw SQL keeps `CAST(:config AS regconfig)`: a qualified name casts fine and does not depend on the `search_path`.

`demo/src/Controller/PagesController.php:65`: `} catch (FuzzphonyException $e) {` with `use Fuzzphony\Core\Exception\FuzzphonyException;` (an unknown table is now `InvalidArgument`, a definition problem `InvalidDefinition`; both are Fuzzphony exceptions).

`demo/docker/init.sh`:

```sh
needs_reindex() { # <index> <its source was just seeded: 0|1>
    case "$DEMO_REINDEX" in always) return 0 ;; never) return 1 ;; esac
    [ "$2" = 1 ] || [ "$(psql -tAc "SELECT EXISTS (SELECT 1 FROM fuzzphony.fuzzphony_$1)")" != t ]
}
```

and the grants block:

```sh
# Read access to the catalogue, and to Fuzzphony's own schema what the search, the sync triggers and the worker need
# (the index tables, the sync queue, the version table); no DDL anywhere.
log "granting $APP_ROLE access"
psql -q -v ON_ERROR_STOP=1 -v role="$APP_ROLE" <<'SQL'
GRANT USAGE ON SCHEMA public TO :"role";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO :"role";
GRANT USAGE ON SCHEMA fuzzphony TO :"role";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA fuzzphony TO :"role";
SQL
```

- [ ] **Step 2: Run the demo end to end in an isolated compose project**

```bash
cd demo
DEMO_PORT=8094 DEMO_DB_PORT=5494 DEMO_ROWS=200000 docker compose -p fz-v04-demo up -d --build
docker compose -p fz-v04-demo logs -f init          # must end with "[init] done"; note the "applying the schema" and "reindexing catalog" timings
curl -fsS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8094/            # 200
for page in compare playground wizard languages benchmark doctor; do curl -fsS -o /dev/null -w "$page %{http_code}\n" "http://127.0.0.1:8094/$page"; done
docker compose -p fz-v04-demo exec db psql -U fuzzphony -tAc "SELECT to_regclass('fuzzphony.fuzzphony_catalog'), to_regclass('public.fuzzphony_catalog'), to_regclass('fuzzphony.fuzzphony_meta')"
#   expected: fuzzphony.fuzzphony_catalog||fuzzphony.fuzzphony_meta   (nothing in public)
docker compose -p fz-v04-demo exec db psql -U fuzzphony -c "UPDATE bench_brand SET name = 'Zebra' WHERE id = 1"
#   then search "zebra" on / after a few seconds: the worker (connecting as fuzzphony_app) refreshed the products
docker compose -p fz-v04-demo exec php php bin/console debug:config doctrine dbal | grep schema_filter   # '~^(?!fuzzphony\.)~'
docker compose -p fz-v04-demo exec php php bin/console fuzzphony:doctor --strict                        # exit 0
docker compose -p fz-v04-demo down -v             # removes only this project's containers and volume
```

Adjust the page paths to the demo's real routes (`demo/src/Controller/*.php`) if they differ. Compare the catalogue reindex time with the README's 500 000-row figure scaled to 200 000 rows (25.4 s × 0.4 ≈ 10 s): `fuzzphony_norm` now carries `SET search_path`, which stops PostgreSQL from inlining it. If the reindex is more than 10% slower, stop and report the numbers to the human before going on.

- [ ] **Step 3: Docs**

`demo/README.md`:
- "Services" table, `init` row: "…`fuzzphony:schema --apply` (creates the `fuzzphony` schema: every index table, the sync queue and the version table live there, the catalogue stays in `public`)…".
- "Security defaults": "…php-fpm and the worker connect as `fuzzphony_app` (not a superuser, no DDL, read-only on the catalogue, read/write on the `fuzzphony` schema's tables, `statement_timeout = 5s`)…".
- Next to the PostgreSQL 18 note about `down -v`: "A demo started before 0.4 has its indexes in `public`; `docker compose down -v` once (the doctor warns about the old tables otherwise)."

CHANGELOG `### Changed`:

```markdown
- Demo: runs on `schema: fuzzphony`; the application role is granted the `fuzzphony` schema's
  tables instead of the `fuzzphony_*` tables in `public`. An existing demo needs
  `docker compose down -v` once.
```

UPGRADE: read the whole "From 0.3 to 0.4" section top to bottom against the CHANGELOG's Breaking list; every Breaking bullet has a step, the steps are numbered 1-7 in order (Exceptions, Withers, Reindexing, Removed Core helpers, Apply the schema, Custom engines, Internal classes), "Moving to a dedicated schema" and a new "### Doctrine Migrations" subsection follow:

```markdown
### Doctrine Migrations

With DoctrineBundle the bundle now sets the DBAL `schema_filter` of Fuzzphony's connection, so
`doctrine:migrations:diff` stops proposing to drop the `fuzzphony_*` tables. If your connection
has its own `schema_filter`, nothing changes and `fuzzphony:doctor` warns with the regex to merge
into yours. A migration generated with `fuzzphony:schema --dump-migration` can be generated again
after upgrading; it now ends with the version record.
```

Then read `docs/commands.md`, `docs/configuration.md`, `docs/integrations.md`, `docs/sync.md`, `docs/architecture.md`, `docs/languages.md`, `README.md` once more for anything that still says `sidecarTable()`, `configName()`, `onPruned`, `IndexDefinition::with(`, `fuzzphony_<index>` without "in Fuzzphony's schema", or `search_path` advice that no longer applies (`grep -rn "with(\|onPruned\|onPruneSkipped\|sidecarTable\|configName\|tsRankWeights\|Analyzer" README.md docs/*.md demo/README.md UPGRADE.md CONTRIBUTING.md`; roadmap and 0.3 UPGRADE sections are history, leave them).

- [ ] **Step 4: Run the gate** (the unit suite includes `PublicApiTest::testTheDemoAndTheBenchmarkUseOnlyThePublicApi`).

- [ ] **Step 5: Commit**

```bash
git add demo/config/packages/fuzzphony.yaml demo/docker/init.sh demo/src/Service/Languages.php demo/src/Controller/PagesController.php \
  demo/README.md CHANGELOG.md UPGRADE.md
git commit -m "Run the demo on its own fuzzphony schema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Add any doc file Step 3's grep made you change to the `git add` list.)

---

## Self-review (done while writing; re-run before execution if the spec changes)

**Spec coverage.**
- R1: new `InvalidArgument` / `InvalidConfiguration` (T1); enum `tryFrom` + `InvalidDefinition` in `IndexBuilder`, `ArrayDefinitionLoader`, `Weight` (T1); `DoctrineNamingStrategy` (T1); `guard()` around `sourceIds`, `queueSize`, `explain`, highlighting, inspector (T1), `recordReindex` (T7); `SchemaPlan::apply()` (T1); `SchemaCommand` directory failure → `FAILURE` (T1); every bare SPL throw listed in the plan's Task 1 Files, `SearchSqlBuilder` invariants stay `\LogicException`.
- R2: 15 typed withers through one private constructor call, `with()` removed, `override()` chains, `WizardCommand` (T2); `Thresholds::with`, `RankingProfile::with`, `SearchBuilder` untouched.
- R3: `ReindexOptions`, `ReindexResult`, facade and `Reindexer::run()` signatures, callbacks removed, `ReindexCommand` through the facade with `--from` → `resumeAfter`, `pruneOrphans()` unchanged (T3).
- R4: public list confirmed against src/ with the additions of decision 8, 51 `@internal`, docs section, CONTRIBUTING link, `Analyzer` deleted (T9).
- R5: `Names` (all listed names + 63-byte limit) and `Types`, the removed Core methods, `ts_rank` literal in `SearchSqlBuilder`, `$fuzzphony$` check and 48-char cap stay (T4).
- R6: `schema` setting in engine and bundle, validated (T5, T6); every object in the schema, `CREATE SCHEMA` (non-public only, decision 2), qualified SQL, function `search_path` (decision 1), doctor by schema, introspector, legacy warning, UPGRADE steps, default `public` (T5, T6); demo (T10); integration test with the schema off the `search_path` (T5).
- R7: meta table and columns, `*` row, `LAYOUT_VERSION` (runner deferred, decision 4), both hashes (decision 3), apply upsert last and in `--dump-migration`, reindex writes `documents_hash` / `reindexed_at`, the four doctor outcomes (+ newer layout), `--drop` deletes the row (T7).
- R8: idempotent migration unchanged plus the upsert (T7, asserted T8), `schema_filter` prepend with the two regexes, application filter left alone + doctor warning + docs, `doctrine/migrations` in `suggest` (T8).
- Success criteria: coverage/mutation in every task's gate; CHANGELOG Breaking and UPGRADE per task; demo on the dedicated schema (T10).

**Placeholder scan.** No TBD/TODO; every code step has code; the two "adjust if" notes (demo routes, PHPStan `class-string` narrowing) name the exact fallback.

**Type consistency.** `Names(extensionSchema, schema)` order matches `PostgresEngine(connection, extensionSchema, schema)` and the bundle's engine arguments `[connection, $extensionSchema, $schema]` (T5, T6, test asserts argument indexes 1 and 2). `ReindexOptions` named arguments (`batchSize`, `resumeAfter`, `prune`, `pruneEmpty`, `onBatch`) and `ReindexResult` fields (`written`, `pruned`, `pruneSkippedEmptySource`) are the same in T3, T7, T8 tests and docs. Guard operation names in `PostgresEngineGuardTest` match the engine code (T1, T7). Check names `Schema`, `Schema version`, `Definition`, `Documents` are distinct and used identically in T6/T7 code and tests. `Fingerprint::definition/documents/shared`, `PostgresSchemaGenerator::LAYOUT_VERSION/reindexed()`, `Names::meta()/metaName()` consistent across T7 and T8.

**Review Focus.** Each of the five lines has a named test in its owning task (T5 ×3, T7 ×2).


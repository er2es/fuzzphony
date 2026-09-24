# Multi-tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one `IndexDefinition` be scoped to a tenant, so a search can never return another tenant's rows without an application developer having to remember a WHERE clause.

**Architecture:** Tenant scoping is a designated *existing* filter, not a new schema concept. `IndexDefinition::$tenant` names which already-declared `FilterDefinition` acts as the tenant column. `SearchQuery::$tenant` carries the value for one query; `PostgresEngine::execute()` is the single choke point that (a) refuses to run a tenant-scoped search with no value supplied, and (b) injects an always-on `Condition` for it before any of the three query branches (browse/full-text/fuzzy) run. Nothing in schema generation, sync (queue/trigger/ORM), reindexing, or the refresh function changes — the tenant value flows through the sidecar table exactly like any other filter column already does.

**Tech Stack:** PHP 8.4, PostgreSQL 15+, PHPUnit 12, PHPStan (max + strict-rules), php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-09-24-multi-tenancy-design.md`

## Global Constraints

- `composer cs` and `composer stan` must pass after every task (repo is currently at zero PHPStan errors — do not reintroduce any).
- Constructor "wither" methods (`IndexDefinition::with()`, `SearchQuery::copy()`) use explicit, validated named arguments — never `get_object_vars()`/`get_object_vars`-style untyped merge+spread (PHPStan strict-rules forbids it; this was hardened repo-wide already).
- Narrowing a `mixed` value to a scalar goes through `Fuzzphony\Core\Support\Coerce` (`Coerce::str()`/`Coerce::int()`/`Coerce::float()`), never a bare cast.
- Run `vendor/bin/phpunit --testsuite=unit` after every unit-test task. Integration tasks need a live Postgres: `docker compose up -d` (repo root `docker-compose.yml`), then `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --testsuite=integration`.
- Commit locally after each task (`git add` the exact files touched — never `git add -A`). Do not push.

---

### Task 1: `IndexDefinition` + `IndexBuilder` — declare the tenant scope

**Files:**
- Modify: `src/Core/Definition/IndexDefinition.php:24-39` (constructor), `src/Core/Definition/IndexDefinition.php:126-169` (`with()`)
- Modify: `src/Core/Definition/IndexBuilder.php:33-34` (properties), `src/Core/Definition/IndexBuilder.php:118-123` (add `tenant()` after `recencyBy()`), `src/Core/Definition/IndexBuilder.php:149-164` (`build()`)
- Test: `tests/Unit/Core/Definition/DefinitionValidatorTest.php`

**Interfaces:**
- Produces: `IndexDefinition::$tenant` (`?string`, the name of the `FilterDefinition` that scopes searches — not a raw column name), `IndexBuilder::tenant(string $filterName): self`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Definition/DefinitionValidatorTest.php` (new method, anywhere in the class body):

```php
    public function testBuilderCanDeclareATenantScope(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name')
            ->filter('account_id', 'int')
            ->tenant('account_id')
            ->build();

        self::assertSame('account_id', $definition->tenant);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testBuilderCanDeclareATenantScope`
Expected: FAIL — `Call to undefined method Fuzzphony\Core\Definition\IndexBuilder::tenant()`.

- [ ] **Step 3: Add `$tenant` to `IndexDefinition`**

In `src/Core/Definition/IndexDefinition.php`, change the constructor (lines 24-39) from:

```php
    public function __construct(
        public string $name,
        public Source $source,
        public array $fields,
        public array $filters = [],
        public array $watches = [],
        public IdType $idType = IdType::Int,
        public SyncMode $sync = SyncMode::Queue,
        public TextConfig $text = new TextConfig(),
        public ?string $boostColumn = null,
        public ?string $recencyColumn = null,
        public array $profiles = ['default' => new RankingProfile()],
        public Thresholds $thresholds = new Thresholds(),
        public ?string $entityClass = null,
        public TriggerLevel $triggerLevel = TriggerLevel::Statement,
    ) {}
```

to:

```php
    public function __construct(
        public string $name,
        public Source $source,
        public array $fields,
        public array $filters = [],
        public array $watches = [],
        public IdType $idType = IdType::Int,
        public SyncMode $sync = SyncMode::Queue,
        public TextConfig $text = new TextConfig(),
        public ?string $boostColumn = null,
        public ?string $recencyColumn = null,
        public array $profiles = ['default' => new RankingProfile()],
        public Thresholds $thresholds = new Thresholds(),
        public ?string $entityClass = null,
        public TriggerLevel $triggerLevel = TriggerLevel::Statement,
        /** The name of the FilterDefinition that scopes every search to one tenant; null = not tenant-scoped. */
        public ?string $tenant = null,
    ) {}
```

- [ ] **Step 4: Wire `$tenant` through `with()`**

In the same file, `with()` (lines 126-169) currently reads:

```php
    public function with(mixed ...$changes): self
    {
        $name = $changes['name'] ?? null;
        $source = $changes['source'] ?? null;
        $fields = $changes['fields'] ?? null;
        $filters = $changes['filters'] ?? null;
        $watches = $changes['watches'] ?? null;
        $idType = $changes['idType'] ?? null;
        $sync = $changes['sync'] ?? null;
        $text = $changes['text'] ?? null;
        $boostColumn = array_key_exists('boostColumn', $changes) ? $changes['boostColumn'] : $this->boostColumn;
        $recencyColumn = array_key_exists('recencyColumn', $changes) ? $changes['recencyColumn'] : $this->recencyColumn;
        $profiles = $changes['profiles'] ?? null;
        $thresholds = $changes['thresholds'] ?? null;
        $entityClass = array_key_exists('entityClass', $changes) ? $changes['entityClass'] : $this->entityClass;
        $triggerLevel = $changes['triggerLevel'] ?? null;

        $entityClassOverride = $this->entityClass;
        if (array_key_exists('entityClass', $changes)) {
            $candidate = $changes['entityClass'];
            if ($candidate === null) {
                $entityClassOverride = null;
            } elseif (is_string($candidate) && class_exists($candidate)) {
                $entityClassOverride = $candidate;
            }
        }

        return new self(
            name: is_string($name) ? $name : $this->name,
            source: $source instanceof Source ? $source : $this->source,
            fields: self::typedList($fields, FieldDefinition::class) ?? $this->fields,
            filters: self::typedList($filters, FilterDefinition::class) ?? $this->filters,
            watches: self::typedList($watches, Watch::class) ?? $this->watches,
            idType: $idType instanceof IdType ? $idType : $this->idType,
            sync: $sync instanceof SyncMode ? $sync : $this->sync,
            text: $text instanceof TextConfig ? $text : $this->text,
            boostColumn: is_string($boostColumn) || $boostColumn === null ? $boostColumn : $this->boostColumn,
            recencyColumn: is_string($recencyColumn) || $recencyColumn === null ? $recencyColumn : $this->recencyColumn,
            profiles: self::typedMap($profiles, RankingProfile::class) ?? $this->profiles,
            thresholds: $thresholds instanceof Thresholds ? $thresholds : $this->thresholds,
            entityClass: $entityClassOverride,
            triggerLevel: $triggerLevel instanceof TriggerLevel ? $triggerLevel : $this->triggerLevel,
        );
    }
```

Add a `$tenant` extraction line right after the `$triggerLevel` line, and a `tenant:` argument right after `triggerLevel:` in the `new self(...)` call:

```php
        $triggerLevel = $changes['triggerLevel'] ?? null;
        $tenant = array_key_exists('tenant', $changes) ? $changes['tenant'] : $this->tenant;
```

```php
            triggerLevel: $triggerLevel instanceof TriggerLevel ? $triggerLevel : $this->triggerLevel,
            tenant: is_string($tenant) || $tenant === null ? $tenant : $this->tenant,
        );
    }
```

- [ ] **Step 5: Add `tenant()` to `IndexBuilder`**

In `src/Core/Definition/IndexBuilder.php`, add a property next to `$recency` (line 34):

```php
    private ?string $recency = null;
    private ?string $tenant = null;
```

Add a method right after `recencyBy()` (currently lines 118-123):

```php
    public function recencyBy(string $column): self
    {
        $this->recency = $column;

        return $this;
    }

    /** Marks an already-declared filter() as the tenant scope: every search must supply forTenant(). */
    public function tenant(string $filterName): self
    {
        $this->tenant = $filterName;

        return $this;
    }
```

In `build()` (lines 149-164), add `tenant: $this->tenant,` right after `triggerLevel: $this->triggerLevel,`:

```php
            entityClass: $this->entityClass,
            triggerLevel: $this->triggerLevel,
            tenant: $this->tenant,
        );
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testBuilderCanDeclareATenantScope`
Expected: PASS

- [ ] **Step 7: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green (110+ tests, no cs diff, no phpstan errors).

- [ ] **Step 8: Commit**

```bash
git add src/Core/Definition/IndexDefinition.php src/Core/Definition/IndexBuilder.php tests/Unit/Core/Definition/DefinitionValidatorTest.php
git commit -m "Add IndexDefinition::\$tenant and IndexBuilder::tenant()"
```

---

### Task 2: `#[Searchable(tenant: ...)]` attribute

**Files:**
- Modify: `src/Core/Attribute/Searchable.php:17-33`
- Modify: `src/Core/Definition/AttributeDefinitionLoader.php:42-47`
- Create: `tests/Fixtures/TenantScopedProduct.php`
- Test: `tests/Unit/Core/Definition/AttributeDefinitionLoaderTest.php`

**Interfaces:**
- Consumes: `IndexBuilder::tenant(string $filterName): self` (Task 1).
- Produces: `Searchable::$tenant` (`?string`).

A dedicated fixture class is used instead of extending the shared `tests/Fixtures/Product.php`, because `Product` is also used by the wizard export/round-trip tests (`ExportersTest.php`) which this plan does not touch — keeping tenant scoping off `Product` avoids coupling this task to wizard-export behavior.

- [ ] **Step 1: Write the fixture and the failing test**

Create `tests/Fixtures/TenantScopedProduct.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;

#[Searchable(tenant: 'account_id')]
final class TenantScopedProduct
{
    public int $id;

    #[SearchField('A')]
    public string $name;

    #[SearchFilter]
    public int $accountId;
}
```

Add to `tests/Unit/Core/Definition/AttributeDefinitionLoaderTest.php` (new method; add `use Fuzzphony\Tests\Fixtures\TenantScopedProduct;` to the `use` block):

```php
    public function testTenantAttributeSetsTheTenantScope(): void
    {
        $definition = (new AttributeDefinitionLoader())->load(TenantScopedProduct::class);

        self::assertSame('account_id', $definition->tenant);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testTenantAttributeSetsTheTenantScope`
Expected: FAIL — `Unknown named parameter $tenant` (the `Searchable` attribute doesn't accept it yet).

- [ ] **Step 3: Add `$tenant` to the `Searchable` attribute**

In `src/Core/Attribute/Searchable.php`, change (lines 17-33):

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Searchable
{
    public function __construct(
        /** Index name; defaults to the snake_cased plural of the class name ("Product" -> "products"). */
        public ?string $name = null,
        public string $language = 'english',
        public bool $unaccent = true,
        public SyncMode $sync = SyncMode::Queue,
        /** Source table; defaults to the ORM table name or the snake_cased class name. */
        public ?string $table = null,
        /** Column holding a popularity/priority number used by the "boost" ranking weight. */
        public ?string $boost = null,
        /** Timestamp column used by the "recency" ranking weight. */
        public ?string $recency = null,
        public TriggerLevel $triggerLevel = TriggerLevel::Statement,
    ) {}
}
```

to:

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Searchable
{
    public function __construct(
        /** Index name; defaults to the snake_cased plural of the class name ("Product" -> "products"). */
        public ?string $name = null,
        public string $language = 'english',
        public bool $unaccent = true,
        public SyncMode $sync = SyncMode::Queue,
        /** Source table; defaults to the ORM table name or the snake_cased class name. */
        public ?string $table = null,
        /** Column holding a popularity/priority number used by the "boost" ranking weight. */
        public ?string $boost = null,
        /** Timestamp column used by the "recency" ranking weight. */
        public ?string $recency = null,
        public TriggerLevel $triggerLevel = TriggerLevel::Statement,
        /** Name of the #[SearchFilter] property (its resulting filter name, snake_cased) that scopes every search to one tenant. */
        public ?string $tenant = null,
    ) {}
}
```

- [ ] **Step 4: Wire it in `AttributeDefinitionLoader`**

In `src/Core/Definition/AttributeDefinitionLoader.php`, change (lines 42-47):

```php
        if ($searchable->boost !== null) {
            $builder->boostBy($searchable->boost);
        }
        if ($searchable->recency !== null) {
            $builder->recencyBy($searchable->recency);
        }
```

to:

```php
        if ($searchable->boost !== null) {
            $builder->boostBy($searchable->boost);
        }
        if ($searchable->recency !== null) {
            $builder->recencyBy($searchable->recency);
        }
        if ($searchable->tenant !== null) {
            $builder->tenant($searchable->tenant);
        }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testTenantAttributeSetsTheTenantScope`
Expected: PASS

- [ ] **Step 6: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Core/Attribute/Searchable.php src/Core/Definition/AttributeDefinitionLoader.php tests/Fixtures/TenantScopedProduct.php tests/Unit/Core/Definition/AttributeDefinitionLoaderTest.php
git commit -m "Add #[Searchable(tenant: ...)] attribute option"
```

---

### Task 3: YAML `tenant` key (`ArrayDefinitionLoader`)

**Files:**
- Modify: `src/Core/Definition/ArrayDefinitionLoader.php:30` (`KEYS`), `:107-133` (`apply()`), `:70-104` (`override()`)
- Test: `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php`

**Interfaces:**
- Consumes: `IndexBuilder::tenant()` (Task 1), `IndexDefinition::with(tenant: ...)` (Task 1).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php` (new method):

```php
    public function testTenantKeySetsTheTenantScope(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('articles', [
            'source' => ['query' => 'SELECT a.id, a.title, a.account_id FROM article a'],
            'fields' => ['title' => 'A'],
            'filters' => ['account_id' => 'int'],
            'tenant' => 'account_id',
        ]);

        self::assertSame('account_id', $definition->tenant);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testTenantKeySetsTheTenantScope`
Expected: FAIL — `Unknown option(s): tenant.` (`InvalidDefinition` thrown by `assertKnownKeys()`).

- [ ] **Step 3: Add `tenant` to `KEYS` and to `apply()`**

In `src/Core/Definition/ArrayDefinitionLoader.php`, change line 30 from:

```php
    private const array KEYS = ['source', 'id_type', 'fields', 'filters', 'watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'class', 'trigger_level'];
```

to:

```php
    private const array KEYS = ['source', 'id_type', 'fields', 'filters', 'watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'class', 'trigger_level', 'tenant'];
```

In `apply()` (currently lines 107-133), change:

```php
        if (isset($config['boost'])) {
            $builder->boostBy(self::str($config['boost'], ''));
        }
        if (isset($config['recency'])) {
            $builder->recencyBy(self::str($config['recency'], ''));
        }
```

to:

```php
        if (isset($config['boost'])) {
            $builder->boostBy(self::str($config['boost'], ''));
        }
        if (isset($config['recency'])) {
            $builder->recencyBy(self::str($config['recency'], ''));
        }
        if (isset($config['tenant'])) {
            $builder->tenant(self::str($config['tenant'], ''));
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testTenantKeySetsTheTenantScope`
Expected: PASS

- [ ] **Step 5: Write the `override()` test (YAML overriding an attribute-defined index)**

Add to the same test file:

```php
    public function testYamlCanOverrideTheTenantScope(): void
    {
        $base = (new AttributeDefinitionLoader())->load(TenantScopedProduct::class);
        $merged = (new ArrayDefinitionLoader())->override($base, ['tenant' => 'account_id']);

        self::assertSame('account_id', $merged->tenant);
    }
```

Add `use Fuzzphony\Tests\Fixtures\TenantScopedProduct;` to the file's `use` block.

- [ ] **Step 6: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testYamlCanOverrideTheTenantScope`
Expected: FAIL — `IndexDefinition::with()` was never given a `tenant` key by `override()`, so `$merged->tenant` is `null`, not `'account_id'`. (It happens to pass trivially if `TenantScopedProduct` already sets `tenant: 'account_id'` via its attribute — to make the test meaningfully exercise the override path, assert against a *different* base without a tenant first.)

Replace the test with one that actually proves the override applies on top of a definition that has **no** tenant yet:

```php
    public function testYamlCanOverrideTheTenantScope(): void
    {
        $base = (new AttributeDefinitionLoader())->load(Product::class); // no #[Searchable(tenant: ...)]
        $merged = (new ArrayDefinitionLoader())->override($base, ['tenant' => 'price']);

        self::assertSame('price', $merged->tenant);
    }
```

(`Product` already declares a `price` filter, so this is a structurally valid override without inventing new fixture data.)

- [ ] **Step 7: Add `tenant` to `override()`**

In `override()` (currently lines 70-104), change:

```php
        if (isset($config['boost'])) {
            $changes['boostColumn'] = self::str($config['boost'], '');
        }
        if (isset($config['recency'])) {
            $changes['recencyColumn'] = self::str($config['recency'], '');
        }
```

to:

```php
        if (isset($config['boost'])) {
            $changes['boostColumn'] = self::str($config['boost'], '');
        }
        if (isset($config['recency'])) {
            $changes['recencyColumn'] = self::str($config['recency'], '');
        }
        if (isset($config['tenant'])) {
            $changes['tenant'] = self::str($config['tenant'], '');
        }
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testYamlCanOverrideTheTenantScope`
Expected: PASS

- [ ] **Step 9: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add src/Core/Definition/ArrayDefinitionLoader.php tests/Unit/Core/Definition/ArrayDefinitionLoaderTest.php
git commit -m "Add tenant YAML key to ArrayDefinitionLoader"
```

---

### Task 4: `DefinitionValidator` — reject a dangling tenant reference

**Files:**
- Modify: `src/Core/Definition/DefinitionValidator.php:58-71` (right after the filters loop)
- Test: `tests/Unit/Core/Definition/DefinitionValidatorTest.php`

**Interfaces:**
- Consumes: `IndexDefinition::$tenant`, `IndexDefinition::$filters` (Task 1).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Definition/DefinitionValidatorTest.php`:

```php
    public function testTenantMustReferenceADeclaredFilter(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::table('product'),
            fields: [new FieldDefinition('name')],
            tenant: 'account_id',
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(1, $violations);
        self::assertStringContainsString('tenant("account_id")', $violations[0]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testTenantMustReferenceADeclaredFilter`
Expected: FAIL — `assertCount(1, $violations)` fails because `$violations` is `[]` (nothing validates `tenant` yet).

- [ ] **Step 3: Add the validation rule**

In `src/Core/Definition/DefinitionValidator.php`, the filters loop currently ends at line 70 (`$seen[$filter->name] = true;`) followed by a blank line and the boost/recency loop at line 72. Insert right after the filters loop's closing brace:

```php
        $seen = [];
        foreach ($index->filters as $filter) {
            if (!Identifier::isName($filter->name)) {
                $v[] = sprintf('Filter name "%s" must match [a-z_][a-z0-9_]*.', $filter->name);
            }
            if (!Identifier::isColumn($filter->column())) {
                $v[] = sprintf('Filter "%s" maps to invalid column "%s".', $filter->name, $filter->column());
            }
            if (isset($seen[$filter->name])) {
                $v[] = sprintf('Filter "%s" is defined twice.', $filter->name);
            }
            $seen[$filter->name] = true;
        }

        if ($index->tenant !== null) {
            $known = array_map(static fn(FilterDefinition $f): string => $f->name, $index->filters);
            if (!in_array($index->tenant, $known, true)) {
                $v[] = sprintf(
                    'tenant("%s") must reference a declared filter. Known filters: %s.',
                    $index->tenant,
                    $known === [] ? '(none)' : implode(', ', $known),
                );
            }
        }

        foreach (['boost' => $index->boostColumn, 'recency' => $index->recencyColumn] as $what => $column) {
```

(The `foreach (['boost' => ...` line already exists at line 72 — this step only inserts the new `if ($index->tenant !== null) { ... }` block between the filters loop and it, nothing else changes.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testTenantMustReferenceADeclaredFilter`
Expected: PASS

- [ ] **Step 5: Write the positive-path regression test**

Add to the same file:

```php
    public function testTenantReferencingADeclaredFilterIsValid(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name')
            ->filter('account_id', 'int')
            ->tenant('account_id')
            ->build(); // build() calls DefinitionValidator::assertValid() internally; it must not throw

        self::assertSame([], DefinitionValidator::validate($definition));
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testTenantReferencingADeclaredFilterIsValid`
Expected: PASS (this also re-confirms Task 1's builder wiring under the new validation rule).

- [ ] **Step 7: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Core/Definition/DefinitionValidator.php tests/Unit/Core/Definition/DefinitionValidatorTest.php
git commit -m "Validate that tenant() references a declared filter"
```

---

### Task 5: `SearchQuery::forTenant()`

**Files:**
- Modify: `src/Core/Query/SearchQuery.php:22-38` (constructor), `:74-77` (add `forTenant()` after `withCondition()`), `:111-132` (`copy()`)
- Test: `tests/Unit/Core/Query/SearchQueryTest.php`

**Interfaces:**
- Produces: `SearchQuery::$tenant` (`mixed`, default `null`), `SearchQuery::forTenant(mixed $value): self`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Query/SearchQueryTest.php`:

```php
    public function testForTenantSetsTheTenantValue(): void
    {
        $query = (new SearchQuery())->forTenant(42);

        self::assertSame(42, $query->tenant);
    }

    public function testTenantDefaultsToNull(): void
    {
        self::assertNull((new SearchQuery())->tenant);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testForTenantSetsTheTenantValue`
Expected: FAIL — `Call to undefined method Fuzzphony\Core\Query\SearchQuery::forTenant()`.

- [ ] **Step 3: Add `$tenant` to the constructor**

In `src/Core/Query/SearchQuery.php`, change the constructor (lines 22-38) from:

```php
    public function __construct(
        public string $text = '',
        public array $conditions = [],
        public string $profile = 'default',
        public int $limit = 20,
        public int $offset = 0,
        public array $highlight = [],
        public array $thresholdOverrides = [],
        public array $rankingOverrides = [],
    ) {
```

to:

```php
    public function __construct(
        public string $text = '',
        public array $conditions = [],
        public string $profile = 'default',
        public int $limit = 20,
        public int $offset = 0,
        public array $highlight = [],
        public array $thresholdOverrides = [],
        public array $rankingOverrides = [],
        public mixed $tenant = null,
    ) {
```

- [ ] **Step 4: Add `forTenant()`**

Right after `withCondition()` (lines 74-77):

```php
    public function withCondition(Condition $condition): self
    {
        return $this->copy(conditions: [...$this->conditions, $condition]);
    }

    public function forTenant(mixed $value): self
    {
        return $this->copy(tenant: $value);
    }
```

- [ ] **Step 5: Wire `tenant` through `copy()`**

`copy()` (lines 111-132) currently reads:

```php
    private function copy(mixed ...$changes): self
    {
        $text = $changes['text'] ?? null;
        $conditions = $changes['conditions'] ?? null;
        $profile = $changes['profile'] ?? null;
        $limit = $changes['limit'] ?? null;
        $offset = $changes['offset'] ?? null;
        $highlight = $changes['highlight'] ?? null;
        $thresholdOverrides = $changes['thresholdOverrides'] ?? null;
        $rankingOverrides = $changes['rankingOverrides'] ?? null;

        return new self(
            text: is_string($text) ? $text : $this->text,
            conditions: self::conditionList($conditions) ?? $this->conditions,
            profile: is_string($profile) ? $profile : $this->profile,
            limit: is_int($limit) ? $limit : $this->limit,
            offset: is_int($offset) ? $offset : $this->offset,
            highlight: self::stringList($highlight) ?? $this->highlight,
            thresholdOverrides: self::stringKeyedArray($thresholdOverrides) ?? $this->thresholdOverrides,
            rankingOverrides: self::stringKeyedArray($rankingOverrides) ?? $this->rankingOverrides,
        );
    }
```

Change to:

```php
    private function copy(mixed ...$changes): self
    {
        $text = $changes['text'] ?? null;
        $conditions = $changes['conditions'] ?? null;
        $profile = $changes['profile'] ?? null;
        $limit = $changes['limit'] ?? null;
        $offset = $changes['offset'] ?? null;
        $highlight = $changes['highlight'] ?? null;
        $thresholdOverrides = $changes['thresholdOverrides'] ?? null;
        $rankingOverrides = $changes['rankingOverrides'] ?? null;
        $tenant = array_key_exists('tenant', $changes) ? $changes['tenant'] : $this->tenant;

        return new self(
            text: is_string($text) ? $text : $this->text,
            conditions: self::conditionList($conditions) ?? $this->conditions,
            profile: is_string($profile) ? $profile : $this->profile,
            limit: is_int($limit) ? $limit : $this->limit,
            offset: is_int($offset) ? $offset : $this->offset,
            highlight: self::stringList($highlight) ?? $this->highlight,
            thresholdOverrides: self::stringKeyedArray($thresholdOverrides) ?? $this->thresholdOverrides,
            rankingOverrides: self::stringKeyedArray($rankingOverrides) ?? $this->rankingOverrides,
            tenant: $tenant,
        );
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testForTenantSetsTheTenantValue|testTenantDefaultsToNull'`
Expected: PASS (2 tests).

- [ ] **Step 7: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Core/Query/SearchQuery.php tests/Unit/Core/Query/SearchQueryTest.php
git commit -m "Add SearchQuery::forTenant()"
```

---

### Task 6: `InvalidQuery::missingTenant()` factory

**Files:**
- Modify: `src/Core/Exception/InvalidQuery.php:10-22` (add a new factory next to `unknownFilter()`)
- Test: `tests/Unit/Core/Query/SearchQueryTest.php` (a direct assertion on the message; the throwing behavior itself is exercised end-to-end in Task 7)

**Interfaces:**
- Produces: `InvalidQuery::missingTenant(string $index): self`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Core/Query/SearchQueryTest.php`:

```php
    public function testMissingTenantMessage(): void
    {
        $exception = InvalidQuery::missingTenant('products');

        self::assertSame('Index "products" requires forTenant(); none was given.', $exception->getMessage());
    }
```

Add `use Fuzzphony\Core\Exception\InvalidQuery;` if not already imported (it already is, per the file's existing `use` block).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testMissingTenantMessage`
Expected: FAIL — `Call to undefined method Fuzzphony\Core\Exception\InvalidQuery::missingTenant()`.

- [ ] **Step 3: Add the factory method**

In `src/Core/Exception/InvalidQuery.php`, add right after `unknownFilter()` (currently lines 10-22):

```php
    /** @param list<string> $known */
    public static function unknownFilter(string $index, string $filter, array $known): self
    {
        $hint = self::closest($filter, $known);

        return new self(sprintf(
            'Index "%s" has no filter "%s".%s Known filters: %s.',
            $index,
            $filter,
            $hint !== null ? sprintf(' Did you mean "%s"?', $hint) : '',
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }

    public static function missingTenant(string $index): self
    {
        return new self(sprintf('Index "%s" requires forTenant(); none was given.', $index));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testMissingTenantMessage`
Expected: PASS

- [ ] **Step 5: Full unit suite + quality gates**

Run: `vendor/bin/phpunit --testsuite=unit && composer cs && composer stan`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Exception/InvalidQuery.php tests/Unit/Core/Query/SearchQueryTest.php
git commit -m "Add InvalidQuery::missingTenant() factory"
```

---

### Task 7: Enforcement — `SearchBuilder::forTenant()` + `PostgresEngine::execute()`

This is the task that actually makes tenant scoping do something. It needs a live PostgreSQL database (see Global Constraints) because it proves two things SQL-side: a query for tenant A never returns tenant B's rows, and an omitted `forTenant()` throws before any SQL runs.

**Files:**
- Modify: `src/Core/Search/SearchBuilder.php:74-77` (add `forTenant()` after `withCondition`'s equivalent — actually right after the `whereNull()` block, matching the query-modifier grouping)
- Modify: `src/Engine/Postgres/PostgresEngine.php:1-32` (imports), `:183-189` (top of `execute()`), `:222-242` (the three branches that consume `$query->conditions`)
- Modify: `tests/Fixtures/Indexes.php` (add a `tenant` parameter)
- Create: `tests/Integration/TenantScopingTest.php`

**Interfaces:**
- Consumes: `SearchQuery::forTenant()` (Task 5), `InvalidQuery::missingTenant()` (Task 6), `IndexDefinition::$tenant` (Task 1).
- Produces: `SearchBuilder::forTenant(mixed $value): self`.

- [ ] **Step 1: Extend the shared fixture**

In `tests/Fixtures/Indexes.php`, change:

```php
final class Indexes
{
    public static function products(string $sync = 'queue', string $table = 'fz_product'): IndexDefinition
    {
        return IndexDefinition::builder('products')
            ->fromQuery(sprintf(
                'SELECT p.id, p.name, p.description, b.name AS brand, p.price, p.in_stock, p.popularity, p.published_at FROM %s p JOIN fz_brand b ON b.id = p.brand_id',
                $table,
            ))
            ->watch($table)
            ->watch('fz_brand', sprintf('SELECT id FROM %s WHERE brand_id = :id', $table))
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'D')
            ->filter('price', 'int')
            ->filter('in_stock', 'bool')
            ->filter('published_at', 'datetime')
            ->language('english')
            ->sync($sync)
            ->boostBy('popularity')
            ->recencyBy('published_at')
            ->profile('popular', new RankingProfile(boost: 0.1, recency: 0.3))
            ->build();
    }
}
```

to (adds an opt-in `$tenant` parameter that scopes by `brand_id` — the fixture data already has three distinct brands, see `EngineConformanceTestCase::fixtureRows()`):

```php
final class Indexes
{
    public static function products(string $sync = 'queue', string $table = 'fz_product', bool $tenant = false): IndexDefinition
    {
        $builder = IndexDefinition::builder('products')
            ->fromQuery(sprintf(
                'SELECT p.id, p.name, p.description, b.name AS brand, p.brand_id, p.price, p.in_stock, p.popularity, p.published_at FROM %s p JOIN fz_brand b ON b.id = p.brand_id',
                $table,
            ))
            ->watch($table)
            ->watch('fz_brand', sprintf('SELECT id FROM %s WHERE brand_id = :id', $table))
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'D')
            ->filter('price', 'int')
            ->filter('in_stock', 'bool')
            ->filter('published_at', 'datetime')
            ->filter('brand_id', 'int')
            ->language('english')
            ->sync($sync)
            ->boostBy('popularity')
            ->recencyBy('published_at')
            ->profile('popular', new RankingProfile(boost: 0.1, recency: 0.3));

        if ($tenant) {
            $builder->tenant('brand_id');
        }

        return $builder->build();
    }
}
```

(`brand_id` becomes a normal filter unconditionally — declaring the filter has zero effect on existing callers of `Indexes::products()` since nothing queries it unless `.tenant()` is also called.)

- [ ] **Step 2: Write the failing integration test**

Create `tests/Integration/TenantScopingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** A search must never return another tenant's rows, and must never silently skip the scope. */
final class TenantScopingTest extends TestCase
{
    private function fuzzphony(bool $tenant): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products(tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testSearchNeverReturnsAnotherTenantsRows(): void
    {
        // Brand 1 (Logitech) has products 1 and 4; brand 2 (Razer) has product 2. All three mention "mouse".
        $ids = $this->fuzzphony(tenant: true)->in('products')->forTenant(1)->query('mouse')->get()->ids();

        self::assertEqualsCanonicalizing([1, 4], $ids);
    }

    public function testOmittingForTenantThrowsBeforeAnySqlRuns(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Index "products" requires forTenant(); none was given.');

        $this->fuzzphony(tenant: true)->in('products')->query('mouse')->get();
    }

    public function testNonTenantScopedIndexIsUnaffected(): void
    {
        $ids = $this->fuzzphony(tenant: false)->in('products')->query('mouse')->get()->ids();

        self::assertEqualsCanonicalizing([1, 2, 4], $ids);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run (with Postgres up per Global Constraints):
`FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit tests/Integration/TenantScopingTest.php`
Expected: FAIL — `testSearchNeverReturnsAnotherTenantsRows` returns `[1, 2, 4]` instead of `[1, 4]` (no scoping yet); `testOmittingForTenantThrowsBeforeAnySqlRuns` does not throw; `testNonTenantScopedIndexIsUnaffected` already passes (nothing to fix there, it's the regression guard).

- [ ] **Step 4: Add `SearchBuilder::forTenant()`**

In `src/Core/Search/SearchBuilder.php`, add right after `whereNull()` (currently ending at line 52, before `profile()` at line 54):

```php
    public function whereNull(string $filter, bool $isNull = true): self
    {
        return $this->with($this->query->whereNull($filter, $isNull));
    }

    public function forTenant(mixed $value): self
    {
        return $this->with($this->query->forTenant($value));
    }

    public function profile(string $profile): self
```

- [ ] **Step 5: Enforce and inject in `PostgresEngine::execute()`**

In `src/Engine/Postgres/PostgresEngine.php`, add two imports (the file already imports `Coerce` on line 25; add these alongside the other `Fuzzphony\Core\Query\*` imports at lines 16-18):

```php
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SearchQuery;
```

becomes:

```php
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SearchQuery;
```

Also add the exception import next to the existing `Fuzzphony\Core\Exception\*` imports (lines 12-13):

```php
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;
```

becomes:

```php
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\InvalidQuery;
```

At the top of `execute()` (currently lines 183-189):

```php
    private function execute(IndexDefinition $index, SearchQuery $query): array
    {
        $started = hrtime(true);
        $thresholds = $index->thresholds->with($query->thresholdOverrides);
        $profile = $query->rankingOverrides === [] ? $index->profile($query->profile) : $index->profile($query->profile)->with($query->rankingOverrides);
        (new FilterCompiler($index))->validate(...$query->conditions);
```

becomes:

```php
    private function execute(IndexDefinition $index, SearchQuery $query): array
    {
        $started = hrtime(true);
        if ($index->tenant !== null && $query->tenant === null) {
            throw InvalidQuery::missingTenant($index->name);
        }
        $conditions = $index->tenant !== null
            ? [new Condition($index->tenant, Operator::Eq, $query->tenant), ...$query->conditions]
            : $query->conditions;

        $thresholds = $index->thresholds->with($query->thresholdOverrides);
        $profile = $query->rankingOverrides === [] ? $index->profile($query->profile) : $index->profile($query->profile)->with($query->rankingOverrides);
        (new FilterCompiler($index))->validate(...$conditions);
```

Then replace every remaining use of `$query->conditions` in the rest of `execute()` (currently lines 222-242) with `$conditions`:

```php
        if ($tsquery === null && $plain === '') {
            $statement = ['label' => 'browse'] + $builder->browse($query->conditions, $profile, $thresholds, $query->limit, $query->offset);
            $rows = $this->run($statement, null);
            $statements[] = $statement;
        } else {
            $alwaysFuzzy = $fuzzyEligible && ($thresholds->fuzzyMode === FuzzyMode::Always || $tsquery === null);
            $statement = ['label' => $alwaysFuzzy ? 'full-text + fuzzy' : 'full-text']
                + $builder->ranked($tsquery, $plain, $alwaysFuzzy, $query->conditions, $profile, $thresholds, $query->limit, $query->offset, $exclusions);
            $threshold = $alwaysFuzzy ? $thresholds->fuzzySimilarity : null;
            $rows = $this->run($statement, $threshold);
            $statements[] = $statement;
            $usedFuzzy = $alwaysFuzzy;

            if (!$alwaysFuzzy && $fuzzyEligible && $thresholds->fuzzyMode === FuzzyMode::Fallback && self::total($rows) < $thresholds->fallbackBelow) {
                $statement = ['label' => 'fallback: full-text + fuzzy']
                    + $builder->ranked($tsquery, $plain, true, $query->conditions, $profile, $thresholds, $query->limit, $query->offset, $exclusions);
                $threshold = $thresholds->fuzzySimilarity;
                $rows = $this->run($statement, $threshold);
                $statements[] = $statement;
                $usedFuzzy = true;
            }
        }
```

becomes (only the four `$query->conditions` occurrences change to `$conditions`):

```php
        if ($tsquery === null && $plain === '') {
            $statement = ['label' => 'browse'] + $builder->browse($conditions, $profile, $thresholds, $query->limit, $query->offset);
            $rows = $this->run($statement, null);
            $statements[] = $statement;
        } else {
            $alwaysFuzzy = $fuzzyEligible && ($thresholds->fuzzyMode === FuzzyMode::Always || $tsquery === null);
            $statement = ['label' => $alwaysFuzzy ? 'full-text + fuzzy' : 'full-text']
                + $builder->ranked($tsquery, $plain, $alwaysFuzzy, $conditions, $profile, $thresholds, $query->limit, $query->offset, $exclusions);
            $threshold = $alwaysFuzzy ? $thresholds->fuzzySimilarity : null;
            $rows = $this->run($statement, $threshold);
            $statements[] = $statement;
            $usedFuzzy = $alwaysFuzzy;

            if (!$alwaysFuzzy && $fuzzyEligible && $thresholds->fuzzyMode === FuzzyMode::Fallback && self::total($rows) < $thresholds->fallbackBelow) {
                $statement = ['label' => 'fallback: full-text + fuzzy']
                    + $builder->ranked($tsquery, $plain, true, $conditions, $profile, $thresholds, $query->limit, $query->offset, $exclusions);
                $threshold = $thresholds->fuzzySimilarity;
                $rows = $this->run($statement, $threshold);
                $statements[] = $statement;
                $usedFuzzy = true;
            }
        }
```

(`$query->conditions` is intentionally left untouched everywhere else in the file — e.g. `explain()` calls `execute()` too and needs no separate change since it goes through the same method.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit tests/Integration/TenantScopingTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Full suites + quality gates**

Run:
```
vendor/bin/phpunit --testsuite=unit
composer cs
composer stan
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --testsuite=integration
```
Expected: all green, including the full pre-existing integration suite (confirms nothing else regressed — `Indexes::products()`'s new `brand_id` filter/select-column is additive and every other integration test calls it with the default `tenant: false`).

- [ ] **Step 8: Commit**

```bash
git add src/Core/Search/SearchBuilder.php src/Engine/Postgres/PostgresEngine.php tests/Fixtures/Indexes.php tests/Integration/TenantScopingTest.php
git commit -m "Enforce tenant scoping in PostgresEngine::execute()"
```

---

### Task 8: `fuzzphony:doctor` reports tenant scoping

**Files:**
- Modify: `src/Engine/Postgres/Inspection/PostgresInspector.php:33-68` (`inspect()`), add a new private method after `configuration()` (currently lines 378-397)

**Interfaces:**
- Consumes: `IndexDefinition::$tenant` (Task 1).

This is purely informational (no `--deep` sampling, per the spec's explicit deferral) so no new integration fixture is needed — it's covered by the existing `PostgresEngineTest::testDoctorIsHappyAfterInstall` pattern, extended with one assertion for the tenant-scoped case.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/TenantScopingTest.php` (from Task 7):

```php
    public function testDoctorReportsTenantScoping(): void
    {
        $fuzzphony = $this->fuzzphony(tenant: true);

        $messages = array_column($fuzzphony->inspect('products')->checks, 'message', 'name');

        self::assertSame('enforced via filter "brand_id"', $messages['Tenant scoping'] ?? null);
    }
```

(`InspectionReport::$checks` is a `list<Check>`; `array_column(..., 'message', 'name')` needs `Check` to be an array-accessible/object with public props — confirm by checking `Check`'s properties are public readonly, which they are per `src/Core/Inspection/Check.php`. `array_column` works on arrays of objects using public property names directly since PHP 7.0.)

- [ ] **Step 2: Run test to verify it fails**

Run: `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --filter testDoctorReportsTenantScoping`
Expected: FAIL — `$messages['Tenant scoping']` is undefined (no such check exists yet).

- [ ] **Step 3: Add the check**

In `src/Engine/Postgres/Inspection/PostgresInspector.php`, add a new private method right after `configuration()` (currently lines 378-397, ending with `return $checks;` / `}`):

```php
    /** @return list<Check> */
    private function configuration(IndexDefinition $index): array
    {
        $checks = [];
        $t = $index->thresholds;
        if ($t->fuzzyMode !== FuzzyMode::Never && !$index->hasFuzzy()) {
            $checks[] = Check::warning('Typo tolerance', 'fuzzy_mode is enabled but no field is marked fuzzy, so it has no effect.', 'Mark the most important field fuzzy: #[SearchField("A", fuzzy: true)]');
        }
        if ($t->fuzzySimilarity < 0.2) {
            $checks[] = Check::warning('Typo tolerance', sprintf('fuzzy_similarity %.2f is very tolerant; expect noisy matches.', $t->fuzzySimilarity));
        }
        if ($t->candidateLimit > 20_000) {
            $checks[] = Check::warning('Candidate limit', sprintf('candidate_limit %d may make frequent words slow to rank.', $t->candidateLimit));
        }
        if ($checks === []) {
            $checks[] = Check::ok('Configuration', sprintf('fuzzy=%s, similarity=%.2f, min_score=%.2f, candidates=%d', $t->fuzzyMode->value, $t->fuzzySimilarity, $t->minScore, $t->candidateLimit));
        }

        return $checks;
    }
```

Add directly after it:

```php
    private function tenantScoping(IndexDefinition $index): ?Check
    {
        return $index->tenant !== null
            ? Check::ok('Tenant scoping', sprintf('enforced via filter "%s"', $index->tenant))
            : null;
    }
```

In `inspect()` (currently lines 33-68), the last content line before `return new InspectionReport(...)` is:

```php
        array_push($checks, ...$this->configuration($index));

        return new InspectionReport($index->name, $checks);
```

Change to:

```php
        array_push($checks, ...$this->configuration($index));
        $tenantCheck = $this->tenantScoping($index);
        if ($tenantCheck !== null) {
            $checks[] = $tenantCheck;
        }

        return new InspectionReport($index->name, $checks);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --filter testDoctorReportsTenantScoping`
Expected: PASS

- [ ] **Step 5: Full suites + quality gates**

Run:
```
vendor/bin/phpunit --testsuite=unit
composer cs
composer stan
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;port=5432;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" vendor/bin/phpunit --testsuite=integration
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Engine/Postgres/Inspection/PostgresInspector.php tests/Integration/TenantScopingTest.php
git commit -m "fuzzphony:doctor reports tenant scoping"
```

---

### Task 9: README documentation + roadmap

**Files:**
- Modify: `README.md` (new `## Multi-tenancy` section after `## Keeping the index in sync`, currently ending at line 248; roadmap bullet update around line 373)

No test — this is documentation. Verification is a manual read-through plus confirming the code example in the README actually compiles against what Tasks 1-7 built (it does — it's the exact API surface built in this plan).

- [ ] **Step 1: Add the `## Multi-tenancy` section**

In `README.md`, the `## Keeping the index in sync` section currently ends right before `## Integrations` (line 249). Insert a new section between them:

```markdown
## Multi-tenancy

If your data is already partitioned by tenant/account in the same tables — a shared-schema
multi-tenant SaaS where each customer's data must never appear in another customer's search
results — mark the tenant column so Fuzzphony enforces it on every search, not just on the
ones a developer remembered to filter:

```php
IndexDefinition::builder('products')
    ->fromTable('product')
    ->filter('account_id', 'int')
    ->tenant('account_id')   // marks that filter as the tenant scope
    ->field('name', 'A', fuzzy: true)
    ->build();

$fuzzphony->in('products')->forTenant($accountId)->query('wireless mouse')->get();

// Omitting forTenant() on a tenant-scoped index throws immediately, before any SQL runs:
$fuzzphony->in('products')->query('wireless mouse')->get();
// InvalidQuery: Index "products" requires forTenant(); none was given.
```

`tenant()` also works with `#[Searchable(tenant: 'account_id')]` and the YAML `tenant: account_id` key.

**You don't need this** for a single-tenant application (nothing changes either way), or for
applications with fully isolated tenants — a separate database or schema per tenant already
works today via one `Connection`/engine instance per tenant.

This is an application-layer guarantee, enforced by Fuzzphony's API surface rather than by the
database. If you need defense-in-depth against raw SQL bypassing the library entirely, pair it
with your own PostgreSQL row-level security policy on the sidecar table — Fuzzphony's filter
stays correct alongside it.
```

- [ ] **Step 2: Update the roadmap**

The `## Roadmap` v1.0 bullet list currently includes:

```markdown
  * Multi-tenancy: tenant-scoped sidecar schema, isolation enforced at the query layer (not just
    application-level convention).
```

Change to:

```markdown
  * ~~Multi-tenancy: tenant-scoped sidecar schema, isolation enforced at the query layer (not
    just application-level convention).~~ **Shipped** — see [Multi-tenancy](#multi-tenancy).
```

- [ ] **Step 3: Read-through check**

Re-read the new section once end to end; confirm the code block matches Task 1/5/7's real method names (`IndexDefinition::builder()`, `->filter()`, `->tenant()`, `->field()`, `->build()`, `Fuzzphony::in()`, `SearchBuilder::forTenant()`, `SearchBuilder::query()`, `SearchBuilder::get()`, `InvalidQuery`'s exact message from Task 6) — no placeholders, no invented method names.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Document multi-tenancy in the README"
```

---

## Plan self-review (already applied above)

- **Spec coverage:** API surface (Tasks 1, 2, 3, 5, 7) — done. Schema/DDL impact "none" — verified true, no schema-generator task exists in this plan. Engine guard + injection (Task 7) — done. Validation (Task 4) — done. Doctor check (Task 8) — done. Migration story "none needed" — nothing to implement, confirmed by Task 7's regression test (`testNonTenantScopedIndexIsUnaffected`) proving zero behavior change for non-adopters. Testing plan (unit: Tasks 1, 2, 3, 4, 5, 6; integration: Tasks 7, 8) — done. README docs — Task 9. Explicitly-deferred items (RLS, cross-tenant/admin search, schema-per-tenant) — intentionally have no task, per spec.
- **Placeholder scan:** no TBD/TODO; every step has literal, complete code.
- **Type consistency:** `IndexDefinition::$tenant` (`?string`, a filter name) vs `SearchQuery::$tenant` (`mixed`, a value) are deliberately different types for different concepts — named consistently as `tenant` in both since the property's owning class disambiguates it; every task that touches one or the other uses the correct type throughout.

# 0.9, part 2: Search log and analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An opt-in search log (PostgreSQL table, written after the response) with a retention period, per-index opt-out and a pluggable query redactor; analytics over it (`SearchAnalytics`, `fuzzphony:analytics`, a demo page).

**Architecture:** Core gets the record, the `SearchLog` and `QueryRedactor` interfaces, `SearchAnalytics` and an optional engine capability `SearchLogStore`; the `SearchGate` of part 1 records every search (and every refusal) through them. The PostgreSQL engine implements the store and creates the table when asked. The bundle buffers records and writes them in one batch on `kernel.terminate`.

**Tech Stack:** PHP 8.4, PostgreSQL 15 to 18, PHPUnit 12, Symfony 7.4/8.x.

**Spec:** `docs/superpowers/specs/2026-10-12-v09-security-and-analytics-design.md` (part 2 and "Compatibility"). **Depends on:** `docs/superpowers/plans/2026-10-12-v09-1-identity.md` (merged: `Actor`, `SearchGate`, `fuzzphony.search_gate`).

## Global Constraints

- Off by default: `search_log.enabled: false`; the table is created only when enabled (`fuzzphony:schema --apply`); nothing is written, no extra statement, no new service work when disabled. The existing suite passes unchanged.
- The log never fails or slows a search: records are buffered, written after the response in one batched `INSERT`; a write error is caught, counted (`fuzzphony.search_log.failed`) and logged.
- Redaction happens before anything is stored (the stored text and `query_norm` are redacted). Default `retention_days: 90`. `log: false` on an index (builder `->log(false)`, `#[Searchable(log: false)]`, YAML `log: false`) keeps it out of the log.
- `SearchAnalytics` on a tenant-scoped index needs `tenant:` or `allTenants: true` (an `InvalidArgument` otherwise). Reports count only `ok` rows.
- The `Engine` interface does not change: the store is the optional capability `Fuzzphony\Core\Engine\SearchLogStore` (like `Suggestions`).
- New public classes go into `tests/Unit/PublicApiTest.php` (`SearchLog`, `SearchRecord`, `RecordStatus`, `QueryRedactor`, `PatternQueryRedactor`, `QueryStat`, `SearchAnalytics`, `SearchLogStore`); the rest is `@internal`.
- php-cs-fixer and PHPStan max clean; docs, README, CHANGELOG `[Unreleased]`, demo README in the same PR.

## Review Focus

- A query that contains an e-mail address: the stored `query` and `query_norm` hold `[redacted]`, never the address, also in the `denied` rows (Task 2).
- The log table does not exist yet (enabled, `schema --apply` not run): the search still succeeds, the failure is counted (Task 4).
- A search of an index with `log: false`: no row (Task 2).
- Time windows: `from` inclusive, `to` exclusive; a search exactly at `from` counts (Task 3).
- The same query with different case or spacing groups into one row (Task 3).
- Analytics on a tenant-scoped index without `tenant`/`allTenants`: refused; with `tenant`: only that tenant's rows (Task 3).
- Two searches in one request and a flush that fails halfway: nothing is lost silently, the buffer is cleared, the metric counts the failure (Task 4).

---

### Task 1: Core: record, log, redactor, analytics, capability, `log` flag

**Files:**
- Create: `src/Core/Analytics/{RecordStatus,SearchRecord,SearchLog,NullSearchLog,QueryRedactor,PatternQueryRedactor,QueryStat,SearchAnalytics}.php`, `src/Core/Engine/SearchLogStore.php`
- Modify: `src/Core/Definition/{IndexDefinition,IndexBuilder,ArrayDefinitionLoader,AttributeDefinitionLoader}.php`, `src/Core/Attribute/Searchable.php`, `src/Core/Fuzzphony.php`, `tests/Unit/PublicApiTest.php`
- Test: `tests/Unit/Core/Analytics/{SearchRecordTest,PatternQueryRedactorTest,SearchAnalyticsTest}.php`, `tests/Unit/Core/Definition/IndexLogFlagTest.php`

**Interfaces:**
- Produces:
  - `enum RecordStatus: string { case Ok = 'ok'; case Denied = 'denied'; case RateLimited = 'rate_limited'; }`
  - `final readonly class SearchRecord { __construct(public \DateTimeImmutable $at, public string $index, public int|string|null $tenant, public ?string $user, public string $query, public int $hits, public float $tookMs, public bool $usedFuzzy, public RecordStatus $status = RecordStatus::Ok); public function normalised(): string }` (`normalised()`: lower-cased, whitespace collapsed, trimmed).
  - `interface SearchLog { public function record(SearchRecord $record): void; }`, `NullSearchLog`.
  - `interface QueryRedactor { public function redact(string $query): string; }`; `PatternQueryRedactor::__construct(list<string> $patterns, string $replacement = '[redacted]')`, `PatternQueryRedactor::fromSettings(list<string> $settings): self` (the setting `email` is the preset, anything else is a regular expression with delimiters).
  - `final readonly class QueryStat { __construct(public string $query, public int $searches, public float $averageHits, public \DateTimeImmutable $lastSeen) }`.
  - `interface SearchLogStore { writeSearchLog(list<SearchRecord>): int; purgeSearchLog(\DateTimeInterface $before): int; topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): list<QueryStat>; zeroResultQueries(same arguments): list<QueryStat>; }`
  - `final readonly class SearchAnalytics { __construct(SearchLogStore $store, IndexRegistry $registry); topQueries(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 20, int|string|null $tenant = null, bool $allTenants = false): list<QueryStat>; zeroResultQueries(...same); purge(\DateTimeInterface $before): int }`.
  - `Fuzzphony::analytics(): SearchAnalytics` (throws `InvalidArgument` when the engine is not a `SearchLogStore`).
  - `IndexDefinition::$log` (`bool`, default `true`), `IndexBuilder::log(bool)`, `Searchable::$log`, YAML key `log`.

- [ ] **Step 1: Write the failing tests.**

`SearchRecordTest`:

```php
public function testTheTextIsNormalisedForGrouping(): void
{
    $record = new SearchRecord(new \DateTimeImmutable(), 'products', null, null, "  Wireless\t MOUSE  ", 3, 1.5, false);

    self::assertSame('wireless mouse', $record->normalised());
}

public function testAccentedCapitalsAreLowerCasedAsUnicode(): void
{
    $record = new SearchRecord(new \DateTimeImmutable(), 'products', null, null, 'CRÈME', 0, 1.0, false);

    self::assertSame('crème', $record->normalised());
}
```

`PatternQueryRedactorTest`:

```php
public function testTheEmailPresetMasksAddresses(): void
{
    $redactor = PatternQueryRedactor::fromSettings(['email']);

    self::assertSame('invoice for [redacted] 2026', $redactor->redact('invoice for ada.lovelace+x@example.co.uk 2026'));
    self::assertSame('wireless mouse', $redactor->redact('wireless mouse'));
}

public function testARegularExpressionSettingIsUsedAsIs(): void
{
    $redactor = PatternQueryRedactor::fromSettings(['/\d{4}-\d{4}-\d{4}-\d{4}/']);

    self::assertSame('card [redacted] expired', $redactor->redact('card 1234-5678-9012-3456 expired'));
}

public function testAnInvalidPatternIsRefusedWhenTheRedactorIsBuilt(): void
{
    $this->expectException(\Fuzzphony\Core\Exception\InvalidConfiguration::class);
    PatternQueryRedactor::fromSettings(['/unterminated']);
}
```

`SearchAnalyticsTest` (a fake store records the arguments and returns a fixed list):
 - a tenant-scoped index (`Indexes::products(tenant: true)`) with neither `tenant` nor `allTenants` throws `InvalidArgument` (message mentions `allTenants`); with `tenant: 3` the store receives `3`; with `allTenants: true` the store receives `allTenants: true`;
 - an index that is not tenant-scoped ignores both;
 - `limit` outside 1..1000 throws `InvalidArgument`;
 - `Fuzzphony::analytics()` on an engine that is not a `SearchLogStore` throws `InvalidArgument`.

`IndexLogFlagTest`: a built index has `log === true`; `->log(false)` sets it; the YAML key `log: false` and `#[Searchable(log: false)]` reach `IndexDefinition::$log`; `ArrayDefinitionLoader`'s `KEYS` accepts `log`.

- [ ] **Step 2: Run them.** Expected: FAIL.

- [ ] **Step 3: Implement.**

`SearchRecord::normalised()`:

```php
    public function normalised(): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->query)));
    }
```

`PatternQueryRedactor`:

```php
final readonly class PatternQueryRedactor implements QueryRedactor
{
    public const string EMAIL = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u';

    /** @param list<string> $patterns */
    public function __construct(private array $patterns, private string $replacement = '[redacted]')
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new InvalidConfiguration(sprintf('search_log.redact: "%s" is not a valid regular expression.', $pattern));
            }
        }
    }

    /** @param list<string> $settings "email" or a regular expression with delimiters */
    public static function fromSettings(array $settings): self
    {
        return new self(array_map(static fn(string $s): string => $s === 'email' ? self::EMAIL : $s, $settings));
    }

    public function redact(string $query): string
    {
        foreach ($this->patterns as $pattern) {
            $query = (string) preg_replace($pattern, $this->replacement, $query);
        }

        return $query;
    }
}
```

`SearchAnalytics`:

```php
    public function topQueries(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 20, int|string|null $tenant = null, bool $allTenants = false): array
    {
        return $this->store->topQueries(...$this->arguments($index, $from, $to, $limit, $tenant, $allTenants));
    }

    /** @return array{IndexDefinition, \DateTimeInterface, ?\DateTimeInterface, int, int|string|null, bool} */
    private function arguments(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        $definition = $this->registry->get($index);
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgument(sprintf('The limit must be between 1 and 1000, got %d.', $limit));
        }
        if ($definition->tenant !== null && $tenant === null && !$allTenants) {
            throw new InvalidArgument(sprintf('Index "%s" is tenant-scoped: name a tenant, or pass allTenants: true for a report across tenants.', $index));
        }

        return [$definition, $from, $to, $limit, $definition->tenant === null ? null : $tenant, $definition->tenant === null ? false : $allTenants];
    }
```
(`zeroResultQueries` mirrors `topQueries`; `purge()` delegates to `purgeSearchLog`.)

`Fuzzphony::analytics()`:

```php
    /** The reports over the search log, and its purge. @throws InvalidArgument when the engine keeps no search log */
    public function analytics(): SearchAnalytics
    {
        if (!$this->engine instanceof SearchLogStore) {
            throw new InvalidArgument(sprintf('The "%s" engine keeps no search log.', $this->engine->name()));
        }

        return new SearchAnalytics($this->engine, $this->registry);
    }
```

`IndexDefinition`: add `public bool $log = true` (constructor, after `synonyms`; keep named-arg compatibility), include it in `copy()`/`withLog(bool $log): self`, `IndexBuilder::log(bool $log = true)`, `Searchable(public bool $log = true)`, `AttributeDefinitionLoader`: `if (!$searchable->log) { $builder->log(false); }`, `ArrayDefinitionLoader`: add `'log'` to `KEYS`, in the pure-YAML branch `if (isset($config['log'])) { $builder->log(self::bool(...)) }` and in the override branch `$merged = $merged->withLog(...)` (copy how `tenant` and `unaccent` are handled; look at the existing bool handling for `unaccent`). Add the new classes to `PublicApiTest::PUBLIC`.

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit && vendor/bin/phpstan analyse --memory-limit=1G`. Expected: PASS.

- [ ] **Step 5: Commit** "Core: SearchRecord, SearchLog, QueryRedactor, SearchAnalytics and the log flag".

---

### Task 2: The gate records searches and refusals

**Files:**
- Modify: `src/Core/Search/SearchGate.php`
- Test: `tests/Unit/Core/Search/SearchGateLogTest.php`

**Interfaces:**
- Consumes: Task 1; `SearchGate` of part 1.
- Produces: `SearchGate::__construct(MetricsCollector $metrics = new NullMetricsCollector(), ?LoggerInterface $logger = null, SearchLog $log = new NullSearchLog(), ?QueryRedactor $redactor = null)`; a row per search (`ok`) and per `ActorRequired` refusal (`denied`); metric `fuzzphony.search_log.failed`.

- [ ] **Step 1: Write the failing test** (`SearchGateLogTest`; a `RecordingSearchLog implements SearchLog` collecting records; a stub engine returning a result with `total: 3`, `tookMs: 4.2`, `usedFuzzy: true`):

```php
public function testASuccessfulSearchIsRecordedWithItsActor(): void
{
    $log = new RecordingSearchLog();
    $gate = new SearchGate(log: $log);
    $builder = new SearchBuilder($this->engineReturning(total: 3, tookMs: 4.2, usedFuzzy: true), Indexes::products(tenant: true), gate: $gate);

    $builder->query('Wireless Mouse')->asActor(new Actor(tenant: 3, user: 'ada'))->get();

    self::assertCount(1, $log->records);
    $r = $log->records[0];
    self::assertSame(['products', 3, 'ada', 'Wireless Mouse', 3, 4.2, true, RecordStatus::Ok], [$r->index, $r->tenant, $r->user, $r->query, $r->hits, $r->tookMs, $r->usedFuzzy, $r->status]);
}

public function testAnActorRefusalIsRecordedAsDenied(): void
{
    $log = new RecordingSearchLog();
    $builder = new SearchBuilder($this->createStub(Engine::class), Indexes::products(tenant: true), gate: new SearchGate(log: $log));

    try {
        $builder->query('x')->asActor(new Actor(user: 'ada'))->get();
    } catch (ActorRequired) {
    }

    self::assertSame([RecordStatus::Denied], array_map(static fn($r) => $r->status, $log->records));
}

public function testTheQueryIsRedactedBeforeItIsRecorded(): void
{
    $log = new RecordingSearchLog();
    $gate = new SearchGate(log: $log, redactor: PatternQueryRedactor::fromSettings(['email']));
    $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: $gate);

    $builder->query('orders of ada@example.com')->get();

    self::assertSame('orders of [redacted]', $log->records[0]->query);
}

public function testAnIndexWithLogFalseIsNotRecorded(): void
{
    $log = new RecordingSearchLog();
    $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products()->withLog(false), gate: new SearchGate(log: $log));

    $builder->query('mouse')->get();

    self::assertSame([], $log->records);
}

public function testALogThatThrowsNeverFailsTheSearch(): void
{
    $metrics = new RecordingMetricsCollector();
    $log = new class implements SearchLog { public function record(SearchRecord $record): void { throw new \RuntimeException('disk full'); } };
    $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: new SearchGate($metrics, log: $log));

    $result = $builder->query('mouse')->get();

    self::assertSame(1, $result->total);
    self::assertSame(1, $metrics->count('fuzzphony.search_log.failed', ['index' => 'products']));
}

public function testASearchWithoutAGateLogsNothingAndNothingChanges(): void
{
    // the same call through a builder without a gate: only the engine is involved
    $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products());

    self::assertSame(1, $builder->query('mouse')->get()->total);
}
```

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement** in `SearchGate`: add the two constructor parameters; in `guarded()` keep the actor check and, around `$run()`:

```php
        $result = $run();
        if ($result instanceof SearchResult) {
            $this->record($index, $query, $result->total, $result->tookMs, $result->usedFuzzy, RecordStatus::Ok);
        }

        return $result;
```
(`explain()` runs the search again for a developer: it records nothing: only `search()` passes a `SearchResult`.) In the `ActorRequired` catch, before rethrowing: `$this->record($index, $query, 0, 0.0, false, RecordStatus::Denied);`. And:

```php
    private function record(IndexDefinition $index, SearchQuery $query, int $hits, float $tookMs, bool $usedFuzzy, RecordStatus $status): void
    {
        if (!$index->log || $this->log instanceof NullSearchLog) {
            return;
        }
        try {
            $text = $this->redactor?->redact($query->text) ?? $query->text;
            $this->log->record(new SearchRecord(new \DateTimeImmutable(), $index->name, $query->actor?->tenant ?? $query->tenant, $query->actor?->user, mb_substr($text, 0, 255), $hits, $tookMs, $usedFuzzy, $status));
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', ['index' => $index->name]);
            $this->logger?->error('Fuzzphony could not record a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);
        }
    }
```
(Add `use` lines for the Analytics classes.) The redaction runs before the 255-character cut, so a pattern is never cut in half.

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Core && vendor/bin/phpstan analyse --memory-limit=1G`. Expected: PASS.

- [ ] **Step 5: Commit** "SearchGate records searches and refusals".

---

### Task 3: PostgreSQL: the table and the store

**Files:**
- Modify: `src/Engine/Postgres/PostgresEngine.php` (constructor, implements `SearchLogStore`), `src/Engine/Postgres/Schema/Names.php`, `src/Engine/Postgres/Schema/PostgresSchemaGenerator.php`, `src/Engine/Postgres/Inspection/PostgresInspector.php`
- Create: `src/Engine/Postgres/SearchLogRepository.php` (the SQL; the engine delegates)
- Test: `tests/Integration/SearchLogStoreTest.php`, `tests/Unit/Postgres/SchemaGeneratorSearchLogTest.php`

**Interfaces:**
- Consumes: `SearchLogStore`, `SearchRecord`, `QueryStat`.
- Produces: `PostgresEngine::__construct(Connection, string $extensionSchema = 'public', string $schema = 'public', MetricsCollector $metrics = new NullMetricsCollector(), bool $searchLog = false, int $searchLogRetentionDays = 90)`; `Names::searchLog(): string` (the qualified `fuzzphony_search_log`); the table is part of `globalSchema()` when `$searchLog` is true; a doctor check "Search log".

- [ ] **Step 1: Write the failing tests.**

`SchemaGeneratorSearchLogTest`: `new PostgresSchemaGenerator($names, searchLog: false)->global(...)` has no statement mentioning `search_log`; with `searchLog: true` it has the `CREATE TABLE IF NOT EXISTS ... fuzzphony_search_log` statement and two `CREATE INDEX IF NOT EXISTS` statements (`(index_name, at)` and `(index_name, query_norm)`), each with a description.

`SearchLogStoreTest` (PostgreSQL; `PostgresTestCase::connect()`, apply the schema with the log enabled):

```php
public function testRecordsAreWrittenInOneBatchAndReportedByNormalisedQuery(): void
{
    $store = $this->store();
    $now = new \DateTimeImmutable('2026-10-12 10:00:00');
    $store->writeSearchLog([
        $this->record($now, 'Wireless Mouse', hits: 5),
        $this->record($now->modify('+1 minute'), '  wireless   mouse', hits: 7),
        $this->record($now->modify('+2 minutes'), 'hedphones', hits: 0),
        $this->record($now->modify('+3 minutes'), 'denied one', hits: 0, status: RecordStatus::Denied),
    ]);

    $top = $store->topQueries($this->index(), $now->modify('-1 hour'), null, 10, null, false);

    self::assertSame(['wireless mouse', 'hedphones'], array_map(static fn($s) => $s->query, $top));
    self::assertSame([2, 1], array_map(static fn($s) => $s->searches, $top));
    self::assertEqualsWithDelta(6.0, $top[0]->averageHits, 0.001);
}

public function testZeroResultQueriesAreOnlyTheOnesThatFoundNothing(): void { /* hedphones only; the denied row never counts */ }

public function testTheWindowIsFromInclusiveToExclusive(): void { /* a row exactly at from counts; exactly at to does not */ }

public function testATenantReportSeesOnlyItsRowsAndAllTenantsSeesAll(): void { /* tenant 1 and 2 rows; tenant: 1 gives only tenant 1; allTenants: true both */ }

public function testPurgeDeletesOnlyOlderRowsAndReturnsTheCount(): void
{
    // two rows older than the cut, one newer: purge returns 2, the newer stays
}

public function testWritingNothingIsANoOp(): void { self::assertSame(0, $this->store()->writeSearchLog([])); }
```
(Write each of the elided tests in full the same way: build records with the helper `record(...)`, call the store, assert exact lists and counts.)

- [ ] **Step 2: Run them.** Expected: FAIL.

- [ ] **Step 3: Implement.**

`Names`: `public function searchLog(): string { return $this->qualify(self::PREFIX . 'search_log'); }` and `searchLogIndexName(string $suffix): string`.

`PostgresSchemaGenerator::__construct(Names $names, private bool $searchLog = false)`; in `global()` append, when `$this->searchLog`:

```php
        $statements[] = new Statement(sprintf(
            "CREATE TABLE IF NOT EXISTS %s (\n    id bigserial PRIMARY KEY,\n    at timestamptz NOT NULL,\n    index_name text NOT NULL,\n    tenant text,\n    actor_user text,\n    query text NOT NULL,\n    query_norm text NOT NULL,\n    hits integer NOT NULL,\n    took_ms real NOT NULL,\n    used_fuzzy boolean NOT NULL,\n    status text NOT NULL\n)",
            $this->names->searchLog(),
        ), 'Search log (opt-in)');
        $statements[] = new Statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (index_name, at)', $this->names->searchLogIndexName('index_at'), $this->names->searchLog()), 'Search log by index and time');
        $statements[] = new Statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (index_name, query_norm)', $this->names->searchLogIndexName('index_norm'), $this->names->searchLog()), 'Search log grouped by query');
```
(Match how `global()` builds and returns its `SchemaPlan`; the snippet shows the statements, add them to that list in the same way.)

`PostgresEngine`: new constructor parameters stored as `private readonly bool $searchLogEnabled`, `private readonly int $searchLogRetentionDays`; pass `$searchLog` to the generator; `implements ... SearchLogStore`; the four methods delegate to `SearchLogRepository($this->connection, $this->names)` inside `$this->guard('search log', ...)` (see how `suggestWords()` uses `guard()`).

`SearchLogRepository`:

```php
    /** @param list<SearchRecord> $records */
    public function write(array $records): int
    {
        if ($records === []) {
            return 0;
        }
        $values = [];
        $params = [];
        foreach ($records as $i => $r) {
            $values[] = sprintf('(:at%1$d, :ix%1$d, :tn%1$d, :us%1$d, :q%1$d, :qn%1$d, :h%1$d, :ms%1$d, :fz%1$d, :st%1$d)', $i);
            $params += [
                "at$i" => $r->at->format('Y-m-d H:i:s.uP'), "ix$i" => $r->index, "tn$i" => $r->tenant === null ? null : (string) $r->tenant,
                "us$i" => $r->user, "q$i" => $r->query, "qn$i" => $r->normalised(), "h$i" => $r->hits, "ms$i" => $r->tookMs,
                "fz$i" => $r->usedFuzzy ? 't' : 'f', "st$i" => $r->status->value,
            ];
        }

        return $this->connection->execute(sprintf(
            'INSERT INTO %s (at, index_name, tenant, actor_user, query, query_norm, hits, took_ms, used_fuzzy, status) VALUES %s',
            $this->names->searchLog(),
            implode(', ', $values),
        ), $params);
    }

    public function purge(\DateTimeInterface $before): int
    {
        return $this->connection->execute(sprintf('DELETE FROM %s WHERE at < :before', $this->names->searchLog()), ['before' => $before->format('Y-m-d H:i:s.uP')]);
    }

    /** @return list<QueryStat> */
    public function top(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $zeroOnly): array
    {
        $params = ['ix' => $index, 'from' => $from->format('Y-m-d H:i:s.uP'), 'lim' => $limit];
        $where = "index_name = :ix AND status = 'ok' AND at >= :from";
        if ($to !== null) {
            $where .= ' AND at < :to';
            $params['to'] = $to->format('Y-m-d H:i:s.uP');
        }
        if ($tenant !== null) {
            $where .= ' AND tenant = :tn';
            $params['tn'] = (string) $tenant;
        }
        if ($zeroOnly) {
            $where .= ' AND hits = 0';
        }
        $rows = $this->connection->fetchAll(sprintf(
            'SELECT query_norm AS query, count(*)::int AS searches, avg(hits)::float8 AS avg_hits, max(at) AS last_seen FROM %s WHERE %s GROUP BY query_norm ORDER BY searches DESC, query_norm LIMIT :lim',
            $this->names->searchLog(),
            $where,
        ), $params);

        return array_map(static fn(array $r): QueryStat => new QueryStat(Coerce::str($r['query']), Coerce::int($r['searches']), Coerce::float($r['avg_hits']), new \DateTimeImmutable(Coerce::str($r['last_seen']))), $rows);
    }
```
(`PostgresEngine::topQueries()` calls `top(..., zeroOnly: false)`, `zeroResultQueries()` with `true`; the `allTenants` argument means "do not filter": pass `$allTenants ? null : $tenant`. Check how `Connection::execute()` binds a `null` and a PHP bool in this codebase: pass booleans as the strings `'t'`/`'f'` as shown, and null as null.)

Inspector: add to the checks of each index, when the engine was built with the log enabled, a check `Search log`: error "table missing" (fix: `fuzzphony:schema --apply`) or ok with the row count, plus a warning when rows older than the retention exist (fix: `fuzzphony:search-log:purge`). Pass the two engine settings into `PostgresInspector` by constructor, with defaults off, so a doctor of an engine without the log is unchanged (assert that in `PostgresInspectorChecksTest`).

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Postgres tests/Integration/SearchLogStoreTest.php tests/Integration/PostgresInspectorChecksTest.php && vendor/bin/phpstan analyse --memory-limit=1G`. Expected: PASS.

- [ ] **Step 5: Commit** "PostgreSQL: the search log table and the store".

---

### Task 4: Bundle: configuration, buffer, flush, commands

**Files:**
- Create: `src/Bundle/Analytics/BufferedSearchLog.php`, `src/Bundle/EventListener/SearchLogFlushListener.php`, `src/Bundle/Command/SearchLogPurgeCommand.php`, `src/Bundle/Command/AnalyticsCommand.php`
- Modify: `src/Bundle/FuzzphonyBundle.php`
- Test: `tests/Unit/Bundle/Analytics/BufferedSearchLogTest.php`, `tests/Unit/Bundle/FuzzphonyBundleSearchLogTest.php`, `tests/Integration/Command/{AnalyticsCommandTest,SearchLogPurgeCommandTest}.php`

**Interfaces:**
- Consumes: Tasks 1 to 3.
- Produces: config `fuzzphony.search_log.{enabled, retention_days, redact}`; service `fuzzphony.search_log` (`BufferedSearchLog`); commands `fuzzphony:analytics <index> [--top|--zero] [--since=7d] [--limit=20] [--tenant=ID|--all-tenants] [--format=table|json]` and `fuzzphony:search-log:purge`.

- [ ] **Step 1: Write the failing tests.**

`BufferedSearchLogTest` (a fake `SearchLogStore` that records `writeSearchLog` calls and can be told to throw):

```php
public function testRecordsAreHeldUntilTheFlushAndWrittenInOneCall(): void
{
    $store = new FakeStore();
    $log = new BufferedSearchLog($store, new RecordingMetricsCollector());

    $log->record($this->record('a'));
    $log->record($this->record('b'));
    self::assertSame([], $store->batches);

    $log->flush();

    self::assertCount(1, $store->batches);
    self::assertCount(2, $store->batches[0]);
}

public function testAFlushWithNothingBufferedWritesNothing(): void { /* batches stays empty */ }

public function testAFailedWriteIsCountedLoggedAndTheBufferCleared(): void
{
    $store = new FakeStore(fail: true);
    $metrics = new RecordingMetricsCollector();
    $log = new BufferedSearchLog($store, $metrics, $logger = new RecordingLogger());

    $log->record($this->record('a'));
    $log->flush();   // must not throw
    $store->fail = false;
    $log->flush();   // the failed batch is not retried forever

    self::assertSame(1, $metrics->count('fuzzphony.search_log.failed', []));
    self::assertSame([], $store->batches);
}
```

`FuzzphonyBundleSearchLogTest`: default config registers no `fuzzphony.search_log` and the gate has only metrics and logger; `search_log: {enabled: true}` registers `BufferedSearchLog`, tags the flush listener for `kernel.terminate` and `console.terminate`, registers both commands, and passes `searchLog: true` and the retention days to the engine (arguments 4 and 5 after the metrics service); `redact: [email]` builds a `PatternQueryRedactor` for the gate; `retention_days: 0` is rejected (`min(1)`); an invalid regex in `redact` fails the container build with `InvalidConfiguration`.

Command tests (`AnalyticsCommandTest`, PostgreSQL): seed rows through the store, run `fuzzphony:analytics products --top --since=7d --format=json` through `CommandTester`, assert the JSON list; `--zero` lists only zero-result queries; a tenant-scoped index without `--tenant`/`--all-tenants` exits 1 with the `allTenants` hint; `--since=garbage` exits `Command::INVALID`. `SearchLogPurgeCommandTest`: rows older than `retention_days` are deleted and the count is printed; newer rows stay.

- [ ] **Step 2: Run them.** Expected: FAIL.

- [ ] **Step 3: Implement.**

`BufferedSearchLog`:

```php
final class BufferedSearchLog implements SearchLog
{
    /** @var list<SearchRecord> */
    private array $buffer = [];

    public function __construct(private readonly SearchLogStore $store, private readonly MetricsCollector $metrics, private readonly ?LoggerInterface $logger = null) {}

    public function record(SearchRecord $record): void
    {
        $this->buffer[] = $record;
    }

    /** Writes everything buffered in one statement; never throws (a lost batch is counted and logged). */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $batch = $this->buffer;
        $this->buffer = [];
        try {
            $this->store->writeSearchLog($batch);
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', [], count($batch));
            $this->logger?->error('Fuzzphony could not write {count} search log records: {message}', ['count' => count($batch), 'message' => $e->getMessage()]);
        }
    }
}
```

`SearchLogFlushListener` (invokable for `TerminateEvent` and `ConsoleTerminateEvent`): `public function __invoke(object $event): void { $this->log->flush(); }`.

Bundle: in `configure()` add

```php
                ->arrayNode('search_log')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('Record every search (who, what, how many hits) in fuzzphony_search_log; run fuzzphony:schema --apply after enabling')->end()
                        ->integerNode('retention_days')->defaultValue(90)->min(1)->info('fuzzphony:search-log:purge deletes older rows')->end()
                        ->arrayNode('redact')->scalarPrototype()->end()->info('"email" or a regular expression with delimiters: matches are replaced by [redacted] before anything is stored')->end()
                    ->end()
                ->end()
```
In `loadExtension()`: read the settings; pass `$searchLogEnabled` and `$retentionDays` as the 5th and 6th arguments of `fuzzphony.engine`; build `$redactor = PatternQueryRedactor::fromSettings($redact)` at load time when `redact !== []` (so a bad pattern fails the container build) and register it as a service `fuzzphony.query_redactor`; when enabled register `fuzzphony.search_log` (`BufferedSearchLog`, args `fuzzphony.engine`, `fuzzphony.metrics`, `logger`), the listener with two `kernel.event_listener` tags (`kernel.terminate`, `console.terminate`), both commands, and extend the `fuzzphony.search_gate` arguments to `[metrics, logger, service('fuzzphony.search_log'), redactorServiceOrNull]`. The purge command receives `service('fuzzphony')` and `$retentionDays`; `AnalyticsCommand` receives `service('fuzzphony')`.

`AnalyticsCommand::execute()`: parse `--since` with `preg_match('/^(\d+)([hdw])$/', ...)` into a `\DateInterval` (`PT{n}H`, `P{n}D`, `P{n}W`), default `7d`; `--top` (default) or `--zero`; call `$this->fuzzphony->analytics()->topQueries(index, new \DateTimeImmutable('-…'), null, limit, tenant, allTenants)`; `catch (InvalidArgument $e)` → `$io->error($e->getMessage()); return Command::FAILURE;`; output a table `query | searches | avg hits | last seen` or, with `--format=json`, `json_encode` of the list (`query`, `searches`, `average_hits`, `last_seen` ISO 8601).

`SearchLogPurgeCommand`: `analytics()->purge(new \DateTimeImmutable("-{$this->retentionDays} days"))`, prints `Deleted N search log rows older than D days.`

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Bundle tests/Integration/Command && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/php-cs-fixer fix --dry-run`. Expected: PASS. Also adjust the existing bundle tests that list every command (`testEveryCommandIsRegisteredAsAConsoleCommand`): the two new commands are registered only when the log is enabled; add a case for that.

- [ ] **Step 5: Commit** "Bundle: search_log, the buffered write, fuzzphony:analytics and the purge".

---

### Task 5: Demo: the Analytics page

**Files:**
- Create: `demo/src/Controller/AnalyticsController.php`, `demo/templates/analytics.html.twig`
- Modify: `demo/config/packages/fuzzphony.yaml` (enable the log, retention 30, redact `email`), `demo/templates/base.html.twig` (the "Under the hood" menu: add `analytics: 'Analytics'`), `demo/tools/shots.mjs` (the page), `demo/tools/flows.mjs` (a check), `demo/README.md`

**Interfaces:**
- Consumes: `Fuzzphony::analytics()`.

- [ ] **Step 1: Enable the log** in the demo's `fuzzphony.yaml`: `search_log: { enabled: true, retention_days: 30, redact: [email] }`; make sure the demo's init (the compose `init` service) applies the schema, which now creates the table (check `docker-compose.yml`/the init script; the existing `fuzzphony:schema --apply` call covers it).

- [ ] **Step 2: The controller.** `#[Route('/under-the-hood/analytics', name: 'analytics')]`, reads `$fuzzphony->analytics()->topQueries('catalog', new \DateTimeImmutable('-7 days'), null, 15)` and `zeroResultQueries(...)` for the same window, renders `analytics.html.twig`. An empty log is the normal first state: the template says "Nothing searched yet: search the catalogue and come back" with a link to the search page. Wrap the analytics calls in a `try { } catch (FuzzphonyException)` so a demo database created before 0.9 (no table) shows "run `fuzzphony:schema --apply`" instead of a 500 (like `SynonymStore` does for its tables).

- [ ] **Step 3: The template.** Extend `base.html.twig`; hero "Analytics" with the lede "What people searched for in the last seven days, and what they did not find. Fuzzphony records each search (without the visitor's e-mail addresses: the demo redacts them) and keeps 30 days."; two `.table-card` tables (top queries; searches that found nothing) using the existing table styles (`query`, `searches`, `average hits`, `last seen`). Reuse the design system classes of `benchmark.html.twig`; no new CSS unless a class is missing.

- [ ] **Step 4: Harness.** In `shots.mjs` add `{ name: 'analytics', path: '/under-the-hood/analytics' }`; in `flows.mjs` add a check: search "mouse" and "hedphones" through `/`, open `/under-the-hood/analytics`, assert both queries are listed and the menu lists Analytics (update the menu check list); bump the counts in the docs.

- [ ] **Step 5: Run** `demo` lint (`php bin/console lint:twig templates`), the flows and the shots (the commands in `demo/README.md`, "Playwright" section), expecting all checks to pass and axe to report no problems.

- [ ] **Step 6: Commit** "Demo: the Analytics page".

---

### Task 6: Documentation, CHANGELOG, push gate

**Files:**
- Create: `docs/analytics.md`
- Modify: `docs/configuration.md`, `docs/commands.md`, `docs/limitations.md` (the log is best-effort), `README.md`, `docs/roadmap.md`, `CHANGELOG.md`, `demo/README.md`, `docs/architecture.md` (the public API list and the capability)

- [ ] **Step 1: `docs/analytics.md`:** what is logged (the columns), turning it on (`search_log.enabled`, `fuzzphony:schema --apply`), redaction (`email`, regular expressions, "redaction happens before anything is stored"), retention and the purge command with a cron line, per-index `log: false`, the three surfaces (`SearchAnalytics` example, `fuzzphony:analytics` examples with JSON, the demo page), tenant rules for reports, the write behaviour (after the response, one batch, best effort: a failed write is counted as `fuzzphony.search_log.failed` and the search is never affected), what a custom `SearchLog` is (and that the built-in reports then do not apply).
- [ ] **Step 2: The other docs and the CHANGELOG** (`### Added`: the log, the redactor, `SearchAnalytics`, the commands, the demo page; "Upgrade: nothing, unless you enable the log: run `fuzzphony:schema --apply`").
- [ ] **Step 3: Push gate:** full `vendor/bin/phpunit` against PostgreSQL, PHPStan, php-cs-fixer, the demo flows; all green. Then push the branch, open the PR, wait for `ci-ok`, merge (merge commit).

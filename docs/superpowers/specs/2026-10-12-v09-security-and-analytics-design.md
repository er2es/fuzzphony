# v0.9: Security and analytics: design

Status: design, agreed 2026-10-12, not implemented. Implements `docs/roadmap.md`'s "v0.9: Security and
analytics". One release (0.9.0), built as three pull requests in dependency order; the tag comes after the third.

## Goal

The people who run a search for a product get three things they did not have: to know **who is searching** (and to
serve a tenant-scoped index from every integration without hand-written glue), to **see what people search for and
what they do not find**, and to **protect** the search (rate limits, index- and field-level authorization).

## Decisions (agreed 2026-10-12)

- **Everything is optional and off by default.** Without configuration a search is byte-for-byte what 0.8 does: the
  same SQL, no extra round trip, no table, no new service loaded. See "Compatibility".
- **Identity is one value, the `Actor`** (tenant and user, both optional), used by the tenant scope, the log and the
  guards, so the three parts share one concept. The core stays explicit: `asActor()` on the builder; only the
  bundle's integrations resolve it automatically. No ambient request context.
- **A missing tenant is a 403, not a 500 and not an empty result** (`ActorRequired`, shown as
  `AccessDeniedHttpException` by the HTTP layer).
- **One global resolver configuration**, not per index: a service implementing `ActorResolver`, or property paths on
  the Symfony Security user.
- **The search log is a PostgreSQL table behind a core interface**, written after the response, with a retention
  period, a per-index opt-out and a pluggable `QueryRedactor`. The analytics and the audit view are two readings of
  the same table.
- **Analytics has three surfaces over one query:** the `SearchAnalytics` service, `fuzzphony:analytics`, and an
  "Analytics" page in the demo.
- **Guards are a core hook (`SearchGuard`)** that runs before each search that has an `Actor`: a rate limiter on
  Symfony's RateLimiter, and an authorization guard on Symfony's voters.
- **Field-level authorization is exact:** a field the viewer may not see is not searched, not ranked, not
  highlighted and not suggested from. A display-only mode was rejected: matching on a hidden field reveals its content.

## Compatibility (a guarantee, tested)

- No `Actor`, no guard, no log configured: the SQL, the number of statements and the results are those of 0.8.
  A test runs the whole 0.8 search suite with nothing configured and compares the statements.
- `forTenant()` stays. A tenant-scoped index searched without a tenant still throws the same `InvalidQuery`
  message (it is `ActorRequired`, a subclass, only after `asActor()` was used).
- A field without `granted` is visible and searchable by everyone on the same fast path as today. The restricted
  path is taken only when a field has `granted` and the viewer is not granted it.
- The `Engine` interface, `Thresholds`, `SearchResult` and `SearchQuery`'s existing members do not change. The log
  and the analytics use a new optional capability interface (like `Suggestions`), so a custom engine does not break.
- `symfony/security-core`, `symfony/property-access`, `symfony/rate-limiter` and `symfony/security-bundle` are
  `suggest` dependencies. A setting that needs a missing package fails at container build with a message naming it.
- The search log table exists only when `search_log.enabled` is true; `fuzzphony:schema --apply` creates it then.
  No sidecar layout change, so no reindex and no upgrade step (UPGRADE: none).

## Part 1: Identity (PR 1)

**Core** (`Fuzzphony\Core\Search`):

- `final readonly class Actor { public function __construct(public int|string|null $tenant = null, public ?string $user = null); public static function anonymous(): self; }`
- `interface ActorResolver { public function resolve(): Actor; }`
- `SearchBuilder::asActor(Actor $actor): self` sets the tenant when the actor has one and keeps the user on the
  `SearchQuery` (`?Actor $actor`). The last of `forTenant()` / `asActor()` wins; an actor without a tenant never
  clears a tenant set before. The user changes no SQL in this part; the log and the guards read it.
- `ActorRequired extends InvalidQuery`: thrown at execution when the index is tenant-scoped and the search has an
  actor but no tenant. Without an actor the old `InvalidQuery` message stays.

**Bundle:**

- `fuzzphony.actor.resolver: <service id>`, or `fuzzphony.actor.tenant: account.id` and `fuzzphony.actor.user:
  userIdentifier` (property paths read on the Security user by `PropertyPathActorResolver`). Nothing configured: the
  `NullActorResolver` (anonymous). No token: anonymous, not an error.
- The API Platform `FuzzphonySearchFilter`, the `SearchComponent` and `fuzzphony:search` resolve the actor and call
  `asActor()`. On `ActorRequired` the HTTP integrations throw `AccessDeniedHttpException` (the original as
  `previous`); the command prints the message and exits 1. `fuzzphony:search` gets `--tenant` and `--user`.
- **The tenant in the Live Component is never a client-writable `LiveProp`:** it is resolved on the server on every
  request.
- Metric `fuzzphony.search.denied` (counter, `index` label) and a log line per refusal.

## Part 2: Search log and analytics (PR 2)

**Core:**

- `interface SearchLog { public function record(SearchRecord $record): void; }`, default `NullSearchLog`.
- `final readonly class SearchRecord` (at, index, tenant, user, query, hits, total, tookMs, usedFuzzy, status:
  `ok` | `denied` | `rate_limited`).
- `interface QueryRedactor { public function redact(string $query): string; }`; built in: `PatternQueryRedactor`
  (replaces matches of a list of regular expressions with `[redacted]`; the preset `email`).
- `Engine\SearchLogStore` (optional capability, implemented by `PostgresEngine`): `writeSearchLog(list<SearchRecord>)`,
  `purgeSearchLog(\DateTimeInterface $before): int`, `topQueries()`, `zeroResultQueries()`.
- `SearchAnalytics` (`Fuzzphony::analytics()`): `topQueries(string $index, \DateTimeInterface $from, ?\DateTimeInterface
  $to = null, int $limit = 20, int|string|null $tenant = null, bool $allTenants = false): list<QueryStat>` and
  `zeroResultQueries(...)` with the same arguments. `QueryStat`: query, searches, average hits, last seen. On a
  tenant-scoped index the call needs a `tenant` or `allTenants: true` (a report across tenants is a decision, not a
  default).

**Table** `fuzzphony_search_log` (in Fuzzphony's schema): `id bigserial`, `at timestamptz`, `index_name text`,
`tenant text null`, `actor_user text null`, `query text`, `query_norm text` (lower-cased, whitespace collapsed, after
redaction; the grouping key), `hits integer`, `took_ms real`, `used_fuzzy boolean`, `status text`. Indexes on
`(index_name, at)` and `(index_name, query_norm)`. Created by `schema --apply` when enabled.

**Bundle:**

- `fuzzphony.search_log.enabled` (false), `retention_days` (90), `redact: [email, '/regex/']`; per index `log: false`
  (builder `->log(false)`, attribute, YAML).
- `BufferedSearchLog` collects records during the request and writes them in one batched `INSERT` on
  `kernel.terminate` (and at the end of a console command). A failure to write never fails or delays the search:
  it is caught, counted (`fuzzphony.search_log.failed`) and logged.
- `fuzzphony:search-log:purge` (deletes older than `retention_days`; for cron), `fuzzphony:analytics <index>
  [--top|--zero] [--since=7d] [--limit=20] [--tenant=ID|--all-tenants] [--format=table|json]`.
- The doctor checks that the table exists when the log is enabled (an error) and warns when it holds rows older
  than the retention.

**Demo:** the log is enabled; an "Analytics" page under "Under the hood" shows the top and the zero-result queries of
the catalogue (it fills as the demo is used), with the retention and the redaction explained on the page.

## Part 3: Guards (PR 3)

**Core:**

- `interface SearchGuard { public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access; }`
  (`$charge` false: do not consume a rate-limit token; a federated search charges once). A guard may throw
  `AccessDenied` or `RateLimited` (both `FuzzphonyException`; `RateLimited` carries `retryAfter` seconds).
- `final readonly class Access { public ?array $fields; static all(); static only(list<string> $fields); }`; guards
  are chained, the allowed fields are intersected. Guards run in `get()`, `explain()` and `suggest()` (and per index
  in `federated()`) when the query has an actor. Order: authorization, then rate limit.
- `FieldDefinition::$granted` (`?string`): a Symfony attribute (`ROLE_ADMIN`, a voter attribute). Builder
  `->field('salary', 'B', granted: 'ROLE_HR')`, `#[SearchField(granted: ...)]`, YAML `granted:`.
- `SearchQuery::$allowedFields` (`?list<string>`), set from the guard result. Null means all fields (the fast path).

**Bundle:**

- `fuzzphony.rate_limit.limiter: <framework.rate_limiter id>`, `per: tenant|user`: `RateLimitGuard` consumes one
  token per search keyed by the tenant or the user (no key: not limited; an IP limit is Symfony's own business).
  Exceeded: `RateLimited`, shown as 429 with `Retry-After`; counter `fuzzphony.search.rate_limited`.
- `fuzzphony.authorization.enabled`: `AuthorizationGuard` asks `isGranted(<index_attribute>, $indexName)`, the attribute
  being `FUZZPHONY_SEARCH` unless `authorization.index_attribute` names another (for example `ROLE_USER`, so an
  application without a voter can use it; with the default attribute and no voter every search is denied) (denied:
  `AccessDenied`, 403) and, per field with `granted`, `isGranted($field->granted, $indexName)` to build the allowed
  fields. It reads the current Security token; an actor without a token is denied (fail closed). `fuzzphony:search`
  does not run guards (an operator tool with database access) and says so.
- Every refusal is also a `denied` / `rate_limited` row in the search log, when it is enabled.

**The restricted path (engine, PostgreSQL), taken only when `allowedFields` is not all fields:**

- Candidates still come from the GIN index on `tsv`; each word must then also match the vector of at least one
  allowed field (`s.t_<f> @@ q` ORed over the allowed fields), as field-scoped words are rechecked today. The
  typo-tolerant branch checks `word_similarity` against the allowed fields' trigram columns (`z_<f>`) only.
- **Ranking uses only the allowed fields:** `ts_rank_cd` runs on the concatenation of the allowed field vectors, and
  the exact and prefix bonuses are 0 when the primary field is not allowed. Otherwise the scores would leak the
  hidden fields.
- Highlights are computed over the allowed fields only; the hit breakdown cannot reveal a hidden field.
- Suggestions (`suggest()`) and "did you mean" return nothing for a restricted viewer (the vocabulary holds the
  words of every field). Filters and facets are not restricted in 0.9 (authorization is about text fields).
- `explain()` shows the restricted statement of the viewer asking, not the unrestricted one.

## Testing

- Unit: `Actor`, `asActor()` precedence, `ActorRequired`, the resolvers, config validation and the missing-package
  messages, the redactors, `SearchRecord` and the buffer (flush, failure swallowed), `Access` intersection, the
  guards, the SQL of the restricted path.
- Integration (PostgreSQL): a tenant-scoped index through `asActor()`; the log written and read back; the
  analytics grouping and windows; the purge; the restricted path against a matrix of leaks: a word only in a hidden
  field does not find the document, is not in the highlight, does not change the score, is not suggested, and is
  not in `didYouMean`; an unrestricted viewer on the same index gets the 0.8 SQL.
- The compatibility test above (0.8 suite with nothing configured, statements compared).
- Bundle (with `symfony/security-bundle` as a dev dependency): the filter, the component and the command with an
  actor, the 403 and 429 mapping, the tenant never client-writable in the component.
- Infection once at the end of the release branch (the changed files), as in 0.8.1.

## Documentation

New `docs/security.md` (actor, guards, authorization, the leak guarantees and their limits) and
`docs/analytics.md` (the log, the redaction, the retention, the reports); `docs/multi-tenancy.md` (the integrations
section), `docs/configuration.md`, `docs/commands.md`, the README, `docs/roadmap.md`, the CHANGELOG and the demo
README. UPGRADE.md: no steps (a note: `schema --apply` creates the log table when the log is enabled).

## Delivery

Three pull requests to `main` (protected: branch, CI green, merge), each with its tests and docs, then the tag
`v0.9.0` and the release: **PR 1** identity, **PR 2** log and analytics (with the demo page), **PR 3** guards.
Each PR is additive; `main` stays releasable after each.

## Out of scope

Row-level (document) authorization, restricting filters or facets, per-index rate limits, IP-based limits, log
shipping (Monolog, Elasticsearch: a custom `SearchLog` can do it, the built-in reports then do not apply), an
analytics dashboard beyond the demo page, and anything for other engines.

## Risks to watch

- The restricted path is the delicate part: every place a hidden field's content can influence a result (match,
  rank, bonus, highlight, suggestion, count) needs a test; a missed one is a leak.
- The buffered write after the response must not hold a database connection open longer than the request.
- `federated()` runs the guards once per index; a rate-limit token should be consumed once per federated search,
  not once per index (to be settled in the plan).

# Search log and analytics

Which texts people search for the most, which searches find nothing, and who searched what: the questions of the
people who own the content. Fuzzphony keeps an opt-in log of every search and reports on it. Back to the
[README](../README.md).

Off by default: nothing is stored, no table exists and no search does any extra work until you turn it on.

## What is logged

One row per search in `fuzzphony_search_log` (in [Fuzzphony's schema](configuration.md#fuzzphonys-schema)):

| Column | Holds |
|---|---|
| `at` | when the search ran (`timestamptz`) |
| `index_name` | the index searched |
| `tenant` | the tenant the search ran for, as text: the tenant of the query on a tenant-scoped index, else the tenant of the actor, else null |
| `actor_user` | the user of the [actor](multi-tenancy.md#the-actor-who-is-searching), or null (anonymous) |
| `actor_ip` | the client IP of the actor, masked or whole, only when [`search_log.ip`](#the-client-ip) is not `off`; null otherwise. The column is nullable and only needed when you use the IP |
| `query` | the text the visitor typed, after [redaction](#redaction), cut at 255 characters |
| `query_norm` | `query` lower-cased, whitespace collapsed and trimmed: the key the reports group by |
| `hits` | the total of the search (a lower bound when the search hit `candidate_limit`) |
| `took_ms` | the time the engine took |
| `used_fuzzy` | whether the typo-tolerant search ran |
| `status` | `ok`; `denied` (a refusal, for example an actor without a tenant on a tenant-scoped index); `rate_limited` |

A search the [zero-result rescue](searching.md#zero-result-rescue) answered is one row like any other: `query` is what the
visitor **typed** (`mpuse`), and `hits`, `used_fuzzy` and `took_ms` are those of the **rescued** search (the hits of
`mouse`, the time of both searches), with status `ok`. The log has no flag for it. So the typo no longer appears in the
zero-result report below (`zeroResultQueries()`, `--zero`, the demo's "found nothing" list); it appears among the top queries
with hits. Typos that the rescue could not answer (no unique correction, a tenant-scoped index, a restricted viewer) still show
as zero-result searches. If you want the typos the catalogue could not answer on its own to stay visible there, set
`rescue_empty: false` (the visitor then sees "no results" and, when there is one, the did-you-mean suggestion). A way to see the
rescued searches in the report is open for the API round (a flag or a status in the log).

Indexes on `(index_name, at)` and `(index_name, query_norm)` keep the reports fast. The "who searched what" report
([`recentSearches()`](#who-searched-what)) reads the rows of one index in a time window, newest first, and needs no index of
its own: the window bounds the rows it looks at, and a filter on the user or the tenant is applied to those.

A row is **personal data** as soon as it has a user, and the IP is personal data too: read
[Privacy](#privacy-the-log-is-personal-data) before you turn on the user, the IP or a report per user.

What is recorded, precisely:

- every `get()` of a search, through the [`SearchGate`](architecture.md) the bundle wires in (a plain PHP
  setup builds one, see [Without Symfony](#without-symfony)): the console command, the API Platform filter, the
  Live Component and your own code alike. A [federated search](searching.md#federated-search) records one row
  per index it searched.
- the text **the visitor typed**. A search-as-you-type search is rewritten for the engine (its last word also
  matches as a prefix); the log keeps the original text, not `(mouse | mouse*)`.
- a search with no text (a filter-only listing) is recorded with an empty query. Keep such a page out with
  [`unlogged()`](#keeping-a-search-out-of-the-log) if you do not want the rows.
- every refusal, as a `denied` row, so a refused visitor leaves a trace. A refusal is recorded even for an
  [unlogged](#keeping-a-search-out-of-the-log) search.
- not recorded: a successful `explain()`, `suggest()` and `facets()` calls on their own, and a search that failed in
  the engine (an exception). An `explain()` the gate refuses (an actor without a tenant) is a refusal like any other
  and is recorded as a `denied` row, with the text that was typed.

The reports count only the searches that ran (`status = ok`); the other rows are there for you to query.

## Turning it on

```yaml
# config/packages/fuzzphony.yaml
fuzzphony:
  search_log:
    enabled: true
    retention_days: 90          # fuzzphony:search-log:purge deletes older rows (default 90)
    redact: [email]             # see below (default: nothing)
    ip: off                     # off (default) | masked | full: keep the client IP, see "The client IP"
```

Then create the table, once (it is part of `fuzzphony:schema`, only when the log is enabled; the statements are
idempotent):

```
bin/console fuzzphony:schema --apply
```

`fuzzphony:doctor` has a "Search log" check: an **error** when the table is missing, or when [`ip`](#the-client-ip) is on and the
`actor_ip` column is not (both fixed by the command above), a warning when it holds rows older than the retention (fixed by the purge command below), otherwise it
reports that the table exists (it never counts the rows: the table can be big). A role that only searches needs
`INSERT` on the table and nothing else (the `id` is an identity column, which needs no privilege on its hidden
sequence); the reports need `SELECT`, the purge `DELETE`. See [Fuzzphony's schema](configuration.md#fuzzphonys-schema) for the
`GRANT`.

## Redaction

People paste e-mail addresses and other secrets into search boxes, and the log would keep them. `redact` lists
what to remove: the preset `email`, and regular expressions (with delimiters) of your own. Every match is replaced
by `[redacted]`:

```yaml
fuzzphony:
  search_log:
    enabled: true
    redact:
      - email                      # ada@example.com becomes [redacted]
      - '/\b\d{3}-\d{2}-\d{4}\b/'  # a social security number
      - '/\b\d{13,19}\b/'          # a card number
```

Redaction happens **before anything is stored**: the redactor runs on the typed text first and the 255-character
cut comes after, so a pattern is never cut in half; the original text is not kept anywhere, not in the buffer, not
in the table, not in the report. A pattern that is not a valid regular expression fails the container build
(`InvalidConfiguration`), not the first search. This is pattern matching, not a guarantee: it removes what you can
describe. Names and free text are not found by it.

The redactor also exists as a class, for a plain PHP setup or your own use:

```php
use Fuzzphony\Core\Analytics\PatternQueryRedactor;

$redactor = PatternQueryRedactor::fromSettings(['email', '/\b\d{13,19}\b/']);
$redactor->redact('invoice for ada@example.com');   // "invoice for [redacted]"
```

A different redactor is any class implementing `Fuzzphony\Core\Analytics\QueryRedactor` (`redact(string): string`).

## The client IP

`search_log.ip` decides whether the log keeps the IP address of the client and how much of it:

| Mode | What is stored | Example |
|---|---|---|
| `off` (default) | nothing, even when the actor carries an address | |
| `masked` | IPv4 with the last octet zeroed; IPv6 cut to its first 48 bits; an IPv4-mapped IPv6 address (`::ffff:203.0.113.45`) is masked as the IPv4 address it carries, and so is the IPv4 address that a 6to4 address (`2002::/16`) embeds | `203.0.113.45` becomes `203.0.113.0`; `2001:db8:abcd:12::1` becomes `2001:db8:abcd::`; `2002:cb00:712d::1` becomes `2002:cb00:7100::` |
| `full` | the valid address in its canonical form (so `2001:DB8::1` and `2001:0db8:0:0:0:0:0:1` are one value) | `203.0.113.45` |

`masked` is the mode this documentation recommends: it keeps what is useful for "the same office keeps searching for
this" and for spotting abuse, and drops the host part. It is **not anonymous**, and IPv6 is the weaker case: a `/24` of IPv4
is shared by up to 254 other addresses, but a `/48` of IPv6 is what an internet provider usually hands to **one customer
site**, so a masked IPv6 address often still points at one household or office. Treat masked IPv6 as less private than
masked IPv4 (the `/48` rule is fixed; use `off` if that matters to you). An address is first cleaned the way the
[rate limit](security.md#limiting-anonymous-visitors-by-ip) cleans it (surrounding whitespace, a port, brackets and a zone id go:
`203.0.113.45:8080` and `[2001:db8::1]:443` are the addresses they carry). In **both** modes anything that is still not a valid IP address
(a host name, `unknown`, an address list) is **not stored**
(null): the column never holds free text. The masking is a pure function (`Fuzzphony\Core\Analytics\IpMode::apply()`),
applied by the `SearchGate` **before** anything is stored, like the redactor: the full address is not kept in the
buffer, the table or a report in `masked` mode.

```yaml
fuzzphony:
  search_log:
    enabled: true
    ip: masked
```

Then add the column, once (it is nullable; the plan runs the `ALTER TABLE ... ADD COLUMN` only when the column is missing, so it is safe to run again, takes no lock on
the table when the column exists, and it
works on a table that already exists):

```
bin/console fuzzphony:schema --apply
```

A deployment that never enables the IP does not need the column: the `INSERT` names `actor_ip` only when at least one
record of the batch has an address, and the report reads the column only if the table has it (the address of every row
is then null). Turning `ip` on **without** applying the schema makes the batches that carry an address fail like any other
log write would: so the log keeps its rows. A write that finds no `actor_ip` column (SQLSTATE `42703`) is repeated **once**
without it: the rows are kept, the addresses of that batch are dropped, and the metric `fuzzphony.search_log.ip_dropped` counts
the dropped addresses (the metrics collector logs it like any event). `fuzzphony:doctor` reports an **error** in its "Search
log" check while `ip` is on and the column is missing, so run `fuzzphony:schema --apply` before (or together with) the
deploy that turns `ip` on; the searches themselves are never affected. The role that applies the schema
must own the table (it is an `ALTER TABLE`). The [retention purge](#retention) deletes whole rows, so it covers the IP.

**Where the address comes from.** The Core knows nothing of HTTP: the address belongs to the
[`Actor`](multi-tenancy.md#the-actor-who-is-searching), whose third, optional argument is `ip`
(`new Actor(tenant: 7, user: 'ada', ip: '203.0.113.45')`; `Actor::anonymous()` and every existing call keep working). The
Symfony bundle fills it, when `ip` is not `off` (or when [`rate_limit.per`](security.md#limiting-anonymous-visitors-by-ip) is `ip`, which needs the address for the limit; the log still stores nothing while `ip` is `off`), with `Request::getClientIp()` of the main request, for every actor the
configured resolver returns (an anonymous visitor, a user read from the Security token, or your own `ActorResolver`; an
actor your resolver built with an `ip` of its own keeps it). `getClientIp()` follows `framework.trusted_proxies`: behind a
reverse proxy or a load balancer, **set `trusted_proxies` and `trusted_headers`**, or every search is logged with the
proxy's address; the bundle never reads `X-Forwarded-For` itself, so a visitor cannot make up an address by sending the
header. The console command `fuzzphony:search` builds its own actor, stores no address and writes no row unless you pass `--log`.

A visitor without a user (`actor_user` null) is anonymous: the report shows `user` as null. The log makes **no
attempt** to tell anonymous visitors apart except through this address.

## Retention

Search texts are personal data in many setups: keep them only as long as you use them. `retention_days` (default
90) is what `fuzzphony:search-log:purge` deletes beyond. Nothing runs it for you; add it to cron:

```
15 3 * * *  cd /var/www/app && bin/console fuzzphony:search-log:purge --env=prod
```

```
 [OK] Deleted 1204 search log rows older than 90 days.
```

The same from code: `$fuzzphony->analytics()->purge(new \DateTimeImmutable('-30 days'))`, which returns the
number of rows deleted.

## Keeping a search or an index out of the log

**An index**, with `log: false` (everything searched there is skipped):

```php
#[Searchable(log: false)]           // attribute
```

```php
IndexDefinition::builder('internal_notes')->log(false)   // builder
```

```yaml
fuzzphony:
  indexes:
    internal_notes: { log: false }  # YAML, also as an override of an attribute index
```

`log` must be a real boolean: in YAML `log: "false"` (a string) is refused with an `InvalidDefinition`, not read as
`true`. `log` is not part of the definition's fingerprint: changing it needs no reindex and no `schema --apply`.

### Keeping a search out of the log

A live search box searches on every pause in typing, so one visitor looking for "wireless headphones" produces a
search for `wi`, `wire`, `wireles`, `wireless h`, ... and the log would fill with fragments that no one meant to
search. Mark such requests `unlogged()`:

```php
$result = $fuzzphony->in('products')->query($text)->asYouType()->unlogged()->get();
```

Only the `ok` row is skipped: a refusal is recorded all the same.

A [federated search](searching.md#federated-search) has its own `unlogged()`: it keeps every per-index search out of the log
(`$fuzzphony->federated()->index('products')->index('articles')->query($text)->unlogged()->get()`).

The [Live Component](integrations.md#live-component) does this for you: its `log` attribute defaults to `false`
(every pause in typing is a search), so its requests are unlogged. `<twig:Fuzzphony:Search index="products"
log="true" />` records them. The attribute is set on the server; the browser cannot change it.

A good pattern, which the demo follows: the live requests are `unlogged()`, and the request the visitor settles on
(pressing Enter, a full page load of the results, or, in the demo, an example click or one silent repeat of the live request after
the text has stood for 1.5 seconds) is logged. The demo's suggestion dropdown and its measured
speed searches are unlogged as well.

## Reading it: three surfaces

(The third report, who searched what, is [below](#who-searched-what).)

### `SearchAnalytics`

```php
$analytics = $fuzzphony->analytics();    // throws InvalidArgument for an engine that keeps no log

$since = new \DateTimeImmutable('-7 days');

foreach ($analytics->topQueries('products', $since, limit: 10) as $stat) {
    // $stat->query (normalised), $stat->searches, $stat->averageHits (float), $stat->lastSeen
    printf("%-30s %5d searches, %.1f hits on average\n", $stat->query, $stat->searches, $stat->averageHits);
}

// the texts that found nothing: what the catalogue lacks, or the synonyms to add
// (a search the zero-result rescue answered is logged with the hits of the rescue, so it is not listed: see "What is logged")
$analytics->zeroResultQueries('products', $since, limit: 10);
```

Both take `($index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 20, int|string|null
$tenant = null, bool $allTenants = false)`: the window is `from` (inclusive) to `to` (exclusive, default now), the
limit is 1 to 1000, most searched first (ties by text). Texts are grouped by their normalised form, so `Mouse`,
`mouse` and ` mouse ` are one row; `query` in the result is that normalised text. `$analytics->purge($before)` is
the purge.

### Who searched what

```php
use Fuzzphony\Core\Analytics\RecordStatus;

// the 50 most recent searches of the last 24 hours, newest first
foreach ($analytics->recentSearches('products', new \DateTimeImmutable('-1 day')) as $s) {
    // $s->at, ->index, ->tenant (?string), ->user (null = anonymous), ->ip (?string), ->query (as stored, redacted),
    // ->hits, ->tookMs, ->usedFuzzy, ->status (RecordStatus)
    printf("%s %-12s %-15s %s\n", $s->at->format('H:i:s'), $s->user ?? '(anonymous)', $s->ip ?? '-', $s->query);
}

$analytics->recentSearches('products', $since, user: 'ada', limit: 100);             // one user, exactly this name
$analytics->recentSearches('products', $since, anonymousOnly: true);                 // the searches without a user
$analytics->recentSearches('products', $since, status: RecordStatus::RateLimited);   // only the refusals by the rate limit
$analytics->recentSearches('tickets', $since, tenant: 42, user: 'ada');              // a tenant-scoped index needs a tenant ...
$analytics->recentSearches('tickets', $since, allTenants: true, user: 'ada');        // ... or an explicit allTenants
```

`recentSearches(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 50, int|string|null
$tenant = null, bool $allTenants = false, ?string $user = null, bool $anonymousOnly = false, ?RecordStatus $status = null)`
returns a `list<LoggedSearch>` (`Fuzzphony\Core\Analytics\LoggedSearch`, final and readonly):

- the window and the limit work as in the other reports: `from` inclusive, `to` exclusive (default now), limit 1 to 1000;
  newest first (the later insert first when two searches share a timestamp);
- the text is the **stored** one (`query`: already redacted and cut at 255), never the normalised one;
- rows with the status `denied` and `rate_limited` are included (a refusal is information, with the user who was
  refused); filter with `status:` to see only some;
- `user:` keeps the searches of exactly that user (case-sensitive; an empty string is refused, it is not "nobody"),
  `anonymousOnly: true` those without a user, and the two together throw `InvalidArgument`. A user whose name is
  the empty string is stored as such and is not anonymous;
- the [tenant rules](#tenant-rules-for-reports) are the ones of the other reports, to the letter: a tenant-scoped index
  needs `tenant:` or `allTenants: true`, naming both is refused, and a call that names a tenant never returns a row of
  another, whatever the user name. On an index that is **not** tenant-scoped, a call without a tenant covers everyone, as
  `topQueries()` does (the log records the actor's tenant there too, so name it to get one customer's rows). The user, the tenant, the status and the limit are bound parameters.

### The command

```
bin/console fuzzphony:analytics products                       # the most searched, last 7 days
bin/console fuzzphony:analytics products --zero --since=30d    # searches that found nothing
bin/console fuzzphony:analytics products --limit=50 --since=12h
bin/console fuzzphony:analytics products --recent --user=ada   # who searched what: the searches of one user
bin/console fuzzphony:analytics products --recent --anonymous --since=24h
bin/console fuzzphony:analytics tickets  --recent --tenant=42 --status=denied
```

```
 --------------- ---------- ---------- ---------------------------
  query           searches   avg hits   last seen
 --------------- ---------- ---------- ---------------------------
  mouse           41         18.0       2026-10-12 09:14:51 +00:00
  headphones      23         7.5        2026-10-12 09:02:10 +00:00
  usb c cable     9          2.0        2026-10-11 17:40:33 +00:00
 --------------- ---------- ---------- ---------------------------
```

With `--recent` the table is one search per line, newest first (the `tenant` column appears when you did not name a tenant):

```
 ---------------------------- ------------- ------------- -------------- ------ ----------------
  time                         user          ip            status         hits   query
 ---------------------------- ------------- ------------- -------------- ------ ----------------
  2026-10-12 09:14:51 +00:00   ada           203.0.113.0   ok             18     wireless mouse
  2026-10-12 09:12:03 +00:00   (anonymous)   198.51.100.0  rate_limited   0      mouse
 ---------------------------- ------------- ------------- -------------- ------ ----------------
```

`--format=json` lists `at`, `index`, `tenant`, `user` (null for anonymous), `ip`, `query`, `hits`, `took_ms`, `used_fuzzy` and
`status` of each search (the text exactly as stored, JSON-escaped). In the table, control characters in the text of a search, the user,
the tenant and the IP (a newline, an ANSI escape) are printed as `U+FFFD`, so what a visitor typed cannot fake a row or write to
your terminal. `--user=NAME` (not empty), `--anonymous` (not with `--user`) and `--status=ok|denied|rate_limited`
belong to `--recent` and are refused without it; `--recent` is not combined with `--top` or `--zero`. `--since`, `--limit`,
`--tenant`, `--all-tenants` and `--format` work as below.

Options: `--top` (the default), `--zero` or `--recent`, one of them; `--since=<n><h|d|w>` (`12h`, `7d`, `4w`; default `7d`);
`--limit=N` (1 to 1000, default 20); `--tenant=ID` or `--all-tenants`; `--format=table|json`. The table shows
"last seen" in PHP's default time zone, with its offset; `--format=json` is for scripts (ISO 8601), with the query text written as is (never read as console markup):

```
bin/console fuzzphony:analytics products --zero --format=json
```

```json
[
  {
    "query": "wireles earbuds",
    "searches": 6,
    "average_hits": 0.0,
    "last_seen": "2026-10-12T08:31:02+00:00"
  }
]
```

An empty report prints `No searches in the last 7d.` (table) or `[]` (json). A bad option, or an engine that keeps
no log, prints why and exits with a non-zero code.

### The demo page

The [demo](../demo/README.md) enables the log (30 days, `redact: [email]`) and has an **Analytics** page under
"Under the hood" (`/under-the-hood/analytics`): the most searched texts and the zero-result texts of the catalogue
over the last seven days. It fills as the demo is used. Type an e-mail address into the search box and press Enter:
the log (and so the page) shows `[redacted]`.

On a publicly hosted demo anyone can plant text that other visitors then see on that page: only e-mail addresses
are redacted, so treat what it lists as public input, or keep a page like this behind a login.

## Tenant rules for reports

On a [tenant-scoped index](multi-tenancy.md) a report across every customer is a decision, not a default, so the
report needs one of two things, or it throws `InvalidArgument` (the command prints the same message and fails). This
holds for `recentSearches()` and `--recent` too, where the rows are per person:

```php
$analytics->topQueries('tickets', $since, tenant: 42);            // one tenant: its own searches only
$analytics->topQueries('tickets', $since, allTenants: true);      // an operator's view across tenants
```

```
bin/console fuzzphony:analytics tickets --tenant=42
bin/console fuzzphony:analytics tickets --all-tenants
```

The tenant is compared as text (the column is text), so `42` matches whether your tenant ids are integers or
strings. A tenant filters on **any** index: the log records the actor's tenant on an index that is not tenant-scoped
too, so a per-customer report on a shared index shows that customer's searches only (without a tenant, such an index
is reported across everyone, as before). Naming a tenant **and** `allTenants: true` is contradictory and throws
`InvalidArgument` (the command refuses `--tenant` with `--all-tenants`). Never show an `allTenants` report to a
tenant: what one customer searched for is that customer's information.

## Privacy: the log is personal data

The log holds what people typed, who they are (`actor_user`) and, if you turn it on, where from (`actor_ip`). With a user,
it is a history of that person's interests. Treat it like any other personal data:

- **Collect little.** Leave `ip` off unless you use it; prefer `masked` to `full`; add `redact` patterns for what your
  users paste into a search box (the [redaction](#redaction) removes what you can describe, not names or free text).
- **Keep it short.** Set `retention_days` to what you really use and run the [purge](#retention) from cron: the purge deletes
  whole rows, the user and the IP with them. If a person asks to be erased, delete their rows
  (`DELETE FROM fuzzphony_search_log WHERE actor_user = :user`); the table has no other copy.
- **Limit who may read.** `recentSearches()` and `--recent` show who searched what; the table needs `SELECT` for the
  reader and nothing for the role that only searches (it needs `INSERT`). Put the report behind an operator role and
  never behind a tenant's own page without a tenant filter that you control. Do not pass a tenant or a user from
  request input to `recentSearches()` without checking that the requester may see it.
- **Say so.** A search history that names a user is something your privacy notice should mention.

See also [SECURITY.md](../SECURITY.md).

## How it is written, and what happens when it fails

- **After the response.** The bundle collects the records of a request in memory (`BufferedSearchLog`) and writes
  them in **one batched `INSERT`** on `kernel.terminate`, after the response has been sent, and at the end of a
  console command (`console.terminate`). A search never waits for its own log line. (With PHP-FPM the response is
  flushed first; on a server that cannot do that, the write is still after the controller returns.) A batch is
  written in chunks of 1000 rows, so a long-running command that searches a lot stays under PostgreSQL's limit
  on bind parameters. A long-running process (`messenger:consume`, FrankenPHP, RoadRunner) never ends between its
  jobs, so the buffer is also written when it reaches 1000 records and on every `kernel.reset` (the bundle tags the
  buffer with it, so a worker flushes between two messages or requests); it never grows without limit. Nothing is
  built for a request or a command that logged nothing.
- **Best effort.** The log never fails, delays or changes a search. A record that cannot be written (the table
  is missing, the database is down, a full disk) is counted in the metric **`fuzzphony.search_log.failed`**
  (a counter; the redactor or log failing inside the search counts one with the `index` label, a lost batch
  counts the rows in it), logged at `error` level on the `fuzzphony` channel, and dropped: there is no retry and no
  spool. Alert on the metric if the log matters to you; `fuzzphony:doctor` finds a missing table.
- A process that is killed (`SIGKILL`, out of memory) before it terminates or resets loses the records it still holds,
  at most 1000. It is a log for
  trends, not an audit trail: see [Limitations](limitations.md#the-search-log-is-best-effort).

## Without Symfony

The core needs three things: an engine that keeps the log, a `SearchLog` to write to, and a gate:

```php
use Fuzzphony\Core\Analytics\{IpMode, PatternQueryRedactor, SearchLog, SearchRecord};
use Fuzzphony\Core\Search\SearchGate;

$engine = new PostgresEngine($connection, searchLog: true, searchLogRetentionDays: 90);

// the simplest log writes each record at once; buffer and write at the end of the request if you prefer
$log = new class($engine) implements SearchLog {
    public function __construct(private PostgresEngine $engine) {}
    public function record(SearchRecord $record): void { $this->engine->writeSearchLog([$record]); }
};

$fuzzphony = new Fuzzphony(
    $engine,
    new IndexRegistry([$products]),
    new SearchGate(log: $log, redactor: PatternQueryRedactor::fromSettings(['email']), ipMode: IpMode::Masked),
);
$fuzzphony->schema()->apply($connection);   // creates the table: the engine was built with searchLog: true
```

`ipMode:` is optional (`IpMode::Off`, the default, keeps no address); the address comes from the actor you give to
`asActor()`, which you fill from your own request: `new Actor(tenant: $t, user: $u, ip: $_SERVER['REMOTE_ADDR'])`. Behind a
proxy, take the client address from a source you trust (your framework's trusted-proxy handling), never from a header
the visitor can set. To add the column to an existing table, apply the schema again
(`$fuzzphony->schema()->apply($connection)`, as above).

The code above writes inside the search; the bundle's buffer writes after the response, which is why it is the
better default.

## A custom `SearchLog`

`SearchLog` is one method, `record(SearchRecord $record): void`. The `SearchRecord` is final and readonly: `at`,
`index`, `tenant`, `user`, `query` (already redacted and cut), `hits`, `tookMs`, `usedFuzzy`, `status`
(`RecordStatus::Ok`, `Denied`, `RateLimited`) and `ip` (what the log is to store: masked as `search_log.ip` says, or null). To send the records somewhere else (Monolog, a queue, Elasticsearch,
an analytics service), implement it and pass it to the `SearchGate` (plain PHP). The bundle has no setting for it:
leave `search_log` off and define the `fuzzphony.search_gate` service yourself, as a `SearchGate` built with your log.
The gate takes care of redaction, the 255-character cut, the per-index `log: false`, `unlogged()` and of a log that
throws (counted and logged, the search goes on).

With a log of your own **the built-in reports do not apply**: `Fuzzphony::analytics()`, `fuzzphony:analytics` and
the doctor check read Fuzzphony's table, which your log does not fill. Build the reports where you keep the data.

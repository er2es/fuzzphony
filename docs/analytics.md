# Search log and analytics

Which texts people search for the most, and which searches find nothing: the questions of the people who own
the content. Fuzzphony keeps an opt-in log of every search and reports on it. Back to the
[README](../README.md).

Off by default: nothing is stored, no table exists and no search does any extra work until you turn it on.

## What is logged

One row per search in `fuzzphony_search_log` (in [Fuzzphony's schema](configuration.md#fuzzphonys-schema)):

| Column | Holds |
|---|---|
| `at` | when the search ran (`timestamptz`) |
| `index_name` | the index searched |
| `tenant` | the tenant the search ran for, as text: the tenant of the query on a tenant-scoped index, else the tenant of the actor, else null |
| `actor_user` | the user of the [actor](multi-tenancy.md#the-actor-who-is-searching), or null |
| `query` | the text the visitor typed, after [redaction](#redaction), cut at 255 characters |
| `query_norm` | `query` lower-cased, whitespace collapsed and trimmed: the key the reports group by |
| `hits` | the total of the search (a lower bound when the search hit `candidate_limit`) |
| `took_ms` | the time the engine took |
| `used_fuzzy` | whether the typo-tolerant search ran |
| `status` | `ok`; `denied` (a refusal, for example an actor without a tenant on a tenant-scoped index); `rate_limited` |

Indexes on `(index_name, at)` and `(index_name, query_norm)` keep the reports fast.

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
```

Then create the table, once (it is part of `fuzzphony:schema`, only when the log is enabled; the statements are
idempotent):

```
bin/console fuzzphony:schema --apply
```

`fuzzphony:doctor` has a "Search log" check: an **error** when the table is missing (fixed by the command
above), a warning when it holds rows older than the retention (fixed by the purge command below), otherwise it
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

The [Live Component](integrations.md#live-component) does this for you: its `log` attribute defaults to `false`
(every pause in typing is a search), so its requests are unlogged. `<twig:Fuzzphony:Search index="products"
log="true" />` records them. The attribute is set on the server; the browser cannot change it.

A good pattern, which the demo follows: the live requests are `unlogged()`, and the request the visitor settles on
(pressing Enter, a full page load of the results, or, in the demo, an example click or one silent repeat of the live request after
the text has stood for 1.5 seconds) is logged. The demo's suggestion dropdown and its measured
speed searches are unlogged as well.

## Reading it: three surfaces

### `SearchAnalytics`

```php
$analytics = $fuzzphony->analytics();    // throws InvalidArgument for an engine that keeps no log

$since = new \DateTimeImmutable('-7 days');

foreach ($analytics->topQueries('products', $since, limit: 10) as $stat) {
    // $stat->query (normalised), $stat->searches, $stat->averageHits (float), $stat->lastSeen
    printf("%-30s %5d searches, %.1f hits on average\n", $stat->query, $stat->searches, $stat->averageHits);
}

// the texts that found nothing: what the catalogue lacks, or the synonyms to add
$analytics->zeroResultQueries('products', $since, limit: 10);
```

Both take `($index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 20, int|string|null
$tenant = null, bool $allTenants = false)`: the window is `from` (inclusive) to `to` (exclusive, default now), the
limit is 1 to 1000, most searched first (ties by text). Texts are grouped by their normalised form, so `Mouse`,
`mouse` and ` mouse ` are one row; `query` in the result is that normalised text. `$analytics->purge($before)` is
the purge.

### The command

```
bin/console fuzzphony:analytics products                       # the most searched, last 7 days
bin/console fuzzphony:analytics products --zero --since=30d    # searches that found nothing
bin/console fuzzphony:analytics products --limit=50 --since=12h
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

Options: `--top` (the default) or `--zero`, not both; `--since=<n><h|d|w>` (`12h`, `7d`, `4w`; default `7d`);
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
report needs one of two things, or it throws `InvalidArgument` (the command prints the same message and fails):

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
use Fuzzphony\Core\Analytics\{PatternQueryRedactor, SearchLog, SearchRecord};
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
    new SearchGate(log: $log, redactor: PatternQueryRedactor::fromSettings(['email'])),
);
$fuzzphony->schema()->apply($connection);   // creates the table: the engine was built with searchLog: true
```

The code above writes inside the search; the bundle's buffer writes after the response, which is why it is the
better default.

## A custom `SearchLog`

`SearchLog` is one method, `record(SearchRecord $record): void`. The `SearchRecord` is final and readonly: `at`,
`index`, `tenant`, `user`, `query` (already redacted and cut), `hits`, `tookMs`, `usedFuzzy` and `status`
(`RecordStatus::Ok`, `Denied`, `RateLimited`). To send the records somewhere else (Monolog, a queue, Elasticsearch,
an analytics service), implement it and pass it to the `SearchGate` (plain PHP). The bundle has no setting for it:
leave `search_log` off and define the `fuzzphony.search_gate` service yourself, as a `SearchGate` built with your log.
The gate takes care of redaction, the 255-character cut, the per-index `log: false`, `unlogged()` and of a log that
throws (counted and logged, the search goes on).

With a log of your own **the built-in reports do not apply**: `Fuzzphony::analytics()`, `fuzzphony:analytics` and
the doctor check read Fuzzphony's table, which your log does not fill. Build the reports where you keep the data.

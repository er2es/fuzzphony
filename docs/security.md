# Security: guards, rate limits and field authorization

Who may search, how often, and which fields of an index they may search and see. Back to the
[README](../README.md).

Everything here is opt-in. With nothing configured a search is what 0.8 did: the same results, and
the same statements except the probe and facet change listed in the [CHANGELOG](../CHANGELOG.md)
(the candidate probes carry the search's tenant and filters; a disjunctive facet with different
conditions uses the trigram condition). A viewer the guards let see every field takes the same fast path as before (a test compares the
statements). The [demo](#a-worked-example) has a Security page that shows all of it running.

## The actor

A guard decides about an *actor*: the tenant and the user who is searching, described once as a
`Fuzzphony\Core\Search\Actor`. Where the actor comes from, and how the API Platform filter and the Live
Component resolve it on the server on every request, is in
[Multi-tenancy](multi-tenancy.md#the-actor-who-is-searching) and
[the bundle configuration](configuration.md#the-actor).

**Who is searching?** Every search has an actor, in this order:

1. The one you name: `->asActor($actor)` (on `FederatedSearch` too, for every index).

   Whoever the actor is, if it has a tenant it can search that tenant only: a `forTenant()` naming another one is refused (`AccessDenied`,
   no tenant named); `asSystem()` is the way across tenants.

2. Otherwise the application's current user, the **ambient actor**: the service asks the `ActorResolver` itself (the Symfony bundle: the
   Security token, plus the client IP of the request when the IP is in use; plain PHP: `Fuzzphony::postgres(..., actors: $resolver)`). A
   controller that forgets `->asActor()` is therefore guarded like one that remembers, and counted by the rate limit.
3. Where nobody can be resolved (a command, a Messenger worker, a test, a request without a token) the actor is **anonymous**: the guards run
   for it, so a [`granted` field](#granted-a-field-only-some-viewers-may-search) stays hidden, and nothing is thrown for that. A tenant-scoped
   index still refuses it with `ActorRequired` (a tenant must be known). With `fuzzphony.authorization.enabled` the authorization guard
   still fails closed without a Security token (`AccessDenied`): voters never see an empty token.

```php
$fuzzphony->in('notes')->query('salary')->get();                                   // as the current user
$fuzzphony->in('notes')->asActor($resolver->resolve())->query('salary')->get();    // as this actor
```

The guards run in `get()`, in `explain()` and in `complete()`, and, for a [federated search](searching.md#federated-search), for each index it
searches.

### Searching as the system

A job that is allowed to see everything (an export, an admin command, a handler that indexes or checks documents, a test) says so, visibly:

```php
$fuzzphony->in('notes')->asSystem()->query('salary')->get();
$fuzzphony->federated()->index('notes')->index('staff')->asSystem()->query('salary')->get();
$fuzzphony->complete('notes', 'sal', system: true);
```

`asSystem()` runs no guard: every field is searched and shown, nothing is rate limited. The search log records the search with the user
`(system)` (no IP), so an audit can find them. A tenant-scoped index still needs `forTenant()`. The last of `asActor()` and `asSystem()` wins.
`fuzzphony.authorization.allow_system: false` (plain PHP: `Fuzzphony::postgres(allowSystem: false)`) refuses it with `AccessDenied` (recorded as a
denied search), for an application where nothing may bypass the guards. Do not put it in a controller that answers a visitor.

### A `granted` field needs its guard

A field with `granted: ROLE_HR` is hidden only by the authorization guard. An index with such a field while `fuzzphony.authorization.enabled` is
false would show it to everybody, so it is an error: the container does not build (`InvalidDefinition`, "set fuzzphony.authorization.enabled: true"),
`Fuzzphony::postgres()` refuses it unless one of the `guards:` can restrict fields (an authorization guard; a custom guard implements `FieldRestrictingGuard`: a rate limit alone returns every field and would show it), and `fuzzphony:doctor` reports it. Fields found by entity attributes are checked when
the registry is built.

## The guards and their order

A guard is a `Fuzzphony\Core\Search\SearchGuard`: `check(IndexDefinition $index, Actor $actor, bool
$charge = true): Access`. It throws `AccessDenied` or `RateLimited` to refuse, or returns an `Access`:
`Access::all()` (every field) or `Access::only(['title', 'body'])`. The guards of the `SearchGate` run
in order and what they allow is intersected: a field is searched only when every guard allows it.

In a Symfony application the order is fixed:

1. **The tenant check.** On a tenant-scoped index an actor without a tenant is refused with
   `ActorRequired`, before any guard runs. So an actor with no tenant is `ActorRequired` even if it
   would also lack the permission: the demo's anonymous visitor shows it.
2. **Authorization** (when `fuzzphony.authorization.enabled`).
3. **The rate limit** (when `fuzzphony.rate_limit.limiter` is set, or `per: ip`). It comes last on purpose: a search
   that is denied never spends a token.

A refusal is shown by the HTTP integrations as **403** (`ActorRequired`, `AccessDenied`) or **429**
with a `Retry-After` header (`RateLimited`), as the original exception's `previous`. (`fuzzphony:search`
runs no guard, so the only refusal it can show is `ActorRequired`, as its error message.) Your own controllers catch the exceptions
(`Fuzzphony\Core\Exception\AccessDenied`, `RateLimited` with `$retryAfter` in seconds; all are
`FuzzphonyException`s, and the three refusals `AccessDenied`, `ActorRequired` and `RateLimited` share the interface
`Fuzzphony\Core\Exception\SearchRefused`) or let the bundle's listener map them. The 403 response says only
"Searching is not allowed for this caller." (no index or tenant rule); the original exception, with the index name, is its `previous` and is logged.

Without Symfony, pass your own guards to the gate:

```php
$fuzzphony = Fuzzphony::postgres($connection, $indexes, guards: [new MyGuard()]);
```

## The rate limit

```yaml
# config/packages/framework.yaml
framework:
  rate_limiter:
    search:
      policy: sliding_window
      limit: 60
      interval: '1 minute'

# config/packages/fuzzphony.yaml
fuzzphony:
  rate_limit:
    limiter: search     # the name of the framework.rate_limiter entry (service limiter.search)
    per: user           # tenant (default), user or ip: whose searches share one bucket
```

It needs `symfony/rate-limiter` (a `suggest` dependency: without it the container fails to build with
the `composer require` line). `limiter` must be the name of a `framework.rate_limiter` entry; an unknown
name fails the container build with Symfony's own "non-existent service limiter.<name>" message. A
`compound` limiter works too. The key is the actor's tenant, user or client IP, as configured
([`per: ip`](#limiting-anonymous-visitors-by-ip) is described below). When it is
exceeded the search throws `RateLimited` (429 with `Retry-After`).

The limiter's state lives in the cache pool of the `framework.rate_limiter` entry (`cache.rate_limiter` unless you set
`cache_pool`), and a search takes its lock (`lock_factory`) while it reads and writes it. With Symfony's default pools these are
filesystem based, so every search touches a file. On a busy site point the entry at a faster pool, for example an APCu
or Redis one (`cache_pool: cache.apcu`, or a Redis pool of your own; APCu is per server, so use Redis, with a Redis-backed
`lock_factory`, when several servers must share one limit). See Symfony's
[rate limiter documentation](https://symfony.com/doc/current/rate_limiter.html).

### Limiting anonymous visitors by IP

The public search box (the Live Component, the API Platform filter) searches as the actor your
`ActorResolver` returns, and for a visitor who is not signed in that is an anonymous actor: no tenant, no user,
so `per: tenant` and `per: user` have no key for it and it was never limited. `per: ip` keys the bucket on the
client address instead:

```yaml
fuzzphony:
  rate_limit:
    per: ip             # no `limiter` needed: see the default below
```

- **The address** is `Request::getClientIp()` of the main request, read when the actor is resolved, so
  `framework.trusted_proxies` applies and Fuzzphony never reads `X-Forwarded-For` itself. Every actor that
  comes out of the bundle's resolver carries it (a signed-in user too, and an actor of your own
  `fuzzphony.actor.resolver`, unless it set an IP itself), and `per: ip` limits all of them by address.
  The address is independent of `search_log.ip`, which only decides what the log **stores**: with the
  default `off` the address is used for the limit and never written down.
- **The bucket** of an IPv4 address is the address; of an IPv6 address its **/64** (a visitor owns a whole /64
  and can rotate inside it, so a bucket per address would limit nothing). An IPv6 address that **embeds an
  IPv4 address** is that IPv4 address, in whatever spelling: IPv4-mapped (`::ffff:203.0.113.45`),
  IPv4-compatible (`::203.0.113.45`, `::/96`), NAT64 (`64:ff9b::203.0.113.45`, `64:ff9b::/96`: behind a NAT64
  gateway one /64 carries every IPv4 visitor, which a /64 bucket would turn into ONE bucket for all of them)
  and 6to4 (`2002:cb00:712d::1`, `2002::/16`: the IPv4 address is bits 16 to 48, and the visitor owns the
  whole /48 behind it, 65 536 /64s). So the same IPv4 client is one bucket however it reaches you. Ordinary
  IPv6 stays a /64.
- **An address that is not exactly an IP address** (only an `ActorResolver` of your own can produce one:
  `getClientIp()` never does) is normalised when that is safe: surrounding whitespace, a port after an IPv4
  address (`1.2.3.4:80`), brackets and a port around an IPv6 address (`[2001:db8::1]:443`) and a zone id
  (`fe80::1%eth0`). What is still no IP address (`unknown`, a list such as `1.2.3.4, 5.6.7.8`) gets no bucket and
  is **not limited** (fail open, a refused visitor is worse than an unlimited one), but it is reported: the
  counter `fuzzphony.search.rate_limit_unkeyed` (no labels) and a warning log line, **once per process**,
  never with the value. If you see it, fix your resolver.
- **The default limiter.** With `per: ip` and no `limiter`, the bundle prepends this entry to
  `framework.rate_limiter` and uses it (service `limiter.fuzzphony_ip`):

  ```yaml
  framework:
    rate_limiter:
      fuzzphony_ip: { policy: sliding_window, limit: 600, interval: '1 minute' }
  ```

  It is deliberately loose: it only stops a script that hammers the endpoint, not a person. To change the
  numbers, define your own entry and name it (`rate_limit: { limiter: public_search, per: ip }`), or define
  an entry called `fuzzphony_ip` yourself (directly under `rate_limiter`, or under its `limiters` / `limiter`
  key): the bundle does not prepend one when you did, and it does not
  override your entry. Naming a `limiter` always wins over the default.
- **A refusal** is a `RateLimited` like any other: HTTP 429 with `Retry-After`, a `rate_limited` row in the
  search log, the `fuzzphony.search.rate_limited` counter and the warning log line. None of them carries the
  address (the row carries one only when `search_log.ip` says so, masked or full, as for every search).
- **With authorization** (`authorization.enabled`) nothing changes in the order: authorization first, so a
  denied search spends no token, then the rate limit. Authorization still refuses a request that has no
  Security token, so with both settings an anonymous visitor is refused (`AccessDenied`) before the limit
  is looked at: `per: ip` alone is the setting for a public search box. On a tenant-scoped index an anonymous
  visitor has no tenant and is refused with `ActorRequired` before any guard, as before.

What the limit by IP cannot do, and what to know before you rely on it:

- **A company network or a NAT shares one address among many people.** All of them draw on one bucket, which
  is why the default is as loose as 600 a minute. If your visitors typically sit behind a few big NATs
  (an office, a university, a mobile carrier's CGNAT), raise the limit; if you face a single abusive
  client, lower it. It is a brake against scripts, not a fair share per person.
- **Without `framework.trusted_proxies`, everyone behind a reverse proxy, CDN or load balancer shares ONE
  bucket**: the application then sees the proxy's address for every visitor. Check what your application
  sees (for example `dump($request->getClientIp())` in a controller behind your real proxy, or the
  `masked` address in `fuzzphony:analytics --recent` with `search_log.ip` on): when it is the proxy's
  address (often a private `10.x`, `172.16.x`, `192.168.x` one) set `framework.trusted_proxies` to the proxy's
  address or range and `trusted_headers` to the header it sets. Trust only addresses you control: whoever can
  reach the application directly from a trusted address can name any client.
  Without a proxy in front, leave `trusted_proxies` empty: a forged `X-Forwarded-For` is then ignored (tests
  prove it).
- **A call with no request cannot be limited by IP.** The library sees no request in a plain PHP call, a
  console command, a worker or a message handler, so an actor there has no address and `per: ip` does not
  limit it (it does not throw either). The same holds for an actor you build with `new Actor(...)` without
  `ip:`; the ambient actor of a plain call carries no address either (the bundle adds the address of the request when there is one).
- **The address is read per request** when the actor is resolved, and is not stored by Fuzzphony unless
  `search_log.ip` says so. The limiter keeps its own state in the cache pool of its entry for the length of
  the window: the bundle hands the limiter an HMAC-SHA-256 of the bucket key, keyed with your `kernel.secret`,
  never the address, so whoever can read the pool (a shared cache server, a backup) cannot recover the
  addresses by trying all of them without the secret. The key is stable across requests and across servers
  that share the secret (so a Redis pool shared by several servers works), and **rotating `kernel.secret`
  resets every bucket** (each visitor starts a fresh window, once). Without the bundle, `RateLimitGuard` takes
  the secret as its third constructor argument and falls back to a fixed public default, which keeps the
  address out of the pool in clear but is no protection against enumeration. Even so, treat the pool as
  holding personal data for a minute (it expires by itself).
- **The limiter's state lives in the cache pool of the limiter** (`cache.rate_limiter`, a filesystem pool by
  default, and a lock per search): on a public endpoint every search then touches a file, so use APCu or
  Redis as described above (one server: APCu; several: Redis for the pool and for `lock_factory`).

What is and is not limited:

- **An actor that has no key** (`per: user` and an actor without a
  user, `per: ip` and an actor without an address) is not limited. A search without `asActor()` is limited as the ambient actor
  (anonymous, with the address of the request, when there is none); `asSystem()` is never limited.
- **One token per search.** `explain()` runs the search, so it **charges** a token too.
  `Fuzzphony::complete()` (the completions) charges one token per call, unless you pass `charge: false`
  (only when the same request already charged a search). The Live Component does: a render is a
  search plus its completions, and the search charges, so a render costs one token, and the Live
  Component debounces its requests, so that is one per pause in typing. `useDidYouMean` ("did you
  mean") is a request of its own and can cost one extra token.
- **A federated search charges once**, on the first index that has an actor; the other indexes run the
  guards but do not charge again. If your `configure` closures set different actors for different
  indexes, only the first actor's bucket is charged.

## Authorization

```yaml
fuzzphony:
  authorization:
    enabled: true
    index_attribute: FUZZPHONY_SEARCH   # the default
```

It needs `symfony/security-bundle`, which provides the `security.authorization_checker` and
`security.token_storage` services (`symfony/security-core` alone is not enough: the container build then
fails with Symfony's own "non-existent service" message). The guard asks Symfony's authorization checker, with the
current Security token:

- **The index:** `isGranted(<index_attribute>, $indexName)`. Not granted: `AccessDenied` (403).
- **Each field:** `isGranted($field->granted, $indexName)` for every field that names a role with
  `granted`. A field without `granted` is visible to everyone who may search the index.

`index_attribute` is `FUZZPHONY_SEARCH` by default: write a voter for it. **With the default attribute
and no voter every search is denied (it fails closed).** Name an attribute that already exists to need
no voter, for example `index_attribute: ROLE_USER`. **No token is denied too**: the guard checks the
token storage itself and refuses before it asks the checker (Symfony's checker would otherwise let the
voters decide for an empty token), so a request outside any firewall cannot search an index the guard
protects, whatever `index_attribute` or the voters say. A logged-out visitor has no token either (Symfony
gives an anonymous visitor none), so with authorization enabled only signed-in users can search.

**A footgun with the integrations.** With `authorization.enabled` the Live Component and the API
Platform filter always search as an actor (an anonymous one when there is no session), so **every
index they search** needs the index attribute granted. A voter that supports only some indexes (like
the demo's `SearchVoter`, which supports only `intranet`) abstains on the others, and abstain means
deny: either grant the attribute for all indexes, or name an existing attribute such as `ROLE_USER` in
`index_attribute` (`PUBLIC_ACCESS` grants nothing to a logged-out visitor here: with no token the guard
has already refused).

### `granted`: a field only some viewers may search

`granted` takes a Symfony attribute (a role such as `ROLE_HR`, or anything a voter decides), in all
four definition styles:

<!-- fragment -->
```php
// attribute
#[SearchField('B', granted: 'ROLE_HR')] public string $salary;

// builder
->field('salary', 'B', granted: 'ROLE_HR')
```

```yaml
# YAML
fuzzphony:
  indexes:
    staff:
      fields:
        name: { weight: A, fuzzy: true }
        salary: { weight: B, granted: ROLE_HR }
```

<!-- fragment -->
```php
// a plain PHP array, the same keys as the YAML (ArrayDefinitionLoader)
['fields' => ['salary' => ['weight' => 'B', 'granted' => 'ROLE_HR']]]
```

`granted` is not part of the index's stored fingerprint: adding, changing or removing it needs neither
`fuzzphony:schema --apply` nor a reindex. The wizard's exporters write it back out.

### What a restricted viewer sees, and does not

A viewer who is not granted a field gets a search over the other fields, and nothing of the hidden one
leaks. A word that exists only in a hidden field is:

- **not matched**: the document is not found by it (not exactly, not as a prefix, not through a
  synonym, not as a typo, and not through a field-scoped `salary:high`, which finds nothing);
- **not ranked**: the score, the exact and prefix bonuses and the score breakdown ignore the hidden
  field, so two documents that differ only in a hidden field score the same;
- **not highlighted**: a hit never shows a snippet of a hidden field;
- **not suggested**: a restricted viewer gets no "did you mean" and `complete()` returns `[]`, because
  the vocabulary holds the words of every field; for the same reason a restricted viewer's search that finds nothing is
  never rescued with a corrected spelling ([zero-result rescue](searching.md#zero-result-rescue); a tenant-scoped index
  has none either). The two swapped letters of `mosue` are matched in the fields the viewer may see only;
- **not counted**: `total`, the facet counts and the exact counts cover the documents the viewer
  could match through their allowed fields only. An excluded word (`lamp -plain`) never excludes a
  document because of a hidden field.

The same holds when every field is hidden: such a viewer finds nothing (browsing without text still
works). Filters and facets are **not** restricted in 0.9: authorization is about text fields, and a
filter is a document attribute (see the limits below).

A highlight name is validated upfront, for everyone, before a hidden field is dropped: asking to
highlight an unknown or non-highlightable field is an error whatever the viewer may see, and even when
the search finds nothing. (Before 0.9 the error appeared only when there were hits.)

### Returned fields

`SearchBuilder::with()` returns columns of the source row ([Searching](searching.md#returning-source-fields-with)); the rules are the ones of the search:

- A `returnable` column that is also a `granted` index field (the field has that name, or reads that column: `field('pay', column: 'salary', granted: ...)` gates `salary`) is returned only to an actor who holds it. For anyone else it is **not there**:
  `with('salary')` fails with the same `InvalidQuery` as a name that does not exist (the message lists only the columns this actor may ask for, so it
  does not reveal that the column exists), and `with()` with no argument leaves it out. An **alias** is gated by the column it reads, by its own name
  and, in a multi-source index, by the column of every source: it cannot reach a granted column under another name, and `with()` accepts only the
  returnable names, never a column that is merely read by an alias. A returnable **many field** is gated by the field of that name (granted: the same
  rule), and its child table is read with the search role (`SELECT` on it is part of `--dump-grants`). A tenant-scoped index repeats the tenant
  condition on every source SELECT of the one statement. An actor without a guard answer (anonymous, a command) holds
  no granted field.
- `restrictedToFields()` narrows the returned granted columns like it narrows the search.
- The ids come from the search, which is already tenant-scoped; a tenant-scoped index repeats its tenant condition on the source query too, so a row
  that moved to another tenant since the last sync returns no data.
- A returnable column that is not a `granted` field is returned to every viewer who may search the index. Do not list a column in `returnable` that
  some viewers must not see unless it is also a `granted` field (by name or by column).
- `with()` reads the **current** row, not the indexed one: between a change of the row and the next sync, a hit can return the current values (for a row that
  has since left the result set, say by an application flag). Only the tenant condition is checked again, so do not list a column in `returnable` whose
  value must never be seen once the row stops matching.
- The statement is read with the search role, which then needs `SELECT` on the source tables ([roles](roles.md)).

### `restrictedToFields()` in your own code

The same restriction is available to application code, with no guard:

```php
$fuzzphony->in('staff')->restrictedToFields('name', 'title')->query('ada')->get();
```

It searches, ranks and highlights only those fields, and it can only **narrow**: the fields searched are those of the list that the actor
may also see. Naming a `granted` field the actor does not hold adds nothing, and a list with none of the allowed fields finds nothing (no
exception). An unknown field throws `InvalidQuery` listing the known ones; a second call narrows the list further, never widens it.

### `fuzzphony:search` and the guards

`fuzzphony:search` is an operator tool: it needs database access anyway, so it runs no guard, with or
without `--tenant` and `--user` (a console has no Security token, so an authorization guard could only
deny it). The bundle gives the command its own `Fuzzphony` over a gate without guards; every other
service keeps the guarded one. Its searches are counted in the metrics but not written to the
[search log](analytics.md) unless you pass `--log` (then with the `--user` you gave). See [Console commands](commands.md).

## Database roles

The guards above decide who may search. The database user decides what the code can do at all: [Database roles and grants](roles.md) has the
least-privilege matrix for the application (reads the index, no DDL), the worker and the admin, `fuzzphony:schema --dump-grants` to print the
`GRANT` statements for your tables, the `fuzzphony.connections` setting to use a separate user for each, and the doctor's privilege check. Every Fuzzphony
function is `SECURITY INVOKER`: none runs with its owner's rights.

`INSERT` on `fuzzphony_watch_log` is a trust boundary: the application role that writes the watched tables has it by design, so it
can also write bogus log entries. Unusable keys are skipped and counted (the doctor reports them), forged TRUNCATE entries queue at most one
full rebuild per index, and none of it can read or change a search result; see [Sync](sync.md#one-trigger-per-table-one-change-log).

The worker role is trusted for **all** indexes: its `UPDATE (cur_xid, bad_rows, bad_at)` on `fuzzphony_watches` can move another index's position in the log or reset the
`bad_rows` counter, and its `DELETE` on the log can lose changes. `bad_rows` is advisory, not a security record ([roles](roles.md#the-worker-role-is-trusted-for-every-index)).

## The limits, stated honestly

The guarantee is about the content of the hidden fields. It is not row-level security, and it is not
a covert-channel proof:

- **Row-level (document) authorization is out of scope.** A viewer who may search an index may find
  every document of it (within the tenant). For per-document rules use a filter, and
  [tenant scoping](multi-tenancy.md).
- **A hidden field's existence is visible.** `salary:x` gives no "Unknown field" warning for a viewer
  who may not see `salary`, while a field that does not exist does warn. A highlight request for it is
  accepted and shows nothing.
- **Timing is not hidden.** The text index still visits the candidates of a hidden match before the
  per-field recheck drops them, so how long a search takes can depend on how often a word occurs in a
  hidden field. For a viewer who holds none of an index's granted fields the open-class indexes (see
  [limitations](limitations.md#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field)) do not hold the
  hidden text at all, so for that viewer the time no longer follows a hidden word; for a viewer who holds some of the granted
  fields, and for a field the viewer can see, it still follows the word. Which statement runs, and its timing, depend on how much hidden text there is (the size of `fz` / `tsv` includes it); the
  results never do. A restricted viewer of a tenant with no match for a word that is common in another tenant waits longer: the time
  reveals something about the other tenants' data volume, not about content.
- **Filters and facets are not restricted.** Their values are attributes of the document, searchable
  and countable by anyone who may search the index.
- **The vocabulary of unrestricted viewers still holds every word.** An unrestricted viewer of an
  index with a hidden field still gets suggestions, "did you mean" and the zero-result rescue built from the words of
  all its fields, which is why a restricted viewer gets none.
- **What a hidden field holds, or how long it is, never changes whether a restricted viewer's phrase or field-scoped word
  matches, nor which candidates it gets.** The lookup uses the words alone (the positions of the whole-text vector are capped at
  16 383) and the allowed fields' own columns decide; the candidates are the best by the text rank and bonuses of the allowed fields, then
  the id, and the work budget of the typo branch counts the size of the visible text only. The one exception is what lies beyond the
  budgets (more than 100 000 strict matches, or the typo branch's work budget): there a very common word takes the first matches of the
  table's physical order, which a hidden field's size shapes; it reveals nothing about what the field holds. A long hidden text
  still costs its recheck time ([limitations](limitations.md#ranking-of-very-common-words)). Tests compare corpora
  that differ only in a hidden field (empty, a phrase, many sizes, a very long field), in five layouts and all fuzzy modes.
- **An unrestricted viewer's and a tenant's typo candidates are the first matches of the table.** Nothing is hidden from an
  unrestricted viewer, so the physical order of the table shows it nothing it could not read. A tenant's candidates are chosen among
  its own documents (the tenant condition is part of the statement), so the size of another tenant's rows does not change them: a
  test compares a tenant's hits, totals and facets over corpora whose other tenant takes four different sizes.
- **The candidates of a very common word are chosen by the fields the viewer may see only.** When a word matches more
  documents than `limits.candidates`, the best candidates are selected by the page's own sort key; for a restricted
  viewer that key is the text rank of the allowed fields' vectors and the exact and prefix bonuses only when the primary
  field is allowed, with the same lookup, recheck, tenant and filter conditions as the cheap selection, so neither which
  documents are candidates nor their order depends on a hidden field (the tests compare corpora that differ only in a
  hidden field, even a very long one, for the three modes). The selection scores at most 100 000 matches that pass
  the recheck; the scan that finds them can still be long when the word is common in a hidden typo-tolerant field (see the
  timing entry above and [limitations](limitations.md#a-restricted-viewers-very-common-word-in-a-hidden-typo-tolerant-field)).
- **A phrase is read from the fields the viewer may see, as if the others did not exist.** Like an
  unrestricted search, a restricted one matches `"ada lovelace"` with `ada` at the end of one allowed field and
  `lovelace` at the start of the next. A hidden field never takes part: a phrase that reaches into one does not
  match, and what a hidden field holds (or lacks) never changes what the viewer finds. The one visible
  difference to an unrestricted search: with a hidden field *between* two allowed ones (index order `name`,
  `notes` hidden, `title`), a phrase made of the last word of `name` and the first of `title` matches for the restricted
  viewer even when `notes` has text, where the unrestricted search sees that text in between and does not match.
- **`explain()` of a restricted viewer returns the statements and a note instead of the plan.** A
  plan's row estimates and `EXPLAIN ANALYZE` counts (for example "Rows Removed by Filter") would leak
  how often a word occurs in the hidden fields. The statements themselves name no hidden column.
- **An index upgraded to layout 2 without a full reindex** has empty per-field columns, so a
  restricted viewer finds nothing: it fails closed. Reindex after upgrading (see
  [UPGRADE.md](../UPGRADE.md#from-04-to-05)).

## Refusals in metrics, logs and the search log

Every refusal is recorded, and none is silent:

- **Metrics** (see [Observability](observability.md)): `fuzzphony.search.denied` with the labels
  `index` and `reason` (`actor_required`: no tenant on a tenant-scoped index; `authorization`: the
  index is not granted), and `fuzzphony.search.rate_limited` with the label `index`.
- **A log line** on the `fuzzphony` channel at warning level ("Fuzzphony refused a search: ..."),
  with the index and the message.
- **The search log** ([Search log and analytics](analytics.md)), when it is enabled: a row with the
  status `denied` or `rate_limited`, with the text that was typed (redacted like any other), the
  tenant and the user. It is written **always, also for an unlogged search** (`unlogged()` or
  `log: false`): a refusal is never silent. A refused `complete()` is counted and logged, but writes no
  row (there is no text).

## A worked example

The demo's **Security** page (`/under-the-hood/security`, under "Under the hood"; see
[demo/README.md](../demo/README.md)) runs exactly this on a small `intranet` index of notes from two
companies, searched as one of five fixed viewers chosen on the page:

- **Anonymous**: no tenant, so the search is refused with `ActorRequired` (403) before the
  authorization runs.
- **Ada** (Acme) and **Bob** (Globex): the tenant scope; `budget` finds a different set of notes for
  each of them.
- **Hana** (Acme, `ROLE_HR`): may search the `confidential` field.
- **Eve** (Acme, a guest without `ROLE_USER`): not granted the index, so `AccessDenied` (403).

A `Voter` for `FUZZPHONY_SEARCH` and a field only HR may see:

```php
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class SearchVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'FUZZPHONY_SEARCH' && is_string($subject);   // the subject is the index name
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return in_array('ROLE_USER', $token->getRoleNames(), true);        // any signed-in user may search
    }
}
```

```yaml
fuzzphony:
  actor: { tenant: company, user: userIdentifier }
  authorization: { enabled: true }                 # FUZZPHONY_SEARCH, decided by the voter above
  rate_limit: { limiter: search, per: user }
  indexes:
    intranet:
      tenant: company_id
      fields:
        title: { weight: A, fuzzy: true }
        body: B
        confidential: { weight: C, fuzzy: true, granted: ROLE_HR }   # RoleVoter decides this one
```

Ada searches `salary` and finds nothing (the word is only in `confidential`) and her `explain()` has no
column of that field; Hana finds the notes and sees the highlight. The sixth search within 10 seconds shows
the `RateLimited` card.

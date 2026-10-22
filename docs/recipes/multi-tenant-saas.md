# Recipe: search in a multi-tenant SaaS

Support tickets of many customers live in one table, and every user of the application must find their own
customer's tickets and no one else's. On top of that: some fields only the support lead may search, a limit on how
often a user may search, a log of what was searched (with the user, a masked address and no e-mail addresses in
it), and reports of it per customer. Back to the [documentation](../README.md).

The code is real: it lives in [`tests/Recipes/MultiTenant`](../../tests/Recipes/MultiTenant), in a Symfony kernel
that handles real requests against PostgreSQL. Every marked block is copied from it and a test fails if they
differ. Where the test kernel differs from your application it says so: the repository does not install
`symfony/security-bundle`, so the test defines by hand the three services the bundle gives the guards (the token
storage, the authorization checker with the voters). They are the same classes the bundle wires.

## Setup

```
composer require fuzzphony/fuzzphony symfony/security-bundle symfony/property-access symfony/rate-limiter
```

`symfony/property-access` and `symfony/security-bundle` are for the `actor:` paths, the rate limiter for
`rate_limit:`, and the Security bundle also for `authorization:`. Each is checked when the container is built, with
the `composer require` line to run. In the Symfony config two things matter, and the second one is easy to miss:
the limiter, and *where it keeps its state*.

<!-- tested: tests/Recipes/MultiTenant/config/fuzzphony.yaml#framework -->
```yaml
framework:
  cache:
    pools:
      cache.search_limiter:
        adapter: cache.adapter.apcu     # not the default filesystem pool; Redis (and a Redis lock_factory) when several servers share one limit
  rate_limiter:
    search:
      policy: sliding_window
      limit: 5
      interval: '1 minute'
      cache_pool: cache.search_limiter
```

The limiter keeps its state in the pool of its entry, `cache.rate_limiter` unless you say otherwise, and that is
a filesystem cache: every search would lock and write a file. Point `cache_pool` at a faster pool as above. APCu
is per server; with several servers behind a load balancer use Redis for the pool and for the limiter's
`lock_factory`, or each server counts alone. (The test swaps the APCu adapter for a filesystem one of its own,
because APCu is rarely on in the command line and an in-memory pool is emptied between two requests; it checks that
the state lands in the pool named in the entry.) The limit of 5 is for the test: tune it.

<!-- tested: tests/Recipes/MultiTenant/config/fuzzphony.yaml#fuzzphony -->
```yaml
fuzzphony:
  actor:
    tenant: account.id                  # property paths on the Security user
    user: userIdentifier
  authorization: { enabled: true }      # FUZZPHONY_SEARCH, decided by SearchVoter; fields by `granted`
  rate_limit: { limiter: search, per: user }
  search_log:
    enabled: true
    retention_days: 30
    redact: [email]
    ip: masked
  indexes:
    tickets:
      source: { table: recipe_ticket }
      tenant: account_id
      language: english
      fields:
        subject: { weight: A, fuzzy: true }
        body: B
        internal_note: { weight: C, fuzzy: true, granted: ROLE_SUPPORT_LEAD }
      filters:
        account_id: int
        status: string
```

`tenant: account_id` makes the index tenant-scoped: a search without a tenant throws before any SQL runs. If you
add `tenant:` to an index that already exists, run `fuzzphony:schema --apply` and `fuzzphony:reindex`; until then
tenant searches find nothing for the rows indexed before (it fails closed).
`fuzzphony:schema --apply` also creates the search log table.

Two pieces of your own code: the Security user, which the `actor:` paths read (`account.id` is `$user->account->id`,
`userIdentifier` is `getUserIdentifier()`), and a voter for the index attribute.

<!-- tested: tests/Recipes/MultiTenant/AppUser.php#user -->
```php
/** The Security user of the application: it knows the account (the tenant) it belongs to (`account.id` in the config). */
final class AppUser implements UserInterface
{
    /**
     * @param non-empty-string $username
     * @param list<string>     $roles
     */
    public function __construct(
        public readonly Account $account,
        private readonly string $username,
        private readonly array $roles = ['ROLE_USER'],
    ) {}

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}
```

<!-- tested: tests/Recipes/MultiTenant/SearchVoter.php#voter -->
```php
/**
 * fuzzphony.authorization asks isGranted('FUZZPHONY_SEARCH', <index name>) before every search made as an actor.
 * This voter grants it to every signed-in user, for every index. A voter that supports only some indexes abstains on
 * the others, and an abstention is a refusal: with the Live Component or the API Platform filter, every index they
 * search needs the attribute granted.
 *
 * @extends Voter<string, string>
 */
final class SearchVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'FUZZPHONY_SEARCH' && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array('ROLE_USER', $token->getRoleNames(), true);
    }
}
```

With the default attribute and no voter every search is denied (it fails closed), and so is a search with no
Security token. Name an existing attribute (`index_attribute: ROLE_USER`) to need no voter. `ROLE_SUPPORT_LEAD` in
`granted:` is decided by Symfony's own role voter. `granted` needs neither a schema change nor a reindex.

## The search: the tenant comes from the token

<!-- tested: tests/Recipes/MultiTenant/TicketSearchController.php#controller -->
```php
final class TicketSearchController
{
    public function __construct(private readonly Fuzzphony $fuzzphony, private readonly ActorResolver $actors) {}

    #[Route('/tickets/search')]
    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->fuzzphony->in('tickets')
            ->asActor($this->actors->resolve())          // the tenant and the user come from the Security token, never from the request
            ->query(trim((string) $request->query->get('q', '')))
            ->highlight('subject')
            ->limit(20)
            ->get();

        // an ActorRequired or AccessDenied is answered with 403, a RateLimited with 429 and Retry-After, by the bundle's listener
        return new JsonResponse([
            'total' => $result->total,
            'hits' => array_map(static fn(Hit $hit): array => ['id' => $hit->id, 'subject' => $hit->highlights['subject'] ?? null], $result->hits),
        ]);
    }
}
```

The core has no ambient actor: in your own code you resolve it and pass it. The API Platform filter and the Live
Component do that for you with the same resolver, on every request ([Multi-tenancy](../multi-tenancy.md#integrations)).

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#isolation -->
```php
$ada = new AppUser(new Account(1), 'ada');   // tenant 1
$bob = new AppUser(new Account(2), 'bob');   // tenant 2

[$status, $ids] = $this->get($ada, 'invoice');
self::assertSame([200, [1, 3]], [$status, $ids]);   // her tenant's tickets only
[$status, $ids] = $this->get($bob, 'invoice');
self::assertSame([200, [4]], [$status, $ids]);      // the same word, another tenant, other rows
```

The test also sends `?tenant=2&account_id=2` with ada's request: nothing happens, because nothing reads them.

## Refusals

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#refusals -->
```php
$eve = new AppUser(new Account(1), 'eve', ['ROLE_GUEST']);   // signed in, but not ROLE_USER: the voter says no

[$status] = $this->get(null, 'invoice');
self::assertSame(403, $status);   // nobody is logged in: no tenant, ActorRequired
[$status] = $this->get($eve, 'invoice');
self::assertSame(403, $status);   // AccessDenied
```

The order is fixed: the tenant check, then authorization, then the rate limit, so a refused search never spends a
token ([Security](../security.md#the-guards-and-their-order)). Each refusal is a `denied` row in the search log,
a metric (`fuzzphony.search.denied`) and a warning in the log. In your own code, without Symfony's listener, catch
`Fuzzphony\Core\Exception\AccessDenied` and `RateLimited` (`$retryAfter`). A search on a tenant-scoped index with no
actor at all throws `InvalidQuery`; with an actor that has no tenant, its subclass `ActorRequired` (the tests check
both, the second one with no token, which shows the tenant check comes first).

## The field only the support lead may search

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#field -->
```php
$hana = new AppUser(new Account(1), 'hana', ['ROLE_USER', 'ROLE_SUPPORT_LEAD']);

[, $ids] = $this->get($hana, 'refund');
self::assertSame([1], $ids);    // "refund" is only in internal_note of ticket 1, and she may search that field
[, $ids] = $this->get(self::ada(), 'refund');
self::assertSame([], $ids);     // ada may not: the word finds nothing, ranks nothing and highlights nothing
```

## The rate limit

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#rate-limit -->
```php
$ada = new AppUser(new Account(1), 'ada');
$bob = new AppUser(new Account(2), 'bob');
$eve = new AppUser(new Account(1), 'eve', ['ROLE_GUEST']);

for ($i = 0; $i < 5; ++$i) {
    self::assertSame(200, $this->get($ada, 'invoice')[0]);   // the limit is 5 a minute
}
[$status, , $response] = $this->get($ada, 'invoice');
self::assertSame(429, $status);
self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));

self::assertSame(200, $this->get($bob, 'invoice')[0]);       // per: user, so bob has a bucket of his own

for ($i = 0; $i < 8; ++$i) {
    self::assertSame(403, $this->get($eve, 'invoice')[0]);   // denied, never 429: authorization runs first and spends no token
}
```

One token per search; `per: tenant` (the default) shares one bucket per customer instead. A search with no key
(no actor, or `per: user` without a user) is not limited. This recipe has no anonymous visitors (authorization refuses
them); a public search box would use [`per: ip`](../security.md#limiting-anonymous-visitors-by-ip) instead, which limits
by client address. The Live Component spends one token per render, one per pause in typing.
[What is and is not limited](../security.md#the-rate-limit).

## The search log

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#log -->
```php
$rows = $this->logRows();
self::assertSame(
    [
        ['1', 'ada', '203.0.113.0', 'invoice for [redacted]', 'ok'],     // the e-mail address never reached the table
        ['2', 'bob', '2001:db8:abcd::', 'invoice', 'ok'],                // the last octet is zeroed; an IPv6 address is cut to its first 48 bits
    ],
    array_map(static fn(array $row): array => [$row['tenant'], $row['actor_user'], $row['actor_ip'], $row['query'], $row['status']], $rows),
);
```

- **It is written after the response**, in one batch (the test checks that nothing is in the table before
  `kernel.terminate`). It is for trends, not an audit trail: a write that fails is counted and dropped
  ([Limitations](../limitations.md#the-search-log-is-best-effort)).
- **Redaction** removes what a pattern describes (`email` is a preset; add regular expressions for card or social
  security numbers) before anything is stored. Names and free text are not found by it.
- **`ip: masked`** is the recommended mode: not anonymous, and weaker for IPv6, where a /48 is often one customer's
  site. Behind a proxy set `framework.trusted_proxies`, or every row has the proxy's address. The address needs the
  nullable `actor_ip` column: `fuzzphony:schema --apply` adds it ([The client IP](../analytics.md#the-client-ip)).
- **Retention.** Nothing runs the purge for you; put it in cron (`15 3 * * * bin/console fuzzphony:search-log:purge`).
  It deletes whole rows, the user and the address with them:

<!-- tested: tests/Recipes/MultiTenant/expected/purge.txt -->
```
[OK] Deleted 1 search log rows older than 30 days.
```

## Reports: per customer, and who searched what

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#reports -->
```php
// what tenant 1 searched for: its own rows only
$top = $analytics->topQueries('tickets', $since, tenant: 1);
self::assertSame(['invoice' => 2, 'refund' => 1], array_column(array_map(static fn($s): array => [$s->query, $s->searches], $top), 1, 0));

// who searched what, newest first: the rows of one tenant, however the users are named
$recent = $analytics->recentSearches('tickets', $since, tenant: 1);
self::assertSame(
    [['hana', '198.51.100.0', 'refund'], ['ada', '203.0.113.0', 'invoice'], ['ada', '203.0.113.0', 'invoice']],
    array_map(static fn($s): array => [$s->user, $s->ip, $s->query], $recent),
);
self::assertSame([], $analytics->recentSearches('tickets', $since, tenant: 2, user: 'ada'));   // ada has no rows in tenant 2
self::assertSame(RecordStatus::Ok, $recent[0]->status);

// a report across tenants is a decision, not a default
try {
    $analytics->recentSearches('tickets', $since);
    self::fail('A tenant-scoped index needs a tenant or allTenants: true.');
} catch (InvalidArgument) {
}
self::assertCount(4, $analytics->recentSearches('tickets', $since, allTenants: true));   // the operator's view
```

The same on the console, `bin/console fuzzphony:analytics tickets --recent --tenant=1 --since=1h` (add `--user=ada`,
`--anonymous`, `--status=denied`, `--format=json`):

<!-- tested: tests/Recipes/MultiTenant/expected/recent.txt -->
```
---------------------------- ------ -------------- -------- ------ --------- 
 time                         user   ip             status   hits   query    
---------------------------- ------ -------------- -------- ------ --------- 
 2026-10-12 09:14:51 +00:00   eve    198.51.100.0   denied   0      mouse    
 2026-10-12 09:14:51 +00:00   ada    203.0.113.0    ok       2      invoice  
---------------------------- ------ -------------- -------- ------ --------- 
```

(The command needs `--tenant` or `--all-tenants` on this index and refuses without.) Treat these reports as the
personal data they are ([Privacy](../analytics.md#privacy-the-log-is-personal-data)): `recentSearches()` shows who
searched what. Show it to an operator role, never to a tenant's own users without a tenant filter that you
control, and never pass a tenant or user from request input without checking the requester may see it. The
reader needs `SELECT` on the table; the role that only searches needs `INSERT`. Erasing a person is a `DELETE`
on `actor_user`.

## What is not hidden

The guarantee is about the content of the hidden fields and about tenants, and it is made by the library's API,
not by the database ([Security](../security.md#the-limits-stated-honestly)):

<!-- tested: tests/Recipes/MultiTenant/MultiTenantTest.php#not-hidden -->
```php
// the field exists: a hidden field gives no "Unknown field" warning, a field that does not exist does
$hidden = $this->fuzzphony()->in('tickets')->asActor($actor)->query('internal_note:refund')->get();
$unknown = $this->fuzzphony()->in('tickets')->asActor($actor)->query('nosuchfield:refund')->get();
self::assertSame([], $hidden->warnings);
self::assertNotSame([], $unknown->warnings);

// filters and facets are not restricted: she counts the statuses of her tenant's tickets, whatever the fields say
$counted = $this->fuzzphony()->in('tickets')->asActor($actor)->facets('status')->get();
self::assertSame(['open' => 2, 'closed' => 1], array_column(array_map(static fn($f): array => [$f->value, $f->count], $counted->facets['status']), 1, 0));

// a tenant-scoped index never suggests a spelling or a completion: its vocabulary is every tenant's words
self::assertNull($this->fuzzphony()->in('tickets')->asActor($actor)->query('invoise')->get()->didYouMean);
self::assertSame([], $this->fuzzphony()->suggest('tickets', 'invo', actor: $actor));
```

- **No row-level authorization.** Whoever may search the index finds every ticket of the tenant. For rules per
  ticket use a filter.
- **Timing is not hidden**: how long a search takes can depend on how often a word occurs in a hidden field.
  `explain()` of a restricted viewer returns no plan, because the plan would leak the same.
- **Filters and facets are not restricted**, as above, and a phrase across two allowed fields does not match for a
  restricted viewer.
- **The tenant guarantee is application-layer.** Raw SQL on the sidecar table bypasses it: add a PostgreSQL
  row-level security policy for defence in depth ([Multi-tenancy](../multi-tenancy.md#what-it-guarantees)).
- **The log is personal data**, masked address or not, and searches are logged for the tenant's own users too.
  Say so in your privacy notice.

## Pitfalls

- **A voter that supports only some indexes** denies the others (an abstention is a refusal): grant the attribute
  for every index, or name `ROLE_USER` as the attribute.
- **Do not take the tenant or the user from the request.** The actor comes from the token.
- **No `symfony/lock` in the test kernel**, so the limiter runs unlocked there; in your application a search takes
  the limiter's lock (`lock_factory`) while it reads and writes the state.
- **`fuzzphony:search` runs no guard** (a console has no token): it takes `--tenant` and `--user`, and its searches
  are logged with that user.
- **The doctor checks the log:** `fuzzphony:doctor` reports an error if the table, or the `actor_ip` column when `ip`
  is on, is missing.

## Reference

[Multi-tenancy](../multi-tenancy.md), [Security](../security.md), [Search log and analytics](../analytics.md),
[Index definitions](../configuration.md#bundle-configuration), [Console commands](../commands.md).

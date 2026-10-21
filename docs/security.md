# Security: guards, rate limits and field authorization

Who may search, how often, and which fields of an index they may search and see. Back to the
[README](../README.md).

Everything here is opt-in. With nothing configured a search is what 0.8 did: the same results, and
the same statements except the probe and facet change listed in the [CHANGELOG](../CHANGELOG.md)
(the candidate probes carry the search's tenant and filters; a disjunctive facet with different
conditions uses the trigram condition). A search made without an actor never runs a guard, and a
viewer the guards let see every field takes the same fast path as before (a test compares the
statements). The [demo](#a-worked-example) has a Security page that shows all of it running.

## The actor

A guard decides about an *actor*: the tenant and the user who is searching, described once as a
`Fuzzphony\Core\Search\Actor` and passed with `asActor()`. Where the actor comes from, and how the
API Platform filter and the Live Component resolve it on the server on every request, is in
[Multi-tenancy](multi-tenancy.md#the-actor-who-is-searching) and
[the bundle configuration](configuration.md#the-actor). In your own code you resolve it and pass it
yourself:

```php
$fuzzphony->in('notes')->asActor($resolver->resolve())->query('salary')->get();
```

The core has no ambient actor, so a search you do not make `asActor()` is not guarded: the guards run
for a search with an actor, in `get()`, in `explain()` and in `suggest()` (with an actor), and, for a
[federated search](searching.md#federated-search), for each index it searches.

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
3. **The rate limit** (when `fuzzphony.rate_limit.limiter` is set). It comes last on purpose: a search
   that is denied never spends a token.

A refusal is shown by the HTTP integrations as **403** (`ActorRequired`, `AccessDenied`) or **429**
with a `Retry-After` header (`RateLimited`), as the original exception's `previous`. (`fuzzphony:search`
runs no guard, so the only refusal it can show is `ActorRequired`, as its error message.) Your own controllers catch the exceptions
(`Fuzzphony\Core\Exception\AccessDenied`, `RateLimited` with `$retryAfter` in seconds; all are
`FuzzphonyException`s) or let the bundle's listener map them.

Without Symfony, pass your own guards to the gate:

```php
use Fuzzphony\Core\Search\SearchGate;

$fuzzphony = new Fuzzphony($engine, $registry, new SearchGate(guards: [new MyGuard()]));
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
    per: user           # tenant (default) or user: whose searches share one bucket
```

It needs `symfony/rate-limiter` (a `suggest` dependency: without it the container fails to build with
the `composer require` line). `limiter` must be the name of a `framework.rate_limiter` entry; an unknown
name fails the container build with Symfony's own "non-existent service limiter.<name>" message. A
`compound` limiter works too. The key is the actor's tenant or user, as configured. When it is
exceeded the search throws `RateLimited` (429 with `Retry-After`).

The limiter's state lives in the cache pool of the `framework.rate_limiter` entry (`cache.rate_limiter` unless you set
`cache_pool`), and a search takes its lock (`lock_factory`) while it reads and writes it. With Symfony's default pools these are
filesystem based, so every search touches a file. On a busy site point the entry at a faster pool, for example an APCu
or Redis one (`cache_pool: cache.apcu`, or a Redis pool of your own; APCu is per server, so use Redis, with a Redis-backed
`lock_factory`, when several servers must share one limit). See Symfony's
[rate limiter documentation](https://symfony.com/doc/current/rate_limiter.html).

What is and is not limited:

- **A search without an actor, or with an actor that has no key** (`per: user` and an actor without a
  user) is not limited. An IP limit is Symfony's own business (a limiter of its own in front of the
  controller).
- **One token per search.** `explain()` runs the search, so it **charges** a token too.
  `Fuzzphony::suggest()` (the completions) charges one token per call, unless you pass `charge: false`
  (only when the same request already charged a search). The Live Component does: a render is a
  search plus its completions, and the search charges, so a render costs one token, and the Live
  Component debounces its requests, so that is one per pause in typing. `useSuggestion` ("did you
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
- **not suggested**: a restricted viewer gets no "did you mean" and `suggest()` returns `[]`, because
  the vocabulary holds the words of every field;
- **not counted**: `total`, the facet counts and the exact counts cover the documents the viewer
  could match through their allowed fields only. An excluded word (`lamp -plain`) never excludes a
  document because of a hidden field.

The same holds when every field is hidden: such a viewer finds nothing (browsing without text still
works). Filters and facets are **not** restricted in 0.9: authorization is about text fields, and a
filter is a document attribute (see the limits below).

A highlight name is validated upfront, for everyone, before a hidden field is dropped: asking to
highlight an unknown or non-highlightable field is an error whatever the viewer may see, and even when
the search finds nothing. (Before 0.9 the error appeared only when there were hits.)

### `restrictedToFields()` in your own code

The same restriction is available to application code, with no guard:

```php
$fuzzphony->in('staff')->restrictedToFields('name', 'title')->query('ada')->get();
```

It searches, ranks and highlights only those fields. An unknown field throws `InvalidQuery` listing the
known ones; a second call can only narrow the list. A guard's `Access` and `restrictedToFields()` are
intersected.

### `fuzzphony:search` and the guards

`fuzzphony:search` is an operator tool: it needs database access anyway, so it runs no guard, with or
without `--tenant` and `--user` (a console has no Security token, so an authorization guard could only
deny it). The bundle gives the command its own `Fuzzphony` over a gate without guards; every other
service keeps the guarded one. Its searches are still counted in the metrics and, with the
[search log](analytics.md) on, logged with the `--user` you gave. See [Console commands](commands.md).

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
  hidden field.
- **Filters and facets are not restricted.** Their values are attributes of the document, searchable
  and countable by anyone who may search the index.
- **The vocabulary of unrestricted viewers still holds every word.** An unrestricted viewer of an
  index with a hidden field still gets suggestions and "did you mean" built from the words of all its
  fields, which is why a restricted viewer gets none.
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
  `log: false`): a refusal is never silent. A refused `suggest()` is counted and logged, but writes no
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

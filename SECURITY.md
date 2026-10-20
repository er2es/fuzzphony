# Security policy

## Supported versions

Fuzzphony is pre-1.0. Security fixes are released for the latest minor version only.

| Version | Supported |
|---|---|
| 0.9.x | yes |
| < 0.9 | no |

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through
[GitHub's private vulnerability reporting](https://github.com/er2es/fuzzphony/security/advisories/new)
with a description, the affected version and, if you can, a minimal reproduction.

You can expect an acknowledgement within a week. Once a fix is available it is released as a
patch version and published as a GitHub security advisory, crediting you unless you prefer
otherwise.

## Scope and trust model

- **Search input is untrusted.** Search text, filter values and tenant values are parsed and
  bound as parameters. Anything that lets search input change the generated SQL is a
  vulnerability.
- **Index definitions are trusted developer input.** The source query given to `fromQuery()`,
  the SQL given to `watch()`, and index, field and filter names come from your code or
  configuration and are embedded in generated SQL and trigger functions. Never build them from
  user input.
- **Output you render.** Highlights (`Hit::$highlights`) are HTML-escaped with `<mark>` tags.
  Warnings (`SearchResult::$warnings`) and `SearchResult::$interpretedAs` are plain text that may
  contain the user's own words: escape them when you render them as HTML.
- **The vocabulary of "did you mean"** is the words of the whole index (see
  [Did you mean](docs/searching.md#did-you-mean)), so a suggestion does not respect a search's filters.
  A tenant-scoped index never suggests anything; for other visibility filters, set `did_you_mean: false`
  where the existence of a word is itself secret.
- **Cost.** Search input is bounded by the `max_query_length`, `max_terms` and `candidate_limit`
  thresholds. Also set a PostgreSQL `statement_timeout` for the application's database role.
- **Authorization is part of the trust model since 0.9.** A field a viewer may not see (`granted`) is not matched, ranked,
  highlighted, counted or suggested from, and a tenant-scoped index needs a tenant: a way around either is a vulnerability. What
  it does not hide is documented (a hidden field's existence, timing, row-level access, filters and facets): see
  [Security](docs/security.md#the-limits-stated-honestly).
- **The search log** (opt-in) stores what visitors typed. Redact it (`search_log.redact`), set a retention and keep the table's
  privileges as narrow as the rest of the schema: see [Search log and analytics](docs/analytics.md).
  - **A per-user search history is personal data.** With an actor the log records the user of every search, and
    `SearchAnalytics::recentSearches()` / `fuzzphony:analytics --recent` read it back person by person (including the
    refused searches). What a person searched for can be sensitive: redaction removes only what a pattern can describe,
    the retention (`search_log.retention_days`, the purge command) is how long it is kept, and the report is for an
    operator role, never for a page that a tenant or a visitor can steer to another person. The report refuses a
    tenant-scoped index without a tenant (or an explicit `allTenants: true`) and, when a tenant is named, never returns another
    tenant's rows, even for a user of the same name. On an index that is **not** tenant-scoped a call without a tenant covers
    everyone (as `topQueries()` does, but these rows are per person), so never let one reach a tenant; the user, the tenant and the limit are bound parameters. To erase a person,
    delete their rows (the purge deletes whole rows).
  - **The client IP is personal data too.** `search_log.ip` is `off` by default (nothing is stored, even when the actor carries
    an address). `masked` (IPv4 `/24`, IPv6 `/48`; the mode the documentation recommends) or `full` store it in `actor_ip`;
    an invalid address is never stored, and `full` stores the canonical form. Masking is weaker for IPv6: a `/48` usually identifies one
    customer site, so a masked IPv6 address is less private than a masked IPv4 address (a 6to4 address `2002::/16` has the last octet
    of its embedded IPv4 address masked too). The address is `Request::getClientIp()`, which follows `framework.trusted_proxies`:
    configure it behind a proxy, and know that Fuzzphony never reads `X-Forwarded-For` itself, so a visitor cannot invent an
    address by sending the header. A per-user history with an address says where a person searched from: keep both short and
    narrow.
- **The demo** (`demo/`) is a local showcase, not a production template. It exposes the SQL and
  query plans of every search on purpose.

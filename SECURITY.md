# Security policy

## Supported versions

Fuzzphony is pre-1.0. Security fixes are released for the latest minor version only.

| Version | Supported |
|---|---|
| 0.3.x | yes |
| < 0.3 | no |

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
- **Cost.** Search input is bounded by the `max_query_length`, `max_terms` and `candidate_limit`
  thresholds. Also set a PostgreSQL `statement_timeout` for the application's database role.
- **The demo** (`demo/`) is a local showcase, not a production template. It exposes the SQL and
  query plans of every search on purpose.

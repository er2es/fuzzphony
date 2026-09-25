# Contributing

Thanks for helping! A few rules keep Fuzzphony predictable:

1. **User input never throws, developer mistakes always do.** Search text degrades with warnings;
   bad definitions / filters fail fast with a message that says how to fix them.
2. **No SQL built from user input.** Values are bound; identifiers come from validated definitions.
3. **Every engine passes `tests/Conformance`.** New behaviour starts as a conformance test.
4. **Every doctor check has a fix.** If something can be wrong in production, the doctor should say so.
5. Record non-obvious design decisions as an ADR in `docs/adr`.

## Running the checks

The integration tests need PostgreSQL 15+ with `pg_trgm` and `unaccent`; the root
`docker-compose.yml` starts one on port 5432.

```bash
docker compose up -d
composer install
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" composer test:all
composer qa      # php-cs-fixer (PER-CS 2.0), PHPStan (level max + strict rules), unit tests
```

Without `FUZZPHONY_TEST_DSN` the integration tests are skipped. CI additionally runs the suite
against PHP 8.4 / 8.5, PostgreSQL 15 to 18, Symfony 7.4 / 8.0 and the lowest allowed dependencies.

## Pull requests

- One topic per pull request, with tests. Fix php-cs-fixer findings with `composer cs:fix`.
- Cover the code you add: the goal is 100% line coverage of `src/`. Codecov reports the coverage
  of each pull request's changes; CI fails below 90% in total.
- Add a line to the `## [Unreleased]` section of [CHANGELOG.md](CHANGELOG.md) under Added,
  Changed, Fixed or Breaking.
- Commit messages are short imperative sentences that say why ("Fix the fuzzy branch ignoring
  nested negations"), with detail in the body when it helps.

## Backward compatibility

Until 1.0, a minor version (0.x) may contain breaking changes. Each one is listed under
**Breaking** in the CHANGELOG and explained in [UPGRADE.md](UPGRADE.md); patch versions never
break. From 1.0 on the project follows Semantic Versioning: classes and methods marked
`@internal` are not covered, and anything removed is deprecated for at least one minor version
first.

## Security

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

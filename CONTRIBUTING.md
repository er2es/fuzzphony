# Contributing

Thanks for helping! A few rules keep Fuzzphony predictable:

1. **User input never throws, developer mistakes always do.** Search text degrades with warnings;
   bad definitions / filters fail fast with a message that says how to fix them.
2. **No SQL built from user input.** Values are bound; identifiers come from validated definitions.
3. **Every engine passes `tests/Conformance`.** New behaviour starts as a conformance test.
4. **Every doctor check has a fix.** If something can be wrong in production, the doctor should say so.
5. Record non-obvious design decisions as an ADR in `docs/adr`.
6. **One Composer package.** The repository is the single package `fuzzphony/fuzzphony`; `src/Core`, `src/Engine/Postgres`, `src/Bridge/Doctrine` and `src/Bundle` are layers of it ([architecture](docs/architecture.md)), not packages: add dependencies and `suggest` entries to the root `composer.json` only.

## Running the checks

The integration tests need PostgreSQL 15+ with `pg_trgm` and `unaccent`; the root
`docker-compose.yml` starts one on port 5432, seeded with a database named `fuzzphony_test`.

```bash
docker compose up -d          # FUZZPHONY_TEST_PORT=55432 docker compose up -d if a local PostgreSQL holds 5432
composer install
composer test:db              # the whole suite against that database (FUZZPHONY_TEST_PORT=... for another port)
composer test:db -- --exclude-group slow   # the quick run: skips the slow sweep tests (the CI runs them on one leg)
composer test:parallel        # the quick run on 4 processes (paratest, each with its own database {dbname}_t1 ..., so the role needs CREATEDB)
composer qa                   # php-cs-fixer (PER-CS 2.0), PHPStan (level max + strict rules), unit tests
```

`composer test` and `composer qa` run the unit tests only. Without `FUZZPHONY_TEST_DSN` (which `composer test:db` builds for you
from `FUZZPHONY_TEST_PORT`, default 5432) the integration tests are skipped, and a skipped test looks green: read the summary
for "skipped". The whole suite takes a few minutes; the tests in `#[Group('slow')]` (the independence sweeps over thousands of
rows) are most of that, and one CI leg runs them with coverage. CI additionally runs the suite
against PHP 8.4 / 8.5, PostgreSQL 15 to 18, Symfony 7.4 LTS / 8.1 (and 8.2 in development, as an early warning) and the lowest allowed dependencies.

The integration tests drop and recreate tables, so `PostgresTestCase::dsn()` refuses to run
against a database whose name doesn't contain "test" (a case-insensitive substring check, so
`fuzzphony_test`, `test_fuzzphony` and even `contest` are all accepted; a DSN without `dbname=`
is refused too) -- a hard test failure, not a skip, telling you the database name it saw. This is
to stop `FUZZPHONY_TEST_DSN` from ever being pointed at a real database by mistake.

### Mutation testing

`composer mutation` runs [Infection](https://infection.github.io) with pcov coverage against
`src/`; it needs the same `FUZZPHONY_TEST_DSN` as the tests. The Mutation Score Indicator (MSI) is
the share of mutants a passing test suite actually kills, not just covers — a low MSI next to 100%
line coverage means the tests run the code but don't assert on it. It runs multi-threaded
(`--threads=max`): the integration tests share one database, so each Infection worker
(`TEST_TOKEN=1, 2, ...`) gets its own `{dbname}_t{TEST_TOKEN}` database, created on first use
(`tests/Integration/PostgresTestCase::dsn()`) — without `TEST_TOKEN` (a normal `composer test`
run) nothing changes. Without pcov in your local PHP (the Windows builds have none), run it in a `php:8.4-cli` container
with `pdo_pgsql` and `pcov` installed, next to the test database. There is no CI gate on the MSI yet (the full run in CI scores 96% covered-code MSI, and the README badge shows it); see
[docs/roadmap.md](docs/roadmap.md#mutation-testing).

## Pull requests

- `main` is protected: every change, the maintainer's too, lands through a pull request once the
  `ci-ok` check (all CI jobs) is green.
- One topic per pull request, with tests. Fix php-cs-fixer findings with `composer cs:fix`.
- Cover the code you add: the goal is 100% line coverage of `src/`. Codecov reports the coverage
  of each pull request's changes; CI fails below 90% in total.
- Make the tests catch bugs, not just run the lines: assert results, boundaries and callback
  arguments. The `mutation / diff` check mutates the lines a pull request changes; fix the
  mutants it reports as escaped in the same pull request.
- Add a line to the `## [Unreleased]` section of [CHANGELOG.md](CHANGELOG.md) under Added,
  Changed, Fixed or Breaking.
- Documentation links are checked in CI (the `docs-links` job: relative links, `#anchors` and absolute links to this
  repository in every `*.md` file; links to other sites are not). Run `php tools/check-docs-links.php` before you push
  when you rename or move a file, a page or a heading.
- Every ```php block in the README and `docs/` must parse (`DocSnippetsSyntaxTest`); a block that is a piece on purpose (an
  attribute, a method chain) has `<!-- fragment -->` on the line before it. Code that must also run is marked `<!-- tested: ... -->`
  (see `tests/Recipes`).
- Commit messages are short imperative sentences that say why ("Fix the fuzzy branch ignoring
  nested negations"), with detail in the body when it helps.

## Backward compatibility

Fuzzphony follows [Semantic Versioning](https://semver.org/) from 1.0. Before 1.0 a release could break the API; the CHANGELOG lists those changes:

- **Covered** (the public API): the classes listed in [docs/architecture.md](docs/architecture.md#public-api) (and in
  `tests/Unit/PublicApiTest.php`) with their public methods, properties and constants not tagged `@internal`; the bundle's
  configuration keys; the console command names and their options.
- **Not covered**: everything tagged `@internal` (every other class), protected/private members, exception message texts, the
  generated SQL and database object names (`fuzzphony:schema` keeps them up to date), and command output meant for humans.
- **Patch** releases fix bugs only. **Minor** releases add features, deprecate, and may raise the minimum PHP, PostgreSQL or
  Symfony version. **Major** releases remove what was deprecated.
- **Deprecation**: the old way keeps working for at least one minor release; it gets `#[\Deprecated]` (a config key: the
  Symfony Config `setDeprecated()`; a command option: a warning in its output) naming the replacement, a **Deprecated** CHANGELOG entry and an
  UPGRADE.md step. It is removed only in the next major release.
- A new class is `@internal` unless it is added to that list (and to `tests/Unit/PublicApiTest.php`).

## Releasing

For the maintainer. `main` is protected, so a release is a pull request first.

1. Move `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) to `## [x.y.z] - date`, add a fresh empty `[Unreleased]`, and update the
   compare links at the bottom; check that [UPGRADE.md](UPGRADE.md) has the step from the previous version.
2. Open the pull request, wait for `ci-ok`, merge it.
3. Tag the merge commit (`git tag -s vX.Y.Z`, `git push origin vX.Y.Z`) and run `gh release create vX.Y.Z` with the CHANGELOG
   section as the notes.
4. Packagist picks the tag up through its GitHub hook; check that the new version appears there.

## Security

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

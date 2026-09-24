# Contributing

Thanks for helping! A few rules keep Fuzzphony predictable:

1. **User input never throws, developer mistakes always do.** Search text degrades with warnings;
   bad definitions / filters fail fast with a message that says how to fix them.
2. **No SQL built from user input.** Values are bound; identifiers come from validated definitions.
3. **Every engine passes `tests/Conformance`.** New behaviour starts as a conformance test.
4. **Every doctor check has a fix.** If something can be wrong in production, the doctor should say so.
5. Record non-obvious design decisions as an ADR in `docs/adr`.

```bash
docker compose up -d
composer install
FUZZPHONY_TEST_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" composer test:all
composer qa
```

Commit messages follow Conventional Commits (`feat:`, `fix:`, `docs:` ...).

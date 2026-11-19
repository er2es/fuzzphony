# Upgrading

1.0 is the baseline: install with `bin/console fuzzphony:schema --apply`, then `bin/console fuzzphony:reindex`, and check with
`bin/console fuzzphony:doctor`. The [CHANGELOG](CHANGELOG.md) lists the changes per release. Restart long-running processes (workers,
RoadRunner, FrankenPHP) after an apply: the engine looks some catalog facts up once per process.

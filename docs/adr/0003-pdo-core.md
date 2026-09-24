# 3. Core depends on a tiny Connection port, not on Doctrine

Status: accepted

## Decision
`fuzzphony/core` defines `Database\Connection` (fetchAll, fetchValue, execute, transactional) with a
PDO implementation. Doctrine DBAL is an adapter in `fuzzphony/doctrine-bridge`.

## Consequences
+ Usable in any PHP project (Laravel, Slim, plain PHP) without pulling Doctrine.
+ In Symfony it shares the application's DBAL connection and transactions.
− Parameters are named and never reused within a statement (native prepares); builders use a
  `ParameterBag` to guarantee this.

# 3. Core depends on a tiny Connection port, not on Doctrine

Status: accepted

## Decision
The core layer (`Fuzzphony\Core`) defines `Database\Connection` (fetchAll, fetchValue, execute, transactional) with a
PDO implementation. Doctrine DBAL is an adapter in the Doctrine bridge layer (`Fuzzphony\Bridge\Doctrine`).

> Note (0.9.4): this ADR was written when these layers were planned as separate packages (`fuzzphony/core`,
> `fuzzphony/doctrine-bridge`). They are layers of the single package `fuzzphony/fuzzphony`; the decision is unchanged.

## Consequences
+ Usable in any PHP project (Laravel, Slim, plain PHP) without pulling Doctrine.
+ In Symfony it shares the application's DBAL connection and transactions.
− Parameters are named and never reused within a statement (native prepares); builders use a
  `ParameterBag` to guarantee this.

# Database roles and grants

Which database user may do what. Back to the [README](../README.md).

Fuzzphony works with **one database user** out of the box: the user of the Symfony `connection` (or the `Connection` you give the plain-PHP
facade) searches, runs the worker, applies the schema and reindexes. That is the simplest setup, and it is a good one for a small site. For a
production database the usual advice is the opposite: the application should be able to read and not much more, and DDL belongs to the user who deploys.
Fuzzphony supports that with **three roles** and prints the exact `GRANT` statements for your tables.

| Role | Does | Privileges (summary) |
|---|---|---|
| **search** (the application) | searches, "did you mean", writes the watched tables and the search log | read the index tables; no DDL; writes no index table |
| **worker** | `fuzzphony:worker`, the async ORM refresh | reads the source tables, writes the index tables, queue and watch bookkeeping; no DDL |
| **admin** | `fuzzphony:schema`, `fuzzphony:reindex`, `fuzzphony:doctor`, the search log commands | DDL, owns the objects, `TRIGGER` on the watched tables |

## The matrix

`S` = `SELECT`, `I` = `INSERT`, `U` = `UPDATE`, `D` = `DELETE`. A dash is no privilege at all. "Sidecar" is `fuzzphony_<index>`; the schema is the
`schema` setting (default `public`). Every row is proved by a test that creates real roles, runs the role's operations with exactly the statements
`--dump-grants` prints, and checks that everything else is refused (`tests/Integration/RoleGrantsTest.php`).

| Object | search | worker | admin | Why |
|---|---|---|---|---|
| Fuzzphony's schema | `USAGE` | `USAGE` | `USAGE`, `CREATE` | names are resolved in it; the admin creates, swaps and drops tables there |
| the extension schema (`extension_schema`) | `USAGE` | `USAGE` | `USAGE` | the operators and functions of `pg_trgm` and `unaccent` |
| the database | `CONNECT` | `CONNECT` | `CONNECT`; `CREATE` when `schema` is not `public` | `CREATE SCHEMA IF NOT EXISTS` is checked against the database even when the schema exists |
| sidecar table | S | S I U D | owner | search reads; the refresh writes |
| vocabulary table (fuzzy indexes) | S | S I U D | owner | "did you mean" reads it |
| values table (indexes with a `many` field) | S | S I D | owner | the matched value and the highlight read it; the refresh rewrites a document's rows (`sync: trigger`: the role that writes the child table needs I D too) |
| child tables of the `many` fields | - | S | S, `TRIGGER` | they are watched tables: the worker reads them, the admin creates their trigger |
| `fuzzphony_queue` | - | S I U D | owner | the drain fills it, the batch takes from it and records failures |
| `fuzzphony_watch_log` | I | S **U** D | owner | the shared trigger appends as the role that writes a watched table; the worker's `DELETE ... FOR UPDATE SKIP LOCKED` needs `UPDATE` too |
| `fuzzphony_watches` | - | S, U on `cur_xid`, `bad_rows`, `bad_at` only | owner | each index's position in the log; the worker cannot rewrite which table an index watches (the admin trusts those names) |
| `fuzzphony_meta` | - | S U | owner | the worker records a failed rebuild; the reindex records its run |
| `fuzzphony_search_log` (when enabled) | I | - | owner | searches write it; the analytics and purge commands run as the admin |
| the source tables of an index (every source's table and every watched table of a multi-source index) | (the application's own rights) | S | S, `TRIGGER` | the worker runs the watch queries and rebuilds; the admin reindexes and creates the triggers |
| the source tables of an index with `returnable` columns | S, only for such an index | S | S, `TRIGGER` | `SearchBuilder::with()` reads the returned columns from the source query as the role that searches |
| `EXECUTE` on `fuzzphony_norm`, `fuzzphony_refresh_<index>`, `fuzzphony_drain_<index>` | norm | all three | owner | functions are `PUBLIC` by default; granted explicitly so a `REVOKE ... FROM PUBLIC` does not break them |

Notes that follow from the code:

- **Every Fuzzphony function is `SECURITY INVOKER`**, including the drain function ([sync](sync.md#one-trigger-per-table-one-change-log)) and the shared trigger
  functions. Nothing runs with its owner's rights: a role needs the privileges on the objects a function touches, and none gets more through it.
  There is no `SECURITY DEFINER` anywhere.
- **Identity columns need no sequence privilege.** The search log's `id` and the other generated columns are `GENERATED ... AS IDENTITY`; inserting
  needs `INSERT` on the table only, which is why no sequence appears above.
- **The role that writes a watched table is the application.** With `sync: queue` (statement level, the default) it needs `INSERT` on
  `fuzzphony_watch_log`; with `trigger_level: row` it needs `INSERT, UPDATE` on `fuzzphony_queue`; with `sync: trigger` the refresh runs in its statement,
  so it needs the sidecar write and the source `SELECT` too. `--dump-grants` adds exactly these to the search role for the indexes that use them.
- **A refresh in the request** (`orm_sync` without `async`, a call of `refresh()`) writes the sidecar as the application user. Give that user the
  worker's grants as well (`--role=worker --to=<the application user>`), or use `orm_sync: { async: true }` so the worker connection does it.
- **No DDL for the worker.** A full rebuild a `TRUNCATE` queued then runs in place (the worker cannot build next to the live table, which needs `CREATE`
  on the schema and ownership); see [sync](sync.md#reindexing-and-orphan-pruning).
- **Ownership cannot be granted.** The admin must own the objects (or be a member of their owner): run `fuzzphony:schema --apply` as the admin role and
  it owns what it creates; for objects that exist already `--dump-grants --role=admin` prints the `ALTER TABLE ... OWNER TO` lines as comments.
- **`CREATE EXTENSION`** needs a superuser, or, from PostgreSQL 13, `CREATE` on the database for the trusted `pg_trgm` and `unaccent`. If the extensions
  exist, `CREATE EXTENSION IF NOT EXISTS` needs nothing.
- **Returned fields.** An index that declares `returnable` columns ([Searching](searching.md#returning-source-fields-with)) makes the search role read the source: `--dump-grants --role=search`
  then prints `SELECT` on the source tables (the table of a `table` source and the watched tables), and only for such indexes. A table the query reads without watching it is yours to grant.
- **A custom query source** (`source: { query: ... }`, or each of `sources`) lists its tables in `watch`; the grants cover the watched tables and the table of a `table` source. A table the query reads
  without watching it is yours to grant `SELECT` on.

## `fuzzphony:schema --dump-grants`

```bash
bin/console fuzzphony:schema --dump-grants                          # all three roles, for all indexes
bin/console fuzzphony:schema products --dump-grants --role=worker   # one index, one role
bin/console fuzzphony:schema --dump-grants --role=search --to=my_app_user
```

It prints, for the real objects of the selected indexes, the `CREATE ROLE` hint (as a comment) and the `GRANT` statements, each with a comment saying
what it is for. `--role=search|worker|admin` selects one role (default: all three, named `fuzzphony_search`, `fuzzphony_worker`, `fuzzphony_admin`);
`--to=<role>` names your own PostgreSQL role (it needs `--role`). The index is the optional argument like in the other commands. An object that does
not exist yet (the schema is not applied, the search log table is not created) is printed as a comment: apply the schema, then dump again. Run it again
after every `fuzzphony:schema --apply` that adds a table (another index, the search log), because a `GRANT ... ON ALL TABLES` covers only the tables
there were. The output is plain SQL: review it, then pipe it to `psql` as the owner of the objects.

## Separate connections: `fuzzphony.connections`

```yaml
# config/packages/doctrine.yaml: one DBAL connection per database user
doctrine:
  dbal:
    default_connection: app
    connections:
      app:    { url: '%env(DATABASE_URL)%' }          # the search role
      worker: { url: '%env(DATABASE_WORKER_URL)%' }
      admin:  { url: '%env(DATABASE_ADMIN_URL)%' }

# config/packages/fuzzphony.yaml
fuzzphony:
  connection: app
  connections: { worker: worker, admin: admin }       # both optional; each defaults to `connection`
```

| Uses `connection` | Uses `connections.worker` | Uses `connections.admin` |
|---|---|---|
| searches, the facade, `fuzzphony:search`, the wizard, the search log writes, a refresh in the request | `fuzzphony:worker`, the async `RefreshDocuments` handler | `fuzzphony:schema` (apply, drop, `--dump-migration`, `--dump-grants`), `fuzzphony:reindex`, `fuzzphony:doctor`, `fuzzphony:analytics`, `fuzzphony:search-log:purge` |

Without the setting nothing changes: everything runs as the Symfony user. A name that is not a configured DBAL connection fails the container build,
and the message lists the connections there are (checked when DoctrineBundle's configuration lists them). Doctrine Migrations: run
`fuzzphony:schema --dump-migration` migrations as the admin user too.

## The worker role is trusted for every index

The worker's `UPDATE (cur_xid, bad_rows, bad_at)` on `fuzzphony_watches` covers all indexes: a compromised worker role can move another index's position in the
change log forward (changes are never applied) or back (they are applied again), and reset the `bad_rows` counter. `bad_rows` is advisory, a hint for the doctor,
not a security record. The same role can already lose data through `DELETE` on the change log. Give the worker role only to a process you trust with the
whole sync; it cannot change what an index watches, run DDL, or read a hidden field through a search.

## The worker role must see every row

The worker drains the log by asking the source tables (the watch queries and, after a `TRUNCATE`, "is the table empty?") as itself. If a watched table has
**row-level security** that hides rows from the worker role, the worker sees an incomplete table: an apparently empty table makes it empty the index
(the TRUNCATE branch). Give the worker role a policy that shows all rows (or `BYPASSRLS`), as the admin role that reindexes needs too.

## The doctor checks the privileges

`fuzzphony:doctor` has a **Privileges** check per index. It asks the catalog (`has_table_privilege`, `has_schema_privilege`,
`has_function_privilege`, `has_database_privilege`, `pg_has_role` for ownership) whether the roles *in use* hold what the matrix says, and names what is
missing with the fix:

```
 ✘  Privileges  worker "fz_worker" lacks SELECT, UPDATE, DELETE on table "public"."fuzzphony_watch_log"
 Fix for "Privileges": run the output of bin/console fuzzphony:schema products --dump-grants --role=worker --to=fz_worker as the owner of the objects
```

- It checks **search** (the user of `connection`) and **worker** (the user of `connections.worker`, or the same user) always; the worker only for an index
  with `sync: queue`. It checks **admin** only when `connections.admin` is a user of its own: an admin that is the application user is not asked for DDL
  (the demo runs this way and applies the schema elsewhere).
- It checks **sufficiency, not minimality**: a role that holds MORE than the matrix (a worker with DDL, a search role with `UPDATE` on the queue) is
  not reported. The other direction is proved by the negative tests that run each role with exactly the `--dump-grants` statements and expect everything
  else to be refused (`tests/Integration/RoleGrantsTest.php`); review the grants of the roles you created by hand.
- An owner or a superuser is never reported (the catalog answers yes), so an install that runs everything as the owner sees `Privileges: ok`.
- A missing privilege that makes the search or the worker fail is an **error**; the check also shows in the Prometheus format as
  `fuzzphony_doctor_check{check="Privileges"}`.

## The demo: a worked example

The [demo](../demo/README.md) runs this split with two users and one connection setting. `init` connects as the owner (`fuzzphony`), applies the schema,
seeds and reindexes (DDL, ownership, no timeout). PHP-FPM and the worker connect as `fuzzphony_app`: not a superuser, no DDL, read-only on the catalogue,
read/write on the `fuzzphony` schema's tables, `statement_timeout = 5s`. That is "app and worker as one user without DDL, admin as another"; with
`fuzzphony.connections` the same split is a configuration instead of two different `DATABASE_URL`s. See `demo/docker/init.sh` for the statements.

## Without separate users

Everything runs as the Symfony user. It then needs the union of the three columns, which in practice means the owner of the objects: that is what
`fuzzphony:schema --apply` makes it when it runs the apply. Nothing in this page is required for that setup, and the doctor does not complain about it.

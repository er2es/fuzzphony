<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

/** @internal The three database roles of the least-privilege setup (docs/roles.md). */
enum GrantRole: string
{
    /** The application: searches, writes the source tables (so the shared watch log) and the search log; no DDL. */
    case Search = 'search';

    /** The queue worker: drains the watch log, refreshes the sidecar tables; no DDL. */
    case Worker = 'worker';

    /** The operator: fuzzphony:schema, fuzzphony:reindex, the doctor, the search log commands; owns the objects. */
    case Admin = 'admin';
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The least-privilege matrix (docs/roles.md): which privilege on which object each of the three database roles needs
 * for the indexes given. fuzzphony:schema --dump-grants prints it as GRANT statements and fuzzphony:doctor asks the catalog
 * whether the roles hold it. Every row comes from what the code does with the object (a test runs each role's operations with
 * exactly these grants and nothing more); every Fuzzphony function is SECURITY INVOKER, so a role needs the rights on the
 * objects a function touches, never on the function's owner's behalf.
 */
final readonly class Grants
{
    public function __construct(
        private Names $names,
        private bool $searchLog,
    ) {}

    /**
     * @param list<IndexDefinition> $indexes
     *
     * @return list<Grant>
     */
    public function for(GrantRole $role, array $indexes, string $database = ''): array
    {
        $n = $this->names;
        $grants = $database !== '' ? [new Grant(Grant::DATABASE, Sql::ident($database), ['CONNECT'], 'connecting')] : [];
        $grants[] = new Grant(Grant::SCHEMA, $n->quotedSchema(), ['USAGE'], 'Fuzzphony\'s own tables and functions');
        if ($n->extensionSchema !== $n->schema) {
            $grants[] = new Grant(Grant::SCHEMA, $n->extension(), ['USAGE'], 'the operators and functions of pg_trgm and unaccent');
        }

        return [...$grants, ...match ($role) {
            GrantRole::Search => $this->search($indexes),
            GrantRole::Worker => $this->worker($indexes),
            GrantRole::Admin => $this->admin($indexes, $database),
        }];
    }

    /**
     * The Fuzzphony tables the admin role must own (or be a member of the owner of): ALTER, DROP, the swap of a reindex and
     * the triggers need ownership, which no GRANT can give.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return list<string> quoted and qualified table names
     */
    public function owned(array $indexes): array
    {
        $tables = [$this->names->queue(), $this->names->meta(), $this->names->watchLog(), $this->names->watches()];
        foreach ($indexes as $index) {
            $tables[] = $this->names->sidecar($index);
            $index->hasFuzzy() && $tables[] = $this->names->vocabulary($index);
        }
        $this->searchLog && $tables[] = $this->names->searchLog();

        return array_values(array_unique($tables));
    }

    /**
     * SEARCH: reads the sidecar tables and the vocabulary; its writes to the source tables fire the shared trigger, which runs
     * as the writer and appends to the watch log; the search log is written by the search itself.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return list<Grant>
     */
    private function search(array $indexes): array
    {
        $grants = [new Grant(Grant::FUNCTION, $this->names->normFunction() . '(text)', ['EXECUTE'], 'the query normaliser')];
        foreach ($indexes as $index) {
            $grants[] = new Grant(Grant::TABLE, $this->names->sidecar($index), ['SELECT'], sprintf('search "%s"', $index->name));
            $index->hasFuzzy() && $grants[] = new Grant(Grant::TABLE, $this->names->vocabulary($index), ['SELECT'], sprintf('"did you mean" of "%s"', $index->name));
        }
        if (array_any($indexes, SharedWatch::applies(...))) {
            $grants[] = new Grant(Grant::TABLE, $this->names->watchLog(), ['INSERT'], 'the shared trigger of a watched table appends here as the role that writes the table');
        }
        foreach ($indexes as $index) {
            if ($index->sync === SyncMode::Queue && $index->triggerLevel === TriggerLevel::Row) {
                $grants[] = new Grant(Grant::TABLE, $this->names->queue(), ['INSERT', 'UPDATE'], sprintf('the row-level triggers of "%s" queue a changed row as the role that writes the table', $index->name));
            }
            if ($index->sync === SyncMode::Trigger) {
                // the refresh runs inside the writing statement, as the writing role: it needs what the worker needs for this index
                $grants[] = new Grant(Grant::TABLE, $this->names->sidecar($index), ['INSERT', 'UPDATE', 'DELETE'], sprintf('sync: trigger refreshes "%s" inside the writing statement', $index->name));
                $grants[] = new Grant(Grant::FUNCTION, sprintf('%s(%s[])', $this->names->refreshFunction($index), Types::id($index->idType)), ['EXECUTE'], sprintf('sync: trigger refreshes "%s"', $index->name));
                foreach ($this->sourceTables($index) as $table) {
                    $grants[] = new Grant(Grant::TABLE, $table, ['SELECT'], sprintf('the refresh of "%s" reads its source', $index->name), true);
                }
            }
        }
        if ($this->searchLog) {
            $grants[] = new Grant(Grant::TABLE, $this->names->searchLog(), ['INSERT'], 'the search log');
        }

        return $grants;
    }

    /**
     * WORKER: drains the watch log into the queue (reading the source tables with the watch queries), refreshes the sidecar
     * tables from the queue and records a failed rebuild; no DDL (a full rebuild then runs in place).
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return list<Grant>
     */
    private function worker(array $indexes): array
    {
        $n = $this->names;
        $grants = [
            new Grant(Grant::FUNCTION, $n->normFunction() . '(text)', ['EXECUTE'], 'the normaliser the refresh uses'),
            new Grant(Grant::TABLE, $n->queue(), ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], 'the queue: drained into, taken from, failures recorded'),
            new Grant(Grant::TABLE, $n->watchLog(), ['SELECT', 'UPDATE', 'DELETE'], 'the shared change log: read, then trimmed (DELETE ... FOR UPDATE needs UPDATE too)'),
            new Grant(Grant::TABLE, $n->watches(), ['SELECT', 'UPDATE'], 'who watches which table, and each index\'s position in the log'),
            new Grant(Grant::TABLE, $n->meta(), ['SELECT', 'UPDATE'], 'the record of a failed full rebuild'),
        ];
        foreach ($indexes as $index) {
            $grants[] = new Grant(Grant::TABLE, $n->sidecar($index), ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], sprintf('refresh "%s"', $index->name));
            $index->hasFuzzy() && $grants[] = new Grant(Grant::TABLE, $n->vocabulary($index), ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], sprintf('the vocabulary of "%s"', $index->name));
            $grants[] = new Grant(Grant::FUNCTION, sprintf('%s(%s[])', $n->refreshFunction($index), Types::id($index->idType)), ['EXECUTE'], sprintf('refresh "%s"', $index->name));
            $grants[] = new Grant(Grant::FUNCTION, $n->drainFunction($index) . '()', ['EXECUTE'], sprintf('drain the watch log into the queue of "%s"', $index->name));
            foreach ($this->sourceTables($index) as $table) {
                $grants[] = new Grant(Grant::TABLE, $table, ['SELECT'], sprintf('the source of "%s" (documents and watch queries)', $index->name), true);
            }
        }

        return $grants;
    }

    /**
     * ADMIN: creates and alters everything (CREATE on the schema; ownership of the objects, see owned()), creates the triggers on
     * the watched tables (TRIGGER) and reads the source tables to reindex.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return list<Grant>
     */
    private function admin(array $indexes, string $database): array
    {
        $grants = [];
        if ($database !== '' && $this->names->schema !== 'public') {
            // CREATE SCHEMA IF NOT EXISTS checks CREATE on the database before it looks whether the schema is there
            $grants[] = new Grant(Grant::DATABASE, Sql::ident($database), ['CREATE'], 'fuzzphony:schema --apply runs CREATE SCHEMA IF NOT EXISTS, which PostgreSQL checks against the database even when the schema exists');
        }
        $grants[] = new Grant(Grant::SCHEMA, $this->names->quotedSchema(), ['CREATE'], 'creating, swapping and dropping the index tables, functions and text search configurations');
        foreach ($indexes as $index) {
            foreach ($this->sourceTables($index) as $table) {
                $grants[] = new Grant(Grant::TABLE, $table, ['SELECT', 'TRIGGER'], sprintf('reindex "%s", and the triggers of its sync', $index->name), true);
            }
        }

        return $grants;
    }

    /** @return list<string> */
    private function sourceTables(IndexDefinition $index): array
    {
        $tables = $index->source->table !== null ? [$index->source->table] : [];
        foreach ($index->effectiveWatches() as $watch) {
            $tables[] = $watch->table;
        }

        return array_values(array_unique(array_map(Sql::ident(...), $tables)));
    }
}

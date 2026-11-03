<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\GrantRole;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The doctor's "Privileges" check (docs/roles.md): for each database role in use (the user of each connection) it asks the
 * catalog (has_table_privilege, has_schema_privilege, has_function_privilege, has_database_privilege, pg_has_role) whether the role
 * holds what Grants says its job needs, and names what is missing with the command that prints the fix. The questions are asked on
 * the probe connection (the doctor's own, which can see the objects) about the role of the other connection, so a role without
 * USAGE on the schema is reported instead of silently skipped; a superuser or an owner is never reported (the catalog says yes).
 */
final readonly class PrivilegeCheck
{
    /** @param array<string, Connection> $subjects the role (search, worker or admin) => the connection that does its job */
    public function __construct(
        private Connection $probe,
        private Grants $grants,
        private array $subjects,
    ) {}

    public function check(IndexDefinition $index): Check
    {
        $database = Coerce::str($this->probe->fetchValue('SELECT current_database()'));
        $missing = [];
        $fixes = [];
        $verified = [];
        foreach ($this->subjects as $kind => $connection) {
            $role = GrantRole::tryFrom($kind);
            if ($role === null || ($role === GrantRole::Worker && $index->sync !== SyncMode::Queue)) {
                continue;
            }
            $user = Coerce::str($connection->fetchValue('SELECT current_user'));
            $verified[] = sprintf('%s: %s', $kind, $user);
            $lacks = $this->lacks($role, $user, $index, $database);
            if ($lacks === []) {
                continue;
            }
            $missing[] = sprintf('%s "%s" lacks %s', $kind, $user, implode('; ', $lacks));
            $fixes[] = sprintf('bin/console fuzzphony:schema %s --dump-grants --role=%s --to=%s', $index->name, $kind, $user);
        }
        if ($missing === []) {
            return Check::ok('Privileges', $verified === [] ? 'nothing to check' : 'hold what they need (' . implode(', ', $verified) . ')');
        }

        return Check::error('Privileges', implode('. ', $missing) . '.', 'run the output of ' . implode(' and of ', $fixes) . ' as the owner of the objects (docs/roles.md)');
    }

    /** @return list<string> */
    private function lacks(GrantRole $role, string $user, IndexDefinition $index, string $database): array
    {
        $literal = Sql::string($user);
        $lacks = [];
        foreach ($this->grants->for($role, [$index], $database) as $grant) {
            $absent = array_values(array_filter($grant->privileges, fn(string $privilege): bool => $this->probe->fetchValue($grant->missing($privilege, $literal)) === true));
            if ($absent !== []) {
                $lacks[] = sprintf('%s on %s %s', implode(', ', $absent), strtolower($grant->kind), $grant->object);
            }
        }
        if ($role === GrantRole::Admin) {
            foreach ($this->grants->owned([$index]) as $table) {
                // NOT: a false answer reads back as null (Connection::fetchValue()), which a table that is not there does too
                if ($this->probe->fetchValue(sprintf('SELECT NOT pg_has_role(%s, c.relowner, \'MEMBER\') FROM pg_class AS c WHERE c.oid = to_regclass(%s)', $literal, Sql::string($table))) === true) {
                    $lacks[] = sprintf('ownership of %s', $table);
                }
            }
        }

        return $lacks;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Engine\Postgres\Sql\Sql;

/** @internal One privilege set on one object: what a role needs, as a GRANT statement and as a has_*_privilege question. */
final readonly class Grant
{
    public const string TABLE = 'TABLE';
    public const string SCHEMA = 'SCHEMA';
    public const string FUNCTION = 'FUNCTION';
    public const string DATABASE = 'DATABASE';

    /**
     * @param string       $object     the object, quoted and qualified for SQL ("public"."fuzzphony_queue"; a function with its argument types)
     * @param list<string> $privileges
     * @param string       $why        the operation that needs it, for the comment above the statement
     * @param list<string> $columns     when not empty, the privileges apply to these columns only (a column privilege: UPDATE (cur_xid))
     * @param bool         $optional   an object that exists only on some installs (a source table the application owns: it may live elsewhere)
     */
    public function __construct(
        public string $kind,
        public string $object,
        public array $privileges,
        public string $why,
        public bool $optional = false,
        public array $columns = [],
    ) {}

    public function sql(string $role): string
    {
        $list = $this->columns === [] ? implode(', ', $this->privileges) : implode(', ', array_map(fn(string $p): string => sprintf('%s (%s)', $p, implode(', ', array_map(Sql::ident(...), $this->columns))), $this->privileges));

        return sprintf('GRANT %s ON %s %s TO %s;', $list, $this->kind, $this->object, Sql::ident($role));
    }

    /** The SQL that says whether the object exists (true or false). */
    public function exists(): string
    {
        $object = Sql::string($this->object);

        return match ($this->kind) {
            self::SCHEMA => sprintf('SELECT to_regnamespace(%s) IS NOT NULL', $object),
            self::FUNCTION => sprintf('SELECT to_regprocedure(%s) IS NOT NULL', $object),
            self::DATABASE => 'SELECT true',
            default => sprintf('SELECT to_regclass(%s) IS NOT NULL', $object),
        };
    }

    /** The SQL that says whether `$role` (a SQL string literal) holds the privilege, or null when the object is not there. */
    public function missing(string $privilege, string $roleLiteral): string
    {
        $object = Sql::string($this->object);

        return match ($this->kind) {
            self::SCHEMA => sprintf('SELECT NOT has_schema_privilege(%s, to_regnamespace(%s)::oid, %s) WHERE to_regnamespace(%2$s) IS NOT NULL', $roleLiteral, $object, Sql::string($privilege)),
            self::DATABASE => sprintf('SELECT NOT has_database_privilege(%s, current_database(), %s)', $roleLiteral, Sql::string($privilege)),
            self::FUNCTION => sprintf('SELECT NOT has_function_privilege(%s, to_regprocedure(%s), %s) WHERE to_regprocedure(%2$s) IS NOT NULL', $roleLiteral, $object, Sql::string($privilege)),
            default => $this->columns !== []
                ? sprintf('SELECT NOT (%s) WHERE to_regclass(%s) IS NOT NULL', implode(' AND ', array_map(fn(string $c): string => sprintf('CASE WHEN EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(%2$s) AND attname = %3$s AND NOT attisdropped) THEN has_column_privilege(%1$s, to_regclass(%2$s), %3$s, %4$s) ELSE false END', $roleLiteral, $object, Sql::string($c), Sql::string($privilege)), $this->columns)), $object)
                : sprintf('SELECT NOT has_table_privilege(%s, to_regclass(%s), %s) WHERE to_regclass(%2$s) IS NOT NULL', $roleLiteral, $object, Sql::string($privilege)),
        };
    }
}

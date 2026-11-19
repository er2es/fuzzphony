<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal DDL that runs only when the catalog says it has something to do. `CREATE INDEX IF NOT EXISTS`,
 * `DROP TRIGGER IF EXISTS` and `CREATE OR REPLACE TRIGGER` lock the table before they look, so a re-apply that changes
 * nothing would still queue behind a long writer (and every later write behind the apply).
 */
final class Guard
{
    private const string TAG = DefinitionValidator::DOLLAR_QUOTE_TAG;

    /** @param string $index the schema-qualified, quoted index name */
    public static function createIndex(string $index, string $create): string
    {
        return sprintf('DO %1$s BEGIN IF to_regclass(%2$s) IS NULL THEN %3$s; END IF; END %1$s', self::TAG, Sql::string($index), $create);
    }

    /** @param string $table the quoted (maybe qualified) table name, resolved like the DROP TRIGGER statement resolves it */
    public static function dropTrigger(string $trigger, string $table): string
    {
        return sprintf(
            'DO %1$s BEGIN IF EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(%2$s) AND tgname = %3$s) THEN DROP TRIGGER %4$s ON %5$s; END IF; END %1$s',
            self::TAG,
            Sql::string($table),
            Sql::string($trigger),
            Sql::ident($trigger),
            $table,
        );
    }

    /**
     * Creates (or replaces) the trigger unless the table has it already with this function, these events and these transition
     * tables. A trigger's name settles the rest of its definition (the timing, the level, the events and the transition tables
     * are all spelled in its suffix), so these columns tell a current trigger from one to replace.
     *
     * @param string $table    the quoted (maybe qualified) table name
     * @param string $function the quoted, qualified function name (without parentheses)
     * @param int    $type     pg_trigger.tgtype: 1 row, 4 insert, 8 delete, 16 update, 32 truncate (after, no instead)
     */
    public static function createTrigger(string $trigger, string $table, string $function, int $type, ?string $oldTable, ?string $newTable, string $create): string
    {
        return sprintf(
            'DO %1$s BEGIN IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(%2$s) AND tgname = %3$s AND tgfoid = to_regprocedure(%4$s) AND tgtype = %5$d AND tgenabled = \'O\' AND tgqual IS NULL AND tgattr = \'\'::int2vector AND tgoldtable IS NOT DISTINCT FROM %6$s AND tgnewtable IS NOT DISTINCT FROM %7$s) THEN %8$s; END IF; END %1$s',
            self::TAG,
            Sql::string($table),
            Sql::string($trigger),
            Sql::string($function . '()'),
            $type,
            $oldTable === null ? 'NULL' : Sql::string($oldTable),
            $newTable === null ? 'NULL' : Sql::string($newTable),
            $create,
        );
    }
}

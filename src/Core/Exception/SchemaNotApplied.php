<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/**
 * A column or index the running code needs is not in the database: the schema was not applied after an upgrade.
 * Run "bin/console fuzzphony:schema --apply" (the doctor names what is missing). A \RuntimeException: the state of the
 * database, not a wrong argument.
 */
final class SchemaNotApplied extends \RuntimeException implements FuzzphonyException
{
    /** SQLSTATE of "undefined column". */
    private const string UNDEFINED_COLUMN = '42703';

    /** The columns schema --apply adds to an existing table (a column of a new layout). */
    private const array COLUMNS = ['tsv_capped', 'actor_ip'];

    /**
     * @param list<string> $missing
     */
    public static function missing(array $missing, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('The Fuzzphony schema is not up to date (missing: %s). Run: bin/console fuzzphony:schema --apply', implode(', ', $missing)),
            0,
            $previous,
        );
    }

    /** The exception for a database error that is "undefined column" for one of the columns schema --apply adds, or null for any other failure. */
    public static function from(\Throwable $failure): ?self
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            $state = $cause instanceof \PDOException ? $cause->getCode() : (method_exists($cause, 'getSQLState') ? $cause->getSQLState() : null);
            if ($state !== self::UNDEFINED_COLUMN) {
                continue;
            }
            foreach (self::COLUMNS as $column) {
                if (preg_match('/\b' . $column . '\b/', $cause->getMessage()) === 1) {
                    return self::missing([$column], $failure);
                }
            }
        }

        return null;
    }
}

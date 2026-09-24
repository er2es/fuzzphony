<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal ts_headline() does not escape the source text. We let PostgreSQL mark matches with
 * private-use characters, HTML-escape the whole snippet in PHP, then turn the markers into <mark>.
 * Stored XSS through highlighted snippets is therefore impossible.
 */
final class Highlighter
{
    private const string START = "\u{E000}";
    private const string STOP = "\u{E001}";

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param list<string>     $fields
     * @param list<int|string> $ids
     *
     * @return array<string, array<string, string>> id => field => html
     */
    public function highlight(IndexDefinition $index, array $fields, string $tsquery, array $ids): array
    {
        if ($fields === [] || $ids === []) {
            return [];
        }
        foreach ($fields as $name) {
            $field = $index->field($name);
            if ($field === null || !$field->highlight) {
                throw new InvalidQuery(sprintf('Cannot highlight "%s": it is not a highlightable field of index "%s".', $name, $index->name));
            }
        }

        $params = new ParameterBag();
        $config = Sql::string($index->text->configName()) . '::regconfig';
        $options = sprintf('StartSel="%s", StopSel="%s", MaxWords=30, MinWords=12, MaxFragments=2, FragmentDelimiter=" … "', self::START, self::STOP);

        $columns = ['d.fz_id::text AS id'];
        foreach ($fields as $name) {
            // native prepared statements cannot reuse a placeholder, so each column binds its own copy
            $columns[] = sprintf(
                "ts_headline(%s, coalesce(d.%s::text, ''), to_tsquery(%s, %s), %s) AS %s",
                $config,
                Sql::ident('fld_' . $name),
                $config,
                $params->add($tsquery),
                $params->add($options),
                Sql::ident('h_' . $name),
            );
        }
        $sql = sprintf(
            'SELECT %s FROM (%s) AS d WHERE d.fz_id = ANY(CAST(%s AS %s[]))',
            implode(', ', $columns),
            DocumentSql::select($index),
            $params->add(Sql::arrayLiteral($ids)),
            $index->idType->sqlType(),
        );

        $out = [];
        foreach ($this->connection->fetchAll($sql, $params->all()) as $row) {
            foreach ($fields as $name) {
                $out[Coerce::str($row['id'])][$name] = self::toHtml(Coerce::str($row['h_' . $name]));
            }
        }

        return $out;
    }

    public static function toHtml(string $marked): string
    {
        return str_replace(
            [self::START, self::STOP],
            ['<mark>', '</mark>'],
            htmlspecialchars($marked, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}

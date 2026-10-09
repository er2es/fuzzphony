<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Fuzzphony;

/**
 * The demo's synonyms: one list per index in the demo_synonym table (sql/demo_synonym.sql), in the Solr format
 * (`tv, television` / `laptop => notebook`). An application would keep its own table, or a file, and call
 * Fuzzphony::useSynonyms() the same way.
 */
final readonly class SynonymStore
{
    /** A list is a plain text field; this is far more than any demo list needs. */
    public const int MAX_BYTES = 200_000;

    public function __construct(private Connection $connection) {}

    /** Hands every stored list to Fuzzphony, one query per request. A list that no longer validates is skipped. */
    public function apply(Fuzzphony $fuzzphony): void
    {
        try {
            $rows = $this->connection->fetchAllKeyValue('SELECT index_name, body FROM demo_synonym');
        } catch (DbalException) {
            return; // an older demo database without the table: the pages work, without synonyms
        }
        foreach ($rows as $index => $body) {
            if (!$fuzzphony->registry()->has((string) $index)) {
                continue;
            }
            try {
                $fuzzphony->useSynonyms((string) $index, Synonyms::fromText((string) $body, (string) $index));
            } catch (FuzzphonyException) {
                // skipped: the editor never saves a list that does not validate
            }
        }
    }

    public function text(string $index): string
    {
        try {
            $body = $this->connection->fetchOne('SELECT body FROM demo_synonym WHERE index_name = :i', ['i' => $index]);
        } catch (DbalException) {
            return '';
        }

        return is_string($body) ? $body : '';
    }

    /**
     * Saves a list, unless it is not valid.
     *
     * @return list<string> what is wrong with it; empty when it was saved
     */
    public function save(Fuzzphony $fuzzphony, string $index, string $body): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return [sprintf('The list is longer than %d bytes.', self::MAX_BYTES)];
        }
        try {
            $synonyms = Synonyms::fromText($body, $index);
            $problems = $synonyms->violations();
        } catch (InvalidDefinition $e) {
            $problems = $e->violations;
            $synonyms = new Synonyms();
        }
        if ($problems !== []) {
            return $problems;
        }
        $this->connection->executeStatement(
            'INSERT INTO demo_synonym (index_name, body) VALUES (:i, :b) ON CONFLICT (index_name) DO UPDATE SET body = EXCLUDED.body',
            ['i' => $index, 'b' => $body],
        );
        $fuzzphony->useSynonyms($index, $synonyms);

        return [];
    }
}

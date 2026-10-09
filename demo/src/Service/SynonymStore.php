<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Fuzzphony;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demo's synonyms, kept the way an application with a long list would keep them:
 *
 * - one row per entry in demo_synonym_entry (a line of the Solr format: `tv, television` or `laptop => notebook`),
 *   per index, so one language's list is its own;
 * - a version per index in demo_synonym_version, bumped by every save;
 * - the prepared list (validated, members stemmed by PostgreSQL: Fuzzphony::stemSynonyms()) in a cache, keyed by
 *   index and version. A request reads the versions (one tiny query), takes the lists from the cache and hands
 *   them to Fuzzphony::useSynonyms(); only the first request after a save rebuilds one.
 *
 * Without the cache every request would read, validate and stem the whole list again.
 */
final readonly class SynonymStore
{
    /** The editor takes a plain text field; this is far more than any demo list needs. */
    public const int MAX_BYTES = 200_000;

    /** The same split everywhere: a line is an entry, blank lines and `#` comments are not. */
    private const string SPLIT = "unnest(string_to_array(:body, E'\\n')) WITH ORDINALITY AS l(line, position)";

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'app.synonym_cache')]
        private CacheItemPoolInterface $cache,
    ) {}

    /** Hands every list to Fuzzphony, from the cache when it holds the current version. */
    public function apply(Fuzzphony $fuzzphony): void
    {
        try {
            /** @var array<string, int|string> $versions */
            $versions = $this->connection->fetchAllKeyValue('SELECT index_name, version FROM demo_synonym_version');
        } catch (DbalException) {
            return; // an older demo database without the tables: the pages work, without synonyms
        }
        foreach ($versions as $index => $version) {
            if ($fuzzphony->registry()->has($index)) {
                try {
                    $fuzzphony->useSynonyms($index, $this->prepared($fuzzphony, $index, (int) $version));
                } catch (FuzzphonyException) {
                    // skipped: the editor never saves a list that does not validate
                }
            }
        }
    }

    /** The list as text, for the editor. */
    public function text(string $index): string
    {
        try {
            $lines = $this->connection->fetchFirstColumn('SELECT entry FROM demo_synonym_entry WHERE index_name = :i ORDER BY position', ['i' => $index]);
        } catch (DbalException) {
            return '';
        }

        return $lines === [] ? '' : implode("\n", array_map(static fn(mixed $line): string => (string) $line, $lines)) . "\n";
    }

    /**
     * Saves a list (replacing the index's entries, bumping its version), unless it is not valid.
     *
     * @return list<string> what is wrong with it; empty when it was saved
     */
    public function save(Fuzzphony $fuzzphony, string $index, string $body): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return [sprintf('The list is longer than %d bytes.', self::MAX_BYTES)];
        }
        try {
            $problems = Synonyms::fromText($body, $index)->violations();
        } catch (InvalidDefinition $e) {
            $problems = $e->violations;
        }
        if ($problems !== []) {
            return $problems;
        }
        $this->connection->transactional(function (Connection $c) use ($index, $body): void {
            $c->executeStatement('DELETE FROM demo_synonym_entry WHERE index_name = :i', ['i' => $index]);
            $c->executeStatement(
                'INSERT INTO demo_synonym_entry (index_name, position, entry) SELECT :i, position, btrim(line) FROM ' . self::SPLIT . " WHERE btrim(line) <> '' AND btrim(line) NOT LIKE '#%'",
                ['i' => $index, 'body' => $body],
            );
            $c->executeStatement(
                'INSERT INTO demo_synonym_version (index_name, version) VALUES (:i, 1) ON CONFLICT (index_name) DO UPDATE SET version = demo_synonym_version.version + 1',
                ['i' => $index],
            );
        });
        $this->apply($fuzzphony);

        return [];
    }

    private function prepared(Fuzzphony $fuzzphony, string $index, int $version): Synonyms
    {
        $item = $this->cache->getItem(sprintf('synonyms.%s.%d', $index, $version));
        $cached = $item->isHit() ? $item->get() : null;
        if ($cached instanceof Synonyms) {
            return $cached;
        }
        $synonyms = $fuzzphony->stemSynonyms($index, Synonyms::fromText($this->text($index), $index));
        $this->cache->save($item->set($synonyms));

        return $synonyms;
    }
}

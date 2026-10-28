<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\SynonymExpander;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal What an engine learns about a text search configuration and a sidecar table and keeps for the life of the process:
 * which leaf tsqueries reduce to nothing (stop words), the stems of the words of the synonyms, the expanders built from them, the
 * digests of the synonyms and whether a sidecar has the optional `tsv_capped` column.
 *
 * They hold as long as the text search configuration (or the table) does not change, which a long-lived process cannot know. A
 * long-lived worker keeps them on purpose (the prepared synonym lookup and the stop-word answers would otherwise be paid again on
 * every request), so nothing resets them by itself: reset() is for an application that changes the configuration while its process runs.
 */
final class TextConfigCache
{
    /**
     * Most stems kept per text configuration; the cache starts over beyond it. The size is checked once per expandSynonyms() call,
     * before the call adds the stems of its own words, so a cache can pass this by one call's words (a query is bounded by max_terms,
     * the synonyms' own words by the list), never grow without limit.
     */
    private const int STEM_CACHE = 5_000;
    /** Most leaf tsqueries remembered per text configuration; the cache starts over beyond it. */
    private const int EMPTY_CACHE = 5_000;
    /** How many words are stemmed per round trip when a whole synonym list is prepared (see stemSynonyms()). */
    private const int STEM_BATCH = 5_000;

    /** @var array<string, SynonymExpander> an expander per index definition, built once */
    private array $expanders = [];
    /** @var array<string, array<string, string>> text configuration => lowercase word => stem */
    private array $stems = [];
    /** @var array<string, array<string, bool>> text configuration => leaf tsquery => whether it reduces to nothing (a stop word) */
    private array $emptyAnswers = [];
    /** @var array<string, bool> sidecar table => whether it has the tsv_capped column (see cappedColumn()) */
    private array $cappedColumns = [];
    /** @var \WeakMap<Synonyms, string>|null the content digest of each Synonyms object (immutable), made once; see expandSynonyms() */
    private ?\WeakMap $synonymDigests = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Names $names,
        private readonly EngineGuard $guard,
    ) {}

    /** Forgets everything this cache remembered. */
    public function reset(): void
    {
        $this->expanders = [];
        $this->stems = [];
        $this->emptyAnswers = [];
        $this->cappedColumns = [];
        $this->synonymDigests = null;
    }

    /**
     * Whether the sidecar has the optional tsv_capped column (it comes with fuzzphony:schema --apply; PostgresSchemaGenerator::CAPPED_COLUMN):
     * asked only by a statement that needs it (a phrase or a field-scoped word on an index with several fields), once per table, the answer
     * kept until reset(). A role that may not read the column counts as "no column". Without it those statements read the later fields'
     * vectors for every document tsv does not match: right, and slower.
     *
     * @return \Closure(): bool
     */
    public function cappedColumn(IndexDefinition $index): \Closure
    {
        return function () use ($index): bool {
            $table = $this->names->sidecar($index);

            return $this->cappedColumns[$table] ??= $this->connection->fetchValue(
                "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attname = :column AND NOT attisdropped AND has_column_privilege(attrelid, attname, 'SELECT'))",
                ['table' => $table, 'column' => PostgresSchemaGenerator::CAPPED_COLUMN],
            ) === true;
        };
    }

    /**
     * The leaf tsqueries the index's text configuration reduces to nothing (stop words such as
     * "for"). The strict tsquery drops them inside PostgreSQL; the per-word fuzzy branch must
     * drop them too, or "mouse for gaming" would require a word similar to "for". Asked right
     * before a fuzzy statement is built, in one round trip.
     *
     * @param list<string> $queries
     *
     * @return list<string>
     */
    public function emptyQueries(IndexDefinition $index, array $queries): array
    {
        // Whether a tsquery reduces to nothing depends only on the text search configuration (its dictionaries and stop word
        // lists), which does not change while a process runs: every answer, "empty" and "not empty", is kept per
        // (configuration, tsquery), and PostgreSQL is asked only for the tsqueries it has not answered yet.
        $config = $this->names->regconfig($index->text);
        if (count($this->emptyAnswers[$config] ?? []) > self::EMPTY_CACHE) {
            $this->emptyAnswers[$config] = [];
        }
        $unknown = array_values(array_unique(array_filter($queries, fn(string $query): bool => !isset($this->emptyAnswers[$config][$query]))));
        if ($unknown !== []) {
            $rows = $this->guard->run('search', fn(): array => $this->connection->fetchAll(
                sprintf(
                    'SELECT t.q FROM unnest(CAST(:queries AS text[])) AS t(q) WHERE numnode(to_tsquery(%s, t.q)) = 0',
                    $config,
                ),
                ['queries' => Sql::arrayLiteral($unknown)],
            ), Hints::INDEX);
            $empty = array_flip(array_map(static fn(array $row): string => Coerce::str($row['q']), $rows));
            foreach ($unknown as $query) {
                $this->emptyAnswers[$config][$query] = isset($empty[$query]);
            }
        }

        return array_values(array_filter($queries, fn(string $query): bool => $this->emptyAnswers[$config][$query]));
    }

    public function stemSynonyms(IndexDefinition $index, Synonyms $synonyms): Synonyms
    {
        $config = $this->names->regconfig($index->text);
        $words = array_values(array_unique(SynonymExpander::wordsOf($synonyms)));
        $stems = [];
        // in batches, so a very long list is not one huge parameter
        foreach (array_chunk($words, self::STEM_BATCH) as $batch) {
            $this->stems[$config] = [];
            $this->fetchStems($config, $batch);
            $stems += $this->stems[$config];
        }
        $this->stems[$config] = [];

        return $synonyms->withStems($config, $stems);
    }

    /**
     * The query with the index's synonyms. PostgreSQL stems the words (the index's text configuration,
     * accents folded; a word with a symbol or a hyphen, `c++`, `wi-fi`, is compared as it is, because
     * PostgreSQL reduces `c++` and `c#` to the same lexeme) in one round trip, and only the words this engine has not seen yet: the stems
     * of the synonyms are fetched once, with the first search, and every expander is kept. The query
     * gets at most four times `max_terms` alternatives, so a large synonym group cannot make a
     * statement huge; a word that would go over is left as typed, with a warning.
     *
     * @param list<string> $warnings
     */
    public function expandSynonyms(IndexDefinition $index, Node $root, Thresholds $thresholds, array &$warnings): Node
    {
        $queryWords = SynonymExpander::queryWords($root);
        if ($queryWords === []) {
            return $root;
        }
        $config = $this->names->regconfig($index->text);
        // the key follows the content of the synonyms (an equal list given again shares the expander); the digest of a long list
        // costs about a millisecond, so it is made once per (immutable) Synonyms object, not on every search
        $this->synonymDigests ??= new \WeakMap();
        $digest = $this->synonymDigests[$index->synonyms] ??= md5(serialize($index->synonyms->toEntries()));
        $key = $index->name . '|' . $config . '|' . $digest;
        if (count($this->stems[$config] ?? []) > self::STEM_CACHE) {
            $this->stems[$config] = [];
        }
        // prepared stems (Fuzzphony::stemSynonyms()) spare the round trip for the members
        $prepared = $index->synonyms->stemConfig === $config;
        $memberWords = isset($this->expanders[$key]) || $prepared ? [] : SynonymExpander::wordsOf($index->synonyms);
        $missing = array_values(array_unique(array_filter([...$memberWords, ...$queryWords], fn(string $word): bool => !isset($this->stems[$config][$word]))));
        $this->fetchStems($config, $missing);
        $this->expanders[$key] ??= new SynonymExpander($index->synonyms, $prepared ? $index->synonyms->stems : array_intersect_key($this->stems[$config] ?? [], array_flip($memberWords)));
        $truncated = false;
        $expanded = $this->expanders[$key]->expand($root, array_intersect_key($this->stems[$config] ?? [], array_flip($queryWords)), $thresholds->maxTerms * 4, $truncated);
        if ($truncated) {
            $warnings[] = 'Synonyms were expanded for part of the query only (too many alternatives).';
        }

        return $expanded;
    }

    /** @param list<string> $words lowercase words to stem for $config; the stems land in the per-configuration cache */
    private function fetchStems(string $config, array $words): void
    {
        if ($words === []) {
            return;
        }
        $rows = $this->guard->run('synonyms', fn(): array => $this->connection->fetchAll(
            sprintf("SELECT w, CASE WHEN w ~ '^[[:alnum:]]+\$' THEN coalesce(nullif(array_to_string(tsvector_to_array(to_tsvector(%s, w)), ' '), ''), w) ELSE w END AS s FROM unnest(string_to_array(:words, chr(31))) AS w", $config),
            ['words' => implode(chr(31), $words)],
        ), Hints::INDEX);
        foreach ($rows as $row) {
            $this->stems[$config][Coerce::str($row['w'])] = Coerce::str($row['s']);
        }
    }
}

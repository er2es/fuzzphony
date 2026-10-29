<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\QueryRenderer;
use Fuzzphony\Core\Query\SynonymExpander;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\Transpositions;

/**
 * @internal The vocabulary of an index (the words of its typo-tolerant text with their document counts): building it, completing a
 * prefix from it, and the did-you-mean lookup that corrects a mistyped word with it.
 */
final class VocabularyService
{
    /**
     * The trigram similarity a vocabulary word needs to be a candidate of the did-you-mean lookup (and the rescue). A letter
     * replaced in the middle of a 4-letter word leaves 2 of 8 trigrams in common, exactly 0.25 (0.3 missed 40% of the
     * one-edit typos of 4-letter words); a swap in a 6-letter word leaves 0.27. Lower finds nothing more and reads more.
     */
    private const float SUGGEST_SIMILARITY = 0.25;

    /** @var array<string, true> vocabulary tables this service has seen exist and be readable; only a positive answer is kept (see suggest()) */
    private array $readable = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Names $names,
        private readonly SearchSession $session,
        private readonly EngineGuard $guard,
    ) {}

    /** Forgets which vocabulary tables were found readable, so a table created or dropped since is looked at again. */
    public function reset(): void
    {
        $this->readable = [];
    }

    /**
     * The words of the index's typo-tolerant text and in how many documents each occurs, built from
     * the live index table. One transaction deletes the old words and inserts the new ones, so readers
     * (only "did you mean" reads it) keep the old words until it commits and never wait; it takes no
     * ACCESS EXCLUSIVE lock, needs no TRUNCATE or TEMPORARY privilege (the role needs SELECT, INSERT and
     * DELETE on the table) and may join a caller's transaction. An advisory lock keeps two rebuilds of
     * the same index from running at once.
     */
    public function rebuild(IndexDefinition $index): int
    {
        $vocabulary = $this->names->vocabulary($index);

        return $this->guard->run('vocabulary', function () use ($index, $vocabulary): int {
            $granted = $this->connection->fetchValue(
                "SELECT to_regclass(:a) IS NOT NULL AND has_table_privilege(to_regclass(:b), 'INSERT') AND has_table_privilege(to_regclass(:c), 'DELETE')",
                ['a' => $vocabulary, 'b' => $vocabulary, 'c' => $vocabulary],
            );
            if ($granted !== true) {
                throw new \RuntimeException(sprintf('%s does not exist or this role may not write it.', $vocabulary));
            }

            return $this->connection->transactional(function (Connection $c) use ($index, $vocabulary): int {
                $c->fetchValue('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => $vocabulary]);
                $c->execute(sprintf('DELETE FROM %s', $vocabulary));
                $c->execute(sprintf(
                    "INSERT INTO %s (word, freq) SELECT w, count(*)::integer FROM (SELECT DISTINCT s.id, w FROM %s AS s CROSS JOIN LATERAL unnest(string_to_array(s.fz, ' ')) AS w WHERE char_length(w) >= :min) AS t GROUP BY w",
                    $vocabulary,
                    $this->names->sidecar($index),
                ), ['min' => $index->thresholds->fuzzyMinLength]);

                return Coerce::int($c->fetchValue(sprintf('SELECT count(*) FROM %s', $vocabulary)));
            });
        }, sprintf('Run "bin/console fuzzphony:schema --apply" to create %1$s, and give the reindexing role SELECT, INSERT and DELETE on it (GRANT ... ON ALL TABLES IN SCHEMA covers it once the table exists).', $vocabulary));
    }

    /** @return list<string> */
    public function words(IndexDefinition $index, string $prefix, int $limit): array
    {
        if ($index->tenant !== null || !$index->hasFuzzy()) {
            return []; // the vocabulary has no tenant column: it would leak the words of other tenants
        }
        $vocabulary = $this->names->vocabulary($index);

        return $this->guard->run('suggest', function () use ($vocabulary, $prefix, $limit): array {
            // a vocabulary the schema has not created yet, or one this role may not read, suggests nothing instead of failing
            if (!$this->canRead($vocabulary)) {
                return [];
            }
            // normalised like the words are, then bound as a constant pattern, so the planner can use the prefix index
            $norm = Coerce::str($this->connection->fetchValue(sprintf('SELECT %s(:p)', $this->names->normFunction()), ['p' => $prefix]));
            if ($norm === '') {
                return [];
            }
            $rows = $this->connection->fetchAll(
                sprintf("SELECT word FROM %s WHERE word LIKE :pattern ESCAPE '!' ORDER BY freq DESC, word LIMIT %d", $vocabulary, $limit),
                ['pattern' => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $norm) . '%'],
            );

            return array_values(array_map(static fn(array $row): string => Coerce::str($row['word']), $rows));
        }, Hints::INDEX);
    }

    /**
     * The query with the words it probably meant, for a search that found few hits: a whole word the
     * vocabulary does not have (and that is not a stop word, nor one a synonym expanded) is replaced
     * by the vocabulary word nearest to it. The trigram index picks the ten closest candidates, then
     * the edit distance decides (a trigram ranking alone suggests `most` for `mose`), then how many
     * documents have the word; nothing farther than a third of the word's length away is suggested.
     * The answer holds the statement that looked the words up, for explain().
     *
     * Its `oneEdit` is set, from the same rows, to the query for the zero-result rescue (see SearchRunner): the typed query with every
     * word that has a vocabulary word exactly one edit away replaced by it, and null unless each of those words has exactly one
     * such word among the closest candidates (a tie, `mose` for `mouse` or `moose`, means no rescue) and at least one word has one.
     * Words with no one-edit candidate stay as typed.
     */
    public function suggest(IndexDefinition $index, Node $typed, Node $expanded, Thresholds $thresholds): Suggestion
    {
        // the vocabulary is the words of the whole index: on a tenant-scoped one a suggestion (or its absence) would tell
        // one customer which words another customer's documents have
        if ($index->tenant !== null) {
            return Suggestion::none();
        }
        $skip = SynonymExpander::expandedWords($expanded);
        $words = [];
        foreach (NodeInspector::suggestibleWords($typed) as $word) {
            $lower = mb_strtolower($word);
            // the index's own minimum, whatever this query set: shorter words were never put in the vocabulary;
            // a word with a digit is a code (rtx4090), and the nearest code is not what the user meant
            if (mb_strlen($lower) >= max($thresholds->fuzzyMinLength, $index->thresholds->fuzzyMinLength) && preg_match('/\d/u', $lower) !== 1 && !in_array($lower, $skip, true)) {
                $words[$lower] = true;
            }
        }
        $words = array_slice(array_keys($words), 0, $thresholds->maxTerms);
        if ($words === []) {
            return Suggestion::none();
        }
        $vocabulary = $this->names->vocabulary($index);
        // A table the schema has not created yet, or one this role may not read (an upgrade before "fuzzphony:schema --apply"
        // and the GRANT), is no reason to fail a search, and a failed statement would abort a transaction the caller may have open.
        // A positive answer is kept for the life of the service (reset() forgets it), a negative one never, so the check is one
        // round trip per process, not per search. It is not trusted when the caller may have a transaction open: a lookup that fails
        // there (the table dropped or the privilege revoked since) would abort it, so that case asks every time, as it always did.
        if (($this->session->callerMayHoldTransaction() && !$this->session->ownsTransaction()) || !isset($this->readable[$vocabulary])) {
            if (!$this->canRead($vocabulary)) {
                return Suggestion::none();
            }
            $this->readable[$vocabulary] = true;
        }
        // known: a stop word, a word of the vocabulary, or any word the index finds (a word of a field that is not typo-tolerant,
        // an inflection, a word added since the last full reindex): the search above found what it could
        // The candidates of a word: the ten closest vocabulary words by trigram similarity and, whatever their similarity, the vocabulary
        // words it becomes with two neighbouring letters swapped (a swap in a short word leaves few trigrams: `mosue` shares two of ten with `mouse`)
        $sql = sprintf(
            "SELECT q.w, q.n, (to_tsvector(%2\$s, q.w) = ''::tsvector OR EXISTS (SELECT 1 FROM %1\$s AS v WHERE v.word = q.n) OR EXISTS (SELECT 1 FROM %5\$s AS s WHERE s.tsv @@ plainto_tsquery(%2\$s, q.w))) AS known, c.word, c.freq FROM (SELECT t.w, %3\$s(t.w) AS n, t.v FROM unnest(string_to_array(:words, chr(31)), string_to_array(:variants, chr(29))) AS t(w, v)) AS q LEFT JOIN LATERAL ((SELECT v.word, v.freq FROM %1\$s AS v WHERE v.word OPERATOR(%4\$s.%%) q.n ORDER BY %4\$s.similarity(v.word, q.n) DESC, v.freq DESC LIMIT 10) UNION (SELECT v.word, v.freq FROM %1\$s AS v WHERE v.word = ANY(string_to_array(q.v, chr(30))))) AS c ON true",
            $vocabulary,
            $this->names->regconfig($index->text),
            $this->names->normFunction(),
            $this->names->extension(),
            $this->names->sidecar($index),
        );
        $parameters = [
            'words' => implode(chr(31), $words),
            'variants' => implode(chr(29), array_map(static fn(string $word): string => implode(chr(30), Transpositions::of($word)), $words)),
        ];
        $statement = new Statement(StatementKind::Suggest, 'did you mean', $sql, $parameters);
        try {
            // the candidates are the words with a trigram similarity of SUGGEST_SIMILARITY or more, whatever the session has set
            $rows = $this->guard->run('did_you_mean', fn() => $this->session->fetch($sql, $parameters, self::SUGGEST_SIMILARITY, SearchSession::SIMILARITY), 'Run "bin/console fuzzphony:schema --apply", then "bin/console fuzzphony:reindex --vocabulary".');
        } catch (FuzzphonyException $e) {
            // the table may be gone (or unreadable) since it was last seen: the next search asks again
            unset($this->readable[$vocabulary]);

            throw $e;
        }

        /** @var array<string, array{int, int, string}> $best typed word => [distance, -documents, vocabulary word] */
        $best = [];
        /** @var array<string, array<string, true>> $ones typed word => the vocabulary words one edit away */
        $ones = [];
        foreach ($rows as $row) {
            $word = Coerce::str($row['w']);
            if (in_array($row['known'], [true, 't', 'true', 1, '1'], true)) {
                continue;
            }
            if ($row['word'] === null) {
                continue;
            }
            $normalised = Coerce::str($row['n']);
            $candidate = Coerce::str($row['word']);
            $distance = self::distance($normalised, $candidate);
            if ($distance < 1 || $distance > max(1, intdiv(mb_strlen($normalised), 3))) {
                continue;
            }
            if ($distance === 1) {
                $ones[$word][$candidate] = true;
            }
            $rank = [$distance, -Coerce::int($row['freq']), $candidate];
            if (!isset($best[$word]) || $rank < $best[$word]) {
                $best[$word] = $rank;
            }
        }
        $oneEdit = null;
        if ($ones !== [] && array_all($ones, static fn(array $candidates): bool => count($candidates) === 1)) {
            $oneEdit = QueryRenderer::render($typed, array_map(static fn(array $candidates): string => (string) array_key_first($candidates), $ones));
        }
        $replace = array_map(static fn(array $rank): string => $rank[2], $best);

        return new Suggestion($replace === [] ? null : QueryRenderer::render($typed, $replace), $statement, $oneEdit);
    }

    /**
     * The edit distance of two words by their characters (PHP's levenshtein() counts bytes), counting two neighbouring letters
     * swapped as one edit (optimal string alignment: a typo the search itself finds, `mosue` for `mouse`).
     */
    public static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);
        $previous = [];
        $row = range(0, $m);
        for ($i = 1; $i <= $n; ++$i) {
            $next = [$i];
            for ($j = 1; $j <= $m; ++$j) {
                $next[$j] = min($row[$j] + 1, $next[$j - 1] + 1, $row[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $next[$j] = min($next[$j], $previous[$j - 2] + 1);
                }
            }
            $previous = $row;
            $row = $next;
        }

        return $row[$m];
    }

    /** Whether the table exists and this role may read it. */
    private function canRead(string $vocabulary): bool
    {
        return $this->connection->fetchValue("SELECT to_regclass(:a) IS NOT NULL AND has_table_privilege(to_regclass(:b), 'SELECT')", ['a' => $vocabulary, 'b' => $vocabulary]) === true;
    }
}

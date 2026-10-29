<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal ts_headline() does not escape the source text. We let PostgreSQL mark matches with
 * private-use characters, HTML-escape the whole snippet in PHP, then turn the markers into <mark>.
 * Stored XSS through highlighted snippets is therefore impossible.
 */
final class Highlighter
{
    public const string START = "\u{E000}";
    public const string STOP = "\u{E001}";
    /** A text longer than this is looked at up to here for typo matches (ts_headline reads all of it). */
    private const int MAX_CHARS = 10_000;
    /** The most distinct words (or runs of words) one page asks PostgreSQL to compare. */
    private const int MAX_WORDS = 4_000;
    /** The typo marks are made for the first hits of the page only (a page can hold a thousand): the others keep ts_headline's snippet. */
    private const int MAX_HITS = 200;
    /** The runs of words (see TypoMarker::cost()) the typo marks of one page may go through; a hit that would pass it keeps ts_headline's snippet. */
    private const int MAX_RUNS = 100_000;
    /** A text of at most this many characters (about MaxWords words) is highlighted whole, a longer one as fragments. */
    private const int WHOLE_TEXT_CHARS = 200;
    private const string TYPO = 'fz_typo';
    private const string TEXT = 'x_';

    public function __construct(
        private readonly Connection $connection,
        private readonly Names $names = new Names(),
    ) {}

    /**
     * @param list<string>     $fields
     * @param list<int|string> $ids
     * @param TypoPlan|null    $typo   the typo-tolerant words and the page's ids the strict branch did not find: their fields get
     *                                 the typo marks (see typoMarks()); null: ts_headline's output as it is
     *
     * @return array<string, array<string, string>> id => field => html
     */
    public function highlight(IndexDefinition $index, array $fields, string $tsquery, array $ids, ?TypoPlan $typo = null): array
    {
        if ($fields === [] || $ids === []) {
            return [];
        }
        self::assertHighlightable($index, $fields);

        $params = new ParameterBag();
        $config = $this->names->regconfig($index->text);
        $options = sprintf('StartSel="%s", StopSel="%s", MaxWords=30, MinWords=12, FragmentDelimiter=" … "', self::START, self::STOP);

        $columns = ['d.fz_id::text AS id'];
        foreach ($fields as $name) {
            // A short text is shown whole (no fragments: ts_headline's fragment mode would cut "Ergonomic mouse 1001" to "Ergonomic mouse"),
            // a long one as up to two fragments; a typo-found hit is built the same way (TypoMarker). Native prepared statements cannot reuse
            // a placeholder, so each use binds its own copy.
            $text = fn(): string => sprintf("translate(coalesce(d.%s::text, ''), %s, '')", Sql::ident('fld_' . $name), $params->add(self::START . self::STOP));
            $headline = fn(int $fragments): string => sprintf(
                'ts_headline(%s, %s, to_tsquery(%s, %s), %s)',
                $config,
                $text(),
                $config,
                $params->add($tsquery),
                $params->add($options . ', MaxFragments=' . $fragments),
            );
            $columns[] = sprintf(
                "CASE WHEN char_length(coalesce(d.%s::text, '')) <= %d THEN %s ELSE %s END AS %s",
                Sql::ident('fld_' . $name),
                self::WHOLE_TEXT_CHARS,
                $headline(0),
                $headline(2),
                Sql::ident('h_' . $name),
            );
        }
        // the typo marks apply to the typo-tolerant fields the typed words can be in; none: the statement is the strict one
        $typoFields = $typo === null ? [] : array_values(array_filter($fields, static fn(string $name): bool => self::typoField($index, $name, $typo)));
        if ($typo !== null && $typoFields !== [] && $typo->ids !== []) {
            array_push($columns, ...$this->typoColumns($index, $typo, $typoFields, array_slice($typo->ids, 0, self::MAX_HITS), $tsquery, $params));
        } else {
            $typo = null;
        }
        $sql = sprintf(
            'SELECT %s FROM (%s) AS d WHERE d.fz_id = ANY(CAST(%s AS %s[]))',
            implode(', ', $columns),
            DocumentSql::select($index),
            $params->add(Sql::arrayLiteral($ids)),
            Types::id($index->idType),
        );

        $rows = $this->connection->fetchAll($sql, $params->all());
        $marked = [];
        foreach ($rows as $row) {
            foreach ($fields as $name) {
                $marked[Coerce::str($row['id'])][$name] = Coerce::str($row['h_' . $name]);
            }
        }
        if ($typo !== null) {
            $marked = $this->typoMarks($index, $typo, $typoFields, $rows, $marked);
        }

        return array_map(static fn(array $field): array => array_map(self::toHtml(...), $field), $marked);
    }

    /**
     * The typo marks of the hits the strict branch did not find. ts_headline marks the words of the strict tsquery, so a
     * document found only through a typo has no mark for the word that was typed wrongly: its snippet is the start of the
     * text, or has only the words that matched exactly. For such a hit (its id is in the plan and the strict tsquery does
     * not match its document: asked in the statement, so a ranking that gives a strict hit no text rank cannot make one of
     * those) and for a field the typed words can be in:
     *
     *  - ts_headline marked something: the snippet stays and the typo matches inside it are marked as well;
     *  - ts_headline marked nothing: the snippet is built around the typo matches (TypoMarker::snippet()), when there are any.
     *
     * Which words match is PostgreSQL's answer to the search's own rule (typoMatches()); this does the text work. A hit
     * that matched strictly is never touched.
     *
     * @param list<string>                         $fields
     * @param list<array<string, mixed>>           $rows
     * @param array<string, array<string, string>> $marked
     *
     * @return array<string, array<string, string>>
     */
    private function typoMarks(IndexDefinition $index, TypoPlan $typo, array $fields, array $rows, array $marked): array
    {
        $marker = new TypoMarker($typo->terms);
        // [id, field, ts_headline's snippet, the text when that snippet has no mark]
        $work = [];
        $words = [];
        $budget = self::MAX_RUNS;
        foreach ($rows as $row) {
            if (!in_array($row[self::TYPO] ?? null, [true, 't', '1', 1], true)) {
                continue;
            }
            $id = Coerce::str($row['id']);
            foreach ($fields as $name) {
                $snippet = $marked[$id][$name];
                $text = str_contains($snippet, self::START) ? null : self::scanned(Coerce::str($row[self::TEXT . $name] ?? ''));
                $cost = $marker->cost($text ?? $snippet, $name);
                // nothing to compare, or the page's budget of work (or of distinct words) is spent: ts_headline's snippet stays
                if ($cost === 0 || $cost > $budget || count($words) >= self::MAX_WORDS) {
                    continue;
                }
                $budget -= $cost;
                foreach ($marker->candidates($text ?? $snippet, $name) as $word) {
                    $words[$word] = true;
                }
                $work[] = [$id, $name, $snippet, $text];
            }
        }
        if ($words === []) {
            return $marked;
        }
        $similarities = $this->typoMatches($typo, array_map(strval(...), array_slice(array_keys($words), 0, self::MAX_WORDS)));
        foreach ($work as [$id, $name, $snippet, $text]) {
            $marked[$id][$name] = $text === null
                ? $marker->extend($snippet, $name, $similarities)
                : ($marker->snippet($text, $name, $similarities) ?? $snippet);
        }

        return $marked;
    }

    /**
     * The words (or runs of words) that reach the similarity their term needs, in one statement: the search's own rule,
     * word_similarity() of the normalised typed words in the normalised candidate, against the similarity the typed word's
     * length needs (or the search's fuzzy_similarity). The normaliser is the search's.
     *
     * @param list<string> $words
     *
     * @return array<string, array<int, float>> candidate => term index => similarity
     */
    private function typoMatches(TypoPlan $typo, array $words): array
    {
        $params = new ParameterBag();
        $norm = $this->names->normFunction();
        $similarity = sprintf('%s.word_similarity', $this->names->extension());
        $selects = [];
        foreach ($typo->terms as $i => $term) {
            $selects[] = sprintf(
                'SELECT w.t AS word, %d AS term, x.s AS sim FROM w CROSS JOIN (SELECT %s(%s) AS nn) AS q CROSS JOIN LATERAL (SELECT %s(q.nn, w.n) AS s) AS x WHERE x.s >= %s',
                $i,
                $norm,
                $params->add($term->needle),
                $similarity,
                $typo->similarity === null ? FuzzyQueryCompiler::similaritySql('q.nn', $term->prefix) : Sql::float($typo->similarity),
            );
        }
        $sql = sprintf(
            "WITH w AS (SELECT u.t, %s(u.t) AS n FROM unnest(CAST(%s AS text[])) AS u(t))\n%s",
            $norm,
            $params->add(Sql::arrayLiteral($words)),
            implode("\nUNION ALL\n", $selects),
        );

        $out = [];
        foreach ($this->connection->fetchAll($sql, $params->all()) as $row) {
            $out[Coerce::str($row['word'])][Coerce::int($row['term'])] = Coerce::float($row['sim']);
        }

        return $out;
    }

    /**
     * The extra columns of the typo marks: whether the hit is one the strict tsquery does not match (TYPO), and the text
     * of every field the marks apply to, for the hits of the plan only (to MAX_CHARS characters: a bound on the work).
     *
     * @param list<string>     $fields
     * @param list<int|string> $ids
     *
     * @return list<string>
     */
    private function typoColumns(IndexDefinition $index, TypoPlan $typo, array $fields, array $ids, string $tsquery, ParameterBag $params): array
    {
        $type = Types::id($index->idType);
        $columns = [sprintf(
            'd.fz_id = ANY(CAST(%s AS %s[])) AND NOT EXISTS (SELECT 1 FROM %s AS s WHERE s.id = d.fz_id AND %s @@ to_tsquery(%s, %s)) AS %s',
            $params->add(Sql::arrayLiteral($ids)),
            $type,
            $this->names->sidecar($index),
            $this->names->visibleVector($index, $typo->allowedFields),
            $this->names->regconfig($index->text),
            $params->add($tsquery),
            Sql::ident(self::TYPO),
        )];
        foreach ($fields as $name) {
            $columns[] = sprintf(
                "CASE WHEN d.fz_id = ANY(CAST(%s AS %s[])) THEN left(coalesce(d.%s::text, ''), %d) END AS %s",
                $params->add(Sql::arrayLiteral($ids)),
                $type,
                Sql::ident('fld_' . $name),
                self::MAX_CHARS,
                Sql::ident(self::TEXT . $name),
            );
        }

        return $columns;
    }

    /** Whether the typed words can be marked in this field: it is typo-tolerant, and no word is scoped to another field only. */
    private static function typoField(IndexDefinition $index, string $name, TypoPlan $typo): bool
    {
        return $index->field($name)?->fuzzy === true
            && array_any($typo->terms, static fn(TypoTerm $term): bool => $term->field === null || $term->field === $name);
    }

    /** The text the typo marks look at: when it was cut at the limit, without the word the cut may have split. */
    private static function scanned(string $text): string
    {
        return mb_strlen($text) >= self::MAX_CHARS ? (string) preg_replace('/[\p{L}\p{N}\p{M}]+$/u', '', $text) : $text;
    }

    /**
     * Every name must be a highlightable field of the index; the engine asks it of every requested name up front, so
     * the same request fails or passes for every viewer (a restricted one included) and whatever the result.
     *
     * @param list<string> $fields
     */
    public static function assertHighlightable(IndexDefinition $index, array $fields): void
    {
        foreach ($fields as $name) {
            $field = $index->field($name);
            if ($field === null || !$field->highlight) {
                throw new InvalidQuery(sprintf('Cannot highlight "%s": it is not a highlightable field of index "%s".', $name, $index->name));
            }
        }
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

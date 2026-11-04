<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Search\MatchedWord;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The words of the documents of one page that the search matched, and how (SearchBuilder::explainMatches()): ONE statement,
 * with every value bound, over the ids of the page. Only the fields the viewer may search are read (a hidden field's text never
 * enters the statement), and the words come from the stored text normalised like the search does (lower case, no accents).
 * A word matches as `exact` (the typed word), `stem` (another form of it), `prefix` (a prefix term), `synonym` (an alternative a
 * synonym added) or `typo` (close enough by the rule of the typo-tolerant branch, in a typo-tolerant field).
 */
final readonly class MatchExplainer
{
    /** The characters of a field looked at (a bound on the work for a very long document). */
    private const int MAX_CHARS = 10_000;
    /** The distinct words of the query that are looked for. */
    private const int MAX_TERMS = 24;
    /** The matched words listed for one hit. */
    private const int MAX_PER_HIT = 50;
    private const array RANK = [MatchedWord::EXACT => 0, MatchedWord::SYNONYM => 1, MatchedWord::STEM => 2, MatchedWord::PREFIX => 3, MatchedWord::TYPO => 4];

    public function __construct(private Connection $connection, private Names $names) {}

    /**
     * @param list<string>     $fields the fields the viewer may see and search (never the hidden ones)
     * @param list<int|string> $ids    the ids of the page
     *
     * @return array<string, list<MatchedWord>> id => matched words, in the order of the fields and of the words in the text
     */
    public function explain(IndexDefinition $index, array $fields, ?Node $root, array $ids, Thresholds $thresholds, bool $typoEnabled): array
    {
        $terms = [];
        self::collect($index, $fields, $root, null, $terms);
        $terms = array_slice(array_values($terms), 0, self::MAX_TERMS);
        $fields = array_values(array_filter($index->fieldNames(), static fn(string $name): bool => in_array($name, $fields, true)));
        if ($terms === [] || $fields === [] || $ids === []) {
            return [];
        }

        $params = new ParameterBag();
        $norm = $this->names->normFunction();
        $config = $this->names->regconfig($index->text);
        $similarity = sprintf('%s.word_similarity', $this->names->extension());

        $queryRows = [];
        foreach ($terms as $term) {
            $queryRows[] = sprintf('(%s(%s), CAST(%s AS boolean), CAST(%s AS boolean), CAST(%s AS text))', $norm, $params->add($term['word']), $params->add($term['prefix'] ? 't' : 'f'), $params->add($term['synonym'] ? 't' : 'f'), $params->add($term['field']));
        }
        $fieldRows = [];
        $manyRows = [];
        foreach ($fields as $ord => $name) {
            if ($index->field($name)?->many !== null) {
                // read value by value from the values table below, not as the joined text
                $manyRows[] = sprintf('(%d, %s, CAST(%s AS boolean))', $ord, $params->add($name), $params->add($index->field($name)->fuzzy ? 't' : 'f'));

                continue;
            }
            $fieldRows[] = sprintf('(%d, %s, CAST(%s AS boolean), x.%s::text)', $ord, $params->add($name), $params->add($index->field($name)?->fuzzy === true ? 't' : 'f'), Sql::ident('fld_' . $name));
        }
        $typo = $typoEnabled
            ? sprintf('(d.fuzzy AND char_length(q.w) >= %d AND %s(q.w, t.token) >= %s)', $thresholds->typo->minLength, $similarity, $thresholds->typo->similarity === null ? FuzzyQueryCompiler::similaritySql('q.w', false) : Sql::float($thresholds->typo->similarity))
            : 'false';
        $stem = sprintf('(length(to_tsvector(%1$s, t.token)) > 0 AND to_tsvector(%1$s, t.token) = to_tsvector(%1$s, q.w))', $config);
        $prefix = '(q.prefix AND left(t.token, char_length(q.w)) = q.w)';
        $idList = fn(): string => sprintf('CAST(%s AS %s[])', $params->add(Sql::arrayLiteral($ids)), Types::id($index->idType));
        $fromDocuments = sprintf(
            "SELECT x.fz_id, f.ord, f.field, f.fuzzy, %s(left(coalesce(f.txt, ''), %d)) AS n%s FROM (%s) AS x CROSS JOIN LATERAL (VALUES %s) AS f(ord, field, fuzzy, txt) WHERE x.fz_id = ANY(%s)",
            $norm,
            self::MAX_CHARS,
            $manyRows === [] ? '' : ', NULL::integer AS vord, NULL::text AS vtext',
            DocumentSql::select($index, $index->isMultiSource() ? sprintf('CAST(%s AS text[])', $params->add(Sql::arrayLiteral($ids))) : null),
            implode(', ', $fieldRows === [] ? ["(0, '', false, NULL::text)"] : $fieldRows),
            $idList(),
        );
        // a many field is read value by value from the values table (its first `limit` values, in the order of the ranking text)
        $d = $manyRows === [] ? $fromDocuments : sprintf(
            "SELECT * FROM (%s AND f.field <> ''\n UNION ALL SELECT mv.doc, mf.ord, mv.field, mf.fuzzy, %s(left(mv.v, %d)), mv.ord, mv.v FROM %s AS mv JOIN (VALUES %s) AS mf(ord, field, fuzzy) ON mf.field = mv.field WHERE mv.doc = ANY(%s)) AS u",
            $fromDocuments,
            $norm,
            self::MAX_CHARS,
            $this->names->values($index),
            implode(', ', $manyRows),
            $idList(),
        );
        $sql = sprintf(
            "WITH q AS (SELECT * FROM (VALUES %s) AS v(w, prefix, synonym, field)),\n"
            . "d AS (%s)\n"
            . "SELECT d.fz_id::text AS id, d.ord, t.pos, d.field, t.token, q.synonym, t.token = q.w AS is_exact, %s AS is_stem, %s AS is_prefix, %s(q.w, t.token) AS sim%s\n"
            . "FROM d CROSS JOIN LATERAL unnest(string_to_array(d.n, ' ')) WITH ORDINALITY AS t(token, pos) JOIN q ON (q.field IS NULL OR q.field = d.field)\n"
            . "WHERE t.token <> '' AND (t.token = q.w OR %s OR %s OR %s)\n"
            . 'ORDER BY d.fz_id, d.ord, %st.pos',
            implode(', ', $queryRows),
            $d,
            $stem,
            $prefix,
            $similarity,
            $manyRows === [] ? '' : ', d.vord, d.vtext',
            $stem,
            $prefix,
            $typo,
            $manyRows === [] ? '' : 'd.vord, ',
        );

        $best = []; // id => "field|token" => [rank, MatchedWord, order]
        foreach ($this->connection->fetchAll($sql, $params->all()) as $row) {
            $synonym = $row['synonym'] === true;
            $via = match (true) {
                $row['is_exact'] === true => $synonym ? MatchedWord::SYNONYM : MatchedWord::EXACT,
                $row['is_stem'] === true => $synonym ? MatchedWord::SYNONYM : MatchedWord::STEM,
                $row['is_prefix'] === true => MatchedWord::PREFIX,
                default => MatchedWord::TYPO,
            };
            $id = Coerce::str($row['id']);
            $field = Coerce::str($row['field']);
            $token = Coerce::str($row['token']);
            $key = $field . '|' . $token;
            $rank = self::RANK[$via];
            if (isset($best[$id][$key]) && $best[$id][$key][0] <= $rank) {
                continue;
            }
            $order = $best[$id][$key][2] ?? count($best[$id] ?? []);
            $best[$id][$key] = [$rank, new MatchedWord($field, $token, $via, $via === MatchedWord::TYPO ? round(Coerce::float($row['sim']), 3) : null, isset($row['vord']) ? Coerce::int($row['vord']) : null, isset($row['vtext']) ? Coerce::str($row['vtext']) : null), $order];
        }

        $out = [];
        foreach ($best as $id => $words) {
            uasort($words, static fn(array $a, array $b): int => $a[2] <=> $b[2]);
            $out[$id] = array_slice(array_values(array_map(static fn(array $w): MatchedWord => $w[1], $words)), 0, self::MAX_PER_HIT);
        }

        return $out;
    }

    /**
     * The positive words of the query (the negated ones are no matches): a term scoped to a hidden field is not looked for, a scope on a
     * name that is no field means every field, as the search treats it.
     *
     * @param list<string>                                                                        $allowed
     * @param array<string, array{word: string, prefix: bool, synonym: bool, field: string|null}> $out
     */
    private static function collect(IndexDefinition $index, array $allowed, ?Node $node, ?string $field, array &$out): void
    {
        $add = static function (string $word, bool $prefix, bool $synonym) use (&$out, $field): void {
            $out[$word . '|' . (int) $prefix . '|' . (int) $synonym . '|' . $field] = ['word' => $word, 'prefix' => $prefix, 'synonym' => $synonym, 'field' => $field];
        };
        if ($node instanceof Term) {
            $add($node->text, $node->prefix, $node->synonym);
        } elseif ($node instanceof Phrase) {
            foreach ($node->words as $word) {
                $add($word, false, $node->synonym);
            }
        } elseif ($node instanceof FieldScoped) {
            $known = in_array($node->field, $index->fieldNames(), true);
            if (!$known || in_array($node->field, $allowed, true)) {
                self::collect($index, $allowed, $node->node, $known ? $node->field : null, $out);
            }
        } elseif ($node instanceof AllOf || $node instanceof AnyOf) {
            foreach ($node->nodes as $child) {
                self::collect($index, $allowed, $child, $field, $out);
            }
        }
    }
}

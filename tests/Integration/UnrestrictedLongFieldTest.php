<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A very long field must not make an unrestricted viewer lose a document. `tsv` is the fields' vectors joined and the
 * positions of a tsvector are capped at 16 383: every position of a field that follows a field of more than about 16 000
 * words is 16 383, so a phrase on `tsv` does not match there, and a lexeme at a clamped position keeps only the highest
 * weight of its fields (a word scoped to a lower-weight field was lost). The lookup is now the AND of the words and the
 * fields' own columns (and the joined vectors of the later fields) decide.
 *
 * The same documents are indexed with a short and with a long field in front of the others (the long one is the short one
 * and 17 000 words no query has); every query, in every fuzzy mode, with two candidate limits, exact counts and facets,
 * must find the same documents, and the same number of them.
 */
final class UnrestrictedLongFieldTest extends TestCase
{
    /** [id, title, body, secret]; `notes`, the field that is long, holds no word of any query */
    private const array DOCUMENTS = [
        [1, 'ends with word1', 'word2 begins', 'word2 x'],
        [2, 'word1 word2', 'plain', 'plain'],
        [3, 'nothing', 'word1 word2', 'word1'],
        [4, 'word2 word1', 'word1', 'word2'],
        [5, 'plain', 'ends word1', 'word2 start'],
        [6, 'the who', 'ada', 'lovelace'],
        [7, 'ada lovelace', 'plain', 'secret word1 note'],
        [8, 'wrod1 here', 'plain', 'plain'],
        [9, 'x', 'ada ends', 'lovelace starts'],
        [10, 'word1 here', 'word1 and word2', 'word1 there'],
    ];

    private const array QUERIES = [
        'secret:word1',
        'title:word1',
        'body:word1',
        'secret:"word1 word2"',
        'title:"word1 word2"',
        '"word1 word2"',
        'word1-word2',
        'word1 word2',
        'word1 -"word1 word2"',
        'word1 -secret:word2',
        'title:word1 -secret:word2',
        '"word1 word2" | ada',
        '(secret:word1 | title:ada) word2',
        'secret:wor*',
        '"ada lovelace"',
        'secret:"ada lovelace"',
        'secret:wrod1',
        'wrd1 secret:word2',
        '-"word1 word2" "ada lovelace"',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_long_doc, fuzzphony_long_docs, fuzzphony_long_docs__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_long_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, notes text NOT NULL, cat text NOT NULL)');
    }

    /** @return iterable<string, array{list<string>}> the index order of the fields; `notes` is the long one */
    public static function layouts(): iterable
    {
        yield 'long field first' => [['notes', 'title', 'body', 'secret']];
        yield 'long field between title and body' => [['title', 'notes', 'body', 'secret']];
        yield 'long field before the last one' => [['title', 'body', 'notes', 'secret']];
        yield 'long field last' => [['title', 'body', 'secret', 'notes']];
    }

    /**
     * @param list<string> $order
     */
    #[DataProvider('layouts')]
    public function testALongFieldLosesNoDocument(array $order): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_long_docs, fuzzphony_long_docs__vocab CASCADE');
        $builder = IndexDefinition::builder('long_docs')->fromQuery('SELECT id, title, body, secret, notes, cat FROM fz_long_doc');
        foreach ($order as $i => $name) {
            $builder = $builder->field($name, ['A', 'B', 'C', 'D'][$i], fuzzy: $name !== 'notes');
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->filter('cat', 'string')->language('english')->sync('manual')->build()]));
        $fuzzphony->schema()->apply($this->connection);

        $filler = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        /** @var array{short: array<string, list<mixed>>, long: array<string, list<mixed>>} $outcomes */
        $outcomes = ['short' => [], 'long' => []];
        // one word in the short variant, so that a phrase never reaches across the field in either
        foreach (['short' => 'x', 'long' => $filler] as $variant => $notes) {
            $this->connection->execute('DELETE FROM fz_long_doc');
            foreach (self::DOCUMENTS as [$id, $title, $body, $secret]) {
                $this->connection->execute('INSERT INTO fz_long_doc VALUES (:id, :title, :body, :secret, :notes, :cat)', ['id' => $id, 'title' => $title, 'body' => $body, 'secret' => $secret, 'notes' => $notes, 'cat' => $id % 2 === 0 ? 'a' : 'b']);
            }
            $fuzzphony->reindex('long_docs');
            foreach (self::QUERIES as $text) {
                foreach (['never', 'fallback', 'always'] as $mode) {
                    foreach ([[100, false], [10, true]] as [$limit, $exact]) {
                        $search = $fuzzphony->in('long_docs')->query($text)->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => $limit])->facets('cat')->highlight('title', 'body', 'secret')->limit(50);
                        $result = ($exact ? $search->exactCounts() : $search)->get();
                        $ids = array_map(static fn($hit): int|string => $hit->id, $result->hits);
                        sort($ids);
                        $highlights = [];
                        foreach ($result->hits as $hit) {
                            $highlights[$hit->id] = $hit->highlights;
                        }
                        ksort($highlights);
                        $facets = array_map(static fn($facet): array => array_map(static fn($v): array => [$v->value, $v->count], $facet), $result->facets);
                        // the candidates of a capped statement are an arbitrary part of the matches: only the exact counts are compared there
                        $outcomes[$variant][$text . ' | ' . $mode . ' | ' . $limit . ($exact ? ' exact' : '')] = $exact
                            ? [$result->total, $facets, $result->warnings]
                            : [$ids, $result->total, $result->totalIsLowerBound, $facets, $result->warnings, $highlights];
                    }
                }
            }
        }
        $changed = [];
        foreach ($outcomes['short'] as $case => $short) {
            if ($short !== $outcomes['long'][$case]) {
                $changed[] = sprintf('%s: short %s, long %s', $case, json_encode($short), json_encode($outcomes['long'][$case]));
            }
        }
        self::assertSame([], $changed, 'a long field changed what is found');
    }
}

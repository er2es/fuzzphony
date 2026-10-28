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
 * What a restricted viewer finds must not depend on a hidden field in any way, including its LENGTH: the positions of a
 * tsvector are capped at 16 383, so after a long hidden field every position of the next field in the whole `tsv`
 * is 16 383 and a phrase on `tsv` would not match there (the lookup of a phrase is the AND of its words instead).
 * Every outcome (hits, order, scores, totals, facets, the statements and their values) is compared across hidden contents.
 */
final class RestrictedHiddenFieldIndependenceTest extends TestCase
{
    private const array DOCUMENTS = [
        [1, 'ends with word1', 'word2 begins', 'word2 x'],
        [2, 'word1 word2', 'plain', 'plain'],
        [3, 'nothing', 'word1 word2', 'word1'],
        [4, 'word2 word1', 'word1', 'word2'],
        [5, 'plain', 'ends word1', 'word2 start'],
        [6, 'the who', 'ada', 'lovelace'],
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_hidden_doc, fuzzphony_hidden_docs, fuzzphony_hidden_docs__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_hidden_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL)');
    }

    /** @return iterable<string, array{list<string>, list<string>}> index order, the fields the viewer may see */
    public static function layouts(): iterable
    {
        yield 'hidden body first, one allowed field' => [['body', 'title', 'secret'], ['title']];
        yield 'hidden body first, two allowed fields' => [['body', 'title', 'secret'], ['title', 'secret']];
        yield 'hidden body in the middle' => [['title', 'body', 'secret'], ['title', 'secret']];
        yield 'hidden secret last' => [['title', 'body', 'secret'], ['title', 'body']];
        yield 'two hidden fields first' => [['body', 'secret', 'title'], ['title']];
    }

    /**
     * @param list<string> $order
     * @param list<string> $allowed
     */
    #[DataProvider('layouts')]
    public function testNothingAHiddenFieldHoldsChangesWhatAViewerFinds(array $order, array $allowed): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_hidden_docs, fuzzphony_hidden_docs__vocab CASCADE');
        $builder = IndexDefinition::builder('hidden_docs')->fromQuery('SELECT id, title, body, secret, cat FROM fz_hidden_doc');
        foreach ($order as $i => $name) {
            $builder = $builder->field($name, ['A', 'B', 'C'][$i], fuzzy: true);
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->filter('cat', 'string')->language('english')->sync('manual')->build()]));
        $fuzzphony->schema()->apply($this->connection);

        $long = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        $hiddenFields = array_values(array_diff(['title', 'body', 'secret'], $allowed));
        $queries = ['"word1 word2"', 'title:"word1 word2"', 'secret:"word1 word2"', 'body:"word1 word2"', 'word1 -"word1 word2"', 'word1-word2', '"word1 word2" | ada', 'secret:word1', 'title:word1 -secret:word2', 'secret:wor*'];
        $outcomes = [];
        foreach (['empty' => '', 'long' => $long, 'words' => 'word1 word2 the who'] as $variant => $hidden) {
            $this->connection->execute('DELETE FROM fz_hidden_doc');
            $documents = self::DOCUMENTS;
            for ($i = 20; $i < 30; ++$i) {
                $documents[] = [$i, 'filler word1 word2 n' . $i, 'other', 'x word1 word2'];
            }
            foreach ($documents as [$id, $title, $body, $secret]) {
                $row = ['title' => $title, 'body' => $body, 'secret' => $secret];
                // The long text sits in the first three documents (a phrase across two fields, in the title, in the body), not in the rest:
                // the position cap of tsv acts per document, and every long text is rechecked by every phrase query.
                foreach ($hiddenFields as $name) {
                    $row[$name] = $variant === 'long' && $id > 3 ? '' : $hidden;
                }
                $this->connection->execute('INSERT INTO fz_hidden_doc VALUES (:id, :title, :body, :secret, :cat)', ['id' => $id, 'title' => $row['title'], 'body' => $row['body'], 'secret' => $row['secret'], 'cat' => $id % 2 === 0 ? 'a' : 'b']);
            }
            $fuzzphony->reindex('hidden_docs');
            foreach ($queries as $text) {
                foreach (['never', 'fallback', 'always'] as $mode) {
                    foreach ([[100, false], [10, true]] as [$limit, $exact]) {
                        $builder = $fuzzphony->in('hidden_docs')->query($text)->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => $limit])
                            ->restrictedToFields(...$allowed)->facets('cat')->limit(5);
                        $explained = ($exact ? $builder->exactCounts() : $builder)->explain();
                        $result = $explained->result;
                        $outcomes[$text . ' | ' . $mode . ' | ' . $limit . ($exact ? ' exact' : '')][$variant] = [
                            array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), round($hit->breakdown->textRank, 9), round($hit->breakdown->fuzzySimilarity, 9)], $result->hits),
                            $result->total,
                            $result->totalIsLowerBound,
                            $result->usedFuzzy,
                            $result->warnings,
                            array_map(static fn($facet): array => array_map(static fn($v): array => [$v->value, $v->count], $facet), $result->facets),
                            $result->didYouMean,
                            array_map(static fn(array $s): array => [$s['label'], $s['sql'], $s['params']], $explained->statements),
                        ];
                    }
                }
            }
        }
        foreach ($outcomes as $case => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'], $outcome, sprintf('%s: the hidden field %s changed the outcome', $case, $variant));
            }
        }
    }
}

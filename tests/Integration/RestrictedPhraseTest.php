<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An exact phrase across two fields. The unrestricted search reads one combined vector, so the last word of one field and
 * the first of the next are adjacent. A restricted viewer's phrase is read from the combined vector of the fields it may
 * see, as if the others did not exist: two allowed fields span, a hidden field never does, and nothing a hidden field holds
 * (a word, its absence) changes what the viewer finds. Fields in the index's order: title, body, secret (hidden or not).
 */
final class RestrictedPhraseTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_phrase_doc, fuzzphony_phrase_docs, fuzzphony_phrase_docs__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_phrase_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL)');
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('phrase_docs')
                ->fromQuery('SELECT id, title, body, secret FROM fz_phrase_doc')
                ->field('title', 'A', fuzzy: true)
                ->field('body', 'B', fuzzy: true)
                ->field('secret', 'C', fuzzy: true)
                ->language('english')
                ->sync('manual')
                ->build(),
        ]));
        $this->fuzzphony->schema()->apply($this->connection);
    }

    /** @param list<array{int, string, string, string}> $rows */
    private function load(array $rows): void
    {
        $this->connection->execute('DELETE FROM fz_phrase_doc');
        foreach ($rows as [$id, $title, $body, $secret]) {
            $this->connection->execute('INSERT INTO fz_phrase_doc VALUES (:id, :title, :body, :secret)', ['id' => $id, 'title' => $title, 'body' => $body, 'secret' => $secret]);
        }
        $this->fuzzphony->reindex('phrase_docs');
    }

    /** @return list<array{int, string, string, string}> */
    private static function documents(): array
    {
        return [
            [1, 'ends with word1', 'word2 begins', ''],          // across title and body
            [2, 'plain', 'ends with word1', 'word2 hidden'],     // across body and secret
            [3, 'ends with word1', 'x', 'word2 secret'],         // title and secret, with a body between
            [4, 'ends with word1', '', 'word2'],                 // title and secret, the body between is empty
            [5, 'word1 word2', 'other', ''],                     // inside one field
            [6, 'nothing', 'nothing', 'word1 word2'],            // inside the hidden field
            [7, 'word2 word1', 'word1', 'word2'],                // the words, in the wrong order, never adjacent
            [8, 'plain', 'plain', 'ends with word1'],            // the first word at the end of the hidden field only
        ];
    }

    private function search(string $text, string $mode = 'never'): SearchBuilder
    {
        return $this->fuzzphony->in('phrase_docs')->query($text)->thresholds(['fuzzy_mode' => $mode]);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int|string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function testAnUnrestrictedPhraseSpansTheFieldsAsItDid(): void
    {
        $this->load(self::documents());

        // adjacent in the combined vector: 2 and 7 span body and secret, 3 has a body between title and secret, 4's body is empty
        self::assertSame([1, 2, 4, 5, 6, 7], self::sorted($this->search('"word1 word2"')->get()->ids()));
    }

    public function testAPhraseSpansTwoAllowedFields(): void
    {
        $this->load(self::documents());

        // title and body: 1 spans them, 5 is inside the title; 2, 4 and 7 reach into the hidden secret, 6 is inside it
        self::assertSame([1, 5], self::sorted($this->search('"word1 word2"')->restrictedToFields('title', 'body')->get()->ids()));
        // body and secret: 2 and 7 span them, 6 is inside the secret
        self::assertSame([2, 6, 7], self::sorted($this->search('"word1 word2"')->restrictedToFields('body', 'secret')->get()->ids()));
        // title and secret, the body hidden: read as if it did not exist, whatever it holds (3 has a word in it, 4 none; 5 is inside the title, 6 inside the secret)
        self::assertSame([3, 4, 5, 6, 7], self::sorted($this->search('"word1 word2"')->restrictedToFields('title', 'secret')->get()->ids()));
        // the typo-tolerant modes add look-alikes, never take the phrase away
        foreach (['fallback', 'always'] as $mode) {
            self::assertContains(1, $this->search('"word1 word2"', $mode)->restrictedToFields('title', 'body')->get()->ids(), $mode);
        }
    }

    public function testAPhraseNeverSpansAnAllowedAndAHiddenField(): void
    {
        $this->load(self::documents());

        $ids = $this->search('"word1 word2"')->restrictedToFields('title', 'body')->get()->ids();
        foreach ([2 => 'body (allowed) and secret (hidden)', 3 => 'title (allowed) and secret (hidden)', 4 => 'title and secret, a hidden word between', 6 => 'inside the hidden field', 7 => 'body and secret'] as $id => $why) {
            self::assertNotContains($id, $ids, $why);
        }
        self::assertSame([5], $this->search('"word1 word2"')->restrictedToFields('title')->get()->ids(), 'a single allowed field: as it always was');
        self::assertSame([], $this->search('"word1 word2"')->restrictedToFields()->get()->ids(), 'nothing visible');
        self::assertSame([8], self::sorted($this->search('"ends with word1"')->restrictedToFields('secret')->get()->ids()), 'a phrase inside the one allowed field, its stop word dropped');
    }

    /** @return iterable<string, array{string}> */
    public static function phrases(): iterable
    {
        yield 'phrase' => ['"word1 word2"'];
        yield 'phrase of three' => ['"with word1 word2"'];
        yield 'a word that splits' => ['word1-word2'];
        yield 'excluded' => ['word1 -"word1 word2"'];
        yield 'or' => ['"word1 word2" | hamlet'];
        yield 'scoped' => ['title:"word1 word2"'];
        yield 'phrase and word' => ['"word1 word2" begins'];
    }

    #[DataProvider('phrases')]
    public function testWhatAHiddenFieldHoldsNeverChangesWhatARestrictedViewerFinds(string $text): void
    {
        $outcomes = [];
        foreach (['', 'word2', 'word1 word2', 'ends with word1 word2 begins', 'zzz'] as $hidden) {
            $rows = array_map(static fn(array $row): array => [$row[0], $row[1], $row[2], $hidden], self::documents());
            $this->load($rows);
            foreach (['never', 'fallback', 'always'] as $mode) {
                $hits = $this->search($text, $mode)->restrictedToFields('title', 'body')->highlight('title', 'body')->get();
                $outcomes[$mode][] = [array_map(static fn($h) => [$h->id, round($h->score, 6)], $hits->hits), $hits->total, $hits->warnings];
            }
        }
        foreach ($outcomes as $mode => $runs) {
            foreach ($runs as $run) {
                self::assertSame($runs[0], $run, sprintf('%s, %s: the same hits, scores and total whatever the hidden field holds', $text, $mode));
            }
        }
    }

    public function testASplitWordSpansToo(): void
    {
        $this->load(self::documents());

        self::assertSame([1, 5], self::sorted($this->search('word1-word2')->restrictedToFields('title', 'body')->get()->ids()));
        // an excluded phrase excludes what spans the two allowed fields too
        self::assertSame([2, 3, 4, 7], self::sorted($this->search('word1 -"word1 word2"')->restrictedToFields('title', 'body')->get()->ids()));
    }
}

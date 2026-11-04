<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\MatchedWord;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** explainMatches(): words are told apart by their field and text, and a viewer limited to a many field still gets its values explained. */
final class MatchExplainerKeysTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->tearDown();
        $this->connection->execute('CREATE TABLE mk_doc (id bigint PRIMARY KEY, a text NOT NULL, ab text NOT NULL)');
        $this->connection->execute("INSERT INTO mk_doc VALUES (1, 'bc', 'c')");
        $this->connection->execute('CREATE TABLE mk_child (id serial PRIMARY KEY, doc_id bigint NOT NULL, label text)');
        $this->connection->execute('CREATE INDEX mk_child_doc ON mk_child (doc_id)');
        $this->connection->execute("INSERT INTO mk_child (doc_id, label) VALUES (1, 'alpha'), (1, 'beta')");
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS mk_doc, mk_child, fuzzphony_mk_two, fuzzphony_mk_two__vocab, fuzzphony_mk_two__next, fuzzphony_mk_two__changes, fuzzphony_mk_only, fuzzphony_mk_only__vocab, fuzzphony_mk_only__next, fuzzphony_mk_only__changes, fuzzphony_mk_only__values, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    private function fuzzphony(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    public function testTwoFieldsWhoseNamePlusWordLookAlikeKeepBothMatches(): void
    {
        $index = IndexDefinition::builder('mk_two')->fromTable('mk_doc')->field('a', 'A')->field('ab', 'B')->sync('manual')->build();
        $result = $this->fuzzphony($index)->in('mk_two')->query('bc OR c')->explainMatches()->asSystem()->get();

        self::assertSame([1], $result->ids());
        self::assertSame([['a', 'bc'], ['ab', 'c']], array_map(static fn(MatchedWord $m): array => [$m->field, $m->word], $result->hits[0]->matches ?? []));
    }

    public function testAViewerWhoseOnlyFieldIsAManyFieldGetsItsValuesExplained(): void
    {
        $index = IndexDefinition::builder('mk_only')
            ->fromTable('mk_doc')
            ->field('a', 'A')
            ->field('label', 'B', many: ['from' => 'mk_child', 'key' => 'doc_id', 'column' => 'label'])
            ->sync('manual')
            ->build();
        $result = $this->fuzzphony($index)->in('mk_only')->query('beta')->restrictedToFields('label')->explainMatches()->asSystem()->get();

        self::assertSame([1], $result->ids());
        self::assertSame([['label', 'beta']], array_map(static fn(MatchedWord $m): array => [$m->field, $m->word], $result->hits[0]->matches ?? []));
    }
}

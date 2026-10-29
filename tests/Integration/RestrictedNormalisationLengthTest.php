<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * A word that grows by more than two letters in normalisation ("ßßßab" is "ssssssab": 5 letters for PHP, 8 for PostgreSQL) crosses the
 * 7 -> 8 band of the typo curve: the recheck of an allowed field accepts a similarity of 0.23 where the lookup used to ask for 0.28 from
 * `fz`, so a restricted viewer found the document only when a field it cannot see also held the word. What it finds must not depend on that.
 */
final class RestrictedNormalisationLengthTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_norm_doc, fuzzphony_norm_docs, fuzzphony_norm_docs__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_norm_doc (id bigint PRIMARY KEY, name text NOT NULL, secret text NOT NULL)');
    }

    public function testAWordThatGrowsInNormalisationFindsTheSameWhateverAHiddenFieldHolds(): void
    {
        $definition = IndexDefinition::builder('norm_docs')->fromQuery('SELECT id, name, secret FROM fz_norm_doc')
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: 'ROLE_SECRET')->language('english')->sync('manual')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
        $fuzzphony->schema()->apply($this->connection);

        $found = [];
        foreach (['nothing' => '', 'the word' => 'ßßßab ssssssab'] as $variant => $secret) {
            $this->connection->execute('TRUNCATE fz_norm_doc');
            // "xaxbsabab" is close enough to the normalised word for the recheck (0.25 >= 0.23), not for the old lookup (0.28)
            $this->connection->execute('INSERT INTO fz_norm_doc VALUES (1, :name, :secret)', ['name' => 'xaxbsabab', 'secret' => $secret]);
            for ($id = 2; $id < 30; ++$id) {
                $this->connection->execute('INSERT INTO fz_norm_doc VALUES (:id, :name, :secret)', ['id' => $id, 'name' => 'filler item ' . $id, 'secret' => 'other']);
            }
            $fuzzphony->reindex('norm_docs');
            $result = $fuzzphony->in('norm_docs')->query('ßßßab')->thresholds(['fuzzy_mode' => 'always'])->restrictedToFields('name')->get();
            $found[$variant] = $result->ids();
        }

        self::assertSame([1], $found['nothing'], 'the allowed field alone is enough');
        self::assertSame($found['nothing'], $found['the word']);
    }
}

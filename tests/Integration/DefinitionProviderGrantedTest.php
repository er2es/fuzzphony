<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\DefinitionProvider;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\FieldRestrictingGuard;
use Fuzzphony\Tests\Fixtures\RowsDefinitionProvider;
use PHPUnit\Framework\TestCase;

/** Plain PHP: provider definitions are passed as a list; a provider-defined `granted` field is hidden from a restricted viewer like any other. */
final class DefinitionProviderGrantedTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_prov_doc, fuzzphony_prov_docs, fuzzphony_prov_docs__vocab, fuzzphony_prov_docs__next, fuzzphony_prov_docs__changes, fuzzphony_rows_docs, fuzzphony_rows_docs__vocab, fuzzphony_rows_docs__next, fuzzphony_rows_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_prov_doc (id bigint PRIMARY KEY, title text NOT NULL, secret text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_prov_doc VALUES (1, 'Plain notebook', 'margin zebrafish'), (2, 'Zebrafish poster', '')");
    }

    public function testThePlainPhpExampleOfTheDocsSearchesTheProvidedIndex(): void
    {
        $connection = $this->connection;
        // region: plain
        $provider = new RowsDefinitionProvider([
            ['name' => 'rows_docs', 'query' => 'SELECT id, title FROM fz_prov_doc', 'fields' => '{"title": "A"}'],
        ]);
        $fuzzphony = Fuzzphony::postgres($connection, indexes: [...$provider->definitions()]);
        // endregion
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('rows_docs');

        self::assertSame([2], $fuzzphony->in('rows_docs')->query('zebrafish')->asSystem()->get()->ids());
    }

    public function testAGrantedFieldOfAProviderIsHiddenFromARestrictedViewer(): void
    {
        $provider = new class implements DefinitionProvider {
            public function definitions(): iterable
            {
                yield IndexDefinition::builder('prov_docs')->fromQuery('SELECT id, title, secret FROM fz_prov_doc')
                    ->field('title', 'A', fuzzy: true)->field('secret', 'C', granted: 'ROLE_BOSS')->language('english')->sync('manual')->build();
            }
        };
        $guard = new class implements FieldRestrictingGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return $actor->user === 'boss' ? Access::all() : Access::only(array_values(array_diff($index->fieldNames(), ['secret'])));
            }
        };

        try {
            Fuzzphony::postgres($this->connection, [...$provider->definitions()]);
            self::fail('a granted field without a field-restricting guard must be refused');
        } catch (InvalidDefinition $e) {
            self::assertSame('prov_docs', $e->index);
        }

        $fuzzphony = Fuzzphony::postgres($this->connection, [...$provider->definitions()], guards: [$guard]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('prov_docs');

        $restricted = $fuzzphony->in('prov_docs')->query('zebrafish')->asActor(new Actor(user: 'ada'))->get();
        self::assertSame([2], $restricted->ids(), 'the restricted viewer does not find a document by its granted field');
        self::assertArrayNotHasKey('secret', $restricted->hits[0]->highlights);
        $boss = $fuzzphony->in('prov_docs')->query('zebrafish')->asActor(new Actor(user: 'boss'))->get()->ids();
        sort($boss);
        self::assertSame([1, 2], $boss);
    }
}

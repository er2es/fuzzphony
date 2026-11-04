<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/** The id-only consumers (EntityLoader::load(), FuzzphonySearchFilter) of a multi-source index never look up the id of another kind. */
final class MultiSourceIdOnlyPathsTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($this->connection);
        $this->connection->execute('DROP TABLE IF EXISTS fz_free, fuzzphony_site, fuzzphony_plain CASCADE');
        $this->connection->execute('CREATE TABLE fz_free (id bigint PRIMARY KEY, title text NOT NULL)');
        // article 1 is tenant 1, article 2 (Bob) is tenant 2; the free post 2 is tenant 1 and matches "ada"
        $this->connection->execute("INSERT INTO fz_article (id, title) VALUES (1, 'Ada Lovelace'), (2, 'Bob')");
        $this->connection->execute("INSERT INTO fz_free VALUES (2, 'Ada biography')");
        $site = IndexDefinition::builder('site')
            ->source('article', 'SELECT id, title, id AS tid FROM fz_article', entity: Article::class, idType: 'int')
            ->source('free', 'SELECT id, title, 1 AS tid FROM fz_free', idType: 'int')
            ->field('title', 'A')->filter('tid', 'int')->tenant('tid')->sync('manual')
            ->build();
        $plain = IndexDefinition::builder('plain')
            ->source('article', 'SELECT id, title FROM fz_article', entity: Article::class, idType: 'int')
            ->source('free', 'SELECT id, title FROM fz_free', idType: 'int')
            ->field('title', 'A')->sync('manual')
            ->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$site, $plain]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('site');
        $this->fuzzphony->reindex('plain');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_free, fuzzphony_site, fuzzphony_plain CASCADE');
    }

    public function testLoadKeepsOnlyTheHitsOfTheSourcesOfTheClass(): void
    {
        $result = $this->fuzzphony->in('site')->forTenant(1)->query('ada')->get();
        self::assertSame(['article:1', 'free:2'], array_map(static fn($h): string => $h->kind . ':' . $h->id, $result->hits));

        $loaded = (new EntityLoader(DoctrineTestCase::entityManager(), $this->fuzzphony))->load($result, Article::class);

        self::assertSame([1], array_map(static fn(array $row): int => $row['entity']->getId(), $loaded), 'the free:2 hit must not load article 2 (tenant 2)');
        self::assertSame(['article'], array_map(static fn(array $row): string => $row['hit']->kind, $loaded));
    }

    public function testTheApiFilterRestrictsToTheIdsOfTheSourcesOfTheClass(): void
    {
        $em = DoctrineTestCase::entityManager();
        $filter = new FuzzphonySearchFilter($this->fuzzphony, index: 'plain');
        $qb = $em->getRepository(Article::class)->createQueryBuilder('a');
        $filter->apply($qb, new QueryNameGenerator(), Article::class, null, ['filters' => ['q' => 'biography']]);

        self::assertSame([], $qb->getQuery()->getResult(), 'only a free-kind hit matches "biography": no article id may be looked up');
    }
}

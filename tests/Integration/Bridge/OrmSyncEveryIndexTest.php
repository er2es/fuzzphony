<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\ImmediateRefreshDispatcher;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/** sync: orm refreshes EVERY index that reads the flushed entity class: a second index of the entity and a multi-source index too. */
final class OrmSyncEveryIndexTest extends TestCase
{
    public function testEveryIndexOfTheEntityIsRefreshed(): void
    {
        $connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($connection);
        $connection->execute('DROP TABLE IF EXISTS fuzzphony_articles_b, fuzzphony_multi CASCADE');
        $em = DoctrineTestCase::entityManager();
        $second = IndexDefinition::builder('articles_b')->fromTable('fz_article')->field('title', 'A')->entity(Article::class)->sync('orm')->build();
        $multi = IndexDefinition::builder('multi')
            ->source('article', 'SELECT id, title FROM fz_article', entity: Article::class, idType: 'int')
            ->field('title', 'A')->sync('orm')
            ->build();
        $registry = new IndexRegistry([...(new DoctrineIndexDiscovery($em))->discover(), $second, $multi]);
        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, $registry);
        $fuzzphony->schema()->apply($connection);
        $em->getEventManager()->addEventListener(['postPersist', 'postUpdate', 'preRemove', 'postFlush'], new OrmSyncListener(new ImmediateRefreshDispatcher($engine), $registry));

        $article = new Article(1, 'Wireless mouse review', null);
        $em->persist($article);
        $em->flush();
        foreach (['articles', 'articles_b', 'multi'] as $index) {
            self::assertCount(1, $fuzzphony->in($index)->query('mouse')->get()->hits, $index . ' sees the new row');
        }

        $em->remove($article);
        $em->flush();
        foreach (['articles', 'articles_b', 'multi'] as $index) {
            self::assertSame([], $fuzzphony->in($index)->query('mouse')->get()->hits, $index . ' dropped the row');
        }
        $connection->execute('DROP TABLE IF EXISTS fuzzphony_articles_b, fuzzphony_multi CASCADE');
    }
}

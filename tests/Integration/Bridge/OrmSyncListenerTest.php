<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\ImmediateRefreshDispatcher;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/**
 * OrmSyncListener is wired with a RefreshDispatcher (not an Engine directly, since a past BC break).
 * These tests run it against a real EntityManager + real Postgres, through ImmediateRefreshDispatcher,
 * and prove that flush() actually keeps the search index in sync.
 */
final class OrmSyncListenerTest extends TestCase
{
    private EntityManagerInterface $em;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($connection);

        $this->em = DoctrineTestCase::entityManager();
        $registry = new IndexRegistry((new DoctrineIndexDiscovery($this->em))->discover());
        $engine = new PostgresEngine($connection);
        $this->fuzzphony = new Fuzzphony($engine, $registry);
        $this->fuzzphony->schema()->apply($connection);

        $listener = new OrmSyncListener(new ImmediateRefreshDispatcher($engine), $registry);
        $this->em->getEventManager()->addEventListener(['postPersist', 'postUpdate', 'preRemove', 'postFlush'], $listener);
    }

    public function testFlushAfterPersistMakesTheEntitySearchable(): void
    {
        self::assertSame([], $this->fuzzphony->in('articles')->query('mouse')->get()->ids(), 'nothing indexed yet');

        $this->em->persist(new Article(1, 'Wireless mouse review', 'A great mouse for everyday use'));
        $this->em->flush();

        self::assertContains(1, $this->fuzzphony->in('articles')->query('mouse')->get()->ids());
    }

    public function testFlushAfterUpdateRefreshesTheDocument(): void
    {
        $article = new Article(2, 'Old headline', null);
        $this->em->persist($article);
        $this->em->flush();
        self::assertContains(2, $this->fuzzphony->in('articles')->query('headline')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());

        $article->setTitle('Ergonomic keyboard guide');
        $this->em->flush();

        self::assertNotContains(2, $this->fuzzphony->in('articles')->query('headline')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
        self::assertContains(2, $this->fuzzphony->in('articles')->query('keyboard')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
    }

    public function testFlushAfterRemoveDropsTheDocument(): void
    {
        $article = new Article(3, 'Deletable gadget review', null);
        $this->em->persist($article);
        $this->em->flush();
        self::assertContains(3, $this->fuzzphony->in('articles')->query('gadget')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());

        $this->em->remove($article);
        $this->em->flush();

        self::assertNotContains(3, $this->fuzzphony->in('articles')->query('gadget')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * README claims EntityLoader "turns results into entities with one query, keeping the ranking order."
 * These tests verify both halves of that claim against a real EntityManager + real Postgres:
 * the query count, and that the entity order matches the hit order exactly (not the DB's natural order).
 */
final class EntityLoaderTest extends TestCase
{
    private EntityManagerInterface $em;
    private Fuzzphony $fuzzphony;
    private EntityLoader $loader;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($connection);

        $this->em = DoctrineTestCase::entityManager();
        $registry = new IndexRegistry((new DoctrineIndexDiscovery($this->em))->discover());
        $engine = new PostgresEngine($connection);
        $this->fuzzphony = new Fuzzphony($engine, $registry);
        $this->fuzzphony->schema()->apply($connection);
        $this->loader = new EntityLoader($this->em);

        // Inserted in an order that is neither id order nor the eventual ranking order,
        // so "same order as the DB" and "same order as the ranking" are actually distinguishable.
        foreach ([
            new Article(3, 'Mouse pad recommendations', 'Best mouse pad for gaming setups'),
            new Article(1, 'Wireless mouse review', 'A great mouse for everyday use'),
            new Article(4, 'Keyboard buying guide', 'How to choose a mechanical keyboard'),
            new Article(2, 'Gaming mouse RGB review', 'Fast mouse with RGB lighting, mouse mouse mouse'),
        ] as $article) {
            $this->em->persist($article);
        }
        $this->em->flush();
        $this->fuzzphony->reindex('articles');
    }

    public function testEntitiesComeBackInTheSearchRankingOrderNotIdOrder(): void
    {
        $result = $this->fuzzphony->in('articles')->query('mouse')->get();
        self::assertGreaterThanOrEqual(3, count($result->hits), 'sanity check: several articles mention "mouse"');

        $loaded = $this->loader->load($result, Article::class);

        self::assertSame($result->ids(), array_map(static fn(array $row): int => $row['entity']->getId(), $loaded));
        self::assertNotSame([1, 2, 3], array_slice(array_map(static fn(array $row): int => $row['entity']->getId(), $loaded), 0, 3), 'would be a coincidence if ranking order equalled id order');
    }

    public function testExecutesExactlyOneQueryRegardlessOfHitCount(): void
    {
        $result = $this->fuzzphony->in('articles')->query('mouse')->get();
        self::assertNotSame([], $result->hits);

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            private array $queries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }

            /** @return list<string> */
            public function queries(): array
            {
                return $this->queries;
            }
        };

        // A fresh EntityManager reads through the logging middleware from a clean identity map,
        // so every hit's entity must be fetched from the database, not just found in memory.
        $freshEm = DoctrineTestCase::entityManager([new Middleware($logger)]);
        $loader = new EntityLoader($freshEm);

        $loaded = $loader->load($result, Article::class);

        self::assertCount(count($result->hits), $loaded);
        $selects = array_filter($logger->queries(), static fn(string $sql): bool => stripos($sql, 'select') === 0);
        self::assertCount(1, $selects, 'expected exactly one SELECT to load all hit entities; got: ' . implode(' | ', $selects));
    }

    public function testHitsWhoseEntityWasDeletedMeanwhileAreSkipped(): void
    {
        $result = $this->fuzzphony->in('articles')->query('mouse')->get();
        self::assertContains(1, $result->ids());

        $connection = PostgresTestCase::connect();
        $connection->execute('DELETE FROM fz_article WHERE id = 1');

        $loaded = $this->loader->load($result, Article::class);

        self::assertNotContains(1, array_map(static fn(array $row): int => $row['entity']->getId(), $loaded));
        self::assertCount(count($result->hits) - 1, $loaded);
    }

    public function testEmptyResultLoadsNothingWithoutQuerying(): void
    {
        $result = $this->fuzzphony->in('articles')->query('nonexistentxyz')->get();
        self::assertSame([], $result->hits);

        self::assertSame([], $this->loader->load($result, Article::class));
    }
}

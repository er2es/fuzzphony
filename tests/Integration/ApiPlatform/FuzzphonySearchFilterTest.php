<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/**
 * FuzzphonySearchFilter restricts and re-orders an API Platform collection query by relevance.
 * Its "keep Fuzzphony's order" comment builds a CASE-based ORDER BY, which is the part most likely
 * to silently regress into "correct set, wrong order" or "correct order, wrong set" — so these
 * tests execute the real DQL against real Postgres rather than just inspecting the QueryBuilder.
 */
final class FuzzphonySearchFilterTest extends TestCase
{
    private EntityManagerInterface $em;
    private FuzzphonySearchFilter $filter;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($connection);

        $this->em = DoctrineTestCase::entityManager();
        $registry = new IndexRegistry((new DoctrineIndexDiscovery($this->em))->discover());
        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, $registry);
        $fuzzphony->schema()->apply($connection);
        $this->filter = new FuzzphonySearchFilter($fuzzphony);

        foreach ([
            new Article(3, 'Mouse pad recommendations', 'Best mouse pad for gaming setups'),
            new Article(1, 'Wireless mouse review', 'A great mouse for everyday use'),
            new Article(4, 'Keyboard buying guide', 'How to choose a mechanical keyboard'),
            new Article(2, 'Gaming mouse RGB review', 'Fast mouse with RGB lighting, mouse mouse mouse'),
        ] as $article) {
            $this->em->persist($article);
        }
        $this->em->flush();
        $fuzzphony->reindex('articles');
    }

    public function testMatchingArticlesComeBackInRelevanceOrderNotIdOrder(): void
    {
        $qb = $this->em->getRepository(Article::class)->createQueryBuilder('a');
        $this->filter->apply($qb, new QueryNameGenerator(), Article::class, null, ['filters' => ['q' => 'mouse']]);

        /** @var list<Article> $result */
        $result = $qb->getQuery()->getResult();
        $ids = array_map(static fn(Article $a): int => $a->getId(), $result);

        self::assertGreaterThanOrEqual(3, count($ids), 'sanity check: several articles mention "mouse"');
        self::assertNotSame([1, 2, 3], array_slice($ids, 0, 3), 'would be a coincidence if relevance order equalled id order');

        // Ground truth: ask Fuzzphony directly for the same query and compare orders.
        self::assertSame($this->rankedIdsFor('mouse'), $ids, 'the filter must preserve Fuzzphony\'s ranking order exactly');
    }

    public function testNoHitsRestrictsTheCollectionToNothing(): void
    {
        $qb = $this->em->getRepository(Article::class)->createQueryBuilder('a');
        $this->filter->apply($qb, new QueryNameGenerator(), Article::class, null, ['filters' => ['q' => 'nonexistentxyzquery']]);

        self::assertSame([], $qb->getQuery()->getResult());
    }

    public function testBlankQueryLeavesTheCollectionUntouched(): void
    {
        $qb = $this->em->getRepository(Article::class)->createQueryBuilder('a');
        $this->filter->apply($qb, new QueryNameGenerator(), Article::class, null, ['filters' => ['q' => '  ']]);

        /** @var list<Article> $result */
        $result = $qb->getQuery()->getResult();
        self::assertCount(4, $result, 'a blank search text must not filter the collection at all');
    }

    /** @return list<int> */
    private function rankedIdsFor(string $query): array
    {
        $connection = PostgresTestCase::connect();
        $registry = new IndexRegistry((new DoctrineIndexDiscovery($this->em))->discover());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), $registry);

        return array_map('intval', $fuzzphony->in('articles')->query($query)->limit(500)->get()->ids());
    }
}

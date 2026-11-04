<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Doctrine\ArticleAdminIndex;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/** Two indexes over one entity: the attribute index and a definition class, through Fuzzphony::postgres() and the EntityLoader. */
final class DefinitionClassIndexTest extends TestCase
{
    private Fuzzphony $fuzzphony;
    private EntityLoader $loader;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($connection);
        $em = DoctrineTestCase::entityManager();
        $this->fuzzphony = Fuzzphony::postgres($connection, [...(new DoctrineIndexDiscovery($em))->discover(), ArticleAdminIndex::class]);
        $this->fuzzphony->schema()->apply($connection);
        $this->loader = new EntityLoader($em);
        foreach ([new Article(1, 'Wireless mouse review', 'A great mouse', true), new Article(2, 'Mouse draft', 'Unpublished notes about a mouse', false)] as $article) {
            $em->persist($article);
        }
        $em->flush();
        $this->fuzzphony->reindex('articles');
        $this->fuzzphony->reindex('articles_admin');
    }

    public function testBothIndexesExistAndTheEntityClassAnswersWithTheAttributeIndex(): void
    {
        self::assertSame('articles', $this->fuzzphony->registry()->get(Article::class)->name);
        self::assertSame('definition class ' . ArticleAdminIndex::class, $this->fuzzphony->registry()->origin('articles_admin'));
        self::assertNull($this->fuzzphony->registry()->origin('articles'));
    }

    public function testTheHitsOfEitherIndexLoadTheSameEntityClass(): void
    {
        foreach (['articles', 'articles_admin'] as $index) {
            $result = $this->fuzzphony->in($index)->query('mouse')->get();
            $entityClass = $this->fuzzphony->definition($index)->entityClass;
            self::assertSame(Article::class, $entityClass);

            $loaded = $this->loader->load($result, $entityClass);

            self::assertSame($result->ids(), array_map(static fn(array $row): int => $row['entity']->getId(), $loaded), $index);
            self::assertNotSame([], $loaded);
        }
    }

    public function testTheDefinitionClassIndexHasItsOwnFields(): void
    {
        $admin = $this->fuzzphony->in('articles_admin')->query('mouse')->get();
        $public = $this->fuzzphony->in('articles')->query('mouse')->get();

        self::assertSame(['title', 'body'], $this->fuzzphony->definition('articles_admin')->fieldNames());
        self::assertEqualsCanonicalizing([1, 2], $admin->ids(), 'the admin index finds the unpublished body text too');
        self::assertNotSame([], $public->ids());
    }

    public function testADefinitionClassNameThatIsNotAClassIsRefused(): void
    {
        $this->expectException(\Fuzzphony\Core\Exception\InvalidDefinition::class);
        Fuzzphony::postgres(PostgresTestCase::connect(), ['App\NoSuchIndex']); // @phpstan-ignore argument.type
    }

    public function testTheSameNameTwiceFromAClassAndAnObjectIsRefused(): void
    {
        $this->expectException(\Fuzzphony\Core\Exception\InvalidDefinition::class);
        $this->expectExceptionMessage('defined twice');
        Fuzzphony::postgres(PostgresTestCase::connect(), [\Fuzzphony\Core\Definition\IndexDefinition::builder('articles_admin')->fromTable('fz_article')->field('title', 'A')->build(), ArticleAdminIndex::class]);
    }
}

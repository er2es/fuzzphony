<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use PHPUnit\Framework\TestCase;

/** How the loader asks for the entities of a result and pairs them with their hits. */
final class EntityLoaderMappingTest extends TestCase
{
    private static function resultOf(int|string ...$ids): SearchResult
    {
        return new SearchResult(
            array_values(array_map(static fn(int|string $id): Hit => new Hit($id, 1.0, new ScoreBreakdown()), $ids)),
            count($ids),
            false,
            0.1,
            false,
            [],
            20,
            0,
        );
    }

    /**
     * @param list<object>        $found       what the repository returns
     * @param array<int, mixed>  $identifiers entity (by spl_object_id) => its identifier value
     */
    private function loader(array $found, mixed $expectedCriteria, array $identifiers): EntityLoader
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $metadata->method('getIdentifierValues')->willReturnCallback(static fn(object $entity): array => ['id' => $identifiers[spl_object_id($entity)]]);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findBy')->with($expectedCriteria)->willReturn($found);
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getRepository')->willReturn($repository);

        return new EntityLoader($em);
    }

    public function testOnlyTheEntitiesOfTheHitsAreRequestedAndEachComesWithItsHit(): void
    {
        $two = new Article(2, 'Two');
        $one = new Article(1, 'One');
        $result = self::resultOf(1, 2);
        $loader = $this->loader([$two, $one], ['id' => [1, 2]], [spl_object_id($two) => 2, spl_object_id($one) => 1]);

        $loaded = $loader->load($result, Article::class);

        self::assertCount(2, $loaded);
        self::assertSame($result->hits[0], $loaded[0]['hit']);
        self::assertSame($one, $loaded[0]['entity'], 'in the order of the hits, not of the repository');
        self::assertSame($result->hits[1], $loaded[1]['hit']);
        self::assertSame($two, $loaded[1]['entity']);
    }

    public function testAStringableIdentifierMatchesTheHitThatCarriesItsString(): void
    {
        $entity = new Article(1, 'One');
        $uuid = new class implements \Stringable {
            public function __toString(): string
            {
                return 'uuid-1';
            }
        };
        $loader = $this->loader([$entity], ['id' => ['uuid-1']], [spl_object_id($entity) => $uuid]);

        $loaded = $loader->load(self::resultOf('uuid-1'), Article::class);

        self::assertSame([$entity], array_column($loaded, 'entity'));
    }

    public function testAnIdentifierThatIsNeitherScalarNorStringableMatchesNoHit(): void
    {
        $entity = new Article(1, 'One');
        $loader = $this->loader([$entity], ['id' => [1]], [spl_object_id($entity) => new \stdClass()]);

        self::assertSame([], $loader->load(self::resultOf(1), Article::class));
    }

    public function testAnEmptyResultAsksTheMappingForNothing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getClassMetadata');
        $em->expects(self::never())->method('getRepository');

        self::assertSame([], (new EntityLoader($em))->load(self::resultOf(), Article::class));
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Doctrine\Note;
use PHPUnit\Framework\TestCase;

/** loadHits() and load() against a mocked entity manager: which classes are asked, with which ids, and how the entities are paired back. */
final class EntityLoaderLoadHitsTest extends TestCase
{
    private static function hit(string $index, string $kind, int|string $id): Hit
    {
        return new Hit($kind, $id, 1.0, new ScoreBreakdown(), index: $index);
    }

    private static function fuzzphony(): Fuzzphony
    {
        $multi = IndexDefinition::builder('mixed')
            ->source('article', 'SELECT id, title FROM a', entity: Article::class, idType: 'int')
            ->source('free', 'SELECT id, title FROM f', idType: 'int')
            ->field('title', 'A')
            ->sync('manual')
            ->build();
        $notes = IndexDefinition::builder('notes')->fromTable('n')->field('title', 'A')->entity(Note::class)->sync('manual')->build();

        return new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([$multi, $notes]));
    }

    /**
     * @param array<class-string, array{type: string|null, found: list<object>, criteria: mixed}> $classes
     * @param array<int, mixed>                                                                     $identifiers entity (spl_object_id) => its identifier
     */
    private function loader(array $classes, array $identifiers): EntityLoader
    {
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturnCallback(function (string $class) use ($classes, $identifiers): ClassMetadata {
            $metadata = self::createStub(ClassMetadata::class);
            $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
            $metadata->method('getTypeOfField')->willReturn($classes[$class]['type']);
            $metadata->method('getIdentifierValues')->willReturnCallback(static fn(object $e): array => ['id' => $identifiers[spl_object_id($e)]]);

            return $metadata;
        });
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($classes): EntityRepository {
            $repository = $this->createMock(EntityRepository::class);
            $repository->expects(self::once())->method('findBy')->with($classes[$class]['criteria'])->willReturn($classes[$class]['found']);

            return $repository;
        });

        return new EntityLoader($em, self::fuzzphony());
    }

    public function testAnyIterableOfHitsIsAcceptedAndTheRankOrderIsKept(): void
    {
        $note = new Note('n-1', 'Note');
        $article = new Article(1, 'One');
        $loader = $this->loader([
            Article::class => ['type' => 'integer', 'found' => [$article], 'criteria' => ['id' => [1]]],
            Note::class => ['type' => 'string', 'found' => [$note], 'criteria' => ['id' => ['n-1']]],
        ], [spl_object_id($article) => 1, spl_object_id($note) => 'n-1']);
        $hits = (static function () {
            yield 5 => self::hit('notes', 'notes', 'n-1');
            yield 9 => self::hit('mixed', 'article', 1);
        })();

        self::assertSame([$note, $article], $loader->loadHits($hits));
    }

    public function testASourceTheIndexDoesNotKnowIsSkippedNotFatal(): void
    {
        $article = new Article(1, 'One');
        $loader = $this->loader([Article::class => ['type' => 'integer', 'found' => [$article], 'criteria' => ['id' => [1]]]], [spl_object_id($article) => 1]);

        self::assertSame([$article], $loader->loadHits([self::hit('mixed', 'ghost', 3), self::hit('mixed', 'free', 4), self::hit('mixed', 'article', 1)]));
    }

    public function testAClassWhoseIdsCannotFitDoesNotStopTheNextOne(): void
    {
        $article = new Article(1, 'One');
        $loader = $this->loader([
            Note::class => ['type' => 'integer', 'found' => [], 'criteria' => 'never'],
            Article::class => ['type' => 'integer', 'found' => [$article], 'criteria' => ['id' => [1]]],
        ], [spl_object_id($article) => 1]);

        self::assertSame([$article], $loader->loadHits([self::hit('notes', 'notes', 'not-a-number'), self::hit('mixed', 'article', 1)]));
    }

    public function testStringableAndOddIdentifiersPairOrMissLikeInLoad(): void
    {
        $byText = new Article(1, 'One');
        $odd = new Article(2, 'Two');
        $text = new class implements \Stringable {
            public function __toString(): string
            {
                return 'a-1';
            }
        };
        $loader = $this->loader([Article::class => ['type' => 'string', 'found' => [$byText, $odd], 'criteria' => ['id' => ['a-1', 'b-2']]]], [spl_object_id($byText) => $text, spl_object_id($odd) => new \stdClass()]);

        self::assertSame([$byText], $loader->loadHits([self::hit('mixed', 'article', 'a-1'), self::hit('mixed', 'article', 'b-2')]));
    }

    public function testIdsThatFitNoKeyAskTheRepositoryNothing(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $metadata->method('getTypeOfField')->willReturn('integer');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->expects(self::never())->method('getRepository');
        $result = new \Fuzzphony\Core\Search\SearchResult([self::hit('notes', 'notes', 'article-1')], 1, false, 0.1, false, [], 20, 0);

        self::assertSame([], (new EntityLoader($em))->load($result, Article::class));
    }
}

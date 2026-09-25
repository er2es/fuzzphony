<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\DoctrineNamingStrategy;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use PHPUnit\Framework\TestCase;

/**
 * DoctrineNamingStrategy's happy path (plain field -> column, single-column id, int id type) is
 * exercised end to end against a real EntityManager elsewhere (e.g. FuzzphonySearchFilterTest,
 * EntityLoaderTest). These tests cover the branches that need metadata shapes those fixtures don't
 * have: an association property, a composite identifier, and a uuid/guid identifier type.
 */
final class DoctrineNamingStrategyTest extends TestCase
{
    public function testColumnFallsBackToThePropertyNameWhenNeitherAFieldNorAnAssociation(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())->method('hasField')->with('virtual')->willReturn(false);
        $metadata->expects(self::once())->method('hasAssociation')->with('virtual')->willReturn(false);

        $strategy = new DoctrineNamingStrategy($this->entityManagerFor($metadata));

        self::assertSame('virtual', $strategy->column(Article::class, 'virtual'));
    }

    public function testColumnUsesTheJoinColumnForASingleValuedAssociation(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())->method('hasField')->with('brand')->willReturn(false);
        $metadata->expects(self::once())->method('hasAssociation')->with('brand')->willReturn(true);
        $metadata->expects(self::once())->method('isSingleValuedAssociation')->with('brand')->willReturn(true);
        $metadata->expects(self::once())->method('getSingleAssociationJoinColumnName')->with('brand')->willReturn('brand_id');

        $strategy = new DoctrineNamingStrategy($this->entityManagerFor($metadata));

        self::assertSame('brand_id', $strategy->column(Article::class, 'brand'));
    }

    public function testIdColumnRejectsACompositeIdentifier(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id', 'tenantId']);

        $strategy = new DoctrineNamingStrategy($this->entityManagerFor($metadata));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Article::class . ' has a composite identifier; Fuzzphony indexes need a single-column id.');

        $strategy->idColumn(Article::class);
    }

    /** @return iterable<string, array{string}> */
    public static function uuidLikeTypes(): iterable
    {
        yield 'uuid' => ['uuid'];
        yield 'guid' => ['guid'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uuidLikeTypes')]
    public function testIdTypeMapsUuidAndGuidFieldTypesToIdTypeUuid(string $doctrineType): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $metadata->expects(self::once())->method('getTypeOfField')->with('id')->willReturn($doctrineType);

        $strategy = new DoctrineNamingStrategy($this->entityManagerFor($metadata));

        self::assertSame(IdType::Uuid, $strategy->idType(Article::class));
    }

    /** @param ClassMetadata<object> $metadata */
    private function entityManagerFor(ClassMetadata $metadata): EntityManagerInterface
    {
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return $em;
    }
}

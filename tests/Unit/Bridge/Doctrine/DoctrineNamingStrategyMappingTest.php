<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\DoctrineNamingStrategy;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Table, column and id type names as the Doctrine mapping gives them. */
final class DoctrineNamingStrategyMappingTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function tables(): iterable
    {
        yield 'no schema' => [null, 'articles'];
        yield 'an empty schema is no schema' => ['', 'articles'];
        yield 'a schema qualifies the table' => ['blog', 'blog.articles'];
    }

    #[DataProvider('tables')]
    public function testTheTableNameCarriesTheSchemaOfTheMapping(?string $schema, string $expected): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getSchemaName')->willReturn($schema);
        $metadata->method('getTableName')->willReturn('articles');

        self::assertSame($expected, (new DoctrineNamingStrategy($this->entityManagerFor($metadata)))->table(Article::class));
    }

    public function testAMappedFieldIsTheColumnTheMappingNames(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getColumnName')->willReturnMap([['createdAt', 'created_at']]);
        $metadata->method('hasAssociation')->willReturn(true);
        $metadata->method('isSingleValuedAssociation')->willReturn(true);
        $metadata->method('getSingleAssociationJoinColumnName')->willReturn('must_not_be_used');

        self::assertSame('created_at', (new DoctrineNamingStrategy($this->entityManagerFor($metadata)))->column(Article::class, 'createdAt'));
    }

    /** @return iterable<string, array{string, IdType}> */
    public static function idTypes(): iterable
    {
        yield 'integer' => ['integer', IdType::Int];
        yield 'bigint' => ['bigint', IdType::Int];
        yield 'smallint' => ['smallint', IdType::Int];
        yield 'string' => ['string', IdType::String];
        yield 'ulid' => ['ulid', IdType::String];
        yield 'uuid' => ['uuid', IdType::Uuid];
    }

    #[DataProvider('idTypes')]
    public function testTheIdTypeFollowsTheMappedTypeOfTheIdentifier(string $doctrineType, IdType $expected): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $metadata->method('getTypeOfField')->willReturn($doctrineType);

        self::assertSame($expected, (new DoctrineNamingStrategy($this->entityManagerFor($metadata)))->idType(Article::class));
    }

    /** @param ClassMetadata<object> $metadata */
    private function entityManagerFor(ClassMetadata $metadata): EntityManagerInterface
    {
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return $em;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\TestCase;

/** Which of the mapped classes the discovery turns into index definitions. */
final class DoctrineIndexDiscoveryMutationsTest extends TestCase
{
    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private static function mapped(string $class, bool $mappedSuperclass = false): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->isMappedSuperclass = $mappedSuperclass;

        return $metadata;
    }

    /** @param list<ClassMetadata<object>> $all */
    private function discoveryOf(array $all): DoctrineIndexDiscovery
    {
        $stub = self::createStub(ClassMetadata::class);
        $stub->method('getSchemaName')->willReturn(null);
        $stub->method('getTableName')->willReturn('mapped_table');
        $stub->method('hasField')->willReturn(true);
        $stub->method('getColumnName')->willReturnArgument(0);
        $stub->method('getIdentifierFieldNames')->willReturn(['id']);
        $stub->method('getTypeOfField')->willReturn('integer');
        $factory = self::createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn($all);
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($factory);
        $em->method('getClassMetadata')->willReturn($stub);

        return new DoctrineIndexDiscovery($em);
    }

    public function testEveryMappedSearchableClassBecomesADefinitionInMappingOrder(): void
    {
        $definitions = $this->discoveryOf([self::mapped(Article::class), self::mapped(Product::class)])->discover();

        self::assertSame(['articles', 'products'], array_map(static fn(IndexDefinition $d): string => $d->name, $definitions));
        self::assertSame([0, 1], array_keys($definitions));
    }

    public function testAClassWithoutTheSearchableAttributeIsSkippedNotAnError(): void
    {
        $definitions = $this->discoveryOf([self::mapped(\stdClass::class), self::mapped(Article::class)])->discover();

        self::assertSame(['articles'], array_map(static fn(IndexDefinition $d): string => $d->name, $definitions));
    }

    public function testAMappedSuperclassIsSkippedEvenWhenItCarriesTheAttribute(): void
    {
        $definitions = $this->discoveryOf([self::mapped(Product::class, true), self::mapped(Article::class)])->discover();

        self::assertSame(['articles'], array_map(static fn(IndexDefinition $d): string => $d->name, $definitions));
    }
}

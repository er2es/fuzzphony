<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use PHPUnit\Framework\TestCase;

/** Which hit ids can be an id of the entity (shared by EntityLoader and FuzzphonySearchFilter). */
final class FittingIdsTest extends TestCase
{
    /** @return ClassMetadata<object> */
    private static function metadata(?string $type): ClassMetadata
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getTypeOfField')->willReturn($type);

        return $metadata;
    }

    public function testIntegerKeysTakeIntegersAndIntegerStringsInRange(): void
    {
        self::assertSame([1, '2', '-3'], EntityLoader::fittingIds(self::metadata('integer'), 'id', [1, 'article-1', '2', '1.5', '', '-3', '2147483648', 'x1']));
        self::assertSame([32767], EntityLoader::fittingIds(self::metadata('smallint'), 'id', [32767, 32768]));
        self::assertSame(['9223372036854775807'], EntityLoader::fittingIds(self::metadata('bigint'), 'id', ['9223372036854775807', '9223372036854775808']));
    }

    public function testUuidKeysTakeUuidStrings(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        self::assertSame([$uuid], EntityLoader::fittingIds(self::metadata('guid'), 'id', [$uuid, 'article-1', 5]));
        self::assertSame([$uuid], EntityLoader::fittingIds(self::metadata('uuid'), 'id', [$uuid, 'nope']));
    }

    public function testOtherKeyTypesAndUnknownTypesTakeEverything(): void
    {
        self::assertSame(['a-1', 2], EntityLoader::fittingIds(self::metadata('string'), 'id', ['a-1', 2]));
        self::assertSame(['a-1', 2], EntityLoader::fittingIds(self::metadata(null), 'id', ['a-1', 2]));
    }
}

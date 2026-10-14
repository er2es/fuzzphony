<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ConventionNamingStrategy;
use Fuzzphony\Core\Definition\IdType;
use PHPUnit\Framework\TestCase;

/** The id type the convention strategy reads off the $id property. */
final class ConventionNamingStrategyMutationsTest extends TestCase
{
    public function testAnIntIdPropertyIsAnIntId(): void
    {
        $class = new class {
            public int $id = 1;
        };

        self::assertSame(IdType::Int, (new ConventionNamingStrategy())->idType($class::class));
    }

    public function testAStringIdPropertyIsAStringId(): void
    {
        $class = new class {
            public string $id = 'a';
        };

        self::assertSame(IdType::String, (new ConventionNamingStrategy())->idType($class::class));
    }

    public function testAUnionTypedIdPropertyIsAStringId(): void
    {
        $class = new class {
            public int|string $id = 1;
        };

        self::assertSame(IdType::String, (new ConventionNamingStrategy())->idType($class::class));
    }

    public function testAClassWithoutAnIdPropertyDefaultsToAnIntId(): void
    {
        self::assertSame(IdType::Int, (new ConventionNamingStrategy())->idType(\stdClass::class));
    }
}

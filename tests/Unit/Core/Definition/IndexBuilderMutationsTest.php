<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\IndexDefinition;
use PHPUnit\Framework\TestCase;

final class IndexBuilderMutationsTest extends TestCase
{
    public function testLogWithoutAnArgumentTurnsLoggingBackOn(): void
    {
        $builder = IndexDefinition::builder('things')->field('name', 'A')->log(false);

        self::assertFalse($builder->build()->log);
        self::assertTrue($builder->log()->build()->log);
    }
}

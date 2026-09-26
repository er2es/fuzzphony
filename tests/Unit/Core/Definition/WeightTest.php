<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\TestCase;

final class WeightTest extends TestCase
{
    public function testParseAcceptsCasesAndLowercaseLabels(): void
    {
        self::assertSame(Weight::C, Weight::parse(Weight::C));
        self::assertSame(Weight::B, Weight::parse('b'));
    }

    public function testParseRejectsAnUnknownLabelWithTheAllowedOnes(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Unknown weight "E". Allowed: A, B, C, D.');

        Weight::parse('e');
    }
}

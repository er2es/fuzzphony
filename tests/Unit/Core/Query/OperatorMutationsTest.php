<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Filter\Operator;
use PHPUnit\Framework\TestCase;

final class OperatorMutationsTest extends TestCase
{
    public function testSpacesAroundAndInsideAnOperatorAreIgnored(): void
    {
        self::assertSame(Operator::Between, Operator::parse('  BETWEEN '));
        self::assertSame(Operator::NotIn, Operator::parse("\tNOT   IN\n"));
        self::assertSame(Operator::Neq, Operator::parse(' <> '));
        self::assertSame(Operator::Eq, Operator::parse(' == '));
    }
}

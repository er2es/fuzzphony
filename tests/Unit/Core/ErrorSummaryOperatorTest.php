<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core;

use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Support\ErrorSummary;
use PHPUnit\Framework\TestCase;

final class ErrorSummaryOperatorTest extends TestCase
{
    public function testARawDriverExceptionIsSummarisedNotPrintedWithItsMessage(): void
    {
        $raw = new \PDOException('SQLSTATE[22P02]: Invalid text representation: invalid input syntax for type integer: "card 4111111111111111"');

        $shown = ErrorSummary::forOperator($raw);

        self::assertStringNotContainsString('4111111111111111', $shown);
        self::assertStringStartsWith('PDOException', $shown);
    }

    public function testFuzzphonysOwnErrorKeepsItsMessage(): void
    {
        self::assertSame('Bad option.', ErrorSummary::forOperator(new InvalidConfiguration('Bad option.')));
    }
}

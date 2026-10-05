<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Ranking;

use Fuzzphony\Core\Ranking\TypoCurve;
use PHPUnit\Framework\TestCase;

final class TypoCurveTest extends TestCase
{
    public function testOneTypoFromFourLettersAndTwoFromEight(): void
    {
        // (n + 1 - 3 typos) / (n + 1 + 3 typos) + 0.03; 0.6 below four letters
        foreach ([0 => 0.6, 1 => 0.6, 3 => 0.6, 4 => 0.28, 5 => 0.3633, 6 => 0.43, 7 => 0.4845, 8 => 0.23, 9 => 0.28, 12 => 0.3984, 20 => 0.5856] as $length => $expected) {
            self::assertEqualsWithDelta($expected, TypoCurve::similarity($length), 0.0001, "length $length");
        }
    }

    public function testAnAsciiWordKeepsItsLengthThroughNormalisation(): void
    {
        foreach (range(1, 40) as $length) {
            self::assertSame(TypoCurve::similarity($length), TypoCurve::lowest($length, ascii: true), "length $length");
        }
    }

    public function testOtherTextMayChangeLengthSoTheNeighbouringLengthsCount(): void
    {
        // "straßen" is 7 characters but "strassen" (8) after normalisation: 0.23, not 0.48
        self::assertEqualsWithDelta(0.23, TypoCurve::lowest(7, ascii: false), 1e-9);
        // "mäßig" ... a four-letter word may shrink to three (0.6) or grow to six (0.43), but stays at least 0.28
        self::assertEqualsWithDelta(0.28, TypoCurve::lowest(4, ascii: false), 1e-9);
        self::assertEqualsWithDelta(0.6, TypoCurve::lowest(1, ascii: false), 1e-9, 'a one-letter word grows to at most three: still short');
        foreach (range(1, 64) as $length) {
            self::assertLessThanOrEqual(TypoCurve::similarity($length), TypoCurve::lowest($length, ascii: false), "length $length");
        }
    }
}

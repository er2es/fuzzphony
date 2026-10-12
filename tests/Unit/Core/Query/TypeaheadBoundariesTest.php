<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Typeahead;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeaheadBoundariesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function texts(): iterable
    {
        yield 'three quotes: still inside a phrase' => ['"a" "noise canc', '"a" "noise canc'];
        yield 'a newline before the last word' => ["wireless\nhea", "wireless\n(hea | hea*)"];
        yield 'a hyphen inside an excluded word' => ['mouse -wi-fi', 'mouse -wi-fi'];
        yield 'an exclusion after a no-break space' => ["mouse\u{00A0}-ca", "mouse\u{00A0}-ca*"];
    }

    #[DataProvider('texts')]
    public function testEdgeCasesOfTheLastWord(string $text, string $expected): void
    {
        self::assertSame($expected, Typeahead::lastWordAsPrefix($text));
    }
}

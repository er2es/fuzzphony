<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Exception;

use Fuzzphony\Core\Exception\InvalidQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidQueryHintsTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, string}> */
    public static function filters(): iterable
    {
        yield 'no filters at all' => ['price', [], 'Index "shop" has no filter "price". Known filters: (none).'];
        yield 'a typo' => ['prise', ['price', 'stock'], 'Index "shop" has no filter "prise". Did you mean "price"? Known filters: price, stock.'];
        yield 'upper case needle' => ['ABCD', ['abce'], 'Did you mean "abce"?'];
        yield 'upper case candidate' => ['abcd', ['ABCE'], 'Did you mean "ABCE"?'];
        yield 'the first of two equally close' => ['cat', ['bat', 'hat'], 'Did you mean "bat"?'];
        yield 'three edits away is still a hint' => ['abc', ['xyz'], 'Did you mean "xyz"?'];
        yield 'four edits away is not' => ['abc', ['wxyz'], 'Index "shop" has no filter "abc". Known filters: wxyz.'];
        yield 'the closest wins' => ['colour', ['coat', 'color'], 'Did you mean "color"?'];
    }

    /** @param list<string> $known */
    #[DataProvider('filters')]
    public function testUnknownFilterSuggestsTheClosestName(string $filter, array $known, string $message): void
    {
        self::assertStringContainsString($message, InvalidQuery::unknownFilter('shop', $filter, $known)->getMessage());
    }

    public function testNoHintMeansNoDidYouMean(): void
    {
        self::assertStringNotContainsString('Did you mean', InvalidQuery::unknownFilter('shop', 'zzzzzzzz', ['price'])->getMessage());
    }
}

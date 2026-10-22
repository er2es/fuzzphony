<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Engine\Postgres\Sql\Transpositions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranspositionsTest extends TestCase
{
    public function testEveryAdjacentSwapIsAVariantAndTheWordItselfNever(): void
    {
        self::assertSame(['omsue', 'msoue', 'mouse', 'moseu'], Transpositions::of('mosue'));
        self::assertSame(['ehdphones', 'hdephones', 'hepdhones', 'hedhpones', 'hedpohnes', 'hedphnoes', 'hedphoens', 'hedphonse'], Transpositions::of('hedphones'), 'n - 1 variants, in the order of the swap');
    }

    public function testTwoEqualNeighboursSwapIntoTheSameWordSoThatSwapIsSkipped(): void
    {
        // "aabb": the swaps of the two a's and of the two b's change nothing, the middle one gives "abab"
        self::assertSame(['abab'], Transpositions::of('aabb'));
        self::assertSame([], Transpositions::of('aaaa'));
    }

    public function testNoVariantRepeats(): void
    {
        foreach (['mouse', 'keyboard', 'abab', 'cacao', 'banana'] as $word) {
            $variants = Transpositions::of($word);
            self::assertSame($variants, array_values(array_unique($variants)), $word);
            self::assertNotContains($word, $variants);
        }
    }

    public function testAccentedLettersAreOneLetterEach(): void
    {
        self::assertSame(['rcème', 'cèrme', 'crmèe', 'crèem'], Transpositions::of('crème'));
    }

    /** @return iterable<string, array{string}> */
    public static function notEligible(): iterable
    {
        yield 'three letters' => ['cab'];
        yield 'sixteen letters' => ['abcdefghijklmnop'];
        yield 'a code with digits' => ['rtx4090'];
        yield 'only digits' => ['1054'];
        yield 'a hyphen' => ['wi-fi'];
        yield 'two words' => ['usb cable'];
        yield 'empty' => [''];
    }

    #[DataProvider('notEligible')]
    public function testOnlyAWordOfFourToFifteenLettersHasVariants(string $word): void
    {
        self::assertSame([], Transpositions::of($word));
    }

    public function testTheBoundsAreInclusive(): void
    {
        self::assertCount(3, Transpositions::of('abcd'));
        self::assertCount(14, Transpositions::of('abcdefghijklmno'));
        self::assertSame(4, Transpositions::MIN_LENGTH);
        self::assertSame(15, Transpositions::MAX_LENGTH);
        self::assertSame(48, Transpositions::MAX_PER_QUERY);
    }
}

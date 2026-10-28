<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Engine\Postgres\VocabularyService;
use PHPUnit\Framework\TestCase;

/** The edit distance "did you mean" ranks the vocabulary words by: characters, however many different ones the two words have. */
final class VocabularyDistanceTest extends TestCase
{
    private static function distance(string $a, string $b): int
    {
        return VocabularyService::distance($a, $b);
    }

    /** @return string a word of $count different characters (private use area) */
    private static function wordOfDifferentCharacters(int $count): string
    {
        $word = '';
        for ($i = 0; $i < $count; ++$i) {
            $word .= mb_chr(0xE000 + $i);
        }

        return $word;
    }

    public function testTheDistanceCountsCharactersNotBytes(): void
    {
        self::assertSame(0, self::distance('кошка', 'кошка'));
        self::assertSame(1, self::distance('кошка', 'кошки'));
        self::assertSame(1, self::distance('mouse', 'mose'));
        self::assertSame(3, self::distance('abc', 'xyz'));
    }

    public function testAWordOf254DifferentCharactersStaysDistinctFromItsOwnFirstCharacter(): void
    {
        $word = self::wordOfDifferentCharacters(254);
        // the same word with its last character replaced by its first: one edit away, however many characters came between them
        $other = mb_substr($word, 0, 253) . mb_substr($word, 0, 1);

        self::assertSame(1, self::distance($word, $other));
    }

    public function testAWordOf129DifferentCharactersStaysDistinctFromItsOwnFirstCharacter(): void
    {
        $word = self::wordOfDifferentCharacters(129);
        $other = mb_substr($word, 0, 128) . mb_substr($word, 0, 1);

        self::assertSame(1, self::distance($word, $other));
    }
}

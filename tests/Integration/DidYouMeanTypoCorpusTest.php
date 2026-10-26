<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Which typos of 4 to 10 letter words get a "did you mean": a letter replaced, missing or extra in the middle, and two
 * neighbouring letters swapped. The candidates are the vocabulary words with a trigram similarity of 0.25 or more (it was 0.3,
 * which a letter replaced in the middle of a 4-letter word, 0.25, and a swap in the middle of a 6-letter word, 0.27, did not
 * reach) and, whatever their similarity, the words a typed word becomes with two neighbouring letters swapped (a swap in a short
 * word leaves a few of its trigrams: `mosue` shares two of ten with `mouse`). The edit distance rule (a third of the word's
 * length, at least one) counts a swap as one edit (optimal string alignment), as the search itself does (see TranspositionTest).
 */
final class DidYouMeanTypoCorpusTest extends TestCase
{
    private const array WORDS = ['lamp', 'desk', 'sofa', 'mouse', 'chair', 'table', 'plant', 'kettle', 'blender', 'monitor', 'speaker', 'printer', 'toaster', 'charger', 'keyboard', 'notebook', 'backpack', 'umbrella', 'headphones', 'microwave', 'projector', 'flashlight'];

    /** The typos that have one since the candidate similarity went from 0.3 to 0.25. */
    private const array NEW_SUGGESTION = ['laxp', 'lap', 'dexk', 'dek', 'soxa', 'soa', 'ketlte'];

    private function fuzzphony(): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        $products = [];
        foreach (self::WORDS as $i => $word) {
            $products[] = [$i + 1, 'Great ' . $word . ' deal', 'x', 1, 1_000, true, 1.0, 'now'];
        }
        PostgresTestCase::createFixtures($connection, ['brands' => [[1, 'Acme']], 'products' => $products]);
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    /** @return array<string, array{string, string, string}> typo => [type, word, typo] */
    private static function typos(): array
    {
        $typos = [];
        foreach (self::WORDS as $word) {
            $i = intdiv(strlen($word), 2);
            $replaced = $word;
            $replaced[$i] = 'x';
            $swapped = $word;
            [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
            foreach (['substitution' => $replaced, 'deletion' => substr($word, 0, $i) . substr($word, $i + 1), 'insertion' => substr($word, 0, $i) . 'q' . substr($word, $i), 'transposition' => $swapped] as $type => $typo) {
                $typos[$typo] = [$type, $word, $typo];
            }
        }

        return $typos;
    }

    public function testTheCorpusIsAsBigAsItClaims(): void
    {
        self::assertCount(88, self::typos());
        self::assertSame([4, 10], [min(array_map('strlen', self::WORDS)), max(array_map('strlen', self::WORDS))]);
    }

    public function testEveryTypoOfTheCorpusGetsItsWordOrNone(): void
    {
        $search = $this->fuzzphony()->in('products');
        $got = [];
        foreach (self::typos() as [, $word, $typo]) {
            $result = $search->query($typo)->thresholds(['rescue_empty' => false, 'relax_when_empty' => false])->get();
            $got[$typo] = $result->didYouMean === null ? null : $word === $result->didYouMean;
        }

        // the four kinds of one-edit typo of every word, the swaps of 4 and 5 letter words included, all give the right word
        self::assertSame([], array_keys(array_filter($got, static fn(?bool $right): bool => $right === null)), 'which typos get no suggestion');
        self::assertSame([], array_keys(array_filter($got, static fn(?bool $right): bool => $right === false)), 'a wrong suggestion');
        foreach (self::NEW_SUGGESTION as $typo) {
            self::assertTrue($got[$typo], $typo . ' gets a suggestion since the candidates start at 0.25');
        }
    }

    public function testNoiseGetsNoSuggestion(): void
    {
        $search = $this->fuzzphony()->in('products');

        foreach (['qzxvb', 'zzzzzz', 'plmokn', 'wqrtyp', 'ghjkl', 'xyzzy'] as $noise) {
            self::assertNull($search->query($noise)->get()->didYouMean, $noise);
        }
    }
}

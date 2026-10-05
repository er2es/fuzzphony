<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The default typo tolerance depends on the length of the word; an explicit similarity stays flat. */
final class LengthAwareTypoToleranceTest extends TestCase
{
    private function fuzzphony(): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Logitech'], [2, 'Sony']],
            'products' => [
                [1, 'Wireless mouse', 'Silent wireless mouse', 1, 3_990, true, 5.0, 'now'],
                [2, 'Mouse pad', 'Large mouse pad', 1, 2_990, true, 1.0, 'now'],
                [3, 'Curved monitor', 'Wide curved monitor', 2, 59_990, true, 3.0, 'now'],
                [4, 'Lawn mower', 'Petrol lawn mower', 2, 99_990, true, 2.0, 'now'],
                [5, 'Wireless headphones', 'Noise cancelling headphones', 2, 49_990, true, 7.0, 'now'],
                [6, 'Strand chair', 'Folding beach chair', 2, 12_990, true, 1.0, 'now'],
            ],
        ]);
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    /** @return list<int|string> */
    private static function ids(SearchResult $result): array
    {
        $ids = $result->ids();
        sort($ids);

        return $ids;
    }

    public function testACorrectlySpelledShortWordNoLongerMatchesSimilarWords(): void
    {
        $search = $this->fuzzphony()->in('products');
        $always = ['fuzzy_mode' => 'always'];

        self::assertSame([1, 2], self::ids($search->query('mouse')->thresholds($always)->get()), 'not the monitor, not the mower');
        self::assertSame([1, 2, 3, 4], self::ids($search->query('mouse')->thresholds($always + ['fuzzy_similarity' => 0.3])->get()), 'a flat 0.3 keeps the lenient behaviour');
        self::assertSame([1, 2], self::ids($search->query('mouse')->thresholds($always + ['fuzzy_similarity' => null])->get()), 'null goes back to by length');
    }

    public function testTyposStillMatchWithinTheirBand(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([1, 2], self::ids($search->query('mouze')->get()), 'five letters, one wrong');
        // four letters tolerate one typo. "mose" is as close to "monitor" and "mower" as to "mouse" (a trigram cannot tell
        // them apart), so they come too, ranked below the mice
        $mose = $search->query('mose')->get();
        self::assertSame([1, 2, 3, 4], self::ids($mose));
        $top = array_slice($mose->ids(), 0, 2);
        sort($top);
        self::assertSame([1, 2], $top);
        self::assertSame([5], self::ids($search->query('hedphones')->get()), 'a long word keeps the 0.3 band');
        self::assertSame([1, 5], self::ids($search->query('wireles')->get()), 'seven letters');
        self::assertSame([3], self::ids($search->query('moitor')->get()), 'six letters');
    }

    public function testTheLengthIsThatOfTheNormalisedWord(): void
    {
        $search = $this->fuzzphony()->in('products')->query('straßen')->thresholds(['fuzzy_mode' => 'always']);

        // "straßen" has 7 characters but is "strassen" (8) once PostgreSQL folds it, and 8 letters tolerate two typos:
        // "strand" is 0.44 similar, which the 7-letter threshold (0.48) would reject
        self::assertSame([6], self::ids($search->get()));
    }

    public function testEveryWordIsCheckedAgainstItsOwnLength(): void
    {
        $result = $this->fuzzphony()->in('products')->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])->get();

        self::assertSame([1], self::ids($result), 'the typo in the long word is tolerated, the monitor is not a mouse');
    }

    public function testAFieldScopedWordUsesItsLengthToo(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([3, 4, 5, 6], self::ids($search->query('brand:sonny')->get()), 'five letters, one extra: the Sony brand');
        // the scoped word is checked on the brand column only: "sonny" is not in the name or the description
        self::assertSame([], self::ids($search->query('name:sonny')->get()));
    }

    public function testTheRelaxationProbeAppliesTheSameSimilarity(): void
    {
        $search = $this->fuzzphony()->in('products');

        // "mouse" matches 1 and 2 only; "monitor"'s neighbour must not make the probe keep a word that matches nothing
        $result = $search->query('headphones mowse zzqq')->get();
        self::assertSame([], self::ids($result), 'three words that never meet: no relaxation can keep a subset that matches');

        $relaxed = $search->query('wireless mouse zzqqx')->get();
        self::assertSame([1], self::ids($relaxed));
        self::assertStringContainsString('zzqqx', implode(' ', $relaxed->warnings));
    }
}

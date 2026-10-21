<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Two neighbouring letters swapped (`mosue`) on the typo-tolerant branch: found as the whole word, ranked below an exact hit. */
final class TranspositionTest extends TestCase
{
    private function fuzzphony(bool $tenant = false): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Logitech'], [2, 'Sony']],
            'products' => [
                [1, 'Wireless mouse', 'Silent wireless mouse', 1, 3_990, true, 5.0, 'now'],
                [2, 'Mouse pad', 'Large mouse pad with a wide surface', 1, 2_990, true, 1.0, 'now'],
                [3, 'Curved monitor', 'Wide curved monitor', 2, 59_990, true, 3.0, 'now'],
                [4, 'Lawn mower', 'Petrol lawn mower', 2, 99_990, true, 2.0, 'now'],
                [5, 'Wireless headphones', 'Noise cancelling headphones', 2, 49_990, true, 7.0, 'now'],
                [6, 'Mosue trap', 'A typo that lives in the data', 2, 990, true, 1.0, 'now'],
                [7, 'Café table', 'Small round table', 1, 19_990, true, 1.0, 'now'],
                [8, 'Torch', 'A bright torch', 1, 1_990, true, 1.0, 'now'],
            ],
        ]);
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    /** @return list<int|string> */
    private static function sorted(SearchResult $result): array
    {
        $ids = $result->ids();
        sort($ids);

        return $ids;
    }

    public function testASwapInAFiveLetterWordNowFindsTheWord(): void
    {
        $search = $this->fuzzphony()->in('products');

        $result = $search->query('mosue')->get();

        // "mouse" (1, 2: name and description), and the product that spells it "Mosue" (6) exactly
        self::assertSame([1, 2, 6], self::sorted($result));
        self::assertTrue($result->usedFuzzy);
    }

    public function testAnExactHitRanksAboveATranspositionHit(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mosue')->get();

        self::assertSame(6, $result->hits[0]->id, 'the document that has the word as typed is first');
        $exact = $result->hits[0]->breakdown;
        self::assertGreaterThan(0.0, $exact->textRank);
        foreach (array_slice($result->hits, 1) as $hit) {
            self::assertSame(0.0, $hit->breakdown->textRank, 'found by the swap alone');
            self::assertGreaterThan(0.0, $hit->breakdown->fuzzySimilarity);
            self::assertLessThan($exact->fuzzySimilarity, $hit->breakdown->fuzzySimilarity);
            self::assertLessThan($result->hits[0]->score, $hit->score);
        }
    }

    public function testATranspositionScoresTheLeastAnOrdinaryTypoOfTheWordMayScore(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mosue')->get();
        $swap = $result->hits[1]->breakdown->fuzzySimilarity;

        // five letters: one typo is tolerated down to (5 + 1 - 3) / (5 + 1 + 3) + 0.03
        self::assertEqualsWithDelta(3 / 9 + 0.03, $swap, 0.0001);

        $flat = $this->fuzzphony()->in('products')->query('mosue')->thresholds(['fuzzy_similarity' => 0.5])->get();
        self::assertEqualsWithDelta(0.5, $flat->hits[1]->breakdown->fuzzySimilarity, 0.0001);
    }

    public function testASwapInALongerWordAndInTwoWords(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([5], self::sorted($search->query('hedaphones')->get()));
        self::assertSame([5], self::sorted($search->query('wirelses hedaphones')->get()));
        self::assertSame([1], self::sorted($search->query('wireless mosue')->get()), 'the other words must still match');
        self::assertSame([8], self::sorted($search->query('troch -mouse')->get()), 'torch, with its neighbours r and o swapped');
        self::assertSame([8], self::sorted($search->query('torhc')->get()), 'torch: found before, found now');
    }

    public function testCaseAndAccentsAreFolded(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([1, 2, 6], self::sorted($search->query('MOSUE')->get()));
        self::assertSame([7], self::sorted($search->query('cfaé')->get()), 'café, typed swapped');
        self::assertSame([7], self::sorted($search->query('cfae')->get()), 'and without the accent');
    }

    public function testOnlyTheTypoTolerantFieldsAreReached(): void
    {
        $search = $this->fuzzphony()->in('products');

        // "wide" occurs only in descriptions, which are not typo-tolerant
        self::assertSame([], $search->query('wdie')->get()->ids());
        self::assertSame([2, 3], self::sorted($search->query('wide')->thresholds(['fuzzy_mode' => 'never'])->get()), 'as typed it is found there');
        // "sony" is a brand, which is typo-tolerant; a word scoped to another field does not look there
        self::assertSame([3, 4, 5, 6], self::sorted($search->query('snoy')->get()));
        self::assertSame([3, 4, 5, 6], self::sorted($search->query('brand:snoy')->get()));
        self::assertSame([], $search->query('name:snoy')->get()->ids());
    }

    public function testASearchWithEnoughExactHitsIsUnchanged(): void
    {
        $search = $this->fuzzphony()->in('products');

        // "mouse" finds 1 and 2 exactly: with fallback_below 1 the typo-tolerant branch never runs, so product 6 ("Mosue", the
        // swap of the typed word) is not added; typo tolerance ran for it only in 'always'
        self::assertSame([1, 2], self::sorted($search->query('mouse')->thresholds(['fallback_below' => 1])->get()));
        $always = $search->query('mouse')->thresholds(['fuzzy_mode' => 'always'])->get();
        self::assertSame([1, 2, 6], self::sorted($always));
        self::assertSame(6, $always->hits[2]->id, 'the swapped one comes after both exact hits');
        self::assertSame([1, 2], self::sorted($search->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->get()));
    }

    public function testItNeedsTypoToleranceToRun(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([6], $search->query('mosue')->thresholds(['fuzzy_mode' => 'never'])->get()->ids(), 'never: only the document that has the word as typed');
        self::assertSame([6], $search->query('mosue')->ranking(['fuzzy' => 0.0])->get()->ids(), 'a profile without typo tolerance');
        self::assertSame([6], $search->query('mosue')->thresholds(['fuzzy_min_length' => 6])->get()->ids(), 'the word is shorter than fuzzy_min_length');
    }

    public function testATenantScopedIndexFindsTheSwapInItsOwnRowsOnly(): void
    {
        $search = $this->fuzzphony(tenant: true)->in('products');

        // brand 1 (Logitech) has the mouse and the mouse pad, brand 2 the typo in the data
        self::assertSame([1, 2], self::sorted($search->forTenant(1)->query('mosue')->get()));
        self::assertSame([6], self::sorted($search->forTenant(2)->query('mosue')->get()));
        self::assertSame([], $search->forTenant(2)->query('hedaphones')->where('price', '<', 1)->get()->ids());
        self::assertNull($search->forTenant(1)->query('mosue')->get()->didYouMean, 'no vocabulary on a tenant-scoped index');
    }

    public function testARestrictedViewerSeesTheSwapOnlyInTheFieldsItMaySee(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([3, 4, 5, 6], self::sorted($search->query('snoy')->get()));
        self::assertSame([3, 4, 5, 6], self::sorted($search->restrictedToFields('brand')->query('snoy')->get()));
        self::assertSame([], $search->restrictedToFields('name')->query('snoy')->get()->ids(), 'the brand is hidden: its words decide nothing');
        self::assertSame([], $search->restrictedToFields('description')->query('snoy')->get()->ids(), 'and the description is not typo-tolerant');
        self::assertSame([1, 2, 6], self::sorted($search->restrictedToFields('name')->query('mosue')->get()));
    }

    public function testAFacetCountsTheSameDocumentsAsTheResult(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mosue')->facets('in_stock')->get();

        self::assertSame(3, $result->total);
        self::assertSame(3, $result->facets['in_stock'][0]->count);
    }

    public function testAProductCodeIsNotSwapped(): void
    {
        $statements = $this->fuzzphony()->in('products')->query('rtx4090 mosue')->explain()->statements;

        $fallback = array_values(array_filter($statements, static fn(array $s): bool => $s['label'] === 'fallback: full-text + fuzzy'));
        self::assertSame(1, substr_count($fallback[0]['sql'], ' AS tv'), 'the code has no variants (rtx4900 is another product), the word has');
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The word a typo most likely meant (the did-you-mean correction: fewest edits, a swap counting as one, then clearly the most documents)
 * ranks the documents that have it above the look-alikes of the typo. Only the order changes: the hits, the total and the facets stay.
 */
final class TypoCorrectionRankTest extends TestCase
{
    /** @param list<list<mixed>> $extra */
    private function fuzzphony(array $extra = [], bool $tenant = false): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Logitech'], [2, 'Sony']],
            'products' => [
                [1, 'Wireless mouse', 'Silent wireless', 1, 3_990, true, 1.0, 'now'],
                [2, 'Mouse pad', 'Large pad with a wide surface', 1, 2_990, false, 1.0, 'now'],
                [3, 'Mosquito net', 'Keeps the bugs away', 2, 990, true, 1.0, 'now'],
                [4, 'Lawn mower', 'Petrol lawn mower', 2, 99_990, true, 1.0, 'now'],
                [5, 'Moose figure', 'A wooden figure', 2, 1_990, true, 1.0, 'now'],
                ...$extra,
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

    /** @return list<int|string> the ids of the first $n hits, sorted */
    private static function top(SearchResult $result, int $n): array
    {
        $ids = array_slice($result->ids(), 0, $n);
        sort($ids);

        return $ids;
    }

    /** The ranked statement looks a correction up (its q column cw<n>). */
    private static function corrects(Fuzzphony $fuzzphony, string $query, ?array $thresholds = null): bool
    {
        $search = $fuzzphony->in('products')->query($query);
        $statements = ($thresholds === null ? $search : $search->thresholds($thresholds))->explain()->statements;

        return array_any($statements, static fn(array $s): bool => preg_match('/\bcw\d+\b/', (string) $s['sql']) === 1);
    }

    public function testASwappedWordRanksItsWordAboveALookAlike(): void
    {
        $fuzzphony = $this->fuzzphony();
        $result = $fuzzphony->in('products')->query('mosue')->facets('in_stock')->get();
        $before = $fuzzphony->in('products')->query('mosue')->facets('in_stock')->thresholds(['rescue' => ['did_you_mean' => false, 'correct_when_empty' => false]])->get();

        self::assertSame('mouse', $result->didYouMean);
        self::assertSame(3, $before->hits[0]->id, 'without the correction the look-alike (mosquito, more trigrams in common) is first');
        self::assertSame([1, 2], self::top($result, 2), 'the documents with "mouse" first');
        self::assertSame(3, $result->hits[2]->id);
        self::assertSame(self::sorted($before), self::sorted($result), 'the same hits');
        self::assertSame($before->total, $result->total);
        self::assertEquals($before->facets, $result->facets);
    }

    public function testAMuchMoreFrequentWordWinsATieOfEdits(): void
    {
        // mose: one edit from both mouse (2 documents) and moose (1); moose shares more trigrams
        $fuzzphony = $this->fuzzphony();
        $result = $fuzzphony->in('products')->query('mose')->get();
        self::assertSame([1, 2], self::top($result, 2));
        self::assertNotContains($result->hits[2]->id, [1, 2]);
        self::assertTrue(self::corrects($fuzzphony, 'mose'));
    }

    public function testATieOfEditsAndOfFrequencyCorrectsNothing(): void
    {
        $fuzzphony = $this->fuzzphony([[6, 'Moose antlers', 'Antlers', 2, 990, true, 1.0, 'now']]);
        self::assertFalse(self::corrects($fuzzphony, 'mose'), 'mouse and moose are equally likely');
        self::assertSame($fuzzphony->in('products')->query('mose')->thresholds(['rescue' => ['did_you_mean' => false, 'correct_when_empty' => false]])->get()->ids(), $fuzzphony->in('products')->query('mose')->get()->ids());
    }

    public function testAKnownWordAndANearlyExactSearchAreNotCorrected(): void
    {
        $fuzzphony = $this->fuzzphony();
        self::assertFalse(self::corrects($fuzzphony, 'mouse', ['typo' => ['mode' => 'always']]));
        self::assertFalse(self::corrects($fuzzphony, 'mosquito mosue', ['rescue' => ['did_you_mean' => false, 'correct_when_empty' => false]]), 'no did-you-mean, no correction');
        self::assertFalse(self::corrects($fuzzphony, 'mosue', ['relevance' => ['min_score' => 0.1]]), 'a raised score could pass min_score: the hits would change');
    }

    public function testARestrictedViewerAndATenantGetNoCorrection(): void
    {
        $fuzzphony = $this->fuzzphony();
        $statements = $fuzzphony->in('products')->restrictedToFields('name')->query('mosue')->explain()->statements;
        self::assertFalse(array_any($statements, static fn(array $s): bool => preg_match('/\bcw\d+\b/', (string) $s['sql']) === 1), 'the vocabulary holds the hidden fields');

        $tenant = $this->fuzzphony(tenant: true);
        $statements = $tenant->in('products')->forTenant(1)->query('mosue')->explain()->statements;
        self::assertFalse(array_any($statements, static fn(array $s): bool => preg_match('/\bcw\d+\b/', (string) $s['sql']) === 1), 'the vocabulary holds every tenant');
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use Fuzzphony\Tests\Unit\Core\Search\RecordingSearchLog;
use PHPUnit\Framework\TestCase;

/**
 * A search that found nothing, with a word that has exactly one vocabulary word one edit away (`mpuse` -> `mouse`), is run
 * once more with the corrected words and answers with that, flagged.
 */
final class ZeroResultRescueTest extends TestCase
{
    private RecordingMetricsCollector $metrics;
    private RecordingSearchLog $log;

    private function counted(string $event): int
    {
        return count(array_filter($this->metrics->calls, static fn(array $call): bool => $call[0] === 'increment' && $call[1] === $event));
    }

    private function fuzzphony(bool $tenant = false, ?Thresholds $thresholds = null): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Logitech'], [2, 'Sony'], [3, 'Acme']],
            'products' => [
                [1, 'Wireless mouse', 'Silent wireless mouse', 1, 3_990, true, 5.0, 'now'],
                [2, 'Mouse pad', 'Large mouse pad', 1, 2_990, false, 1.0, 'now'],
                [3, 'Curved monitor', 'Wide curved monitor', 2, 59_990, true, 3.0, 'now'],
                [4, 'Lawn mower', 'Petrol lawn mower', 2, 99_990, true, 2.0, 'now'],
                [5, 'Wireless headphones', 'Noise cancelling headphones', 2, 49_990, true, 7.0, 'now'],
                [6, 'Zebra print rug', 'Green and leafy', 3, 1_990, true, 1.0, 'now'],
                [7, 'Zabra tent', 'For two', 3, 8_990, true, 1.0, 'now'],
            ],
        ]);
        $this->metrics = new RecordingMetricsCollector();
        $this->log = new RecordingSearchLog();
        $index = Indexes::products('manual', tenant: $tenant);
        if ($thresholds !== null) {
            $index = $index->withThresholds($thresholds);
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection, metrics: $this->metrics), new IndexRegistry([$index]), new SearchGate(log: $this->log));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testASearchThatFoundNothingAnswersWithTheCorrectedOne(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mpuse')->get();

        self::assertEqualsCanonicalizing([1, 2], $result->ids());
        self::assertSame(2, $result->total);
        self::assertSame('mpuse', $result->correctedFrom);
        self::assertSame('mouse', $result->interpretedAs);
        self::assertContains("No results for 'mpuse'; showing results for 'mouse'.", $result->warnings);
        self::assertNull($result->didYouMean, 'the suggestion was taken');
    }

    public function testASearchWithResultsIsNeverRescued(): void
    {
        $search = $this->fuzzphony()->in('products');

        foreach (['mouse', 'mouze', 'wireles', 'hedphones'] as $text) {
            $result = $search->query($text)->get();
            self::assertNotSame([], $result->ids(), $text);
            self::assertNull($result->correctedFrom, $text);
        }
        self::assertSame(0, $this->counted('fuzzphony.search.rescued'));
    }

    public function testASearchThatFoundNothingAndHasNoCorrectionStaysEmpty(): void
    {
        $search = $this->fuzzphony()->in('products');

        foreach (['qqqqzzzz', 'mxxse', 'zzqqx'] as $text) {
            $result = $search->query($text)->get();
            self::assertSame([], $result->ids(), $text);
            self::assertNull($result->correctedFrom, $text);
        }
    }

    public function testATieBetweenTwoCandidatesIsNoRescue(): void
    {
        // "zxbra" is one edit from "zebra" and from "zabra" (a replaced letter in the middle: no typo-tolerant hit), no guess
        $result = $this->fuzzphony()->in('products')->query('zxbra')->get();

        self::assertSame([], $result->ids());
        self::assertNull($result->correctedFrom);
        self::assertNotNull($result->didYouMean, 'the suggestion (the more frequent word) is as it was');
    }

    public function testEveryWordThatNeedsACorrectionMustHaveExactlyOne(): void
    {
        $search = $this->fuzzphony()->in('products');

        $both = $search->query('mpuse pda')->get(); // "pda" is a three-letter word: left as typed, no rescue for it
        // "pda" has no correction and stays as typed; "mouse pda" finds nothing together, so the relaxation drops it, as for any search
        self::assertSame([1, 2], $this->sorted($both->ids()));
        self::assertSame('mpuse pda', $both->correctedFrom);
        // the relaxation comes first: "pad" is found, so "mpuse" is ignored and the search had a result (no rescue)
        $relaxedFirst = $search->query('mpuse pad')->get();
        self::assertSame([2], $relaxedFirst->ids());
        self::assertNull($relaxedFirst->correctedFrom);
        self::assertContains('mouse pad', [$relaxedFirst->didYouMean]);
        $ok = $search->query('mpuse pad')->thresholds(['relax_when_empty' => false])->get();
        self::assertSame([2], $ok->ids());
        self::assertSame('mpuse pad', $ok->correctedFrom);
        self::assertSame('(mouse AND pad)', $ok->interpretedAs);
        // a tie in one word blocks the rescue of the whole query
        self::assertSame([], $search->query('mpuse zxbra')->get()->ids());
        // a word that has no correction stays as typed; the relaxation then drops it, as for any search
        $relaxed = $search->query('mpuse zzqqx')->get();
        self::assertSame([1, 2], $this->sorted($relaxed->ids()));
        self::assertSame('mpuse zzqqx', $relaxed->correctedFrom);
        self::assertStringContainsString('zzqqx', implode(' ', $relaxed->warnings));
    }

    public function testOperatorsQuotesAndFieldsStayWhereTheyWere(): void
    {
        $search = $this->fuzzphony()->in('products');

        self::assertSame([2], $search->query('mpuse -wireless')->get()->ids());
        self::assertSame('(mouse AND NOT wireless)', $search->query('mpuse -wireless')->get()->interpretedAs);
        self::assertSame([1, 2], $this->sorted($search->query('name:mpuse')->get()->ids()));
        self::assertSame('name:mouse', $search->query('name:mpuse')->get()->interpretedAs);
        // an OR with a word that is found has results: no rescue
        self::assertNull($search->query('wireless | mpuse')->get()->correctedFrom);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int|string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function testFiltersFacetsAndPagingAreTheOriginalSearchs(): void
    {
        $search = $this->fuzzphony()->in('products');

        $filtered = $search->query('mpuse')->where('in_stock', true)->facets('in_stock')->get();
        self::assertSame([1], $filtered->ids());
        self::assertSame('mpuse', $filtered->correctedFrom);
        self::assertSame(1, $filtered->facets['in_stock'][0]->count);

        $faceted = $search->query('mpuse')->facets('in_stock', 'price')->get();
        self::assertSame(2, $faceted->total);
        self::assertSame(['in_stock', 'price'], array_keys($faceted->facets));

        $paged = $search->query('mpuse')->limit(1, 1)->get();
        self::assertCount(1, $paged->hits);
        self::assertSame(2, $paged->total);
        self::assertSame(2, $paged->page());

        // the corrected search finds nothing either (the filter): the original, empty answer stands
        $none = $search->query('mpuse')->where('price', '>', 1_000_000)->get();
        self::assertSame([], $none->ids());
        self::assertNull($none->correctedFrom);
        self::assertSame('mouse', $none->didYouMean);
        self::assertSame('mpuse', $none->interpretedAs);
    }

    public function testHighlightsComeFromTheCorrectedSearch(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mpuse -wireless')->highlight('name')->get();

        self::assertSame(['name' => '<mark>Mouse</mark>'], $result->hits[0]->highlights);
    }

    public function testItIsSwitchedOffByRescueEmptyAndDependsOnDidYouMean(): void
    {
        $off = $this->fuzzphony()->in('products')->query('mpuse')->thresholds(['rescue_empty' => false])->get();
        self::assertSame([], $off->ids());
        self::assertNull($off->correctedFrom);
        self::assertSame('mouse', $off->didYouMean, 'the suggestion stays');

        $indexOff = $this->fuzzphony(thresholds: new Thresholds(rescueEmpty: false))->in('products');
        self::assertSame([], $indexOff->query('mpuse')->get()->ids());
        self::assertSame([1, 2], $this->sorted($indexOff->query('mpuse')->thresholds(['rescue_empty' => true])->get()->ids()), 'a query turns it back on');

        // typo tolerance switched off (the mode, or a profile without fuzzy weight): the rescue is a typo correction too
        $never = $this->fuzzphony()->in('products');
        self::assertSame([], $never->query('mpuse')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
        self::assertSame([], $never->query('mpuse')->ranking(['fuzzy' => 0.0])->get()->ids());
        self::assertSame([1, 2], $this->sorted($never->query('mpuse')->thresholds(['fuzzy_mode' => 'always'])->get()->ids()), 'always: rescued like fallback');

        $noMean = $this->fuzzphony()->in('products')->query('mpuse')->thresholds(['did_you_mean' => false])->get();
        self::assertSame([], $noMean->ids(), 'it uses the did-you-mean lookup, so it needs did_you_mean');
        self::assertNull($noMean->correctedFrom);
        self::assertNull($noMean->didYouMean);
    }

    public function testATenantScopedIndexIsNeverRescued(): void
    {
        $search = $this->fuzzphony(tenant: true)->in('products');

        $result = $search->forTenant(1)->query('mpuse')->get();
        self::assertSame([], $result->ids());
        self::assertNull($result->correctedFrom);
        // the words of another tenant's documents are never a candidate: "pad" is brand 1's, "plant" brand 3's
        self::assertSame([], $search->forTenant(3)->query('zxbra')->get()->ids());
        self::assertSame([], $search->forTenant(1)->query('zxbra')->get()->ids());
    }

    public function testARestrictedViewerIsNeverRescued(): void
    {
        $search = $this->fuzzphony()->in('products');

        foreach (['name', 'brand', 'description'] as $field) {
            $result = $search->restrictedToFields($field)->query('mpuse')->get();
            self::assertSame([], $result->ids(), $field);
            self::assertNull($result->correctedFrom, $field);
            self::assertNull($result->didYouMean, $field);
        }
        // and what a restricted viewer is told does not depend on a hidden field: the word "zebra" is in the name only
        $hidden = $search->restrictedToFields('brand')->query('zxbra')->get();
        self::assertSame([], $hidden->ids());
        self::assertNull($hidden->correctedFrom);
        self::assertSame([], $hidden->warnings);
    }

    public function testASearchAsYouTypeIsNeverRescued(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mpuse')->asYouType()->get();

        self::assertNull($result->correctedFrom);
        self::assertSame([], $result->ids());
    }

    public function testTheSearchLogRecordsWhatTheVisitorTypedWithTheHitsOfTheRescue(): void
    {
        $this->fuzzphony()->in('products')->query('mpuse')->get();

        self::assertCount(1, $this->log->records);
        $record = $this->log->records[0];
        self::assertInstanceOf(SearchRecord::class, $record);
        self::assertSame('mpuse', $record->query);
        self::assertSame(2, $record->hits);
    }

    public function testTheRescueIsCountedAndExplained(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->in('products')->query('mpuse')->get();

        self::assertSame(1, $this->counted('fuzzphony.search.rescued'));
        $explanation = $fuzzphony->in('products')->query('mpuse')->facets('in_stock')->explain();
        $labels = array_column($explanation->statements, 'label');
        self::assertContains('did you mean', $labels);
        self::assertContains('rescue: full-text', $labels);
        self::assertContains('rescue: facet: in_stock', $labels);
        self::assertSame('mpuse', $explanation->result->correctedFrom);
        self::assertSame('mouse', $explanation->interpretedAs);
        self::assertNotSame([], $explanation->plan, 'the plan is the rescued search statement');
    }

    public function testThereIsAtMostOneRescueAttempt(): void
    {
        $fuzzphony = $this->fuzzphony();
        $explanation = $fuzzphony->in('products')->query('mpuse')->explain();
        $labels = array_column($explanation->statements, 'label');

        self::assertSame(1, count(array_filter($labels, static fn(string $l): bool => $l === 'did you mean')), 'the lookup runs once');
        self::assertSame('mpuse', $explanation->result->correctedFrom);

        // the corrected search has nothing either: still one attempt, and the original answer
        $empty = $fuzzphony->in('products')->query('mpuse')->where('price', '>', 1_000_000)->explain();
        self::assertSame(1, count(array_filter(array_column($empty->statements, 'label'), static fn(string $l): bool => $l === 'rescue: full-text')), 'the attempt is listed');
        self::assertNull($empty->result->correctedFrom);
    }

    public function testAWordWithADigitIsNotCorrected(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mpuse4')->get();

        self::assertSame([], $result->ids());
        self::assertNull($result->correctedFrom);
    }

    public function testAFederatedSearchRescuesEachIndexOnItsOwn(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Razer'], [2, 'Sony']],
            'products' => [[1, 'Gaming mouse', 'x', 1, 1, true, 1.0, 'now']],
        ]);
        $brands = IndexDefinition::builder('brands')->fromTable('fz_brand')->field('name', 'A', fuzzy: true)->language('english')->sync('manual')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual'), $brands]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');
        $fuzzphony->reindex('brands');

        $result = $fuzzphony->federated()->index('products')->index('brands')->query('rxzer')->get();

        self::assertSame('rxzer', $result->results['brands']->correctedFrom);
        self::assertSame('rxzer', $result->results['products']->correctedFrom);
        self::assertSame([1], $result->results['brands']->ids());
        self::assertContains("[brands] No results for 'rxzer'; showing results for 'razer'.", $result->warnings);
        // the untouched one: a word both find has no flag
        $found = $fuzzphony->federated()->index('products')->index('brands')->query('razer')->get();
        self::assertNull($found->results['brands']->correctedFrom);
    }
}

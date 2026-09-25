<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** PostgreSQL side of the empty-result relaxation: the statements it runs, and tenant isolation. */
final class EmptyResultRelaxationTest extends TestCase
{
    private function fuzzphony(bool $tenant = false): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testExplainShowsTheProbeAndTheRelaxedSearch(): void
    {
        $explanation = $this->fuzzphony()->in('products')->query('wireless mouse offfice')->explain(analyze: true);

        self::assertSame(
            ['full-text', 'fallback: full-text + fuzzy', 'relaxation probe', 'relaxed: full-text', 'relaxed: fallback: full-text + fuzzy'],
            array_column($explanation->statements, 'label'),
        );
        self::assertSame('(wireless AND mouse)', $explanation->interpretedAs);
        self::assertSame([1], $explanation->result->ids());
        self::assertNotEmpty(array_filter($explanation->plan, static fn(string $l): bool => str_contains($l, 'actual time')));

        $probe = $explanation->statements[2];
        self::assertStringContainsString('EXISTS (SELECT 1 FROM "fuzzphony_products" AS s WHERE (s.tsv @@ q.ft0 OR q.fn1 <% s.fz)', $probe['sql']);
        self::assertStringNotContainsString('offfice', $probe['sql'], 'user text is bound, never inlined');
        self::assertContains('offfice', $probe['params']);
    }

    public function testTheProbeRunsOnlyWhenItCanChangeSomething(): void
    {
        $search = $this->fuzzphony()->in('products');
        $labels = static fn(string $q, bool $relax = true, string $fuzzyMode = 'fallback'): array => array_column(
            $search->query($q)->thresholds(['relax_when_empty' => $relax, 'fuzzy_mode' => $fuzzyMode])->explain()->statements,
            'label',
        );

        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], $labels('offfice'), 'one word: nothing to keep');
        self::assertSame(['full-text'], $labels('the ab'), 'a stop word is not a word to keep or to report');
        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], $labels('wireless mouse offfice', relax: false));
        self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'relaxation probe'], $labels('mouse torch'), 'every word matches something: no second search');
        self::assertSame(['full-text', 'fallback: full-text + fuzzy'], $labels('wireless mouse'), 'hits: no probe');
        self::assertSame(['full-text', 'relaxation probe', 'relaxed: full-text'], $labels('wireless mouse offfice', fuzzyMode: 'never'));
    }

    public function testTheProbeReadsTheTextIndexesInsteadOfScanningForAFirstMatch(): void
    {
        // the probe is the last statement here, so it is the one explain() shows
        $explanation = $this->fuzzphony()->in('products')->query('mouse torch')->explain(analyze: true);
        $plan = implode("\n", $explanation->plan);

        self::assertSame(['full-text', 'fallback: full-text + fuzzy', 'relaxation probe'], array_column($explanation->statements, 'label'));
        self::assertStringContainsString('Bitmap Index Scan on fuzzphony_products_tsv', $plan);
        self::assertStringContainsString('Bitmap Index Scan on fuzzphony_products_fz', $plan);
        self::assertStringNotContainsString('Seq Scan on fuzzphony_products', $plan);
        self::assertStringNotContainsString('Index Scan using', $plan);
    }

    public function testAStopWordIsNeitherKeptNorReportedAsUnmatched(): void
    {
        $result = $this->fuzzphony()->in('products')->query('wireless mouse for offfice')->get();

        self::assertSame([1], $result->ids());
        self::assertContains('No results for all words; ignored words that match nothing: "offfice".', $result->warnings);
    }

    public function testAWordThatOnlyExistsForAnotherTenantIsReportedLikeAWordThatExistsNowhere(): void
    {
        $search = $this->fuzzphony(tenant: true)->in('products');

        // Sony (brand 3) has products 3 and 5; "gaming" only exists in Razer's product 2
        $other = $search->forTenant(3)->query('wireless gaming')->get();
        $nowhere = $search->forTenant(3)->query('wireless zzqqx')->get();

        self::assertSame([3], $other->ids());
        self::assertSame('wireless', $other->interpretedAs);
        self::assertContains('No results for all words; ignored words that match nothing: "gaming".', $other->warnings);
        self::assertSame(self::observable($nowhere), self::observable($other), 'nothing tells the two apart');

        // and the other way round: Razer has no wireless product
        $razer = $search->forTenant(2)->query('wireless gaming')->get();
        self::assertSame([2], $razer->ids());
        self::assertContains('No results for all words; ignored words that match nothing: "wireless".', $razer->warnings);
    }

    /** @return array<string, mixed> */
    private static function observable(SearchResult $result): array
    {
        return [
            'ids' => $result->ids(),
            'total' => $result->total,
            'usedFuzzy' => $result->usedFuzzy,
            'interpretedAs' => $result->interpretedAs,
            'warnings' => str_replace(['gaming', 'zzqqx'], 'WORD', $result->warnings),
        ];
    }
}

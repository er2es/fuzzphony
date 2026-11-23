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
 * A facet that leaves its own filter out selects its own candidates, at the session similarity of the lowest word. A word that needs
 * more ("telly" next to "television") is probed on its own first, at its own similarity, and the facet uses its ids: the same counts,
 * without the trigram index returning every row that clears the lowest word's similarity. Words that all need the same: no probe, the
 * statement as it was.
 */
final class FacetWordThresholdsTest extends TestCase
{
    private InterceptingConnection $log;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        $names = ['Television stand', 'Telly remote', 'Tellus globe', 'Smart telvision', 'Telescope', 'Tell tale lamp', 'Telefon cable', 'Teddy bear'];
        $products = [];
        foreach ($names as $i => $name) {
            $products[] = [$i + 1, $name, 'Plain', 1, 1_000 * ($i + 1), $i % 2 === 0, 1.0, 'now'];
        }
        PostgresTestCase::createFixtures($connection, ['brands' => [[1, 'Acme']], 'products' => $products]);
        $this->log = new InterceptingConnection($connection);
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->log), new IndexRegistry([Indexes::products('manual')]));
        $this->fuzzphony->schema()->apply($connection);
        $this->fuzzphony->reindex('products');
    }

    public function testAWordThatNeedsMoreIsProbedForTheFacetThatLeavesItsFilterOut(): void
    {
        $this->log->log = [];
        $result = $this->search('television | telly');

        $probes = $this->probes();
        // the page's two words, then the facet's one word that needs more than television's similarity
        self::assertSame([['television'], ['telly'], ['telly']], array_map(static fn(array $entry): array => [array_values($entry[1])[0]], $probes));
        self::assertStringNotContainsString('in_stock', $probes[2][0], 'the facet leaves its filter out');
        $facet = $this->facetSql();
        self::assertStringContainsString('s.id = ANY(q.id', $facet);
        self::assertSame(1, substr_count($facet, 'OPERATOR("public".<%)'), 'television keeps its trigram condition');
        self::assertSame([[true, 4], [false, 3]], self::counts($result), 'as without the probe (v1.0.1)');
    }

    public function testWordsOfTheSameSimilarityAddNoProbeAndKeepTheFacetStatement(): void
    {
        $this->log->log = [];
        $result = $this->search('telly | teddy');

        self::assertCount(2, $this->probes(), 'the page\'s probes only');
        $facet = $this->facetSql();
        self::assertStringNotContainsString('ANY(q.id', $facet);
        self::assertSame(2, substr_count($facet, 'OPERATOR("public".<%)'));
        self::assertNotSame([], self::counts($result));
    }

    private function search(string $text): SearchResult
    {
        return $this->fuzzphony->in('products')->query($text)->where('in_stock', true)->facets('in_stock')->thresholds(['typo' => ['mode' => 'always']])->get();
    }

    /** @return list<array{string, array<string, scalar|null>}> */
    private function probes(): array
    {
        return array_values(array_filter($this->log->log, static fn(array $entry): bool => str_starts_with($entry[0], "SELECT s.id\nFROM")));
    }

    private function facetSql(): string
    {
        $facets = array_values(array_filter($this->log->log, static fn(array $entry): bool => str_contains($entry[0], 'AS rk FROM fc')));
        self::assertCount(1, $facets);

        return $facets[0][0];
    }

    /** @return list<array{bool|int|string|null, int}> */
    private static function counts(SearchResult $result): array
    {
        return array_map(static fn($v): array => [$v->value, $v->count], $result->facets['in_stock']);
    }
}

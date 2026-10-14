<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The empty-result relaxation only runs when some of the words match and some do not. */
final class EmptyResultRelaxationMutationsTest extends TestCase
{
    /** @return \Closure(string): list<string> the labels of the statements a search runs, typo tolerance off */
    private function labels(): \Closure
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')->withThresholds(new Thresholds(didYouMean: false))]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return static fn(string $query): array => array_column(
            $fuzzphony->in('products')->query($query)->thresholds(['fuzzy_mode' => 'never'])->explain()->statements,
            'label',
        );
    }

    public function testWhenNoWordMatchesNothingIsRelaxedEvenIfAStopWordIsLeft(): void
    {
        $labels = $this->labels();

        // "the" is dropped by the search itself and never probed; both other words match nothing: no word to keep
        self::assertSame(['full-text', 'relaxation probe'], $labels('xqzvkw zzqqyyx the'));
        // with a word that does match, the same shape relaxes
        self::assertSame(['full-text', 'relaxation probe', 'relaxed: full-text'], $labels('wireless xqzvkw the'));
    }

    public function testARelaxedQueryThatWouldLookForNothingIsNotRun(): void
    {
        $labels = $this->labels();

        // dropping "xqzvkw" leaves "wireless | -cable", an OR with an exclusion: it does not look for anything by itself
        self::assertSame(['full-text', 'relaxation probe'], $labels('xqzvkw (wireless | -cable)'));
        self::assertSame(['full-text', 'relaxation probe', 'relaxed: full-text'], $labels('xqzvkw (wireless | cable)'), 'the same without the exclusion relaxes');
    }
}

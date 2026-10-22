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

/** "Did you mean" looks up at most max_terms words of a query. */
final class DidYouMeanMutationsTest extends TestCase
{
    private function fuzzphony(): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')->withThresholds(new Thresholds(relaxWhenEmpty: false, rescueEmpty: false))]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testOnlyTheFirstMaxTermsWordsOfAPhraseAreLookedUp(): void
    {
        $fuzzphony = $this->fuzzphony();
        $mean = static fn(int $maxTerms): ?string => $fuzzphony->in('products')->thresholds(['max_terms' => $maxTerms])->query('"wireles hedphones mose"')->get()->didYouMean;

        // a quoted phrase counts as one term for the parser, so the words it holds are limited here
        self::assertSame('"wireless headphones mouse"', $mean(3));
        self::assertSame('"wireless headphones mose"', $mean(2), 'the third word is not looked up');
    }
}

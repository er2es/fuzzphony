<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The engine keeps one synonym expander per index: another index with the same synonym entries never borrows it. */
final class SynonymsMutationsTest extends TestCase
{
    public function testTwoIndexesWithTheSameEntriesKeepTheirOwnExpander(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Acme']],
            'products' => [
                [1, 'Smart television', 'Ultra HD screen', 1, 49_990, true, 5.0, 'now'],
                [2, 'Wall bracket', 'Fits every TV on the wall', 1, 2_990, true, 3.0, 'now'],
                [3, 'TV', 'The television', 1, 9_990, true, 1.0, 'now'],
            ],
        ]);
        $thresholds = new Thresholds(didYouMean: false);
        $entries = [['tv', 'television']];
        $first = Indexes::products('manual')->withThresholds($thresholds)->withSynonyms(Synonyms::fromEntries($entries));
        $second = Indexes::products('manual')->withName('products_b')->withThresholds($thresholds)->withSynonyms(Synonyms::fromEntries($entries));
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$first, $second]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');
        $fuzzphony->reindex('products_b');

        // the same entries, but stems that do not match what PostgreSQL makes of the query: "television" no longer meets "tv"
        $prepared = $fuzzphony->stemSynonyms('products_b', Synonyms::fromEntries($entries));
        self::assertNotNull($prepared->stemConfig);
        $fuzzphony->useSynonyms('products_b', $prepared->withStems($prepared->stemConfig, ['tv' => 'tv', 'television' => 'television']));

        $ids = static function (Fuzzphony $fuzzphony, string $index): array {
            $ids = $fuzzphony->in($index)->thresholds(['fuzzy_mode' => 'never'])->query('television')->get()->ids();
            sort($ids);

            return $ids;
        };

        self::assertSame([1, 2, 3], $ids($fuzzphony, 'products'), 'the first index expands "television" to "tv"');
        self::assertSame([1, 3], $ids($fuzzphony, 'products_b'), 'the second one has its own expander, made from its own stems');
    }
}

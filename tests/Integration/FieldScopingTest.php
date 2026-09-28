<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** brand:x searches the brand field only, on the full-text side and on the typo-tolerant side. */
final class FieldScopingTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_shared_b, fuzzphony_shared_b__next, fuzzphony_shared_b__changes CASCADE');
        // brand and description share weight B
        $sharedB = IndexDefinition::builder('shared_b')
            ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'B')
            ->language('english')
            ->sync('manual')
            ->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual'), $sharedB]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('products');
        $this->fuzzphony->reindex('shared_b');
    }

    public function testAScopedWordSearchesOnlyItsFieldNotTheWholeWeightGroup(): void
    {
        self::assertEqualsCanonicalizing([1, 3], $this->ids('shared_b', 'wireless', 'never'));
        self::assertSame([], $this->ids('shared_b', 'brand:wireless', 'never'), 'description shares weight B, but it is not the brand');
        self::assertSame([1], $this->ids('shared_b', 'description:wireless', 'never'));
        self::assertEqualsCanonicalizing([3, 5], $this->ids('shared_b', 'brand:sony', 'never'));
    }

    public function testAScopedWordNoLongerFallsBackToAnotherFuzzyField(): void
    {
        self::assertSame([], $this->ids('products', 'name:sony'), 'no name has "sony"; the brand field is not searched, not even by typo tolerance');
        self::assertSame([], $this->ids('products', 'name:logitech'));
        self::assertEqualsCanonicalizing([3, 5], $this->ids('products', 'brand:sony'));
    }

    public function testAScopedTypoMatchesOnlyItsField(): void
    {
        self::assertSame([2], $this->ids('products', 'brand:razr'));
        self::assertSame([], $this->ids('products', 'name:razr'), 'Razer is a brand, not a name');
        self::assertSame([3], $this->ids('products', 'name:hedphones'));
        self::assertSame([], $this->ids('products', 'brand:hedphones'));
    }

    public function testAnExcludedScopedWordOnlyExcludesItsField(): void
    {
        self::assertEqualsCanonicalizing([1, 3], $this->ids('shared_b', 'wireless -brand:silent', 'never'), 'product 1 has "silent" in its description (weight B too), not in its brand');
        self::assertSame([2], $this->ids('products', 'mouse -brand:logitech', 'never'));
    }

    public function testAnExcludedScopedWordInAnOrDoesNotNarrowTheCandidates(): void
    {
        self::assertEqualsCanonicalizing([1, 3], $this->ids('products', 'wireless (mouse | -brand:logitech)', 'never'), 'product 3 is no mouse and not Logitech');
        self::assertEqualsCanonicalizing([1, 3], $this->ids('products', 'wireless (mouse | -brand:logitech)'), 'with typo tolerance too');
        self::assertSame([1], $this->ids('products', 'wireless (mouse | -brand:sony)', 'never'));
    }

    public function testAnExcludedGroupExcludesAScopedWordOnlyByItsField(): void
    {
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Travel mouse', 'Pairs with Sony laptops', 1, 1990, true, 1.0, now())");
        $this->fuzzphony->reindex('shared_b');

        self::assertEqualsCanonicalizing([1, 4, 6], $this->ids('shared_b', 'mouse -(brand:sony | cable)', 'never'), 'product 6 has "sony" in its description (weight B too), not in its brand; product 2 has a cable');
        self::assertEqualsCanonicalizing([1, 4, 6], $this->ids('shared_b', 'mouse -(brand:sony | cable)'), 'with typo tolerance too');
        self::assertEqualsCanonicalizing([1, 2, 4], $this->ids('shared_b', 'mouse -(description:sony | brand:sony)', 'never'));
    }

    public function testAScopedStopWordIsIgnoredLikeAnyStopWord(): void
    {
        // same documents; the order may differ, since the exact / prefix bonuses compare the plain words ("the mouse")
        self::assertEqualsCanonicalizing($this->ids('products', 'mouse', 'never'), $this->ids('products', 'brand:the mouse', 'never'));
        self::assertEqualsCanonicalizing($this->ids('products', 'mouse'), $this->ids('products', 'brand:the mouse'), 'with typo tolerance too');
        self::assertNotSame([], $this->ids('products', 'brand:the mouse'));
    }

    public function testAnOrOfScopedWordsKeepsEachToItsField(): void
    {
        self::assertEqualsCanonicalizing([2, 3, 5], $this->ids('products', 'brand:sony | name:gaming', 'never'));
    }

    public function testAnUnknownFieldStillSearchesEverywhereWithAWarning(): void
    {
        $result = $this->fuzzphony->in('products')->query('colour:mouse')->thresholds(['fuzzy_mode' => 'never'])->get();

        self::assertEqualsCanonicalizing([1, 2, 4], $result->ids());
        self::assertContains('Unknown field "colour"; searched in all fields instead.', $result->warnings);
    }

    public function testAScopedWordRanksLikeTheSameUnscopedWord(): void
    {
        $scoped = $this->fuzzphony->in('products')->query('brand:razer')->thresholds(['fuzzy_mode' => 'never'])->get()->hits[0] ?? self::fail('no hit');
        $plain = $this->fuzzphony->in('products')->query('razer')->thresholds(['fuzzy_mode' => 'never'])->get()->hits[0] ?? self::fail('no hit');

        self::assertSame(2, $scoped->id);
        self::assertSame($plain->breakdown->textRank, $scoped->breakdown->textRank);
    }

    /** @return list<int|string> */
    private function ids(string $index, string $query, string $fuzzyMode = 'fallback'): array
    {
        return $this->fuzzphony->in($index)->query($query)->thresholds(['fuzzy_mode' => $fuzzyMode])->get()->ids();
    }
}

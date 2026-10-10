<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Search-as-you-type: the last word also matches as the beginning of a longer one. */
final class AsYouTypeTest extends TestCase
{
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')]));
        $this->fuzzphony->schema()->apply($connection);
        $this->fuzzphony->reindex('products');
    }

    private function search(string $text): SearchBuilder
    {
        return $this->fuzzphony->in('products')->query($text);
    }

    /**
     * @param SearchBuilder $search
     *
     * @return list<int|string>
     */
    private static function ids(SearchBuilder $search): array
    {
        $ids = $search->get()->ids();
        sort($ids);

        return $ids;
    }

    public function testAWordBeingTypedFindsTheLongerWords(): void
    {
        self::assertSame([], self::ids($this->search('mo')), 'a whole word, and too short for typo tolerance: nothing');
        self::assertSame([1, 2, 4], self::ids($this->search('mo')->asYouType()));
        self::assertSame([3], self::ids($this->search('wireless hea')->asYouType()), 'the words before it stay whole words');
        self::assertSame([5], self::ids($this->search('Crè')->asYouType()), 'accents are folded');
        self::assertSame('(mo OR mo*)', $this->search('mo')->asYouType()->get()->interpretedAs);
    }

    public function testAFinishedWordIsStillAWholeWordWithTypoToleranceAndSuggestions(): void
    {
        $typo = $this->search('mose')->asYouType()->get();

        self::assertContains(1, $typo->ids(), '"mose" is a typo of "mouse": found as before');
        self::assertSame('mouse', $typo->didYouMean, 'without the prefix alternative the engine saw');
        self::assertSame('wireless mouse', $this->search('wireles mose')->asYouType()->get()->didYouMean);
        self::assertSame([1, 2, 4], self::ids($this->search('mouse')->asYouType()));
        self::assertSame([], self::ids($this->search('mo ')->asYouType()), 'a space ends the word: nothing to complete');
        self::assertSame([], self::ids($this->search('"noise canc')->asYouType()), 'inside a quoted phrase');
    }

    public function testFieldsAndExclusionsTakeThePrefix(): void
    {
        self::assertSame([1, 4], self::ids($this->search('brand:logi')->asYouType()));
        self::assertSame([1, 2, 4], self::ids($this->search('mouse -ga')), 'a whole word excludes nothing');
        self::assertSame([1, 4], self::ids($this->search('mouse -ga')->asYouType()), 'a prefix excludes the gaming mouse');
    }

    public function testItWorksWithFacetsAndFederatedSearch(): void
    {
        $result = $this->search('mo')->asYouType()->facets('in_stock')->get();
        self::assertSame(3, $result->total);
        self::assertSame(3, $result->facets['in_stock'][0]->count);

        $federated = $this->fuzzphony->federated()->index('products', configure: static fn(SearchBuilder $b): SearchBuilder => $b->asYouType())->query('mo')->get();
        self::assertSame(3, $federated->total);
        self::assertSame([], $this->fuzzphony->federated()->index('products')->query('mo')->get()->hits);
    }

    public function testTheExplainedStatementIsTheOneThatRan(): void
    {
        $explanation = $this->search('mo')->asYouType()->explain();

        self::assertSame('(mo OR mo*)', $explanation->interpretedAs);
        self::assertSame(3, $explanation->result->total);
    }
}
